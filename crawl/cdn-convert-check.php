<?php
/**
 * Does Shopify's CDN convert a wide-gamut upload to sRGB when it resizes, or only re-tag it?
 *
 *   php cdn-convert-check.php results.csv [how-many, default 2]
 *
 * For the wide-gamut images with the largest shift that a Shopify CDN serves: fetch the original
 * (the full file, with its profile) and the CDN's 300 px copy (tagged sRGB). Downscale the original
 * to the same size with GD (no colour management) and compare, pixel by pixel in CIELAB:
 *   A = CDN copy read as sRGB (what browsers show);
 *   B = original read through its profile (the intended colour);
 *   C = original read as sRGB (what a stripped file would show).
 * If the CDN converted the pixels, A ≈ B. If it only re-tagged the file, A ≈ C and A differs from B
 * by the full shift.
 */
require __DIR__ . '/../lib/icc.php';
require __DIR__ . '/../lib/http.php';

$csv     = $argv[1] ?? '';
$howmany = (int) ( $argv[2] ?? 2 );
if ( ! $csv || ! is_file( $csv ) ) {
    fwrite( STDERR, "usage: php cdn-convert-check.php results.csv [n]\n" );
    exit( 1 );
}
$rows = array_map( 'str_getcsv', file( $csv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) );
$head = array_shift( $rows );
$col  = array_flip( $head );
$wide = array_values( array_filter( $rows, fn( $r ) => 'ok' === $r[ $col['status'] ] && 'cdn-processed' === $r[ $col['served_file'] ] && ! in_array( $r[ $col['class'] ], [ 'none', 'srgb' ], true ) && str_contains( $r[ $col['src'] ], 'cdn.shopify.com' ) ) );
if ( ! $wide ) {
    echo "no wide-gamut image served by a Shopify CDN in $csv\n";
    exit;
}
usort( $wide, fn( $a, $b ) => (float) $b[ $col['de_mean'] ] <=> (float) $a[ $col['de_mean'] ] );

$mean_de = function ( $a, $b ) {
    $s = 0;
    $n = min( count( $a ), count( $b ) );
    for ( $i = 0; $i < $n; $i++ ) {
        $s += sqrt( ( $a[ $i ][0] - $b[ $i ][0] ) ** 2 + ( $a[ $i ][1] - $b[ $i ][1] ) ** 2 + ( $a[ $i ][2] - $b[ $i ][2] ) ** 2 );
    }
    return $n ? $s / $n : 0;
};
$tmp_orig  = sys_get_temp_dir() . '/colour-cdn-orig-' . getmypid();
$tmp_thumb = sys_get_temp_dir() . '/colour-cdn-thumb-' . getmypid();

foreach ( array_slice( $wide, 0, $howmany ) as $r ) {
    $src       = $r[ $col['src'] ];
    $thumb_url = preg_replace( '/(\.\w+)(\?.*)?$/', '_300x$1$2', $src, 1 );
    $orig      = http_get( $src, null, 30, 'image/jpeg' );
    $thumb     = http_get( $thumb_url, null, 30, 'image/jpeg' );
    if ( 200 !== $orig['code'] || 200 !== $thumb['code'] ) {
        echo "fetch failed for $src\n";
        continue;
    }
    file_put_contents( $tmp_orig, $orig['body'] );
    file_put_contents( $tmp_thumb, $thumb['body'] );
    $profile = icc_parse( icc_from_file( $tmp_orig ) );
    $tprof   = icc_parse( icc_from_file( $tmp_thumb ) );
    $o       = @imagecreatefromstring( $orig['body'] );
    $t       = @imagecreatefromstring( $thumb['body'] );
    if ( ! $o || ! $t || ! $profile || ! $profile['matrix'] ) {
        echo "unreadable image or profile for $src\n";
        continue;
    }
    $tw = imagesx( $t );
    $th = imagesy( $t );
    // Downscale the original to the CDN copy's exact size, then sample both the same way (a 32×32 grid).
    $d = imagecreatetruecolor( $tw, $th );
    imagecopyresampled( $d, $o, 0, 0, 0, 0, $tw, $th, imagesx( $o ), imagesy( $o ) );
    $sample = function ( $im ) use ( $tw, $th ) {
        $px = [];
        for ( $y = 0; $y < 32; $y++ ) {
            for ( $x = 0; $x < 32; $x++ ) {
                $c    = imagecolorat( $im, (int) ( ( $x + .5 ) * $tw / 32 ), (int) ( ( $y + .5 ) * $th / 32 ) );
                $px[] = [ ( $c >> 16 ) & 255, ( $c >> 8 ) & 255, $c & 255 ];
            }
        }
        return $px;
    };
    $cdn = $sample( $t );
    $own = $sample( $d );
    $A   = array_map( fn( $p ) => colour_pixel_lab( $p, null ), $cdn );      // CDN copy as sRGB
    $B   = array_map( fn( $p ) => colour_pixel_lab( $p, $profile ), $own );  // intended colour (original through its profile)
    $C   = array_map( fn( $p ) => colour_pixel_lab( $p, null ), $own );      // original stripped, read as sRGB
    printf( "%s  original %s, CDN copy tagged %s, %dx%d\n", basename( parse_url( $src, PHP_URL_PATH ) ), $profile['desc'] ?: '(unnamed)', $tprof ? ( $tprof['desc'] ?: '(unnamed)' ) : '(none)', $tw, $th );
    printf( "  dE(CDN copy, intended colour) = %.1f   dE(CDN copy, stripped original) = %.1f   dE(intended, stripped) = %.1f\n", $mean_de( $A, $B ), $mean_de( $A, $C ), $mean_de( $B, $C ) );
    echo '  → ', $mean_de( $A, $B ) < $mean_de( $A, $C ) ? 'the CDN CONVERTED the pixels to sRGB (colour kept)' : 'the CDN only RE-TAGGED the file as sRGB (colour shifted)', "\n";
    imagedestroy( $o );
    imagedestroy( $t );
    imagedestroy( $d );
}
@unlink( $tmp_orig );
@unlink( $tmp_thumb );
