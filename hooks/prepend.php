<?php
/**
 * Run any prepends located in the /usr/local/hestia/plugins directory based on priority naming schema
 */

$folderPath = "/usr/local/hestia/plugins";
$prependsArray = array();

// Scan the plugins directory for any prepend files
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folderPath));
foreach( $iterator as $file) {
    if ( $file->getExtension() == "php" ) {
        $fileKey = pathinfo( $file->getFilename(), PATHINFO_FILENAME );
        if ( strpos( $fileKey, 'prepend' ) === 0 ) {
            $prependsArray[$fileKey] = $file->getPathname();
        }
    }
}

// Sort our prepend arrays by key, default 'prepend' to prepend_10.
foreach( $prependsArray as $key => $value ) {
    if ( $key == "prepend" ) {
        $prependsArray["prepend_10"] = $value;
        unset( $prependsArray[$key] );
    }
}

// Sort numerically by the priority number
usort( $prependsArray, function( $a, $b ) {
    $a = explode( '_', $a )[1];
    $b = explode( '_', $b )[1];
    return $a - $b;
});

// Load and execute the prepend files in the order they were sorted
foreach( $prependsArray as $key => $value ) {
    require_once( $value );
}

