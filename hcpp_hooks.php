<?php
/**
 * Our Hestia Control Panel Plugin Hooks (HCPP_Hooks) object. Use this class
 * to easily and quickly create a HestiaCP Pluginable plugin.
 * 
 * @author Virtuosoft/Stephen J. Carnam
 * @license AGPL-3.0, for other licensing options contact support@virtuosoft.com
 * @link https://github.com/virtuosoft-dev/hestiacp-pluginable
 * 
 */


if ( class_exists( 'HCPP_Hooks' ) ) return;
class HCPP_Hooks {
    public function __construct() {
        $self = new \ReflectionClass( $this );
        $public_methods = $self->getMethods( \ReflectionMethod::IS_PUBLIC );

        if ( empty ( $public_methods ) ) {
            return;
        }
        foreach ( $public_methods as $method ) {
            if ( $method->name != '__construct' ){

                // Check if name begins with a valid plugin prefix
                global $hcpp_plugin_prefixes;
                global $hcpp;
                foreach ( $hcpp_plugin_prefixes as $p ){
                    if ( strpos( $method->name, $p ) === 0 ){

                        // Assume for action/filter hook definition
                        $name = $method->name;
                        $priority = $hcpp->getRightMost( $name, '_' );
                        if ( is_numeric( $priority ) ){
                            $priority = (int) $priority;
                            $name = $hcpp->delRightMost( $name, '_' );
                        }else{
                            $priority = 10;
                        }

                        // Use a closure to wrap the method call and pass $hcpp
                        $callback = function() use ($method, $hcpp) {
                            $args = func_get_args();
                            return call_user_func_array(array($this, $method->name), array_merge([$hcpp], $args));
                        };
                        $hcpp->add_action( $name, $callback->bindTo($this, $this), $priority );
                    }
                }
            }
        }
    }
}