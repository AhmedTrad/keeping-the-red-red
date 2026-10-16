<?php
/**
 * Embedded colour profiles and the colour-loss measure, with no imaging library.
 *
 * Reading the profile (section 3.3 of the paper):
 *   icc_from_file( $path )  → the raw ICC bytes of a JPEG (APP2 "ICC_PROFILE" segments),
 *                             PNG (iCCP chunk) or WebP (ICCP chunk), or ''.
 *   icc_parse( $icc )       → [ desc, space, matrix, trc, srgb ] for a matrix/TRC profile,
 *                             or null when the bytes are not an ICC profile.
 *   icc_classify( $p )      → 'none' | 'srgb' | 'display-p3' | 'adobe-rgb' | 'prophoto' |
 *                             'other-matrix' | 'other-lut'.
 *
 * Measuring the shift (section 3.4):
 *   colour_sample_pixels( $path, 48 )     → up to 48×48 pixels as [ [r,g,b], … ].
 *   colour_shift( $pixels, $profile )     → mean ΔE76, 95th percentile, share of pixels past 2.3,
 *                                           between each pixel read through its profile and read as sRGB.
 *   colour_average( $pixels )             → "#rrggbb".
 *
 * Requires PHP 8.0+ with GD (for the pixel sample only); zlib for PNG profiles; iconv for v4 names.
 */

/* ─── Finding the profile ──────────────────────────────────────────────── */

/** The embedded ICC profile of a JPEG, PNG or WebP file, or ''. */
function icc_from_file( $path ) {
    $fh = @fopen( $path, 'rb' );
    if ( ! $fh ) {
        return '';
    }
    $head = (string) fread( $fh, 12 );
    $icc  = '';
    if ( str_starts_with( $head, "\xFF\xD8" ) ) {
        $icc = icc_jpeg( $fh );
    } elseif ( str_starts_with( $head, "\x89PNG" ) ) {
        $icc = icc_png( $fh );
    } elseif ( 'RIFF' === substr( $head, 0, 4 ) && 'WEBP' === substr( $head, 8, 4 ) ) {
        $icc = icc_webp( $fh );
    }
    fclose( $fh );
    return $icc;
}

// JPEG: APP2 segments tagged "ICC_PROFILE", numbered, before the image data.
function icc_jpeg( $fh ) {
    fseek( $fh, 2 );
    $chunks = [];
    while ( ! feof( $fh ) ) {
        $b = (string) fread( $fh, 2 );
        if ( strlen( $b ) < 2 || "\xFF" !== $b[0] ) {
            break;
        }
        $marker = ord( $b[1] );
        while ( 0xFF === $marker ) { // fill bytes
            $c = (string) fread( $fh, 1 );
            if ( '' === $c ) {
                break 2;
            }
            $marker = ord( $c );
        }
        if ( 0xDA === $marker || 0xD9 === $marker ) {
            break; // image data / end
        }
        if ( ( $marker >= 0xD0 && $marker <= 0xD7 ) || 0x01 === $marker ) {
            continue; // no length
        }
        $len = unpack( 'n', (string) fread( $fh, 2 ) )[1] ?? 0;
        if ( $len < 2 ) {
            break;
        }
        if ( 0xE2 === $marker ) {
            $payload = (string) fread( $fh, $len - 2 );
            if ( str_starts_with( $payload, "ICC_PROFILE\0" ) && strlen( $payload ) > 14 ) {
                $chunks[ ord( $payload[12] ) ] = substr( $payload, 14 );
            }
        } else {
            fseek( $fh, $len - 2, SEEK_CUR );
        }
    }
    ksort( $chunks );
    return implode( '', $chunks );
}

// PNG: the iCCP chunk, a name then a zlib stream.
function icc_png( $fh ) {
    fseek( $fh, 8 );
    while ( ! feof( $fh ) ) {
        $h = (string) fread( $fh, 8 );
        if ( strlen( $h ) < 8 ) {
            break;
        }
        $len  = unpack( 'N', substr( $h, 0, 4 ) )[1];
        $type = substr( $h, 4, 4 );
        if ( 'iCCP' === $type ) {
            $data = (string) fread( $fh, $len );
            $nul  = strpos( $data, "\0" );
            $icc  = false === $nul ? false : @gzuncompress( substr( $data, $nul + 2 ) );
            return $icc ?: '';
        }
        if ( 'IDAT' === $type || 'IEND' === $type ) {
            break;
        }
        fseek( $fh, $len + 4, SEEK_CUR );
    }
    return '';
}

