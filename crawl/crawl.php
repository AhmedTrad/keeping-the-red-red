<?php
/**
 * Colour-profile measurement over live stores, through what they publish.
 *
 *   php crawl.php --seeds=seeds.txt --out=results.csv [--pages=urls.txt] [--max-products=50]
 *                 [--max-images=100] [--delay=1.0]
 *
 * Stores (--seeds, one URL per line, # for comments): for each,
 *   1. reads /robots.txt and skips the store if its API paths are disallowed;
 *   2. lists products through the WooCommerce Store API (/wp-json/wc/store/v1/products, public),
 *      failing that Shopify's public catalogue (/products.json), failing that the WordPress product
 *      sitemap (core's, Yoast's or Rank Math's), whose product pages are then read as pages;
 *   3. for each product image, fetches the first 128 KB of the full-size file (a Range request:
 *      the ICC profile sits in the JPEG/PNG/WebP header) and parses the embedded profile
 *      (../lib/icc.php, the method of section 3.3 of the paper);
 *   4. for an image whose profile is not sRGB, downloads a small thumbnail, records whether the
 *      host's resized copy kept the profile, and measures the colour shift (ΔE76 between the
 *      pixels read through the profile and read as sRGB);
 *   5. appends one CSV row per image, so a run can be stopped and resumed (stores and pages already
 *      in the CSV are skipped), and prints a summary at the end.
 *
 * Pages (--pages, one product-page URL per line): any platform. The page's images are collected from
 * its Open Graph tag, its JSON-LD product data and, only when it declares neither, its <img> tags.
 *
 * Politeness: one request at a time, a pause between requests (--delay, default 1 s), a named
 * User-Agent with a contact address, robots.txt honoured, at most --max-images files per store,
 * only the header bytes of each full-size file. No personal data is read: product images only.
 *
 * What it can and cannot see: a store hands out the files it serves. A host that dropped the
 * profile when it resized (GD does) serves an untagged file, so the profile is invisible there; a
 * "-scaled" name went through the host's editor, a WebP/AVIF full size is a conversion, and an
 * image CDN (Shopify's, Cloudinary, imgix) re-encodes what it serves. The CSV classes every file
 * and the summary reports prevalence separately for files that are the upload itself.
 *
 * Needs PHP 8.0+ with curl and GD. No WordPress, no imaging library.
 */

require __DIR__ . '/../lib/icc.php';
require __DIR__ . '/../lib/http.php';

$opts = getopt( '', [ 'seeds::', 'pages::', 'out:', 'max-products::', 'max-images::', 'delay::' ] );
if ( empty( $opts['out'] ) || ( empty( $opts['seeds'] ) && empty( $opts['pages'] ) ) ) {
    fwrite( STDERR, "usage: php crawl.php --out=results.csv (--seeds=seeds.txt | --pages=urls.txt) [--max-products=50] [--max-images=100] [--delay=1.0]\n" );
    exit( 1 );
}
$max_products = (int) ( $opts['max-products'] ?? 50 );
$max_images   = (int) ( $opts['max-images'] ?? 100 );
http_set_delay( (float) ( $opts['delay'] ?? 1.0 ) );

/** True when robots.txt forbids one of the given paths for everyone or for us. */
function robots_forbid( $base, $paths ) {
    $r = http_get( $base . '/robots.txt', null, 10 );
    if ( 200 !== $r['code'] ) {
        return false;
    }
    $applies = false;
    foreach ( preg_split( '/\r?\n/', $r['body'] ) as $line ) {
        $line = trim( preg_replace( '/#.*/', '', $line ) );
        if ( '' === $line ) {
            continue;
        }
        if ( preg_match( '/^user-agent:\s*(.+)$/i', $line, $m ) ) {
            $ua      = trim( $m[1] );
            $applies = '*' === $ua || false !== stripos( STUDY_UA, $ua );
        } elseif ( $applies && preg_match( '/^disallow:\s*(\S*)/i', $line, $m ) ) {
            foreach ( $paths as $path ) {
                if ( '' !== $m[1] && str_starts_with( $path, $m[1] ) ) {
                    return true;
                }
            }
        }
    }
    return false;
}

