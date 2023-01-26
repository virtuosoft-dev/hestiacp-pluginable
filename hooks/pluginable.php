<?php
/**
 * Our Hestia Control Panel Plugin (HCPP) actions/filter API. This file furnishes a basic 
 * WordPress-like API for extending/modifying HestiaCP's functionality. This file reads the
 * /usr/local/hestia/plugins directory and loads any plugins found there. 
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
        public $logging = true;
        public $folder_ports = '/opt/hcpp/ports';
        public $start_port = 50000;
        public $installers = [];
        
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
         * Allocate a unique port number for a service. This function will check for an existing port allocation.
         * If one is found, it will be returned. If not, a new port will be allocated and returned. If neither
         * user nor domain is specified, the port will be allocated for the system; otherwise, if only user is
         * specified, the port will be allocated for the user; if domain is specified, the port will be allocated
         * specifically for the domain (user required for domain option).
         * 
         * @param string $name The name of the service to allocate a port for.
         * @param string $user The optional username to allocate the port for.
         * @param string $domain The optional domain to allocate the port for.
         * @return int The port number allocated or zero if an error occurred.
         */
        public function allocate_port( $name, $user = '', $domain = '' ) {
    
            // Check for existing port
            $port = $this->get_port( $name, $user, $domain );

            // Return existing port
            if ( $port != 0 ) {
                return $port;
            }

            // Determine which ports file to update
            $file = '';
            if ( $user == '' && $domain == '' ) { // System port
                $file = "$this->folder_ports/system.ports";
            }
            if ( $user != '' && $domain == '' ) { // User port
                $file = "$this->folder_ports/$user/user.ports";
            }
            if ( $user != '' && $domain != '' ) { // Domain port
                $file = "$this->folder_ports/$user/$domain.ports";
            }
            if ( $file == '' ) {
                return 0;
            }

            // Create the ports folder if it doesn't exist
            if ( !is_dir( dirname( $file ) ) ) {
                mkdir( dirname( $file ), 0755, true );
            }

            // Update the ports file with the next available port
            $port = $this->find_next_port();
            file_put_contents( $file, "set \$$name $port;\n", FILE_APPEND );
            if ( strpos( $file, 'system.ports' ) !== false ) {
                chmod( $file, 0644 );
                chown( $file, $user );
                chgrp( $file, $user );
            }
            return $port;
        }       

        /**
         * Delete a service port allocation.
         * 
         * @param string $name The name of the service to delete the port allocation for.
         * @param string $user The optional username to delete the port for; if blank, the system port will be deleted.
         * @param string $domain The optional domain to delete the port for (requires $user); if blank, the user port will be deleted.
         */
        public function delete_port( $name, $user = '', $domain = '' ) {

            // Exit if ports folder doesn't exist
            if ( !is_dir( $this->folder_ports ) ) {
                return;
            }

           // Determine which ports file to update
           $file = '';
           if ( $user == '' && $domain == '' ) { // System port
               $file = "$this->folder_ports/system.ports";
           }
           if ( $user != '' && $domain == '' ) { // User port
               $file = "$this->folder_ports/$user/user.ports";
           }
           if ( $user != '' && $domain != '' ) { // Domain port
               $file = "$this->folder_ports/$user/$domain.ports";
           }
           if ( $file == '' ) {
               return 0;
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
         * @param string $user The optional username to obtain the port for; if blank, the system port will be returned.
         * @param string $domain The optional domain to obtain the port for (requires $user); if blank, the user port will be returned.
         * @return int The port number allocated or zero if not found.
         */
        public function get_port( $name, $user = '', $domain = '' ) {

            // Create ports folder if it doesn't exist
            if ( !is_dir( $this->folder_ports ) ) {
                mkdir( $this->folder_ports, 0755, true );
                return 0;
            }
            
            // Determine which ports file to read
            $file = '';
            if ( $user == '' && $domain == '' ) { // System port
                $file = "$this->folder_ports/system.ports";
            }
            if ( $user != '' && $domain == '' ) { // User port
                $file = "$this->folder_ports/$user/user.ports";
            }
            if ( $user != '' && $domain != '' ) { // Domain port
                $file = "$this->folder_ports/$user/$domain.ports";
            }
            if ( $file == '' ) {
                return 0;
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

            // Get list of existing Nginx port files
            $files = array();
            $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->folder_ports ) );
            foreach( $iterator as $file ) {
                if ( !$file->isDir() && strpos( $file->getFilename(), 'prepend' ) === 0 && $file->getExtension() == 'ports' ) {
                    $fileKey = pathinfo( $file->getFilename(), PATHINFO_FILENAME );
                    $fileArray[$fileKey] = $file->getPathname();
                }
            }

            // Read all port numbers from files
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
         * Register a script to be exectued once when the plugin is first present
         * in /usr/local/hestia/plugins.
         */
        public function register_install_script( $file ) {
            
            // Check that the installed flag file doesn't already exist
            $plugin_name = basename( dirname( $file ) );
            if ( !file_exists( "/opt/hcpp/installed/$plugin_name" ) ) {
                 
                // Remember the plugin_name to run its install script
                $this->log( "Registering install script for $plugin_name");
                $this->installers[] = $file;
            }
        }
        
        /**
         * Register a script to be executed after the plugin folder has been
         * from /usr/local/hesita/plugins deleted. 
         */
        public function register_uninstall_script( $file ) {

            // Check if the uninstallers file already exists, if not; copy it over
            $plugin_name = basename( dirname( $file ) );
            if ( !file_exists( "/opt/hcpp/uninstallers/$plugin_name" ) ) {
                copy( $file, "/opt/hcpp/uninstallers/$plugin_name" );
                shell_exec( "chmod 700 /opt/hcpp/uninstallers/$plugin_name" );
            }
        }

        /**
         * Our object contructor
         */
        public function __construct() {
            $this->add_action( 'priv_check_user_password', [ $this, 'run_install_scripts' ] );
        }

        /**
         * Run any install scripts for plugins that have registered install scripts,
         * and run any uninstall scripts for plugins that have been removed.
         */
        public function run_install_scripts() {
            
            // Run install scripts for plugins that have been installed
            foreach( $this->installers as $file ) {
                $plugin_name = basename( dirname( $file ) );

                // Mark installed flag file to prevent it from running again
                touch ( "/opt/hcpp/installed/$plugin_name" );
                $this->log( "Running install script for $plugin_name" );
                $cmd = 'cd ' . dirname( $file ) . ' && ';
                $cmd .= "nohup $file ";
                $cmd .= ' > /dev/null 2>&1 &';
                $this->log( $cmd );
                $this->log( shell_exec( $cmd ) );
            }

            // Run uninstall scripts for plugins that have been removed
            $uninstallers = glob( '/opt/hcpp/uninstallers/*' );
            foreach( $uninstallers as $file ) {
                $plugin_name = pathinfo( $file, PATHINFO_FILENAME );
                if ( ! file_exists( "/usr/local/hestia/plugins/$plugin_name" ) ) {
                    $this->log( "Running uninstall script for $plugin_name" );
                    $cmd  = "cd /opt/hcpp/uninstallers && ";
                    $cmd .= "$file && ";
                    $cmd .= "rm -f $file && "; // remove uninstall script when done
                    $cmd .= "rm -f /opt/hcpp/installed/$plugin_name"; // remove installed flag file  
                    $this->log( $cmd );
                    $this->log( shell_exec( $cmd ) );
                }
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
         * Write a log message to the /tmp/hcpp.log file. Why here? Because
         * we can't log UI events to /var/log/hestia/ because open_basedir,
         * and we are logging privledged (root) and (admin) process space
         * events and they are isolated. /tmp/ is the only safe place to
         * write w/out causing runtime issues. 
         * 
         * @param mixed $msg The message or object to write to the log.
         */
        public function log( $msg ) {
            if ( $this->logging == false ) return;

            // Make sure log file is writable
            $logFile = '/tmp/hcpp.log';
            
            // Write timestamp and message as JSON to log file
            $t = (new DateTime('Now'))->format('H:i:s.') . substr( (new DateTime('Now'))->format('u'), 0, 2);
            $msg = json_encode( $msg, JSON_PRETTY_PRINT );
            $msg = $t . ' ' . $msg;
            if ( strlen( $msg ) > 120 ) {
                $msg = substr( $msg, 0, 120 ) . '...';
            }
            $msg .= "\n";
            error_log( $msg, 3, $logFile );
        }

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
        public function patch_file( $file, $search, $replace ) {
            if ( file_exists( $file ) && ! strstr( file_get_contents( $file ), $replace ) && strstr( file_get_contents( $file ), $search )) {
                $content = file_get_contents( $file );
                $content = str_replace( $search, $replace, $content );
                file_put_contents( $file, $content );
                $this->log( "Patched $file with $replace");
            }
        }
    }

    global $hcpp;
    $hcpp = new HCPP();

    // Check/create plugins folder
    $plugins_folder = '/usr/local/hestia/plugins';
    if ( !is_dir( $plugins_folder ) ) {
        mkdir( $plugins_folder );
        file_put_contents( $plugins_folder . '/index.php', '<' . "?php\n// Silence is golden." );
        chmod( $plugins_folder . '/index.php', 0644 );
    }

    // Load any plugins
    $plugins = glob( $plugins_folder . '/*' );
    foreach($plugins as $p) {
        $plugin_file = $p . '/plugin.php';
        if ( $plugin_file != "/usr/local/hestia/plugins/index.php/plugin.php" ) {
            if ( file_exists( $plugin_file ) ) {
                require_once( $plugin_file );
            }
        }
    }

    // Throw new_web_domain_ready via v-invoke-plugin hook when
    // conf folder and public_html folders are accessible
    $hcpp->add_action( 'pre_add_web_domain_backend', function( $args ) {
        $user = $args[0];
        $domain = $args[1];

        // Fire off our delay script to await the new domain's folders
        global $hcpp;
        $cmd = "nohup " . __DIR__ . "/await_domain.sh ";
        $cmd .= escapeshellarg( $user ) . " ";
        $cmd .= escapeshellarg( $domain );
        $cmd .= ' > /dev/null 2>&1 &';
        $hcpp->log( $cmd );
        shell_exec( $cmd ); 
    });
    
    // Throw new_web_domain_ready via v-invoke-plugin hook
    $hcpp->add_action( 'invoke-plugin', function( $args ) {
        global $hcpp;
        if ( $args[0] == 'new_web_domain_ready' ) {
            array_shift( $args );
            $hcpp->do_action( 'new_web_domain_ready', $args );
        }
    });
}
