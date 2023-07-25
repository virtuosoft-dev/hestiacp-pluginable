#!/bin/php
<?php
/**
 * Patch Hesta Control Panel files to support plugins. Also invoke any plugins that
 * wish to intercept the patch and post_install hook.
 *
 * @version 1.0.0
 * @license GPL-3.0
 * @link https://github.com/virtuosoft-dev/hestiacp-pluginable
 * 
 */

// Copy pluginable.php to /usr/local/hestia/web/pluginable.php
copy( '/etc/hestiacp/hooks/pluginable.php', '/usr/local/hestia/web/pluginable.php' );
shell_exec('cd /usr/local/hestia/web/js/dist && wget https://code.jquery.com/jquery-3.7.0.min.js' );

// Ensure log is present and writable when needed
if ( ! file_exists( '/tmp/hcpp.log' ) ) {
    touch( '/tmp/hcpp.log' );
    chmod( '/tmp/hcpp.log', 0666 );
}

// Copy prepend/append/pluginable system to /usr/local/hestia/data/hcpp
if ( !is_dir( '/usr/local/hestia/data/hcpp/installed' ) ) {
    mkdir( '/usr/local/hestia/data/hcpp/installed', 0755, true );
}
if ( !is_dir( '/usr/local/hestia/data/hcpp/uninstallers' ) ) {
    mkdir( '/usr/local/hestia/data/hcpp/uninstallers', 0755, true );
}
copy( '/etc/hestiacp/hooks/prepend.php', '/usr/local/hestia/data/hcpp/prepend.php' );
copy( '/etc/hestiacp/hooks/append.php', '/usr/local/hestia/data/hcpp/append.php' );

// Copy v-invoke-plugin to /usr/local/hestia/bin to allow invocation from API
copy( '/etc/hestiacp/hooks/v-invoke-plugin', '/usr/local/hestia/bin/v-invoke-plugin' );
chmod( '/usr/local/hestia/bin/v-invoke-plugin', 0755 );

require_once( '/usr/local/hestia/web/pluginable.php' );
global $hcpp;

$hcpp->do_action( 'hcpp_pre_patch' );

// Patch Hestia templates php-fpm templates ..templates/web/php-fpm/*.tpl
$folderPath = "/usr/local/hestia/data/templates/web/php-fpm";
$files = glob( "$folderPath/*.tpl" );
foreach( $files as $file ) {
    if ( strpos( $file, 'no-php.tpl' ) !== false ) {
        continue;
    }
    // Patch php-fpm templates open_basedir to include /usr/local/hestia/plugins and /usr/local/hestia/data/hcpp
    $hcpp->patch_file( 
        $file,
        "\nphp_admin_value[open_basedir] =",
        "\nphp_admin_value[open_basedir] = /home/%user%/.composer:/home/%user%/web/%domain%/public_html:/home/%user%/web/%domain%/private:/home/%user%/web/%domain%/public_shtml:/home/%user%/tmp:/tmp:/var/www/html:/bin:/usr/bin:/usr/local/bin:/usr/share:/opt:/usr/local/hestia/plugins:/usr/local/hestia/data/hcpp\n;php_admin_value[open_basedir] ="
    );

    // Patch php-fpm templates to support plugins prepend/append system
    $hcpp->patch_file( 
        $file,
        "\nphp_admin_value[open_basedir] =",
        "\nphp_admin_value[auto_prepend_file] = /usr/local/hestia/data/hcpp/prepend.php\n\nphp_admin_value[auto_append_file] = /usr/local/hestia/data/hcpp/append.php\nphp_admin_value[open_basedir] ="
    );
}

// domain.sh
$hcpp->patch_file( 
    '/usr/local/hestia/func/domain.sh',
    'if [[ $backend_template =~ ^.*PHP-([0-9])\_([0-9])$ ]]; then',
    'if [[ $backend_template =~ ^.*PHP-([0-9])\_([0-9])(.*)$ ]]; then'
);
$hcpp->patch_file( 
    '/usr/local/hestia/func/domain.sh',
    '${BASH_REMATCH[1]}.${BASH_REMATCH[2]}',
    '${BASH_REMATCH[1]}.${BASH_REMATCH[2]}${BASH_REMATCH[3]}'
);

