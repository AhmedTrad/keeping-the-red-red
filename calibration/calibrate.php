<?php
/**
 * Quality calibration experiment (section 3.5 of the paper), on a folder of photographs.
 *
 *   php calibrate.php photos/ [--width=600] [--height=800] [--per-category=6] [--qualities=60,68,76,82,88,94]
 *
 * photos/ holds one sub-folder per category (chiffon/, jersey/, necklaces/ …) of JPEG or PNG
 * files. For each category, up to --per-category images → a centred crop to the card size the
 * storefront serves (600×800 by default) → WebP at each quality → file size, and SSIM on luminance
 * (8×8 windows, stride 4, at half size) against the lossless crop. Also a detail measure of each
 * crop (variance of the Laplacian of luma), to see whether categories really differ.
 *
 * Needs PHP 8.0+ with GD built with WebP. Nothing outside the temp folder is written.
 */
$opts = getopt( '', [ 'width::', 'height::', 'per-category::', 'qualities::' ] );
$dir  = $argv[ count( $argv ) - 1 ] ?? '';
if ( ! $dir || ! is_dir( $dir ) ) {
    fwrite( STDERR, "usage: php calibrate.php photos/ [--width=600] [--height=800] [--per-category=6] [--qualities=60,68,76,82,88,94]\n" );
    exit( 1 );
}
$W         = (int) ( $opts['width'] ?? 600 );
$H         = (int) ( $opts['height'] ?? 800 );
$per_cat   = (int) ( $opts['per-category'] ?? 6 );
$qualities = array_map( 'intval', explode( ',', $opts['qualities'] ?? '60,68,76,82,88,94' ) );
$today     = in_array( 82, $qualities, true ) ? 82 : $qualities[ (int) ( count( $qualities ) / 2 ) ];
$tmp       = sys_get_temp_dir() . '/calibrate-' . getmypid() . '.webp';

function luma_grid( $im, $w, $h ) {
    $g = [];
    for ( $y = 0; $y < $h; $y++ ) {
        $row = [];
        for ( $x = 0; $x < $w; $x++ ) {
            $c     = imagecolorat( $im, $x, $y );
            $row[] = 0.299 * ( ( $c >> 16 ) & 255 ) + 0.587 * ( ( $c >> 8 ) & 255 ) + 0.114 * ( $c & 255 );
        }
        $g[] = $row;
    }
    return $g;
}

/** Mean SSIM over 8×8 windows, stride 4, with the standard constants. */
function ssim( $a, $b, $w, $h ) {
    $c1  = ( 0.01 * 255 ) ** 2;
    $c2  = ( 0.03 * 255 ) ** 2;
    $sum = 0;
    $n   = 0;
    for ( $y = 0; $y + 8 <= $h; $y += 4 ) {
        for ( $x = 0; $x + 8 <= $w; $x += 4 ) {
            $ma = $mb = 0;
            for ( $j = 0; $j < 8; $j++ ) {
                for ( $i = 0; $i < 8; $i++ ) {
                    $ma += $a[ $y + $j ][ $x + $i ];
                    $mb += $b[ $y + $j ][ $x + $i ];
                }
            }
            $ma /= 64;
            $mb /= 64;
            $va = $vb = $cov = 0;
            for ( $j = 0; $j < 8; $j++ ) {
                for ( $i = 0; $i < 8; $i++ ) {
                    $da   = $a[ $y + $j ][ $x + $i ] - $ma;
                    $db   = $b[ $y + $j ][ $x + $i ] - $mb;
                    $va  += $da * $da;
                    $vb  += $db * $db;
                    $cov += $da * $db;
                }
            }
            $va  /= 63;
            $vb  /= 63;
            $cov /= 63;
            $sum += ( ( 2 * $ma * $mb + $c1 ) * ( 2 * $cov + $c2 ) ) / ( ( $ma * $ma + $mb * $mb + $c1 ) * ( $va + $vb + $c2 ) );
            $n++;
        }
    }
    return $sum / max( 1, $n );
}

/** Variance of the Laplacian of luma: how much fine detail the crop holds. */
function detail( $g, $w, $h ) {
    $vals = [];
    for ( $y = 1; $y < $h - 1; $y++ ) {
        for ( $x = 1; $x < $w - 1; $x++ ) {
            $vals[] = 4 * $g[ $y ][ $x ] - $g[ $y - 1 ][ $x ] - $g[ $y + 1 ][ $x ] - $g[ $y ][ $x - 1 ] - $g[ $y ][ $x + 1 ];
        }
    }
    $m = array_sum( $vals ) / count( $vals );
    $v = 0;
    foreach ( $vals as $x ) {
        $v += ( $x - $m ) ** 2;
    }
    return $v / count( $vals );
}

/** A centred crop of the image scaled to exactly $w×$h, as a storefront card does. */
function card_crop( $path, $w, $h ) {
    $info = @getimagesize( $path );
    if ( ! $info ) {
        return null;
    }
    $loader = [ 'image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/webp' => 'imagecreatefromwebp' ][ $info['mime'] ] ?? '';
    if ( ! $loader || ! function_exists( $loader ) ) {
        return null;
    }
    $im = @$loader( $path );
    if ( ! $im ) {
        return null;
    }
    $sw    = imagesx( $im );
    $sh    = imagesy( $im );
    $scale = max( $w / $sw, $h / $sh );
    $cw    = (int) round( $w / $scale );
    $ch    = (int) round( $h / $scale );
    $sx    = (int) ( ( $sw - $cw ) / 2 );
    $sy    = (int) ( ( $sh - $ch ) / 2 );
    $out   = imagecreatetruecolor( $w, $h );
    imagecopyresampled( $out, $im, 0, 0, $sx, $sy, $w, $h, $cw, $ch );
    imagedestroy( $im );
    return $out;
}

