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
    public $script_file = '';
    public $class_name = '';

    public function __construct() {
        $self = new \ReflectionClass( $this );
        $this->class_name = $self->name;
        $this->script_file = $self->getFileName();
        $public_methods = $self->getMethods( \ReflectionMethod::IS_PUBLIC );

        if ( empty ( $public_methods ) ) {
            return;
        }
        foreach ( $public_methods as $method ) {
            if ( $method->name != '__construct' ){

                // Check if name begins with a valid plugin prefix
                global $hcpp;
                foreach ( $hcpp->prefixes as $p ){
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
                        $hcpp->add_action( $name, array( $this, $method->name ), $priority );
                    }
                }
            }
        }
    }
}