/** What the served file is: the upload itself, or something the host or a CDN made from it. */
function served_file_class( $url ) {
    $host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
    if ( preg_match( '/cdn\.shopify\.com|shopifycdn|cloudinary\.com|imgix\.net|cloudfront\.net|optimole|wp\.com\/|i\d\.wp\.com/', $host . '/' ) ) {
        return 'cdn-processed';
    }
    if ( preg_match( '/-scaled\.\w+(\?.*)?$/', $url ) ) {
        return 'scaled-by-host';
    }
    if ( preg_match( '/\.(webp|avif)(\?.*)?$/i', $url ) ) {
        return 'converted-by-host';
    }
    return 'upload';
}

/** WooCommerce Store API → images, or null when the store has none. */
function images_woocommerce( $base, $max_products, $max_images ) {
    $probe = http_get( $base . '/wp-json/wc/store/v1/products?per_page=1', null, 20, 'application/json' );
    $json  = json_decode( $probe['body'], true );
    if ( 200 !== $probe['code'] || ! is_array( $json ) ) {
        return null;
    }
    $images = [];
    $seen   = [];
    for ( $page = 1; count( $images ) < $max_images; $page++ ) {
        $per  = min( 100, $max_products );
        $r    = http_get( $base . "/wp-json/wc/store/v1/products?per_page=$per&page=$page", null, 20, 'application/json' );
        $list = json_decode( $r['body'], true );
        if ( 200 !== $r['code'] || ! is_array( $list ) || ! $list ) {
            break;
        }
        foreach ( $list as $product ) {
            foreach ( (array) ( $product['images'] ?? [] ) as $img ) {
                $key = $img['id'] ?? $img['src'] ?? '';
                if ( ! $key || isset( $seen[ $key ] ) || empty( $img['src'] ) ) {
                    continue;
                }
                $seen[ $key ] = true;
                $images[]     = [ 'product' => (string) ( $product['id'] ?? '' ), 'id' => (string) ( $img['id'] ?? '' ), 'src' => $img['src'], 'thumb' => $img['thumbnail'] ?? '' ];
                if ( count( $images ) >= $max_images ) {
                    break 2;
                }
            }
        }
        if ( count( $list ) < $per || $page * $per >= $max_products ) {
            break;
        }
    }
    return $images;
}

/** Shopify's public catalogue → images, or null when the store is not Shopify. */
function images_shopify( $base, $max_products, $max_images ) {
    $r    = http_get( $base . '/products.json?limit=' . min( 250, $max_products ), null, 20, 'application/json' );
    $json = json_decode( $r['body'], true );
    if ( 200 !== $r['code'] || ! isset( $json['products'] ) || ! is_array( $json['products'] ) ) {
        return null;
    }
    $images = [];
    foreach ( $json['products'] as $product ) {
        foreach ( (array) ( $product['images'] ?? [] ) as $img ) {
            if ( empty( $img['src'] ) ) {
                continue;
            }
            // Shopify's CDN serves a width-limited copy when "_300x" is inserted before the extension.
            $thumb    = preg_replace( '/(\.\w+)(\?.*)?$/', '_300x$1$2', $img['src'], 1 );
            $images[] = [ 'product' => (string) ( $product['id'] ?? '' ), 'id' => (string) ( $img['id'] ?? '' ), 'src' => $img['src'], 'thumb' => $thumb ];
            if ( count( $images ) >= $max_images ) {
                break 2;
            }
        }
    }
    return $images;
}

/**
 * A WordPress store whose Store API is off → product pages from its product sitemap
 * (core's /wp-sitemap-posts-product-1.xml, or /product-sitemap.xml from Yoast SEO and Rank Math),
 * read as pages. Null when there is no such sitemap.
 */
