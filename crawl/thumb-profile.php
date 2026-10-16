<?php
/**
 * Post-pass for crawl CSVs written before the thumb_profile column existed: for every wide-gamut
 * row, fetch its thumbnail once more (a small file) and record whether the host's resized copy
 * kept the profile. Writes a CSV: store, src, class, thumb_url, thumb_profile, thumb_type.
 *
 *   php thumb-profile.php out.csv results-1.csv results-2.csv ...
 */
require __DIR__ . '/../lib/icc.php';
require __DIR__ . '/../lib/http.php';

array_shift( $argv );          // the script itself
$out   = array_shift( $argv ); // the CSV to write
$files = $argv;                // the crawl CSVs to read
if ( ! $out || ! $files ) {
    fwrite( STDERR, "usage: php thumb-profile.php out.csv results-1.csv [results-2.csv ...]\n" );
    exit( 1 );
}
$fh = fopen( $out, 'w' );
fputcsv( $fh, [ 'store', 'src', 'class', 'thumb_url', 'thumb_profile', 'thumb_type' ] );
$tmp = sys_get_temp_dir() . '/colour-thumb-' . getmypid();
$n   = 0;
foreach ( $files as $file ) {
    $rows = array_map( 'str_getcsv', file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) );
    $head = array_shift( $rows );
    $col  = array_flip( $head );
    foreach ( $rows as $r ) {
        if ( 'ok' !== $r[ $col['status'] ] || in_array( $r[ $col['class'] ], [ 'none', 'srgb' ], true ) ) {
            continue;
        }
        $src = $r[ $col['src'] ];
        // The same thumbnail rule as the crawler: Shopify "_300x"; for WordPress the thumbnail name
        // is not known here, so the two sizes every install has are tried, "-150x150" then "-300x300".
        $candidates = str_contains( $src, 'cdn.shopify' ) || str_contains( $src, 'shopifycdn' )
            ? [ preg_replace( '/(\.\w+)(\?.*)?$/', '_300x$1$2', $src, 1 ) ]
            : [ preg_replace( '/(\.\w+)$/', '-150x150$1', $src, 1 ), preg_replace( '/(\.\w+)$/', '-300x300$1', $src, 1 ) ];
        $thumb = '';
        $class = 'unavailable';
        $type  = '';
        foreach ( $candidates as $cand ) {
            $resp = http_get( $cand, null, 20, 'image/*' );
            if ( 200 === $resp['code'] && '' !== $resp['body'] ) {
                file_put_contents( $tmp, $resp['body'] );
                $thumb = $cand;
                $class = icc_classify( icc_parse( icc_from_file( $tmp ) ) );
                $type  = (string) ( $resp['headers']['content-type'] ?? '' );
                break;
            }
        }
        fputcsv( $fh, [ $r[ $col['store'] ], $src, $r[ $col['class'] ], $thumb, $class, $type ] );
        echo $r[ $col['store'] ], ' ', basename( parse_url( $src, PHP_URL_PATH ) ), ' ', $r[ $col['class'] ], ' → thumbnail ', $class, ' ', $type, "\n";
        $n++;
    }
}
fclose( $fh );
@unlink( $tmp );
echo "$n wide-gamut images checked\n";
