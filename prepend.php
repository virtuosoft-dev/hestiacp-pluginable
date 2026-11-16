<?php
/**
 * Run any prepends located in the /usr/local/hestia/plugins directory based on priority naming schema.
 */

$hcppFolder = "/usr/local/hestia/plugins";
$hcppFiles = array_merge(
    glob( "$hcppFolder/prepend*.php", GLOB_NOSORT ) ?: [],
    glob( "$hcppFolder/*/prepend*.php", GLOB_NOSORT ) ?: [],
    glob( "$hcppFolder/*/*/prepend*.php", GLOB_NOSORT ) ?: []
);
$prependsArray = array();
foreach ( $hcppFiles as $filePath ) {
    if ( strpos( $filePath, '.disabled/' ) !== false ) continue;
    $fileKey = pathinfo( $filePath, PATHINFO_FILENAME );
    if ( $fileKey === 'prepend' ) $fileKey = 'prepend_10';
    if ( preg_match( '/^prepend_\d$/', $fileKey ) ) { // lead zero if nec.
        $fileKey = 'prepend_0' . substr( $fileKey, -1 );
    }
    $prependsArray[$filePath] = $fileKey;
}

// Sort by the priority number
asort( $prependsArray );

// Load and execute the prepend files in the order they were sorted
foreach ( $prependsArray as $key => $value ) {
    require_once( $key );
}
unset( $hcppFolder, $hcppFiles, $prependsArray, $filePath, $fileKey, $key, $value );
