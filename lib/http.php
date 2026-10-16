<?php
/**
 * One polite GET for the crawl scripts: a User-Agent that names the study and a contact
 * address, an optional Range (only the header bytes of an image), three redirects at most,
 * a timeout, and a pause between requests. Needs the curl extension.
 *
 *   http_get( $url, $range = null, $timeout = 20, $accept = '*\/*' )
 *     → [ 'code' => int, 'body' => string, 'headers' => [ lowercase name => value ], 'error' => string ]
 *   http_set_delay( 1.0 )   seconds between two requests (default 1).
 *
 * TLS: PHP's curl needs a CA bundle. If php.ini sets none (curl.cainfo / openssl.cafile),
 * the STUDY_CA_BUNDLE environment variable can name one; on Linux the system store is used.
 */

const STUDY_UA = 'ColourProfileStudy/0.2 (academic measurement of embedded colour profiles in product images; contact tradahmed1995@gmail.com to opt out)';

function http_set_delay( $seconds ) {
    $GLOBALS['http_delay'] = (float) $seconds;
}

function http_get( $url, $range = null, $timeout = 20, $accept = '*/*' ) {
    static $last = 0;
    if ( ! function_exists( 'curl_init' ) ) {
        throw new RuntimeException( 'the curl extension is required' );
    }
    $delay = $GLOBALS['http_delay'] ?? 1.0;
    $wait  = $last + $delay - microtime( true );
    if ( $wait > 0 ) {
        usleep( (int) ( $wait * 1e6 ) );
    }
    $last = microtime( true );

    $headers = [ 'Accept: ' . $accept ];
    if ( $range ) {
        $headers[] = 'Range: bytes=0-' . ( $range - 1 );
    }
    $resp_headers = [];
    $ch           = curl_init( $url );
    $opts         = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => STUDY_UA,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_HEADERFUNCTION => function ( $ch, $line ) use ( &$resp_headers ) {
            if ( preg_match( '#^HTTP/#', $line ) ) {
                $resp_headers = []; // a redirect's headers give way to the final response's
            } elseif ( str_contains( $line, ':' ) ) {
                [ $k, $v ]                             = explode( ':', $line, 2 );
                $resp_headers[ strtolower( trim( $k ) ) ] = trim( $v );
            }
            return strlen( $line );
        },
    ];
    $ca = getenv( 'STUDY_CA_BUNDLE' );
    if ( $ca && is_file( $ca ) ) {
        $opts[ CURLOPT_CAINFO ] = $ca;
    }
    curl_setopt_array( $ch, $opts );
    $body = curl_exec( $ch );
    $code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
    $err  = curl_error( $ch );
    curl_close( $ch );
    if ( false === $body ) {
        return [ 'code' => 0, 'body' => '', 'headers' => [], 'error' => $err ];
    }
    return [ 'code' => $code, 'body' => (string) $body, 'headers' => $resp_headers, 'error' => '' ];
}