// func/main.sh
$hcpp->patch_file(
    '/usr/local/hestia/func/main.sh',
    'BIN=$HESTIA/bin',
    'BIN="/etc/hestiacp/hooks/bin_actions "'
);
$hcpp->patch_file(
    '/usr/local/hestia/func/main.sh',
    '# Internal variables',
    "# Internal variables\nPARENT=\$(tr '\\0' '\n' < \"/proc/\$PPID/cmdline\" | sed 's/.*/\"&\"/');\nPARENT=\${PARENT//\$'\\n'/ };\nif [[ \$PARENT == *sudo* ]]; then\n    PARENT=\"/etc/hestiacp/hooks/priv_actions \$PARENT\";\n    eval_out=\$(eval \"\$PARENT\");\n    eval \"\$eval_out\";\nfi"
);
$hcpp->patch_file(
    '/usr/local/hestia/web/inc/main.php',
    'define("HESTIA_CMD", "/usr/bin/sudo /usr/local/hestia/bin/");',
    'define("HESTIA_CMD", "/etc/hestiacp/hooks/bin_actions sudo ");'
);
$hcpp->patch_file(
    '/usr/local/hestia/web/inc/main.php',
    "require_once dirname(__FILE__) . \"/helpers.php\";",
    "require_once dirname(__FILE__) . \"/helpers.php\";\nrequire_once(\"/usr/local/hestia/web/pluginable.php\");"
);
$hcpp->patch_file(
    '/usr/local/hestia/web/inc/main.php',
    "// Header",
    "// Header\n\tglobal \$hcpp;\n\tob_start();\n"
);
$hcpp->patch_file(
    '/usr/local/hestia/web/inc/main.php',
    'include $__template_dir . "header.php";',
    "include \$__template_dir . \"header.php\";\n\t\$args = [ 'TAB' => \$TAB, 'page' => \$page, 'user' => \$user, 'content' => ob_get_clean() ];\n    echo \$hcpp->do_action('hcpp_render_header', \$args)['content'];\n"
);
$hcpp->patch_file(
    '/usr/local/hestia/web/inc/main.php',
    "// Body",
    "// Body\n\tob_start();\n"
);
$hcpp->patch_file(
    '/usr/local/hestia/web/inc/main.php',
    "// Footer",
    "\t\$args['content'] = ob_get_clean();\n\techo \$hcpp->do_action('hcpp_render_page', \$args)['content'];\n\n\t// Footer"
);
$hcpp->patch_file(
    '/usr/local/hestia/web/inc/main.php',
    "// Footer",
    "// Footer\n\tob_start();\n"
);
$hcpp->patch_file(
    '/usr/local/hestia/web/inc/main.php',
    'include $__template_dir . "footer.php";',
    "include \$__template_dir . \"footer.php\";\n\t\$args['content'] = ob_get_clean();\n\techo \$hcpp->do_action('hcpp_render_footer', \$args)['content'];\n"
);
$hcpp->patch_file(
    '/usr/local/hestia/web/inc/main.php',
    "\t} else {\n\t\treturn true;\n\t}",
    "\t} else {\n\t\tglobal \$hcpp;\n\t\t\$hcpp->do_action('hcpp_csrf_verified');\n\t\treturn true;\n\t}"
);
$hcpp->patch_file(
    '/usr/local/hestia/web/inc/main.php',
    "function show_alert_message(\$data) {",
    "function show_alert_message(\$data) {\n\tglobal \$hcpp;\n\tob_start();\n"
);
$hcpp->patch_file(
    '/usr/local/hestia/web/inc/main.php',
    "\t\t\t\$msgText,\n\t\t);\n\t}\n",
    "\t\t\t\$msgText,\n\t\t);\n\t}\n\t\$args['content'] = ob_get_clean();\n\techo \$hcpp->do_action('hcpp_show_alert_message', \$args)['content'];\n",
);

// api/index.php
$hcpp->patch_file(
    '/usr/local/hestia/web/api/index.php',
    'define("HESTIA_CMD", "/usr/bin/sudo /usr/local/hestia/bin/");',
    'define("HESTIA_CMD", "/etc/hestiacp/hooks/bin_actions sudo ");'
);

// web/templates/header.php
$hcpp->patch_file(
    '/usr/local/hestia/web/templates/header.php',
    "<head>\n<?php\n",
    "<head>\n<?php\nglobal \$hcpp;\nob_start();\n"
);
$hcpp->patch_file(
    '/usr/local/hestia/web/templates/header.php',
    "?>\n</head>\n",
    "\$args['content'] = ob_get_clean();\necho \$hcpp->do_action('hcpp_head', \$args)['content'];\n?>\n</head>"
);

$hcpp->do_action( 'hcpp_post_install' );
