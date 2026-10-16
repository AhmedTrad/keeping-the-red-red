<?php
/**
 * A synthetic Display P3 profile for validating the parser and the shift measure (section 4.3).
 *
 *   php make-p3-profile.php out.icc                 writes the profile
 *   php make-p3-profile.php out.icc in.jpg out.jpg  also writes a copy of in.jpg with the profile embedded
 *
 * The profile is a minimal ICC v2 matrix/TRC profile: Display P3 primaries adapted to D50 and the
 * sRGB tone curve as a 'para' type 3 curve, so the measured shift against sRGB comes from the
 * primaries alone. It is named "Display P3".
 */

/** A minimal ICC v2 matrix/TRC profile with Display P3 primaries (D50-adapted) and the sRGB curve as 'para' type 3. */
function make_p3_profile() {
    $s15       = fn( $f ) => pack( 'N', ( (int) round( $f * 65536 ) ) & 0xFFFFFFFF );
    $xyz       = fn( $x, $y, $z ) => 'XYZ ' . "\0\0\0\0" . $s15( $x ) . $s15( $y ) . $s15( $z );
    $para      = 'para' . "\0\0\0\0" . pack( 'n', 3 ) . "\0\0" . $s15( 2.4 ) . $s15( 1 / 1.055 ) . $s15( 0.055 / 1.055 ) . $s15( 1 / 12.92 ) . $s15( 0.04045 );
    $desc_text = "Display P3\0";
    $desc      = 'desc' . "\0\0\0\0" . pack( 'N', strlen( $desc_text ) ) . $desc_text . str_repeat( "\0", 79 - strlen( $desc_text ) );
    $tags      = [
        'desc' => $desc,
        'wtpt' => $xyz( 0.9642, 1.0, 0.8249 ),
        'rXYZ' => $xyz( 0.5151, 0.2412, -0.0011 ),
        'gXYZ' => $xyz( 0.2920, 0.6922, 0.0419 ),
        'bXYZ' => $xyz( 0.1571, 0.0666, 0.7841 ),
        'rTRC' => $para,
        'gTRC' => $para,
        'bTRC' => $para,
    ];
    $table  = '';
    $data   = '';
    $offset = 128 + 4 + 12 * count( $tags );
    foreach ( $tags as $sig => $bytes ) {
        $pad    = ( 4 - strlen( $bytes ) % 4 ) % 4;
        $table .= $sig . pack( 'N', $offset + strlen( $data ) ) . pack( 'N', strlen( $bytes ) );
        $data  .= $bytes . str_repeat( "\0", $pad );
    }
    $size = $offset + strlen( $data );
    $h    = pack( 'N', $size );
    $h   .= 'test';                       // preferred CMM
    $h   .= pack( 'N', 0x02100000 );      // version 2.1
    $h   .= 'mntr';                       // display device
    $h   .= 'RGB ';
    $h   .= 'XYZ ';
    $h   .= str_repeat( "\0", 12 );       // creation date
    $h   .= 'acsp';
    $h   .= str_repeat( "\0", 4 );        // platform
    $h   .= str_repeat( "\0", 4 );        // flags
    $h   .= str_repeat( "\0", 4 );        // manufacturer
    $h   .= str_repeat( "\0", 4 );        // model
    $h   .= str_repeat( "\0", 8 );        // attributes
    $h   .= str_repeat( "\0", 4 );        // rendering intent
    $h   .= $s15( 0.9642 ) . $s15( 1.0 ) . $s15( 0.8249 ); // illuminant D50
    $h   .= str_repeat( "\0", 4 );        // creator
    $h   .= str_repeat( "\0", 44 );       // id + reserved
    return $h . pack( 'N', count( $tags ) ) . $table . $data;
}

/** A copy of $jpeg with $icc embedded as APP2 ICC_PROFILE segments. */
function jpeg_with_icc( $jpeg, $icc ) {
    $data   = file_get_contents( $jpeg );
    $chunks = str_split( $icc, 65000 );
    $out    = substr( $data, 0, 2 );
    foreach ( $chunks as $i => $chunk ) {
        $payload = "ICC_PROFILE\0" . chr( $i + 1 ) . chr( count( $chunks ) ) . $chunk;
        $out    .= "\xFF\xE2" . pack( 'n', strlen( $payload ) + 2 ) . $payload;
    }
    return $out . substr( $data, 2 );
}

if ( realpath( $argv[0] ?? '' ) === __FILE__ ) {
    $out = $argv[1] ?? '';
    if ( ! $out ) {
        fwrite( STDERR, "usage: php make-p3-profile.php out.icc [in.jpg out.jpg]\n" );
        exit( 1 );
    }
    $icc = make_p3_profile();
    file_put_contents( $out, $icc );
    echo strlen( $icc ), " bytes written to $out\n";
    if ( ! empty( $argv[2] ) && ! empty( $argv[3] ) ) {
        file_put_contents( $argv[3], jpeg_with_icc( $argv[2], $icc ) );
        echo "{$argv[3]} written with the profile embedded\n";
    }
}
