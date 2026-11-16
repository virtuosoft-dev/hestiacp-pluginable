<?php
/**
 * Run any appends located in the /usr/local/hestia/plugins directory based on priority naming schema.
 */

$hcppFolder = "/usr/local/hestia/plugins";
$hcppFiles = array_merge(
    glob( "$hcppFolder/append*.php", GLOB_NOSORT ) ?: [],
    glob( "$hcppFolder/*/append*.php", GLOB_NOSORT ) ?: [],
    glob( "$hcppFolder/*/*/append*.php", GLOB_NOSORT ) ?: []
);
$appendsArray = array();
foreach ( $hcppFiles as $filePath ) {
    if ( strpos( $filePath, '.disabled/' ) !== false ) continue;
    $fileKey = pathinfo( $filePath, PATHINFO_FILENAME );
    if ( $fileKey === 'append' ) $fileKey = 'append_10';
    if ( preg_match( '/^append_\d$/', $fileKey ) ) { // lead zero if nec.
        $fileKey = 'append_0' . substr( $fileKey, -1 );
    }
    $appendsArray[$filePath] = $fileKey;
}

// Sort by the priority number
asort( $appendsArray );

// Load and execute the append files in the order they were sorted
foreach ( $appendsArray as $key => $value ) {
    require_once( $key );
}
unset( $hcppFolder, $hcppFiles, $appendsArray, $filePath, $fileKey, $key, $value );
