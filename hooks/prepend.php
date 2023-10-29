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
        $filePath = $file->getPathname();
        if ( strpos( $fileKey, 'prepend' ) === 0 && strpos( $filePath, '.disabled/prepend'  ) === false ) {
            if ( $fileKey === 'prepend' ) $fileKey = 'prepend_10';
            if (preg_match('/^prepend_\d$/', $fileKey)) { // lead zero if nec.
               $fileKey = 'prepend_0' . substr($fileKey, -1);
            }
            $prependsArray[$filePath] = $fileKey;
        }
    }
}

// Sort numerically by the priority number
asort($prependsArray);

// Load and execute the prepend files in the order they were sorted
foreach( $prependsArray as $key => $value ) {
    require_once( $value );
}
