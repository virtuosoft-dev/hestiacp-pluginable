<?php
/**
 * Our basic plugin actions/filter API for Hestia Control Panel. This file furnishes a basic 
 * WordPress-like API for extending/modifying HestiaCP's functionality. This file reads the
 * /usr/local/hestia/plugins directory and loads any plugins found there. 
 * 
 * @version 1.0.0
 * @license GPL-3.0
 * @link https://github.com/steveorevo/hestiacp-pluginable
 * 
 */

/**
 * Add a plugin action/filter
 * 
 * @param string $tag The name of the action/filter to hook the $function_to_add to.
 * @param callable $function_to_add The name of the function to be called.
 * @param int $priority Optional, default is 10. Determines the execution priority, lower occurring first.
 */ 
function add_action( $tag, $function_to_add, $priority = 10) {
    global $hestia_actions;
    $priority = str_pad($priority, 3, '0', STR_PAD_LEFT);
    if ( !isset( $hestia_actions ) ) {
        $hestia_actions = [];
    }
    $count = 0;
    if ( isset( $hestia_actions[$tag] ) ) {
        $count = count( $hestia_actions[$tag] );
    }
    $idx = $priority . '_' . $tag . '_' . $count;
    $hestia_actions[$tag][$idx] = $function_to_add;
    ksort( $hestia_actions[$tag] );
}

/**
 * Invoke specific plugin action/filter hook.
 * 
 * @param string $tag The name of the action/filter hook.
 * @param mixed $args Optional. Arguments to pass to the functions hooked to the action/filter.
 * @return mixed The filtered value after all hooked functions are applied to it.
 */
function do_action( $tag, $args = null ) {
    //file_put_contents( '/tmp/hestia.log', "add_action " . $tag . " " . substr(json_encode( $args ), 0, 50) . "...\n", FILE_APPEND );
    global $hestia_actions;
    if ( isset( $hestia_actions[$tag] ) ) {
        foreach( $hestia_actions[$tag] as $action ) {
            $args = $action( $args );
        }
    }
    return $args;
}

// Check/create plugins folder
$plugins_folder = '/usr/local/hestia/plugins';
if ( !is_dir( $plugins_folder ) ) {
    mkdir( $plugins_folder );
}

// Load any plugins
$plugins = glob( $plugins_folder . '/*' );
foreach($plugins as $p) {
    $plugin_file = $p . '/plugin.php';
    if ( file_exists( $plugin_file ) ) {
        require_once( $plugin_file );
    }
}