function images_wordpress_sitemap( $base, $max_products, $max_images ) {
    $m = null;
    foreach ( [ '/wp-sitemap-posts-product-1.xml', '/product-sitemap.xml' ] as $path ) {
        $r = http_get( $base . $path, null, 20, 'application/xml,text/xml,*/*' );
        if ( 200 === $r['code'] && preg_match_all( '#<loc>\s*([^<\s]+)\s*</loc>#i', $r['body'], $m ) ) {
            break;
        }
        $m = null;
    }
    if ( ! $m ) {
        return null;
    }
    $images = [];
    foreach ( array_slice( $m[1], 0, min( 20, $max_products ) ) as $url ) {
        foreach ( (array) images_from_page( html_entity_decode( $url ), 6 ) as $img ) {
            $images[] = $img;
            if ( count( $images ) >= $max_images ) {
                break 2;
            }
        }
    }
    return $images;
}

/** A product page of any platform → its images from Open Graph, JSON-LD and <img> tags. */
function images_from_page( $url, $max_images ) {
    $r = http_get( $url, null, 20, 'text/html,*/*' );
    if ( 200 !== $r['code'] || '' === $r['body'] ) {
        return null;
    }
    $html  = $r['body'];
    $found = [];
    if ( preg_match_all( '/<meta[^>]+property=["\']og:image(?::secure_url)?["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m ) ) {
        $found = array_merge( $found, $m[1] );
    }
    if ( preg_match_all( '/<script[^>]+application\/ld\+json[^>]*>(.*?)<\/script>/is', $html, $m ) ) {
        foreach ( $m[1] as $block ) {
            if ( preg_match_all( '/"image"\s*:\s*(\[[^\]]*\]|"[^"]+")/', $block, $mm ) ) {
                foreach ( $mm[1] as $v ) {
                    $vals = json_decode( $v, true );
                    foreach ( (array) $vals as $u ) {
                        if ( is_string( $u ) ) {
                            $found[] = $u;
                        } elseif ( is_array( $u ) && ! empty( $u['url'] ) ) {
                            $found[] = $u['url'];
                        }
                    }
                }
            }
        }
    }
    // Open Graph and JSON-LD name the product's own images; <img> tags also carry the page's
    // related products, cards and icons, so they are read only when the page declares nothing.
    if ( ! $found && preg_match_all( '/<img[^>]+(?:data-src|src)=["\']([^"\']+)["\']/i', $html, $m ) ) {
        $found = $m[1];
    }
    $images = [];
    $seen   = [];
    foreach ( $found as $src ) {
        $src = html_entity_decode( $src );
        if ( str_starts_with( $src, 'data:' ) || preg_match( '/\.svg(\?|$)|logo|icon|sprite|flag|pixel|spinner|loading/i', $src ) ) {
            continue;
        }
        if ( str_starts_with( $src, '//' ) ) {
            $src = 'https:' . $src;
        } elseif ( str_starts_with( $src, '/' ) ) {
            $p   = parse_url( $url );
            $src = $p['scheme'] . '://' . $p['host'] . $src;
        } elseif ( ! preg_match( '#^https?://#', $src ) ) {
            continue;
        }
        if ( isset( $seen[ $src ] ) ) {
            continue;
        }
        $seen[ $src ] = true;
        $images[]     = [ 'product' => $url, 'id' => '', 'src' => $src, 'thumb' => $src ];
        if ( count( $images ) >= $max_images ) {
            break;
        }
    }
    return $images;
}

/** Fetch the header bytes, parse the profile, measure the shift from the thumbnail when needed; one CSV row. */
function measure( $img, $write, $tmp ) {
    $head = http_get( $img['src'], 131072, 20, 'image/jpeg,image/png,image/webp,image/*' );
    if ( ! in_array( $head['code'], [ 200, 206 ], true ) || '' === $head['body'] ) {
        $write( 'image-error', [ 'product_id' => $img['product'], 'image_id' => $img['id'], 'src' => $img['src'] ], $head['error'] ?: 'HTTP ' . $head['code'] );
        return false;
    }
    file_put_contents( $tmp, $head['body'] );
    $profile = icc_parse( icc_from_file( $tmp ) );
    $size    = @getimagesizefromstring( $head['body'] );
    $type    = (string) ( $head['headers']['content-type'] ?? ( $size['mime'] ?? '' ) );
    $length  = $head['headers']['content-range'] ?? '';
    $bytes   = preg_match( '#/(\d+)$#', (string) $length, $m ) ? (int) $m[1] : (int) ( $head['headers']['content-length'] ?? strlen( $head['body'] ) );
    $fields  = [
        'product_id'   => $img['product'],
        'image_id'     => $img['id'],
        'src'          => $img['src'],
        'served_file'  => served_file_class( $img['src'] ),
        'content_type' => $type,
        'bytes'        => $bytes,
        'width'        => $size[0] ?? '',
        'height'       => $size[1] ?? '',
        'profile'      => $profile ? $profile['desc'] : '',
        'class'        => icc_classify( $profile ),
    ];
    if ( $profile && ! $profile['srgb'] && $profile['matrix'] && $img['thumb'] ) {
        $th = http_get( $img['thumb'], null, 20, 'image/jpeg,image/png,image/webp,image/*' );
        if ( 200 === $th['code'] && '' !== $th['body'] ) {
            file_put_contents( $tmp, $th['body'] );
            // Did the host's resizer keep the profile? A wide-gamut upload whose thumbnail carries
            // none is a store whose resized images lose their colour (GD does that; Imagick does not).
            $fields['thumb_profile'] = icc_classify( icc_parse( icc_from_file( $tmp ) ) );
            $pixels                  = colour_sample_pixels( $tmp, 48 );
            if ( $pixels ) {
                $shift                 = colour_shift( $pixels, $profile );
                $fields['de_mean']     = $shift['mean'];
                $fields['de_p95']      = $shift['p95'];
                $fields['de_over_2_3'] = $shift['over'];
                $fields['thumb_bytes'] = strlen( $th['body'] );
            }
        }
    }
    $write( 'ok', $fields );
    echo '  ', $fields['class'], ' ', $fields['served_file'], ' ', $fields['width'], 'x', $fields['height'], ' ', basename( (string) parse_url( $img['src'], PHP_URL_PATH ) ), isset( $fields['de_mean'] ) ? " ΔE {$fields['de_mean']} / {$fields['de_p95']} / {$fields['de_over_2_3']}%" : '', "\n";
    return true;
}

$columns = [ 'store', 'status', 'product_id', 'image_id', 'src', 'served_file', 'content_type', 'bytes', 'width', 'height', 'profile', 'class', 'de_mean', 'de_p95', 'de_over_2_3', 'thumb_bytes', 'thumb_profile', 'note' ];
$done    = [];
if ( file_exists( $opts['out'] ) ) {
    foreach ( array_map( 'str_getcsv', file( $opts['out'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ) as $row ) {
        if ( isset( $row[0] ) && 'store' !== $row[0] ) {
            $done[ $row[0] ] = true;
        }
    }
    $out = fopen( $opts['out'], 'a' );
} else {
    $out = fopen( $opts['out'], 'w' );
    fputcsv( $out, $columns );
}
$tmp = sys_get_temp_dir() . '/colour-crawl-' . getmypid();

$read_list = function ( $file ) {
    $list = [];
    foreach ( file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
        $line = trim( preg_replace( '/#.*/', '', $line ) );
        if ( '' !== $line ) {
            $list[] = rtrim( preg_match( '#^https?://#', $line ) ? $line : 'https://' . $line, '/' );
        }
    }
    return $list;
};
$writer = function ( $subject ) use ( $out, $columns ) {
    return function ( $status, $fields = [], $note = '' ) use ( $out, $columns, $subject ) {
        $row           = array_fill_keys( $columns, '' );
        $row['store']  = $subject;
        $row['status'] = $status;
        $row['note']   = $note;
        foreach ( $fields as $k => $v ) {
            $row[ $k ] = $v;
        }
        fputcsv( $out, array_values( $row ) );
        fflush( $out );
    };
};

foreach ( empty( $opts['seeds'] ) ? [] : $read_list( $opts['seeds'] ) as $base ) {
    if ( isset( $done[ $base ] ) ) {
        echo "skip (already in the CSV): $base\n";
        continue;
    }
    echo "\n$base\n";
    $write = $writer( $base );
    if ( robots_forbid( $base, [ '/wp-json/wc/store/v1/products', '/products.json' ] ) ) {
        echo "  robots.txt disallows the catalogue: skipped\n";
        $write( 'robots' );
        continue;
    }
    $images = images_woocommerce( $base, $max_products, $max_images );
    $source = 'woocommerce';
    if ( null === $images ) {
        $images = images_shopify( $base, $max_products, $max_images );
        $source = 'shopify';
    }
    if ( null === $images ) {
        $images = images_wordpress_sitemap( $base, $max_products, $max_images );
        $source = 'wordpress-sitemap';
    }
    if ( null === $images ) {
        echo "  no WooCommerce Store API, Shopify catalogue or WordPress product sitemap: skipped (use --pages for this store)\n";
        $write( 'no-catalogue' );
        continue;
    }
    echo '  ', count( $images ), " images from the $source catalogue\n";
    if ( ! $images ) {
        $write( 'no-images' );
        continue;
    }
    $errors = 0;
    foreach ( $images as $img ) {
        if ( ! measure( $img, $write, $tmp ) && ++$errors >= 3 ) {
            echo "  three image errors: giving up on this store\n";
            break;
        }
    }
}

foreach ( empty( $opts['pages'] ) ? [] : $read_list( $opts['pages'] ) as $url ) {
    if ( isset( $done[ $url ] ) ) {
        echo "skip (already in the CSV): $url\n";
        continue;
    }
    echo "\n$url\n";
    $write  = $writer( $url );
    $images = images_from_page( $url, min( $max_images, 20 ) );
    if ( null === $images ) {
        echo "  page not readable: skipped\n";
        $write( 'page-error' );
        continue;
    }
    echo '  ', count( $images ), " images on the page\n";
    if ( ! $images ) {
        $write( 'no-images' );
        continue;
    }
    foreach ( $images as $img ) {
        measure( $img, $write, $tmp );
    }
}
fclose( $out );
@unlink( $tmp );

// Summary over the whole CSV (this run and earlier ones).
$rows = array_map( 'str_getcsv', file( $opts['out'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) );
array_shift( $rows );
$ok      = array_filter( $rows, fn( $r ) => 'ok' === $r[1] );
$stores  = array_unique( array_column( $rows, 0 ) );
$api     = array_unique( array_column( array_filter( $rows, fn( $r ) => in_array( $r[1], [ 'ok', 'no-images', 'image-error' ], true ) ), 0 ) );
$classes = array_count_values( array_column( $ok, 11 ) );
$served  = array_count_values( array_column( $ok, 5 ) );
$uploads = array_filter( $ok, fn( $r ) => 'upload' === $r[5] );
$cls_up  = array_count_values( array_column( $uploads, 11 ) );
$des     = array_filter( array_map( fn( $r ) => '' === $r[12] ? null : (float) $r[12], $ok ), fn( $v ) => null !== $v );
sort( $des );
$small = array_filter( $ok, fn( $r ) => '' !== $r[8] && (int) $r[8] < 900 );
printf( "\nSummary: %d stores or pages in the CSV, %d yielded images, %d images measured.\n", count( $stores ), count( $api ), count( $ok ) );
foreach ( $served as $s => $n ) {
    printf( "  served as %-18s %5d  (%4.1f%%)\n", $s, $n, 100 * $n / max( 1, count( $ok ) ) );
}
foreach ( $classes as $c => $n ) {
    printf( "  profile %-13s %5d  (%4.1f%%)   among files that are the upload itself: %d of %d\n", $c, $n, 100 * $n / max( 1, count( $ok ) ), $cls_up[ $c ] ?? 0, count( $uploads ) );
}
if ( $des ) {
    $q = fn( $p ) => $des[ min( count( $des ) - 1, (int) floor( $p * count( $des ) ) ) ];
    printf( "  mean ΔE of non-sRGB images: median %.1f, 90th percentile %.1f, max %.1f (n=%d)\n", $q( 0.5 ), $q( 0.9 ), end( $des ), count( $des ) );
}
printf( "  images narrower than 900 px (shown enlarged in a 900 px box): %d of %d (%.1f%%)\n", count( $small ), count( $ok ), 100 * count( $small ) / max( 1, count( $ok ) ) );