// WebP: the ICCP chunk of the RIFF container.
function icc_webp( $fh ) {
    fseek( $fh, 12 );
    for ( $n = 0; $n < 32 && ! feof( $fh ); $n++ ) {
        $h = (string) fread( $fh, 8 );
        if ( strlen( $h ) < 8 ) {
            break;
        }
        $fourcc = substr( $h, 0, 4 );
        $len    = unpack( 'V', substr( $h, 4, 4 ) )[1];
        if ( 'ICCP' === $fourcc ) {
            return (string) fread( $fh, $len );
        }
        fseek( $fh, $len + ( $len % 2 ), SEEK_CUR );
    }
    return '';
}

/* ─── Reading the profile ──────────────────────────────────────────────── */

/**
 * The parts of a profile the shift needs: its name, whether it is sRGB, and
 * for a matrix/TRC RGB profile (every display profile) the RGB→XYZ matrix
 * and the three tone curves. Null for anything that isn't an ICC profile.
 */
function icc_parse( $icc ) {
    if ( strlen( $icc ) < 132 || 'acsp' !== substr( $icc, 36, 4 ) ) {
        return null;
    }
    $count = unpack( 'N', substr( $icc, 128, 4 ) )[1];
    if ( $count > 256 ) {
        return null;
    }
    $tags = [];
    for ( $i = 0; $i < $count; $i++ ) {
        $o = 132 + 12 * $i;
        if ( $o + 12 > strlen( $icc ) ) {
            break;
        }
        $u = unpack( 'Noff/Nsize', substr( $icc, $o + 4, 8 ) );
        if ( $u['off'] + $u['size'] <= strlen( $icc ) ) {
            $tags[ substr( $icc, $o, 4 ) ] = substr( $icc, $u['off'], $u['size'] );
        }
    }
    $out = [
        'desc'   => icc_text( $tags['desc'] ?? '' ),
        'space'  => trim( substr( $icc, 16, 4 ) ),
        'matrix' => null,
        'trc'    => null,
        'srgb'   => false,
    ];
    if ( 'RGB' === $out['space'] && isset( $tags['rXYZ'], $tags['gXYZ'], $tags['bXYZ'], $tags['rTRC'], $tags['gTRC'], $tags['bTRC'] ) ) {
        $r = icc_xyz( $tags['rXYZ'] );
        $g = icc_xyz( $tags['gXYZ'] );
        $b = icc_xyz( $tags['bXYZ'] );
        if ( $r && $g && $b ) {
            $out['matrix'] = [ [ $r[0], $g[0], $b[0] ], [ $r[1], $g[1], $b[1] ], [ $r[2], $g[2], $b[2] ] ];
            $out['trc']    = [ icc_curve( $tags['rTRC'] ), icc_curve( $tags['gTRC'] ), icc_curve( $tags['bTRC'] ) ];
            // sRGB's primaries, adapted to the profile connection space (D50), as every sRGB profile stores them.
            $srgb = colour_srgb_matrix();
            $same = true;
            for ( $i = 0; $i < 3 && $same; $i++ ) {
                for ( $j = 0; $j < 3; $j++ ) {
                    if ( abs( $out['matrix'][ $i ][ $j ] - $srgb[ $i ][ $j ] ) > 0.02 ) {
                        $same = false;
                        break;
                    }
                }
            }
            $out['srgb'] = $same;
        }
    }
    // Without primaries to compare (a LUT profile) the name is all there is.
    // With them, the name is not trusted: "Display P3 Gamut with sRGB
    // Transfer" is a P3 profile that merely borrows sRGB's tone curve.
    if ( ! $out['matrix'] && false !== stripos( $out['desc'], 'sRGB' ) ) {
        $out['srgb'] = true;
    }
    return $out;
}

/** A short class for a parsed profile (or null), as used in the CSVs. */
function icc_classify( $profile ) {
    if ( ! $profile ) {
        return 'none';
    }
    if ( $profile['srgb'] ) {
        return 'srgb';
    }
    $d = strtolower( $profile['desc'] );
    if ( str_contains( $d, 'p3' ) ) {
        return 'display-p3';
    }
    if ( str_contains( $d, 'adobe' ) ) {
        return 'adobe-rgb';
    }
    if ( str_contains( $d, 'prophoto' ) ) {
        return 'prophoto';
    }
    return $profile['matrix'] ? 'other-matrix' : 'other-lut';
}

function icc_xyz( $tag ) {
    if ( 'XYZ ' !== substr( $tag, 0, 4 ) || strlen( $tag ) < 20 ) {
        return null;
    }
    $v = [];
    for ( $i = 0; $i < 3; $i++ ) {
        $v[] = icc_s15f16( substr( $tag, 8 + 4 * $i, 4 ) );
    }
    return $v;
}

