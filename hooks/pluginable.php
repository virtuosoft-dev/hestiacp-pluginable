<?php
/**
 * Our Hestia Control Panel Plugin (HCPP) actions/filter API. This file furnishes a basic 
 * WordPress-like API for extending/modifying HestiaCP's functionality. This file reads the
 * /usr/local/hestia/web/plugins directory and loads any plugins found there. 
 * 
 * @version 1.0.0
 * @license GPL-3.0
 * @link https://github.com/steveorevo/hestiacp-pluginable
 * 
 */

 if ( ! class_exists( 'HCPP') ) {
    class HCPP {

        public $hcpp_filters = [];
        public $hcpp_filter_count = 0;
        public $logging = false;
        public $folder_ports = '/opt/hestia-pluginable/ports';
        public $start_port = 50000;
        
        /**
         * Allow us to extend the HCPP dynamically.
         */
        public function __call($method, $args) {
            if (isset($this->$method)) {
                $func = $this->$method;
                return call_user_func_array($func, $args);
            }
        }

        /**
         * Add a plugin action/filter
         * 
         * @param string $tag The name of the action/filter to hook the $function_to_add to.
         * @param callable $function_to_add The name of the function to be called.
         * @param int $priority Optional, default is 10. Determines the execution priority, lower occurring first.
         */ 
        public function add_action( $tag, $function_to_add, $priority = 10) {
            $priority = str_pad($priority, 3, '0', STR_PAD_LEFT);
            $idx = $priority . '_' . $tag . '_' . $this->hcpp_filter_count;
            $this->hcpp_filter_count++;
            $this->hcpp_filters[$tag][$idx] = $function_to_add;
            ksort($this->hcpp_filters[$tag]);
            return true;
        }

        /**
         * Allocate a unique port number for a service.
         * 
         * @param string $name The name of the service to allocate a port for.
         * @param string $type The type of service; 'domain' (default), 'user', or 'system'.
         * @param string $id The username or domain name to delete the port allocation for (only used if $type is 'user' or 'domain').
         * @return int The port number allocated.
         */
        public function allocate_port( $name, $type = 'domain', $id = '' ) {
    
            // Check for existing port
            $port = $this->get_port( $name, $type, $id );
            if ( $port == 0 ) {
                $port = $this->find_next_port();

                // Determine which ports file to update
                switch( $type ) {
                    case 'system':
                        $file = "$this->folder_ports/system.ports";
                        break;
                    case 'user':
                        $file = "$this->folder_ports/user-$id.ports";
                        break;
                    default:
                        $file = "$this->folder_ports/domain-$id.ports";
                }

                // Update the ports folder with the ports file
                file_put_contents( $file, "set \$$name $port;\n", FILE_APPEND );
            }
            return $port;
        }       

        /**
         * Delete a service port allocation.
         * 
         * @param string $name The name of the service to delete the port allocation for.
         * @param string $type The type of service; 'domain' (default), 'user', or 'system'.
         * @param string $id The username or domain name to delete the port allocation for (only used if $type is 'user' or 'domain').
         */
        public function delete_port( $name, $type = 'domain', $id = '' ) {

            // Exit if ports folder doesn't exist
            if ( !is_dir( $this->folder_ports ) ) {
                return;
            }

            // Determine which ports file to update
            switch( $type ) {
                case 'system':
                    $file = "$this->folder_ports/system.ports";
                    break;
                case 'user':
                    $file = "$this->folder_ports/user-$id.ports";
                    break;
                default:
                    $file = "$this->folder_ports/domain-$id.ports";
            }

            // Check for existing ports file
            if ( !file_exists( $file ) ) {
                return;
            }

            // Update the file, removing the port allocation
            $new_content = "";
            $content = file_get_contents( $file );
            $content = explode( "\n", $content );
            foreach( $content as $line ) {
                if ( strpos( $line, "set $name " ) === false ) {
                    $new_content .= "$line\n";
                }
            }
            file_put_contents( $file, $new_content );
        }

        /**
         * Get the port number allocated to a service.
         * 
         * @param string $name The name of the service to get the port for.
         * @param string $type The type of service; 'domain' (default), 'user', or 'system'.
         * @param string $id The username or domain name to delete the port allocation for (only used if $type is 'user' or 'domain').
         * @return int The port number allocated or zero if not found.
         */
        public function get_port( $name, $type = 'domain', $id = '' ) {
            
            // Create ports folder if it doesn't exist
            if ( !is_dir( $this->folder_ports ) ) {
                mkdir( $this->folder_ports, 0755, true );
            }
            
            // Determine which ports file to update
            switch( $type ) {
                case 'system':
                    $file = "$this->folder_ports/system.ports";
                    break;
                case 'user':
                    $file = "$this->folder_ports/user-$id.ports";
                    break;
                default:
                    $file = "$this->folder_ports/domain-$id.ports";
            }

            // Check for existing port
            $port = 0;
            if ( file_exists( $file ) ) {
                $content = file_get_contents( $file );
                $content = explode( "\n", $content );
                foreach( $content as $line ) {
                    $parse = explode( ' ', $line );
                    if ( isset( $parse[1] ) && $parse[1] == "\$$name" ) {
                        $port = intval( $parse[2] );
                        break;
                    }
                }
            }
            return $port;
        }

        /**
         * Find the next unique service port number. 
         * 
         * @return int The next available port number. 
         */
        private function find_next_port() {

            // Read existing nginx port files
            $files = glob( "$this->folder_ports/*.ports" );
            $used_ports = [];
            foreach( $files as $file ) {
                $content = file_get_contents( $file );
                $content = explode( "\n", $content );

                // Gather all port numbers
                foreach( $content as $line ) {
                    $parse = explode( ' ', $line );
                    if ( isset( $parse[2] ) ) {
                        $used_ports[] = intval( $parse[2] );
                    }
                }
            }

            // Find first available port from starting port
            $port = $this->start_port;
            while( in_array( $port, $used_ports ) ) {
                $port++;
            }
            return $port;
        }

        /**
         * Invoke specific plugin action/filter hook.
         * 
         * @param string $tag The name of the action/filter hook.
         * @param mixed $arg Optional. Arguments to pass to the functions hooked to the action/filter.
         * @return mixed The filtered value after all hooked functions are applied to it.
         */
        public function do_action( $tag, $arg = '' ) {
            $this->log( 'do action as ' . trim( shell_exec( 'whoami' ) ) . ', ' . $tag );$this->log( $arg );
            if ( ! isset( $this->hcpp_filters[$tag] ) ) return $arg;

            $args = array();
            if ( is_array($arg) && 1 == count($arg) && isset($arg[0]) && is_object($arg[0]) ) // array(&$this)
                $args[] =& $arg[0];
            else
                $args[] = $arg;
            for ( $a = 2, $num = func_num_args(); $a < $num; $a++ )
                $args[] = func_get_arg($a);

            foreach ( $this->hcpp_filters[$tag] as $func ) {
                $arg = call_user_func_array( $func, $args );
                if ($arg != null) {
                    $args = array();
                    if ( is_array($arg) && 1 == count($arg) && isset($arg[0]) && is_object($arg[0]) ) // array(&$this)
                        $args[] =& $arg[0];
                    else
                        $args[] = $arg;
                    for ( $a = 2, $num = func_num_args(); $a < $num; $a++ )
                        $args[] = func_get_arg($a);
                }
            }
            if (is_array($args) && 1 == count($args)) {
                return $args[0];
            }else{
                return $args;
            }
        }

        /**
         * Run an API command and return JSON if applicable.
         * 
         * @param string $cmd The API command to execute along with it's arguments; the 'v-' prefix is optional.
         * @return mixed The output of the command; automatically returns JSON decoded if applicable.
         */
        public function run( $cmd ) {
            if ( file_exists( '/usr/local/hestia/bin/v-' . strtok( $cmd, " " ) ) ) {
                $cmd = '/etc/hestiacp/hooks/bin_actions ' . 'v-' . $cmd;
            }
            if ( file_exists( '/usr/local/hestia/bin/' . strtok( $cmd, " " ) ) ) {
                $cmd = '/etc/hestiacp/hooks/bin_actions ' . $cmd;
            }
            $output = shell_exec( $cmd );
            if ( strpos( $cmd, ' json') !== false ) {
                return json_decode( $output, true );
            }else{
                return $output;
            }
        }

        /**
         * Write a log message to the HestiaCP log.
         * 
         * @param mixed $msg The message or object to write to the log.
         */
        public function log( $msg ) {
            if ( $this->logging == false ) return;

            // Make sure log file is writable
            $logFile = '/var/log/hestia/pluginable.log';
            try {
                chmod( $logFile, 0666 );
            } catch (Exception $e) {
                // Do nothing
            }
            
            // Write timestamp and message as JSON to log file
            $t = (new DateTime('Now'))->format('H:i:s.') . substr( (new DateTime('Now'))->format('u'), 0, 2);
            $msg = json_encode( $msg, JSON_PRETTY_PRINT );
            error_log( $t . ' ' . substr( $msg, 0, 80 ) . "\n", 3, $logFile );

            // Only keep the last 8000 lines
            $maxLines = 8000;            
            $lineCount = 0;
            $lines = array();
            
            // read log file
            if (file_exists($logFile)) {
                $handle = fopen($logFile, "r");
                while(!feof($handle)) {
                    $line = fgets($handle);
                    $lines[] = $line;
                    $lineCount++;
                }
                fclose($handle);
            }
            
            // remove extra lines
            if ($lineCount > $maxLines) {
                $lines = array_slice($lines, $lineCount - $maxLines);
            }
            
            // write log file
            $handle = fopen($logFile, "w");
            foreach ($lines as $line) {
                fwrite($handle, $line);
            }
            fclose($handle);            
        }
    }

    global $hcpp;
    $hcpp = new HCPP();

    // Check/create plugins folder
    $plugins_folder = '/usr/local/hestia/web/plugins';
    if ( !is_dir( $plugins_folder ) ) {
        mkdir( $plugins_folder );
        file_put_contents( $plugins_folder . '/index.php', '<' . "?php\n// Silence is golden." );
        chmod( $plugins_folder . '/index.php', 0644 );
    }

    // Load any plugins
    $plugins = glob( $plugins_folder . '/*' );
    foreach($plugins as $p) {
        $plugin_file = $p . '/plugin.php';
        if ( $plugin_file != "/usr/local/hestia/web/plugins/index.php/plugin.php" ) {
            if ( file_exists( $plugin_file ) ) {
                require_once( $plugin_file );
            }
        }
    }
}
