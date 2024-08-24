<?php
/**
 * Our Hestia Control Panel Plugin (HCPP) actions/filter API. This file furnishes a basic 
 * WordPress-like API for extending/modifying HestiaCP's functionality. This file reads the
 * /usr/local/hestia/plugins directory and loads any plugins found there. 
 * 
 * @version 1.0.0
 * @license GPL-3.0
 * @link https://github.com/virtuosoft-dev/hestiacp-pluginable
 * 
 */

 if ( ! class_exists( 'HCPP') ) {
    class HCPP {

        public $hcpp_filters = [];
        public $hcpp_filter_count = 0;
        public $logging = false;
        public $folder_ports = '/usr/local/hestia/data/hcpp/ports';
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
            if ( strpos( $file, 'system.ports' ) == false ) {
                chmod( $file, 0640 );
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
                if ( strpos( $line, "set \$$name " ) === false ) {
                    $new_content .= "$line\n";
                }
            }
            $new_content = trim( $new_content );
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
        public function find_next_port() {
 
            // Get list of existing Nginx port files
            $files = array();
            $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->folder_ports ) );
            foreach( $iterator as $file ) {
                if ( !$file->isDir() && $file->getExtension() == 'ports' ) {
                    $files[] = $file->getPathname();
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
            if ($this->logging) {
                $this->log( 'do action as ' . trim( shell_exec( 'whoami' ) ) . ', ' . $tag );$this->log( $arg );
            }
            if ( ! isset( $this->hcpp_filters[$tag] ) ) return $arg;

            $args = array();
            if ( is_array($arg) && 1 == count($arg) && isset($arg[0]) && is_object($arg[0]) ) // array(&$this)
                $args[] =& $arg[0];
            else
                $args[] = $arg;
            for ( $a = 2, $num = func_num_args(); $a < $num; $a++ )
                $args[] = func_get_arg($a);

            foreach ( $this->hcpp_filters[$tag] as $func ) {
                try {
                    $arg = call_user_func_array( $func, $args );
                } catch (Exception $e) {
                    
                    // Echo out the error message if an exception occurs
                    echo 'Error: do_action failed ' . $e->getMessage();
                    $this->log( 'Error: do_action failed ' . $e->getMessage() );
                }
                
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
            if ( !file_exists( "/usr/local/hestia/data/hcpp/installed/$plugin_name" ) ) {
                 
                // Remember the plugin_name to run its install script
                $this->log( "Registering install script for $plugin_name");
                $this->installers[] = $file;
            }
        }
        
        /**
         * Register a script to be executed after the plugin folder has been
         * from /usr/local/hestia/plugins deleted. 
         */
        public function register_uninstall_script( $file ) {

            // Check if the uninstallers file already exists, if not; copy it over
            $plugin_name = basename( dirname( $file ) );
            if ( !file_exists( "/usr/local/hestia/data/hcpp/uninstallers/$plugin_name" ) ) {
                copy( $file, "/usr/local/hestia/data/hcpp/uninstallers/$plugin_name" );
                shell_exec( "chmod 700 /usr/local/hestia/data/hcpp/uninstallers/$plugin_name" );
            }
        }

        /**
         * Our object contructor
         */
        public function __construct() {
            $this->add_action( 'priv_check_user_password', [ $this, 'run_install_scripts' ] );
            $this->add_action( 'priv_check_user_password', [ $this, 'run_uninstall_scripts' ] );
        }

        /**
         * Run install scripts for plugins that have been installed
         */
        public function run_install_scripts( $args = null ) {
            foreach( $this->installers as $file ) {
                $plugin_name = basename( dirname( $file ) );
                if ( $this->str_ends_with( $plugin_name, '.disabled' ) ) {
                    continue;
                }

                // Mark installed flag file to prevent it from running again
                touch ( "/usr/local/hestia/data/hcpp/installed/$plugin_name" );
                $this->log( "Running install script for $plugin_name" );
                $cmd = 'cd ' . dirname( $file ) . ' && ';
                $cmd .= "nohup $file ";
                $cmd .= ' > /dev/null 2>&1 &';
                $this->log( $cmd );
                $this->log( shell_exec( $cmd ) );
                $this->do_action( 'hcpp_plugin_installed', $plugin_name );
            }
            return  $args;
        }

        /**
         * Run uninstall scripts for plugins that have been removed
         */
        public function run_uninstall_scripts( $args = null ) {
            
            $uninstallers = glob( '/usr/local/hestia/data/hcpp/uninstallers/*' );
            foreach( $uninstallers as $file ) {
                $plugin_name = pathinfo( $file, PATHINFO_FILENAME );

                if ( ! is_dir( "/usr/local/hestia/plugins/$plugin_name" ) && 
                        ! is_dir( "/usr/local/hestia/plugins/$plugin_name.disabled" ) ) {
                        
                    $this->log( "Running uninstall script for $plugin_name" );
                    $cmd  = "cd /usr/local/hestia/data/hcpp/uninstallers && ";
                    $cmd .= "$file && ";
                    $cmd .= "rm -f $file && "; // remove uninstall script when done
                    $cmd .= "rm -f /usr/local/hestia/data/hcpp/installed/$plugin_name"; // remove installed flag file  
                    $this->log( $cmd );
                    $this->log( shell_exec( $cmd ) );
                    $this->do_action( 'hcpp_plugin_uninstalled', $plugin_name );
                }
            }
            return  $args;
        }
        /**
         * Run a trusted API command and return JSON if applicable.
         * 
         * @param string $cmd The API command to execute along with it's arguments; the 'v-' prefix is optional.
         * @return mixed The output of the command; automatically returns JSON decoded if applicable.
         */
        public function run( $cmd ) {
            if ( file_exists( '/usr/local/hestia/bin/v-' . strtok( $cmd, " " ) ) ) {
                $cmd = '/etc/hestiacp/hooks/bin_actions sudo ' . 'v-' . $cmd;
            }
            if ( file_exists( '/usr/local/hestia/bin/' . strtok( $cmd, " " ) ) ) {
                $cmd = '/etc/hestiacp/hooks/bin_actions sudo ' . $cmd;
            }
            $output = shell_exec( $cmd );
            if ( strpos( $cmd, ' json') !== false ) {
                return json_decode( $output, true );
            }else{
                return $output;
            }
        }
        
        /**
         * Run an arbituary command as the given user.
         *
         * @param string $user The Linux user account to run the given command as.
         * @param string $cmd The command to execute.
         * @return string The output of the command.
         */
         public function runuser( $user, $cmd ) {
            $cmd = $this->do_action( 'hcpp_runuser', $cmd );
            $cmd = "runuser -s /bin/bash -l $user -c " . escapeshellarg( 'cd /home/$user && ' . $cmd );
            global $hcpp;
            $hcpp->log( $cmd );
            $cmd = $this->do_action( 'hcpp_runuser_exec', $cmd );
            $result = shell_exec( $cmd );
            $cmd = $this->do_action( 'hcpp_runuser_result', $cmd );
            $hcpp->log( $result );
            return $result;
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
            $msg .= "\n";

            // Suppress warnings that can hang the UI (i.e. v-restart-web-backend)
            $last_err_reporting = error_reporting();
            error_reporting( E_ALL & ~E_WARNING );
            try {
                chmod( $logFile, 0666 );
                file_put_contents( $logFile, $msg, FILE_APPEND );
            }catch( Exception $e ) {
                echo 'An error occured: ' . $e->getMessage();
            }
            error_reporting( $last_err_reporting );
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
         * @param boolean $retry_tabs If true, retry with spaces instead of tabs.
         * @param boolean $backup If true, backup the file before patching.
         */ 
        public function patch_file( $file, $search, $replace, $retry_tabs = false, $backup = true ) {
            if ( $retry_tabs ) {
                $search = str_replace( "\t", '    ', $search );
                $replace = str_replace( "\t", '    ', $replace );
            }
            if ( file_exists( $file ) ) {
                $content = file_get_contents( $file );
                if ( !strstr( $content, $replace ) && strstr( $content, $search ) ) {

                    // Backup file before patch with timestamp of patch yyyy_mm_dd_hh_mm
                    if ( !file_exists( $file . '.bak' ) && $backup ) {
                        copy( $file, $file . '.bak_' . date('Y_m_d_H_i') );
                    }
                    $content = str_replace( $search, $replace, $content );
                    file_put_contents( $file, $content );
                    $this->log( "Patched $file with $replace");
                }else{
                    if ( strstr( $content, $replace ) ) {
                        $this->log( "Already patched $file with $replace" );
                    }
                }

                // Report patch_file failures, Hestia version may not be compatible
                if (!strstr( $content, $replace ) && !strstr( $content, $search ) ) {
                    if ( $retry_tabs ) {

                        // Try second time with spaces instead of tabs
                        $this->patch_file( $file, $search, $replace, true );
                    }else{
                        $this->log( "!!! Failed to patch $file with $replace" );
                    }
                }
                
            }else{

                // Report patch_file failures, Hestia version may not be compatible
                $this->log( "!!! Failed to patch $file not found, with $replace" );
            }

        }

        /**
         * Copy a folder recursively, quickly, and retain/restore executable permissions.
         */
        public function copy_folder( $src, $dst, $user ) {
            // Append / to source and destination if necessary
            if (substr($src, -1) != '/') {
                $src .= '/';
            }
            $dst = rtrim( $dst, '/' );
            if ( ! is_dir( $dst ) ) {
                mkdir( $dst, 0750, true );
                chown( $dst, $user );
                chgrp( $dst, $user );
            }
            $cmd = 'cp -RTp ' . $src . ' ' . $dst . ' && chown -R ' . $user . ':' . $user . ' ' . $dst;
            shell_exec( $cmd );
            $cmd = 'find "' . $dst . '" -type f -perm /111 -exec chmod +x {} \;';
            shell_exec( $cmd );
            $cmd = 'find "' . $dst . '" -type d -perm /111 -exec chmod +x {} \;';
            shell_exec( $cmd );
        }

        /**
         * Re-apply pluginable when hestiacp core updates
         */
        public function update_core() {
            $detail = $this->run( 'list-sys-info json' );
            if ( !is_dir( '/usr/local/hestia/data/hcpp/' ) ) {
                mkdir( '/usr/local/hestia/data/hcpp/', 0755, true );
            }
            $hestia = $detail['sysinfo']['HESTIA'];
            if ( !file_exists( '/usr/local/hestia/data/hcpp/last_hestia.txt' ) ) {
                file_put_contents( '/usr/local/hestia/data/hcpp/last_hestia.txt', $hestia );
            }
            $last_hestia = file_get_contents( '/usr/local/hestia/data/hcpp/last_hestia.txt' );
            if ( $hestia != $last_hestia ) {
                $this->log( 'HestiaCP core updated from ' . $last_hestia . ' to ' . $hestia );
                $cmd = 'cd /etc/hestiacp/hooks && /etc/hestiacp/hooks/post_install.sh && service hestia restart';
                $cmd = $this->do_action( 'hcpp_update_core_cmd', $cmd );
                shell_exec( $cmd );
            }
            file_put_contents( '/usr/local/hestia/data/hcpp/last_hestia.txt', $hestia );
        }

        /**
         * Update plugins from their given git repo.
         */
        function update_plugins() {
            sleep(mt_rand(1, 30)); // stagger actual update check
            $this->log( 'Running update plugins...' );
            $pluginsDir = '/usr/local/hestia/plugins';
            $subfolders = glob( $pluginsDir . '/*', GLOB_ONLYDIR );
            foreach ( $subfolders as $subfolder ) {

                // Skip disabled plugins
                if ( $this->str_ends_with( $subfolder, '.disabled' ) ) {
                    continue;
                }
                $pluginFilePath = $subfolder . '/plugin.php';
                $pluginGitFolder = $subfolder . '/.git';
                if ( file_exists( $pluginFilePath ) && is_dir( $pluginGitFolder ) ) {
                    $fileLines = file($pluginFilePath);

                    // Search for the line containing 'Plugin URI:'
                    $url = '';
                    foreach ($fileLines as $line) {
                        if (strpos($line, 'Plugin URI:') !== false) {
                            $url = trim( $this->delLeftMost( $line, 'Plugin URI:' ) );
                            break;
                        }
                    }
                    
                    // If the plugin is a git repo with a URL, update it
                    if ( $url != '' ) {

                        // Get the installed version number of the plugin
                        $installed_version = shell_exec( 'cd ' . $subfolder . ' && git describe --tags --abbrev=0' );
                        $installed_version = trim( $installed_version );
                        $latest_version = $this->find_latest_repo_tag( $url );
                        if ( $installed_version != $latest_version && $latest_version != '' ) {

                            // Do a force reset on the repo to avoid merge conflicts, and obtain found latest version
                            $cmd = 'cd ' . $subfolder . ' && git reset --hard';
                            $cmd .= ' && git clean -f -d';
                            $cmd .= ' && git fetch origin tag ' . $latest_version . ' && git checkout tags/' . $latest_version;
                            $this->log( 'Update ' . $subfolder . ' from ' . $installed_version . ' to ' . $latest_version);
                            $this->log( $cmd );
                            $this->log( shell_exec( $cmd ) );

                            // Run the update script if it exists
                            if ( file_exists( $subfolder . '/update' ) ) {
                                $cmd = 'cd ' . $subfolder . ' && ./update ' . escapeshellarg( $installed_version ) . ' ' . escapeshellarg( $latest_version  );
                                $this->log( shell_exec( $cmd ) );
                            }
                        }
                    }
                }
            }
        }

        /**
         * Find the latest git repo's non-beta release tag.
         * 
         * @param string $url The git repo URL.
         * @return string The latest release tag.
         */
        public function find_latest_repo_tag( $url ) {
            $this->log( 'Finding latest release tag for ' . $url );

            // Execute the git ls-remote command
            $command = "git ls-remote --tags --sort=\"version:refname\" $url";
            $output = explode( PHP_EOL, shell_exec( $command ) );

            // Extract version numbers
            $versions = [];
            foreach ($output as $line) {

                // Omit $line if it contains the word beta
                if (strpos($line, 'beta') !== false) {
                    continue;
                }
                if (preg_match('/refs\/tags\/(v?[0-9]+\.[0-9]+\.[0-9]+)/', $line, $matches)) {
                    $versions[] = $matches[1];
                }
            }

            // Sort version numbers
            usort($versions, 'version_compare');

            // Get the most recent version number
            $latestRelease = end($versions);
            return $latestRelease;
        }

        // *************************************************************************
        // * Conveniently used string parsing and query functions used by this and
        // * other plugins. Linear version, lifted from github/steveorevo/GString
        // *************************************************************************

        /**
         * Deletes the right most string from the found search string
         * starting from right to left, including the search string itself.
         *
         * @return string
         */
        public function delRightMost( $sSource, $sSearch ) {
            for ( $i = strlen( $sSource ); $i >= 0; $i = $i - 1 ) {
                $f = strpos( $sSource, $sSearch, $i );
                if ( $f !== false ) {
                    return substr( $sSource, 0, $f );
                    break;
                }
            }
            return $sSource;
        }

        /**
         * Deletes the left most string from the found search string
         * starting from 
         *
         * @return string
         */
        public function delLeftMost( $sSource, $sSearch ) {
            for ( $i = 0; $i < strlen( $sSource ); $i = $i + 1 ) {
                $f = strpos( $sSource, $sSearch, $i );
                if ( $f !== false ) {
                    return substr( $sSource, $f + strlen( $sSearch ), strlen( $sSource ) );
                    break;
                }
            }
            return $sSource;
        }

        /**
         * Returns the left most string from the found search string
         * starting from left to right, excluding the search string itself.
         *
         * @return string
         */
        public function getLeftMost( $sSource, $sSearch ) {
            for ( $i = 0; $i < strlen( $sSource ); $i = $i + 1 ) {
                $f = strpos( $sSource, $sSearch, $i );
                if ( $f !== false ) {
                    return substr( $sSource, 0, $f );
                    break;
                }
            }
            return $sSource;
        }

        /**
         * Returns the right most string from the found search string
         * starting from right to left, excluding the search string itself.
         *
         * @return string
         */
        public function getRightMost( $sSource, $sSearch ) {
            for ( $i = strlen( $sSource ); $i >= 0; $i = $i - 1 ) {
                $f = strpos( $sSource, $sSearch, $i );
                if ( $f !== false ) {
                    return substr( $sSource, $f + strlen( $sSearch ), strlen( $sSource ) );
                }
            }
            return $sSource;
        }
        
        /**
         * PHP 7 compatible poly fills
         */
        public function str_ends_with(string $haystack, string $needle) {
            $needle_len = strlen($needle);
            return ($needle_len === 0 || 0 === substr_compare($haystack, $needle, - $needle_len));
        }
        function str_starts_with($haystack, $needle) {
            return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
        }
    }

    // *************************************************************************
    // * Initial HCPP behaviour
    // *************************************************************************

    global $hcpp;
    $hcpp = new HCPP();

    // Check/create plugins folder
    $plugins_folder = '/usr/local/hestia/plugins';
    if ( !is_dir( $plugins_folder ) ) {
        mkdir( $plugins_folder );
    }

    // Load any plugins
    $plugins = glob( $plugins_folder . '/*' );
    foreach($plugins as $p) {
        if ( $hcpp->str_ends_with( $p, '.disabled' ) ) {
            continue;
        }
        $plugin_file = $p . '/plugin.php';
        if ( $plugin_file != "/usr/local/hestia/plugins/index.php/plugin.php" ) {
            if ( file_exists( $plugin_file ) ) {
                require_once( $plugin_file );
            }
        }
    }

    // Throw one-time hcpp_new_domain_ready via v-invoke-plugin hook when
    // conf folder and public_html folders are first accessible
    $hcpp->add_action( 'pre_add_fs_directory', function( $args ) {
        global $hcpp;
        $user = $args[0];
        $domain = $args[1];
        if ( $hcpp->getRightMost( $domain, '/' ) != 'public_html' ) return $args;
        $domain = $hcpp->delRightMost( $domain, '/' );
        $domain = $hcpp->getRightMost( $domain, '/' );
        if ( file_exists( "/home/$user/web/$domain/public_html" ) ) return $args;

        // Fire off our delay script to await the new domain's folders
        $cmd = "nohup /etc/hestiacp/hooks/await_domain.sh ";
        $cmd .= escapeshellarg( $user ) . " ";
        $cmd .= escapeshellarg( $domain );
        $cmd .= ' > /dev/null 2>&1 &';
        $hcpp->log( $cmd );
        shell_exec( $cmd );
        return $args;
    });

    // Delete the ports file when the domain is deleted
    $hcpp->add_action( 'pre_delete_web_domain_backend', function( $args ) {
        global $hcpp;
        $user = $args[0];
        $domain = $args[1];
        if ( file_exists( "/usr/local/hestia/data/hcpp/ports/$user/$domain.ports" ) ) {
            unlink( "/usr/local/hestia/data/hcpp/ports/$user/$domain.ports" );
        }
        return $args;
    });

    // Delete the ports/user folder when user is deleted
    $hcpp->add_action( 'priv_delete_user', function( $args ) {
        global $hcpp;
        $user = $args[0];
        if ( file_exists( "/usr/local/hestia/data/hcpp/ports/$user" ) ) {
            shell_exec( "rm -rf /usr/local/hestia/data/hcpp/ports/$user" );
        }
        return $args;
    });

    // Throw hcpp_rebooted when the system has been started
    $hcpp->add_action( 'hcpp_invoke_plugin', function( $args ) {
        if ( $args[0] == 'hcpp_rebooted' ) {
            global $hcpp;
            $hcpp->update_core(); // HestiaCP core may have updated before reboot, ensure pluginable is applied
            $hcpp->do_action( 'hcpp_rebooted' );
        }
        return $args;
    });
        
    // Throw hcpp_new_domain_ready via v-invoke-plugin hook
    $hcpp->add_action( 'hcpp_invoke_plugin', function( $args ) {
        if ( $args[0] == 'hcpp_new_domain_ready' ) {
            global $hcpp;
            array_shift( $args );
            $hcpp->do_action( 'hcpp_new_domain_ready', $args );
        }
        return $args;
    });

    // Disable/enable/uninstall plugins via trusted command
    $hcpp->add_action( 'hcpp_invoke_plugin', function( $args ) {
        if ( count( $args ) < 3 ) return $args;
        if ( $args[0] != 'hcpp_config' ) return $args;
        $v = $args[1];
        $plugin = $args[2];        
        global $hcpp;
        switch( $v ) {
            case 'yes':
                if ( file_exists( "/usr/local/hestia/plugins/$plugin.disabled") ) {
                    rename( "/usr/local/hestia/plugins/$plugin.disabled", "/usr/local/hestia/plugins/$plugin" );
                    $hcpp->run( 'invoke-plugin hcpp_plugin_enabled ' . escapeshellarg( $plugin ) );
                }
                $hcpp->run_install_scripts();
                break;
            case 'no':
                if ( file_exists( "/usr/local/hestia/plugins/$plugin") ) {
                    $hcpp->do_action( 'hcpp_plugin_disabled', $plugin );
                    rename( "/usr/local/hestia/plugins/$plugin", "/usr/local/hestia/plugins/$plugin.disabled" );
                }
                break;
            case 'uninstall':
                if ( file_exists( "/usr/local/hestia/plugins/$plugin.disabled") && !file_exists( "/usr/local/hestia/plugins/$plugin") ) {
                    rename( "/usr/local/hestia/plugins/$plugin.disabled", "/usr/local/hestia/plugins/$plugin" );
                }
                $hcpp->do_action( 'hcpp_plugin_uninstall', $plugin );
                if ( file_exists( "/usr/local/hestia/plugins/$plugin") ) {
                    shell_exec( "rm -rf /usr/local/hestia/plugins/$plugin" );
                    $hcpp->run_uninstall_scripts();
                }
                break;
        }
        return $args;
    });

    // Get plugin version via trusted command
    $hcpp->add_action( 'hcpp_invoke_plugin', function( $args ) {
        if ( $args[0] == 'hcpp_get_plugin_version' ) {
            $plugin = $args[1];
            $version = shell_exec( 'cd "' . $plugin . '" && git describe --tags --abbrev=0' );
            echo $version;
        }
        return $args;
    });

    // Invoke plugin enabled after plugin is renamed and loaded
    $hcpp->add_action( 'hcpp_invoke_plugin', function( $args ) {
        if ( $args[0] == 'hcpp_plugin_enabled' ) {
            $plugin = $args[1];
            global $hcpp;
            $hcpp->do_action( 'hcpp_plugin_enabled', $plugin );
        }
        return $args;
    });

    // List plugins in HestiaCP's Configure Server UI
    $hcpp->add_action( 'hcpp_render_body', function( $args ) {
        global $hcpp;
        $content = $args['content'];
        if ( false == ($args['page'] == 'edit_server' && $args['TAB'] == 'SERVER' ) ) {
            return $args;
        }

        // Process any submissions
        foreach( $_REQUEST as $k => $v ) {
            if ( $hcpp->str_starts_with( $k, 'hcpp_' ) ) {
                $plugin = substr( $k, 5 );
                $hcpp->run( 'invoke-plugin hcpp_config ' . escapeshellarg( $v ) . ' ' . escapeshellarg( $plugin ) );
            }
        }

        // Parse the page content
        $before = $hcpp->delRightMost( $content, 'name="v_firewall"' ) . 'name="v_firewall"';
        $after = $hcpp->getRightMost( $content, 'name="v_firewall"' );

        // Parse the page content under HestiaCP 1.6.X
        $before .= $hcpp->getLeftMost( $after, '</div>' ) . '</div>';
        $after = $hcpp->delLeftMost( $after, '</div>' );

        // Create a block to list our plugins
        $block = '<div class="u-mb10">
                    <label for="hcpp_%name%" class="form-label">
                        %label% <span style="font-size:smaller;font-weight:lighter;">(%version%)</span>
                    </label>
                    <select class="form-select" name="hcpp_%name%" id="hcpp_%name%">
                        <option value="no">No</option>
                        <option value="yes">Yes</option>
                        <option value="uninstall">Uninstall</option>
                    </select>
                    </div>';


        // List the plugins 
        $plugins = glob( '/usr/local/hestia/plugins/*' );
        $insert = '';
        foreach($plugins as $p) {

            // Extract name from plugin.php header or default to folder name
            if ( ! file_exists( $p . '/plugin.php' ) ) continue;
            $label = file_get_contents( $p . '/plugin.php' );
            $name = basename( $p, '.disabled' );
            if ( strpos( $label, 'Plugin Name: ') !== false ) {
                $label = $hcpp->delLeftMost( $label, 'Plugin Name: ' );
                $label = trim( $hcpp->getLeftMost( $label, "\n") );
            }else{
                $label = $name;
            }

            // Extract version if git repo
            $version = '';
            if ( file_exists( $p . '/.git' ) ) {
                $version = trim( $hcpp->run( 'invoke-plugin hcpp_get_plugin_version ' . escapeshellarg( $p ) ) );
                $version = trim( $version );
            }
            if ( is_dir( $p ) && ($p[0] != '.') ) {
                if ( file_exists( $p . '/plugin.php' ) ) {
                    $item = str_replace( array( '%label%', '%name%', '%version%' ), array( $label, $name, $version ), $block );
                    if ( strpos( $p, '.disabled') === false) {
                        $item = str_replace( 'value="yes"', 'value="yes" selected=true', $item );
                    }else{
                        $item = str_replace( 'value="no"', 'value="no" selected=true', $item );
                    }
                    $insert .= $item;
                }
            }
        }

        $content = $before . $insert . $after;
        $args['content'] = $content;
        return $args;
    });

    // Check for updates to plugins daily
    $hcpp->add_action( 'update_sys_queue', function( $args ) {
        if (is_array($args) && count($args) === 1 && $args[0] === 'daily') {
            global $hcpp;
            $hcpp->update_core();
            $hcpp->update_plugins();
        }
        return $args;
    });

    // Frequently check for updates in logging mode
    if ( $hcpp->logging ) {
        $hcpp->add_action( 'priv_update_sys_rrd', function( $args ) { // every 5 minutes
        //$hcpp->add_action( 'priv_update_sys_queue', function( $args ) { // every 2 minutes
            global $hcpp;
            $hcpp->update_core();
            $hcpp->update_plugins();
            return $args;
        });
    }
    
    // Ensure we reload nginx to read our plugin configurations (req. by NodeApp, Collabora, etc.)
    $hcpp->add_action( 'post_restart_service', function( $args ) {
            global $hcpp;
            $cmd = '(sleep 5 && service nginx reload) > /dev/null 2>&1 &';
            $cmd = $hcpp->do_action( 'hcpp_nginx_reload', $cmd );
            shell_exec( $cmd );
    }, 50 );

    // Restore jquery
    $hcpp->add_action( 'hcpp_render_header', function( $args ) {
        $content = $args['content'];
        $inject = '<head><script src="/js/dist/jquery-3.7.0.min.js"></script>';
        $content = str_replace( '<head>', $inject, $content );
        $args['content'] = $content;
        return $args;
    } );
}

// Allow loading a plugin's index.php file if requested, sanitize request
if ( php_sapi_name() !== 'cli' ) {
    if ( isset( $_GET['load'] ) ) {
        $load = $_GET['load'];
        $load = str_replace(array('/', '\\'), '', $load);
        if (empty($load) || !preg_match('/^[A-Za-z0-9_-]+$/', $load)) {
            echo "Invalid plugin specified.";
        } else {
            $load = "/usr/local/hestia/plugins/$load/index.php";
            if ( file_exists( $load ) ) {
                require_once( $load );
            }else{
                echo "Plugin not found.";
            }
        }
    }
}