function icc_s15f16( $bytes ) {
    $n = unpack( 'N', $bytes )[1];
    if ( $n >= 0x80000000 ) {
        $n -= 0x100000000;
    }
    return $n / 65536;
}

function icc_text( $tag ) {
    $type = substr( $tag, 0, 4 );
    if ( 'desc' === $type && strlen( $tag ) >= 12 ) { // v2: ASCII, count includes the NUL
        $len = unpack( 'N', substr( $tag, 8, 4 ) )[1];
        return trim( substr( $tag, 12, max( 0, $len - 1 ) ) );
    }
    if ( 'mluc' === $type && strlen( $tag ) >= 28 ) { // v4: UTF-16BE records, the first one
        $len = unpack( 'N', substr( $tag, 20, 4 ) )[1];
        $off = unpack( 'N', substr( $tag, 24, 4 ) )[1];
        $utf = @iconv( 'UTF-16BE', 'UTF-8//IGNORE', substr( $tag, $off, $len ) );
        return trim( (string) $utf );
    }
    if ( 'text' === $type ) {
        return trim( substr( $tag, 8 ) );
    }
    return '';
}

/** A tone curve: [ 'gamma', g ] | [ 'table', [ 0..65535, … ] ] | [ 'para', type, [ params ] ]. */
function icc_curve( $tag ) {
    $type = substr( $tag, 0, 4 );
    if ( 'curv' === $type && strlen( $tag ) >= 12 ) {
        $n = unpack( 'N', substr( $tag, 8, 4 ) )[1];
        if ( 0 === $n ) {
            return [ 'gamma', 1.0 ];
        }
        if ( 1 === $n ) {
            return [ 'gamma', unpack( 'n', substr( $tag, 12, 2 ) )[1] / 256 ];
        }
        return [ 'table', array_values( unpack( 'n*', substr( $tag, 12, 2 * $n ) ) ) ];
    }
    if ( 'para' === $type && strlen( $tag ) >= 16 ) {
        $kind   = unpack( 'n', substr( $tag, 8, 2 ) )[1];
        $counts = [ 1, 3, 4, 5, 7 ];
        $p      = [];
        for ( $i = 0; $i < ( $counts[ $kind ] ?? 0 ); $i++ ) {
            $p[] = icc_s15f16( substr( $tag, 12 + 4 * $i, 4 ) );
        }
        return [ 'para', $kind, $p ];
    }
    return [ 'gamma', 2.2 ];
}

/** Encoded value 0–1 → linear light 0–1 through a tone curve. */
function icc_curve_apply( $curve, $x ) {
    switch ( $curve[0] ) {
        case 'gamma':
            return $x ** $curve[1];
        case 'table':
            $pts = $curve[1];
            $n   = count( $pts ) - 1;
            $pos = $x * $n;
            $i   = (int) floor( $pos );
            if ( $i >= $n ) {
                return $pts[ $n ] / 65535;
            }
            $f = $pos - $i;
            return ( $pts[ $i ] * ( 1 - $f ) + $pts[ $i + 1 ] * $f ) / 65535;
        case 'para':
            $p = $curve[2] + [ 0, 1, 0, 0, 0, 0, 0 ];
            [ $g, $a, $b, $c, $d, $e, $f ] = $p;
            switch ( $curve[1] ) {
                case 0:
                    return $x ** $g;
                case 1:
                    return max( 0, $a * $x + $b ) ** $g;
                case 2:
                    return max( 0, $a * $x + $b ) ** $g + $c;
                case 3:
                    return $x >= $d ? max( 0, $a * $x + $b ) ** $g : $c * $x;
                case 4:
                    return $x >= $d ? max( 0, $a * $x + $b ) ** $g + $e : $c * $x + $f;
            }
    }
    return $x ** 2.2;
}

/* ─── Colour maths ─────────────────────────────────────────────────────── */

/** sRGB → XYZ (D50), the matrix every sRGB profile carries. Rows X, Y, Z. */
function colour_srgb_matrix() {
    return [
        [ 0.4360747, 0.3850649, 0.1430804 ],
        [ 0.2225045, 0.7168786, 0.0606169 ],
        [ 0.0139322, 0.0971045, 0.7141733 ],
    ];
}

/** The sRGB electro-optical transfer function: encoded 0–1 → linear 0–1. */
function colour_srgb_linear( $c ) {
    return $c <= 0.04045 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
}

