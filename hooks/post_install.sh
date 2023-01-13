#!/bin/php
<?php
/**
 * Patch Hesta Control Panel files to support plugins. Also invoke any plugins that
 * wish to intercept the patch and post_install hook.
 *
 * @version 1.0.0
 * @license GPL-3.0
 * @link https://github.com/steveorevo/hestiacp-pluginable
 * 
 */

// Copy pluginable.php to /usr/local/hestia/web/pluginable.php
copy( '/etc/hestiacp/hooks/pluginable.php', '/usr/local/hestia/web/pluginable.php' );

require_once( '/usr/local/hestia/web/pluginable.php' );
global $hcpp;

$hcpp->do_action( 'pre_patch_hestiacp' );

/**
 * patch_file function. 
 * 
 * Tests if the given file exists and  does not contain the content of replace;
 * if missing it performs a search and replace on the file.
 * 
 * @param string $file The file to patch.
 * @param string $search The search string.
 * @param string $replace The replace string.
 */ 
function patch_file( $file, $search, $replace ) {
    if ( file_exists( $file ) && ! strstr( file_get_contents( $file ), $replace ) && strstr( file_get_contents( $file ), $search )) {
        $content = file_get_contents( $file );
        $content = str_replace( $search, $replace, $content );
        file_put_contents( $file, $content );
        echo "Patched $file with $replace\n";
    }
}

// Patch Hestia files to support plugins

// domain.sh
patch_file( 
    '/usr/local/hestia/func/domain.sh',
    'if [[ $backend_template =~ ^.*PHP-([0-9])\_([0-9])$ ]]; then',
    'if [[ $backend_template =~ ^.*PHP-([0-9])\_([0-9])(.*)$ ]]; then'
);
patch_file( 
    '/usr/local/hestia/func/domain.sh',
    '${BASH_REMATCH[1]}.${BASH_REMATCH[2]}',
    '${BASH_REMATCH[1]}.${BASH_REMATCH[2]}${BASH_REMATCH[3]}'
);

// func/main.sh
patch_file(
    '/usr/local/hestia/func/main.sh',
    'BIN=$HESTIA/bin',
    'BIN="/etc/hestiacp/hooks/bin_actions "'
);
patch_file(
    '/usr/local/hestia/func/main.sh',
    '# Internal variables',
    '# Internal variables' . "\n" . 'PARENT=$(ps -o args= $PPID);/etc/hestiacp/hooks/priv_actions $PARENT'
);

// inc/main.php
patch_file(
    '/usr/local/hestia/web/inc/main.php',
    "define('HESTIA_CMD', '/usr/bin/sudo /usr/local/hestia/bin/');",
    "define('HESTIA_CMD', '/etc/hestiacp/hooks/bin_actions sudo ');"
);
patch_file(
    '/usr/local/hestia/web/inc/main.php',
    "include(\$__template_dir . 'pages/' . \$page . '.html');",
    "ob_start(); // render_page_body\n    include(\$__template_dir . 'pages/' . \$page . '.html');\n    global \$hcpp; echo \$hcpp->do_action('render_page_body', \$hcpp->do_action('render_page_body_' . \$TAB . '_' . \$page, ob_get_clean()));\n"
);

// templates/header.html
// Accomodate format changes and rename to header.php (https://github.com/Steveorevo/hestiacp/commits/main/web/templates)
$file = '/usr/local/hestia/web/templates/header.html';
if ( !file_exists($file) ) { 
    $file = '/usr/local/hestia/web/templates/header.php';
    if ( !file_exists($file) ) {
        echo "Could not find $file\n";
    }
}
patch_file(
    $file,
    "<head>",
    "<head><" . "?php include( '/usr/local/hestia/web/pluginable.php' );ob_start(); ?" . ">"
);
patch_file(
    $file,
    "</head>",
    "<" . "?php global \$hcpp;echo \$hcpp->do_action('head', ob_get_clean()); ?" . "></head>"
);
patch_file(
    $file,
    "<body class=\"body-<?=strtolower(\$TAB)?> lang-<?=\$_SESSION['language']?>\">",
    "<body class=\"body-<?=strtolower(\$TAB)?> lang-<?=\$_SESSION['language']?>\"><" . "?php ob_start(); ?" . ">"
);
patch_file(
    $file,
    "<body class=\"body-<?= strtolower(\$TAB) ?> lang-<?= \$_SESSION[\"language\"] ?>\">",
    "<body class=\"body-<?= strtolower(\$TAB) ?> lang-<?= \$_SESSION[\"language\"] ?>\"><" . "?php ob_start(); ?" . ">"
);

// templates/footer.html
patch_file(
    '/usr/local/hestia/web/templates/footer.html',
    "</body>",
    "<" . "?php global \$hcpp;echo \$hcpp->do_action('body', ob_get_clean()); ?" . "></body>"
);

// api/index.php
patch_file(
    '/usr/local/hestia/web/api/index.php',
    "define('HESTIA_CMD', '/usr/bin/sudo /usr/local/hestia/bin/');",
    "define('HESTIA_CMD', '/etc/hestiacp/hooks/bin_actions sudo ');"
);

$hcpp->do_action( 'post_install' );
