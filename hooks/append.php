<?php
/**
 * Run any appends located in the /usr/local/hestia/plugins directory based on priority naming schema
 */

$folderPath = "/usr/local/hestia/plugins";
$appendsArray = array();

// Scan the plugins directory for any append files
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folderPath));
foreach( $iterator as $file ) {
    if ( $file->getExtension() == "php") {
        $fileKey = pathinfo( $file->getFilename(), PATHINFO_FILENAME );
        if ( strpos( $fileKey, 'append' ) === 0 ) {
            $appendsArray[$fileKey] = $file->getPathname();
        }
    }
}

// Sort our append arrays by key, default 'append' to append_10.
foreach( $appendsArray as $key => $value ) {
    if ( $key == "append" ) {
        $appendsArray["append_10"] = $value;
        unset( $appendsArray[$key] );
    }
}

// Sort numerically by the priority number
usort( $appendsArray, function( $a, $b ) {
    $a = explode( '_', $a )[1];
    $b = explode( '_', $b )[1];
    return $a - $b;
});


// Load and execute the append files in the order they were sorted
foreach( $appendsArray as $key => $value ) {
    require_once( $value );
}