function colour_xyz_to_lab( $xyz ) {
    $white = [ 0.96422, 1.0, 0.82521 ]; // D50
    $f     = [];
    foreach ( $xyz as $i => $v ) {
        $t   = $v / $white[ $i ];
        $f[] = $t > 0.008856 ? $t ** ( 1 / 3 ) : 7.787 * $t + 16 / 116;
    }
    return [ 116 * $f[1] - 16, 500 * ( $f[0] - $f[1] ), 200 * ( $f[1] - $f[2] ) ];
}

function colour_matrix_apply( $m, $v ) {
    return [
        $m[0][0] * $v[0] + $m[0][1] * $v[1] + $m[0][2] * $v[2],
        $m[1][0] * $v[0] + $m[1][1] * $v[1] + $m[1][2] * $v[2],
        $m[2][0] * $v[0] + $m[2][1] * $v[1] + $m[2][2] * $v[2],
    ];
}

/** One pixel [r,g,b] 0–255 → CIELAB (D50), read through a parsed profile, or as sRGB when $profile is null. */
function colour_pixel_lab( $p, $profile = null ) {
    $lin = [];
    foreach ( $p as $i => $v ) {
        $c     = $v / 255;
        $lin[] = $profile ? icc_curve_apply( $profile['trc'][ $i ], $c ) : colour_srgb_linear( $c );
    }
    return colour_xyz_to_lab( colour_matrix_apply( $profile ? $profile['matrix'] : colour_srgb_matrix(), $lin ) );
}

/**
 * How far the sampled pixels move when the profile is ignored: ΔE (CIE76,
 * in Lab) between each pixel read through its own profile and read as
 * sRGB. Returns the mean, the 95th percentile and the share of pixels past
 * 2.3, the usual "just noticeable" distance.
 */
function colour_shift( $pixels, $profile ) {
    $des  = [];
    $over = 0;
    foreach ( $pixels as $p ) {
        $lab_true = colour_pixel_lab( $p, $profile );
        $lab_seen = colour_pixel_lab( $p, null );
        $de       = sqrt( ( $lab_true[0] - $lab_seen[0] ) ** 2 + ( $lab_true[1] - $lab_seen[1] ) ** 2 + ( $lab_true[2] - $lab_seen[2] ) ** 2 );
        $des[]    = $de;
        if ( $de > 2.3 ) {
            $over++;
        }
    }
    sort( $des );
    $n = count( $des );
    return [
        'mean' => round( array_sum( $des ) / $n, 1 ),
        'p95'  => round( $des[ min( $n - 1, (int) floor( $n * 0.95 ) ) ], 1 ),
        'over' => (int) round( 100 * $over / $n ),
    ];
}

/* ─── Pixels ───────────────────────────────────────────────────────────── */

/** Up to $max×$max pixels of the image as [ [r,g,b], … ] (0–255), transparency over white. Needs GD. */
function colour_sample_pixels( $path, $max ) {
    $info = @getimagesize( $path );
    if ( ! $info ) {
        return [];
    }
    $loaders = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png'  => 'imagecreatefrompng',
        'image/gif'  => 'imagecreatefromgif',
        'image/webp' => 'imagecreatefromwebp',
        'image/avif' => 'imagecreatefromavif',
    ];
    $loader = $loaders[ $info['mime'] ] ?? '';
    if ( ! $loader || ! function_exists( $loader ) ) {
        return [];
    }
    $image = @$loader( $path );
    if ( ! $image ) {
        return [];
    }
    $w     = imagesx( $image );
    $h     = imagesy( $image );
    $scale = min( 1, $max / max( $w, $h ) );
    $sw    = max( 1, (int) round( $w * $scale ) );
    $sh    = max( 1, (int) round( $h * $scale ) );
    $small = imagecreatetruecolor( $sw, $sh );
    imagealphablending( $small, true );
    imagefilledrectangle( $small, 0, 0, $sw, $sh, imagecolorallocate( $small, 255, 255, 255 ) );
    imagecopyresampled( $small, $image, 0, 0, 0, 0, $sw, $sh, $w, $h );
    imagedestroy( $image );
    $pixels = [];
    for ( $y = 0; $y < $sh; $y++ ) {
        for ( $x = 0; $x < $sw; $x++ ) {
            $c        = imagecolorat( $small, $x, $y );
            $pixels[] = [ ( $c >> 16 ) & 255, ( $c >> 8 ) & 255, $c & 255 ];
        }
    }
    imagedestroy( $small );
    return $pixels;
}

function colour_average( $pixels ) {
    $n = count( $pixels );
    $r = $g = $b = 0;
    foreach ( $pixels as $p ) {
        $r += $p[0];
        $g += $p[1];
        $b += $p[2];
    }
    return sprintf( '#%02x%02x%02x', (int) round( $r / $n ), (int) round( $g / $n ), (int) round( $b / $n ) );
}