$results = [];
foreach ( glob( rtrim( $dir, '/\\' ) . '/*', GLOB_ONLYDIR ) as $catdir ) {
    $cat   = basename( $catdir );
    $files = array_values( array_filter( glob( $catdir . '/*' ), fn( $f ) => preg_match( '/\.(jpe?g|png|webp)$/i', $f ) ) );
    foreach ( array_slice( $files, 0, $per_cat ) as $src ) {
        $ref = card_crop( $src, $W, $H );
        if ( ! $ref ) {
            continue;
        }
        // SSIM at half size for speed, as in the paper
        $sw        = (int) ( $W / 2 );
        $sh        = (int) ( $H / 2 );
        $ref_small = imagecreatetruecolor( $sw, $sh );
        imagecopyresampled( $ref_small, $ref, 0, 0, 0, 0, $sw, $sh, $W, $H );
        $ref_luma = luma_grid( $ref_small, $sw, $sh );
        $row      = [ 'cat' => $cat, 'file' => basename( $src ), 'detail' => detail( $ref_luma, $sw, $sh ), 'q' => [] ];
        foreach ( $qualities as $q ) {
            imagewebp( $ref, $tmp, $q );
            clearstatcache( true, $tmp );
            $bytes     = filesize( $tmp );
            $dec       = imagecreatefromwebp( $tmp );
            $dec_small = imagecreatetruecolor( $sw, $sh );
            imagecopyresampled( $dec_small, $dec, 0, 0, 0, 0, $sw, $sh, $W, $H );
            $row['q'][ $q ] = [ 'bytes' => $bytes, 'ssim' => ssim( $ref_luma, luma_grid( $dec_small, $sw, $sh ), $sw, $sh ) ];
            imagedestroy( $dec );
            imagedestroy( $dec_small );
        }
        imagedestroy( $ref );
        imagedestroy( $ref_small );
        $results[] = $row;
        fwrite( STDERR, '.' );
    }
}
fwrite( STDERR, "\n" );
@unlink( $tmp );
if ( ! $results ) {
    fwrite( STDERR, "no readable JPEG/PNG/WebP files in the category sub-folders of $dir\n" );
    exit( 1 );
}

// per-category averages
$by = [];
foreach ( $results as $r ) {
    $by[ $r['cat'] ]['n']      = ( $by[ $r['cat'] ]['n'] ?? 0 ) + 1;
    $by[ $r['cat'] ]['detail'] = ( $by[ $r['cat'] ]['detail'] ?? 0 ) + $r['detail'];
    foreach ( $r['q'] as $q => $m ) {
        $by[ $r['cat'] ]['q'][ $q ]['bytes'] = ( $by[ $r['cat'] ]['q'][ $q ]['bytes'] ?? 0 ) + $m['bytes'];
        $by[ $r['cat'] ]['q'][ $q ]['ssim']  = ( $by[ $r['cat'] ]['q'][ $q ]['ssim'] ?? 0 ) + $m['ssim'];
    }
}
printf( "%-16s %3s %7s | %s\n", 'category', 'n', 'detail', implode( ' | ', array_map( fn( $q ) => sprintf( 'q%-2d KB  SSIM ', $q ), $qualities ) ) );
foreach ( $by as $cat => $c ) {
    $cells = [];
    foreach ( $qualities as $q ) {
        $cells[] = sprintf( '%5.0f  %.4f', $c['q'][ $q ]['bytes'] / $c['n'] / 1024, $c['q'][ $q ]['ssim'] / $c['n'] );
    }
    printf( "%-16s %3d %7.0f | %s\n", $cat, $c['n'], $c['detail'] / $c['n'], implode( ' | ', $cells ) );
}
// (a) One SSIM target for every category: the lowest quality per category that reaches it, and the
// bytes that saves or costs against the reference quality. (b) The SSIM each category gets at that quality.
foreach ( [ 0.980, 0.985, 0.990 ] as $target ) {
    printf( "\nTarget SSIM ≥ %.3f (luma, half size):\n", $target );
    foreach ( $by as $cat => $c ) {
        $pick = null;
        foreach ( $qualities as $q ) {
            if ( $c['q'][ $q ]['ssim'] / $c['n'] >= $target ) {
                $pick = $q;
                break;
            }
        }
        if ( $pick ) {
            printf( "  %-16s q%-2d  %+5.0f%% bytes vs q%d   (at q%d: SSIM %.4f)\n", $cat, $pick, 100 * ( $c['q'][ $pick ]['bytes'] / $c['q'][ $today ]['bytes'] - 1 ), $today, $today, $c['q'][ $today ]['ssim'] / $c['n'] );
        } else {
            printf( "  %-16s not reached by q%d   (at q%d: SSIM %.4f)\n", $cat, max( $qualities ), $today, $c['q'][ $today ]['ssim'] / $c['n'] );
        }
    }
}
printf( "\nPer-image spread at q%d (SSIM min–max per category):\n", $today );
foreach ( $by as $cat => $c ) {
    $rows_c = array_filter( $results, fn( $r ) => $r['cat'] === $cat );
    $vals   = array_map( fn( $r ) => $r['q'][ $today ]['ssim'], $rows_c );
    $dets   = array_map( fn( $r ) => $r['detail'], $rows_c );
    printf( "  %-16s %.4f – %.4f   detail %.0f – %.0f\n", $cat, min( $vals ), max( $vals ), min( $dets ), max( $dets ) );
}
