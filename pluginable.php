<?php
/**
 * Our Hestia Control Panel Plugin (HCPP) object. This file furnishes a basic action/filter, 
 * WordPress-like, API for extending/modifying HestiaCP's functionality. This file reads the
 * /usr/local/hestia/plugins directory and loads any plugins found there. 
 * 
 *
 * @author Virtuosoft/Stephen J. Carnam
 * @license AGPL-3.0, for other licensing options contact support@virtuosoft.com
 * @link https://github.com/virtuosoft-dev/hestiacp-pluginable
 * 
 */


// Define HCPP if it doesn't already exist
global $hcpp;
if ( !class_exists( 'HCPP') ) {
    class HCPP {

        public $folder_ports = '/usr/local/hestia/data/hcpp/ports';
        public $prefixes = ['hcpp_', 'v_'];
        public $hcpp_filters = [];
        public $hcpp_filter_count = 0;
        public $html_content = '';
        public $installers = [];
        public $logging = false;
        public $start_port = 50000;
        public $custom_pages = [];
        public $plugins = [];

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
         * Our object contructor
         */
        public function __construct() {
            $this->logging = file_exists( '/etc/hestiacp/hooks/logging' );
            $this->add_action( 'v_check_user_password', [ $this, 'run_install_scripts' ] );
            $this->add_action( 'v_check_user_password', [ $this, 'run_uninstall_scripts' ] );
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
         * Add a custom page to the HestiaCP UI that maintains the header, menu, and footer.
         * 
         * @param string $p The 'p' GET parameter to match to display the custom page.
         * @param string $file The file to include when the 'p' GET parameter matches.
         */
        public function add_custom_page( $p, $file ) {
            $this->custom_pages[$p] = $file;
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
         * Define our append method to filter the output of the control panel.
         */
        public function append() {
            $this->do_action( 'hcpp_append' );
            
            // Get the DOMXPath object
            $html = ob_get_clean();
            $dom = new DOMDocument();
            libxml_use_internal_errors( true );
            $dom->loadHTML( $html );
            libxml_clear_errors();
            $xpath = new DOMXPath($dom);

            // Get the path
            if ( isset( $_GET['p'] ) ) {
                $path = $_GET['p'];
            }else{
                $request_url = $_SERVER['REQUEST_URI'];
                $parsed_url = parse_url( $request_url );
                $path = trim( $parsed_url['path'], '/' );
            }
            $path = str_replace( ['/index.php', '/', '-'], ['', '_', '_'], $path );

            // Run the path specific actions
            if ( $path != 'index.php' ) {
                $xpath = $this->do_action( 'hcpp_' . $path . '_xpath', $xpath );
                $dom = $xpath->document;
                $html = $dom->saveHTML();
                $html = $this->do_action( 'hcpp_' . $path . '_html', $html );
            }

            // Run all pages actions after specifics
            $xpath = $this->do_action( 'hcpp_all_xpath', $xpath );
            $dom = $xpath->document;
            $html = $dom->saveHTML();
            $html = $this->do_action( 'hcpp_all_html', $html );

            echo $html;            
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
         * Invoke specific plugin action/filter hook.
         * 
         * @param string $tag The name of the action/filter hook.
         * @param mixed $arg Optional. Arguments to pass to the functions hooked to the action/filter.
         * @return mixed The filtered value after all hooked functions are applied to it.
         */
        public function do_action( $tag, $arg = '' ) {
            if ($this->logging) {
                $this->log( 'do action as ' . trim( shell_exec( 'whoami' ) ) . ', ' . $tag );
                $this->log( $arg );
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

            // Ensure port is available for service
            while( !$this->is_service_port_free( $port ) ) {
                $port++;
            }
            return $port;
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
         * Get the repo's version tag for the given repo's folder.
         * 
         * @param string $folder The folder to get the repo's version tag for.
         * @return string The repo's version tag.
         */
        public function get_repo_folder_tag( $folder ) {
            $cmd = "cd $folder && git describe --tags --abbrev=0 2>&1"; // Redirect stderr to stdout
            $tag = trim( shell_exec( $cmd ) );
            if ( strpos( $tag, 'fatal' ) !== false ) {
                $cmd = "cd $folder && git describe --all 2>&1"; // Redirect stderr to stdout
                $tag = trim( shell_exec( $cmd ) );
                $tag = explode( '/', $tag );
                $tag = end( $tag );
            }
            if ( strpos( $tag, 'fatal' ) !== false ) {
                $tag = '';
            }
            return $tag;
        }

        /**
         * Insert HTML content into the element with the specified DOMXPath query selector.
         * 
         * @param DOMXPath $xpath The DOMXPath object to use for querying the DOM.
         * @param string $query The query selector to use for selecting the target element.
         * @param string $html The HTML content to insert into the target element.
         * 
         * @return DOMXPath The updated DOMXPath object with the HTML content inserted.
         */
        public function insert_html( $xpath, $query, $html ) {

           // Append the pluginable plugins to the plugins section
           $div_plugins = $xpath->query( $query );
           if ($div_plugins->length > 0) {
               $xml = $xpath->document->createDocumentFragment();

               // Use DOMDocument to validate and clean up the HTML string
               $tempDom = new DOMDocument();
               libxml_use_internal_errors(true);
               $tempDom->loadHTML('<div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
               libxml_clear_errors();

               // Import the validated HTML into the fragment
               foreach ($tempDom->documentElement->childNodes as $child) {
                   $node = $xpath->document->importNode($child, true);
                   $xml->appendChild($node);
               }

               // Append the fragment to the target node
               $div_plugins->item(0)->appendChild($xml);
           } else {
               $this->log('No element found using query selector: ' . $query);
           }
           return $xpath;
        }

        /**
         * Check if a TCP port is free for running a service.
         *
         * @param int $port The port number to check.
         * @param string $host The host to check the port on (default is '127.0.0.1').
         * @return bool True if the port is free, false if it is in use.
         */
        function is_service_port_free( $port, $host = '127.0.0.1' ) {
            $socket = @socket_create( AF_INET, SOCK_STREAM, SOL_TCP );
            if ( $socket === false ) {
                // Failed to create socket
                return false;
            }

            $result = @socket_bind( $socket, $host, $port );
            if ( $result === false ) {
                // Failed to bind socket, port is in use
                socket_close( $socket );
                return false;
            }

            // Successfully bound socket, port is free
            socket_close( $socket );
            return true;
        }

        /**
         * Define our install method to install the pluginable system.
         */
        public function install() {

            // Create plugins folder
            @mkdir( '/usr/local/hestia/plugins', 0755, true );

            // Create the HCPP directory structure
            @mkdir( '/usr/local/hestia/data/hcpp/installed', 0755, true );
            @mkdir( '/usr/local/hestia/data/hcpp/uninstallers', 0755, true );

            // // Copy local.conf to /etc/hestiacp/local.conf
            // copy( __DIR__ . '/local.conf', '/etc/hestiacp/local.conf' );

            // Append to /etc/hestiacp/local.conf
            $local_conf = '';
            if ( file_exists( '/etc/hestiacp/local.conf' ) ) {
                $local_conf = file_get_contents( '/etc/hestiacp/local.conf' );
            }
            $local_conf .= "\nsource /etc/hestiacp/hooks/local.conf\n";
            file_put_contents( '/etc/hestiacp/local.conf', $local_conf );

            // Copy the prepend/append/pluginable system to /usr/local/hestia/data/hcpp
            copy( '/etc/hestiacp/hooks/prepend.php', '/usr/local/hestia/data/hcpp/prepend.php' );
            copy( '/etc/hestiacp/hooks/append.php', '/usr/local/hestia/data/hcpp/append.php' );

            // Copy v-invoke-plugin to /usr/local/hestia/bin to allow invocation from API
            copy( '/etc/hestiacp/hooks/v-invoke-plugin', '/usr/local/hestia/bin/v-invoke-plugin' );
            chmod( '/usr/local/hestia/bin/v-invoke-plugin', 0755 );

            // Install jQuery 3.7.1
            shell_exec( 'wget -O /usr/local/hestia/web/js/dist/jquery-3.7.1.min.js https://code.jquery.com/jquery-3.7.1.min.js' );

            // Patch /usr/local/hestia/php/lib/php.ini
            $this->patch_file( 
                '/usr/local/hestia/php/lib/php.ini',
                "auto_append_file =\n",
                'auto_append_file = /etc/hestiacp/hooks/pluginable.php'
            );
            $this->patch_file( 
                '/usr/local/hestia/php/lib/php.ini',
                "auto_prepend_file =\n",
                'auto_prepend_file = /etc/hestiacp/hooks/pluginable.php'
            );

            // Patch Hestia templates php-fpm templates ..templates/web/php-fpm/*.tpl
            $folderPath = "/usr/local/hestia/data/templates/web/php-fpm";
            $files = glob( "$folderPath/*.tpl" );
            foreach( $files as $file ) {
                if ( strpos( $file, 'no-php.tpl' ) !== false ) {
                    continue;
                }
                // Patch php-fpm templates open_basedir to include /usr/local/hestia/plugins and /usr/local/hestia/data/hcpp
                $this->patch_file( 
                    $file,
                    "\nphp_admin_value[open_basedir] =",
                    "\nphp_admin_value[open_basedir] = /home/%user%/.composer:/home/%user%/web/%domain%/public_html:/home/%user%/web/%domain%/private:/home/%user%/web/%domain%/public_shtml:/home/%user%/tmp:/tmp:/var/www/html:/bin:/usr/bin:/usr/local/bin:/usr/share:/opt:/usr/local/hestia/plugins:/usr/local/hestia/data/hcpp\n;php_admin_value[open_basedir] ="
                );

                // Patch php-fpm templates to support plugins prepend/append system
                $this->patch_file( 
                    $file,
                    "\nphp_admin_value[open_basedir] =",
                    "\nphp_admin_value[auto_prepend_file] = /usr/local/hestia/data/hcpp/prepend.php\n\nphp_admin_value[auto_append_file] = /usr/local/hestia/data/hcpp/append.php\nphp_admin_value[open_basedir] ="
                );
            }

            // Patch /usr/local/hestia/func/domain.sh
            $this->patch_file( 
                '/usr/local/hestia/func/domain.sh',
                'if [[ $backend_template =~ ^.*PHP-([0-9])\_([0-9])$ ]]; then',
                'if [[ $backend_template =~ ^.*PHP-([0-9])\_([0-9])(.*)$ ]]; then'
            );
            $this->patch_file( 
                '/usr/local/hestia/func/domain.sh',
                '${BASH_REMATCH[1]}.${BASH_REMATCH[2]}',
                '${BASH_REMATCH[1]}.${BASH_REMATCH[2]}${BASH_REMATCH[3]}'
            );

            // Comment out disable_functions in php.ini 
            // (undo https://github.com/hestiacp/hestiacp/blob/main/CHANGELOG.md#1810---service-release)
            shell_exec( 'sed -i \'s/^disable_functions =/;disable_functions =/g\' /etc/php/*/fpm/php.ini' );
            shell_exec( 'sed -i \'s/^disable_functions =/;disable_functions =/g\' /etc/php/*/cli/php.ini' );

            // Install the hcpp_rebooted action hook service
            $serviceFile = '/etc/systemd/system/hcpp_rebooted.service';

            // Check if the service file already exists
            if (!file_exists($serviceFile)) {
                $serviceContent = "[Unit]
                    Description=Trigger hcpp_rebooted action hook
                    After=network.target

                    [Service]
                    Type=oneshot
                    ExecStartPre=/bin/sleep 10
                    ExecStart=/usr/bin/php /etc/hestiacp/hooks/pluginable.php --rebooted

                    [Install]
                    WantedBy=multi-user.target
                ";
                
                // Remove leading spaces from each line
                $serviceContent = preg_replace('/^\s+/m', '', $serviceContent);
                
                file_put_contents($serviceFile, $serviceContent);
                // Enable the service
                exec('systemctl enable hcpp_rebooted.service');
                echo "Installed hcpp_rebooted.service\n";
            } else {
                echo "hcpp_rebooted.service already exists. Skipping installation.\n";
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

            // Only write initial file if we're root and set permissions accordingly
            if ( ! file_exists( '/tmp/hcpp.log' ) ) {
                if ( posix_getpwuid( posix_geteuid() )['name'] == 'root' ) {
                    touch( '/tmp/hcpp.log' );
                    chmod( '/tmp/hcpp.log', 0666 );
                }else{
                    return;
                }
            }
            
            // Write timestamp and message as JSON to log file
            $logFile = '/tmp/hcpp.log';
            $t = (new DateTime('Now'))->format('H:i:s.') . substr( (new DateTime('Now'))->format('u'), 0, 2);
            $msg = json_encode( $msg, JSON_PRETTY_PRINT );
            $msg = $t . ' ' . $msg;
            $msg = substr( $msg, 0, 4096 );
            $msg .= "\n";

            // Use PHP's native error logging function
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
         * @param boolean $backup If true, backup the file before patching.
         */ 
        public function patch_file( $file, $search, $replace, $backup = true ) {
            if ( file_exists( $file ) ) {
                $content = file_get_contents( $file );
                if ( !strstr( $content, $replace ) && strstr( $content, $search ) ) {

                    // Backup file before patch with timestamp of patch yyyy_mm_dd_hh_mm
                    $backup_file = $file . '.bak_' . date('Y_m_d_H_i');
                    if ( !file_exists( $backup_file ) && $backup ) {
                        copy( $file, $backup_file );
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
                    $this->log( "!!! Failed to patch $file with $replace" );
                }
                
            }else{

                // Report patch_file failures, Hestia version may not be compatible
                $this->log( "!!! Failed to patch $file not found, with $replace" );
            }
        }

        /**
         * Define our prepend method to capture the output of the control panel.
         */
        public function prepend() {
            $this->do_action( 'hcpp_prepend' );
            ob_start();
            $this->do_action( 'hcpp_ob_started' );
        }

        /**
         * Generate random alpha numeric for passwords, seeds, etc.
         *
         * @param int $length The length of characters to return.
         * @param string $chars The set of possible characters to choose from.
         * @return string The resulting randomly generated string.
         */
        public function random_chars( $length = 10, $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz1234567890' ) {
            $string = '';
            $max_index = strlen( $chars ) - 1;
            for ( $i = 0; $i < $length; $i++ ) {
                $string .= $chars[random_int( 0, $max_index )]; // random_int is more crypto secure
            }
            return $string;
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
         * Register a plugin with the HestiaCP Pluginable object and create an instance of it.
         * 
         * @param object $plugin The plugin class to register and create.
         */
        public function register_plugin( $class ) {
            $property = strtolower( (new \ReflectionClass( $class ))->getShortName() );
            $this->$property = new $class();
            $this->plugins[] = $this->$property;
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
         * Define our remove method to remove the pluginable system.
         */
        public function remove() {

            // Remove data folder,local.conf, v-invoke-plugin, and restore original php.ini
            shell_exec( 'rm -rf /usr/local/hestia/data/hcpp' );
            shell_exec( 'rm -f /etc/hestiacp/local.conf' );
            shell_exec( 'rm -f /usr/local/hestia/bin/v-invoke-plugin' );
            $this->restore_backup( '/usr/local/hestia/php/lib/php.ini' );

            // Remove jQuery 3.7.1
            shell_exec( 'rm -f /usr/local/hestia/web/js/dist/jquery-3.7.1.min.js' );

            // Restore Hestia templates php-fpm templates ..templates/web/php-fpm/*.tpl
            $folderPath = "/usr/local/hestia/data/templates/web/php-fpm";
            $files = glob( "$folderPath/*.tpl" );
            foreach( $files as $file ) {
                if ( strpos( $file, 'no-php.tpl' ) !== false ) {
                    continue;
                }

                // Restore php-fpm templates
                $this->restore_backup( $file );
            }

            // Restore /usr/local/hestia/func/domain.sh
            $this->restore_backup( '/usr/local/hestia/func/domain.sh' );
            
            // Re-enable disable_functions in php.ini 
            // (undo https://github.com/hestiacp/hestiacp/blob/main/CHANGELOG.md#1810---service-release)
            shell_exec( 'sed -i \'s/^;disable_functions =/disable_functions =/g\' /etc/php/*/fpm/php.ini' );
            shell_exec( 'sed -i \'s/^;disable_functions =/disable_functions =/g\' /etc/php/*/cli/php.ini' );

            // Disable and remove the hcpp_rebooted service
            $serviceFile = '/etc/systemd/system/hcpp_rebooted.service';
            exec('systemctl disable hcpp_rebooted.service');
            exec('systemctl stop hcpp_rebooted.service');
        
            // Remove the service file
            if (file_exists($serviceFile)) {
                unlink($serviceFile);
                echo "Removed hcpp_rebooted.service\n";
            } else {
                echo "hcpp_rebooted.service does not exist. Skipping removal.\n";
            }
        
            // Reload systemd to apply changes
            exec('systemctl daemon-reload');
        }

        /**
         * Define our method to restore a file from a backup.
         * 
         * @param string $file The file to restore.
         */

        public function restore_backup( $file ) {
            // Get the directory and base name of the file
            $dir = dirname( $file );
            $base = basename( $file );
        
            // Find all backup files for the given file
            $backup_files = glob( "$dir/$base.bak_*" );
        
            // Check if there are any backup files
            if ( empty( $backup_files) ) {
                $this->log( "No backup files found for $file" );
                return false;
            }
        
            // Sort the backup files by date (oldest first)
            usort( $backup_files, function( $a, $b ) {
                return filemtime( $a ) - filemtime( $b );
            });
        
            // Choose the oldest backup file
            $oldest_backup = $backup_files[0];
        
            // Restore the contents of the original file from the oldest backup file
            if ( copy( $oldest_backup, $file ) ) {
                // Remove the backup file after restoration
                unlink( $oldest_backup );
                $this->log( "Restored $file from $oldest_backup and removed the backup file" );
                return true;
            } else {
                $this->log( "Failed to restore $file from $oldest_backup" );
                return false;
            }
        }

        /**
         * Run a trusted API command and return JSON if applicable.
         * 
         * @param string $cmd The API command to execute along with it's arguments.
         * @return mixed The output of the command; automatically returns JSON decoded if applicable.
         */
        public function run( $cmd ) {
            $cmd = 'sudo /usr/local/hestia/bin/' . $cmd; 
            $output = shell_exec( $cmd );
            if ( strpos( $cmd, ' json') !== false ) {
                return json_decode( $output, true );
            }else{
                return $output;
            }
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

            // foreach( $this->plugins as $plugin ) {
            //     if ( file_)
            // }            
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
         * Run an arbituary command as the given user.
         *
         * @param string $user The Linux user account to run the given command as.
         * @param string $cmd The command to execute.
         * @return string The output of the command.
         */
        public function runuser( $user, $cmd ) {
            $args = [ $user, $cmd ];
            $args = $this->do_action( 'hcpp_runuser', $args );
            $cmd = $args[1];
            $user = $args[0];
            $cmd = "runuser -s /bin/bash -l {$user} -c " . escapeshellarg( 'cd /home/' . $user . ' && ' . $cmd );
            global $hcpp;
            $hcpp->log( $cmd );
            $cmd = $this->do_action( 'hcpp_runuser_exec', $cmd );
            $result = shell_exec( $cmd );
            $result = $this->do_action( 'hcpp_runuser_result', $result );
            $hcpp->log( $result );
            return $result;
        }

        /**
         * Perform self update of the pluginable (hooks folder) from the git repo.
         */
        public function self_update() {
            // Only run if autoupdate is enabled
            if ( strpos( $this->run('v-list-sys-hestia-autoupdate'), 'Enabled') == false ) {
                return;
            }
            sleep(mt_rand(1, 30)); // stagger actual update check
            $this->log( 'Running self update...' );
            $url = 'https://github.com/virtuosoft-dev/hestiacp-pluginable';
            $installed_version = $this->get_repo_folder_tag( '/etc/hestiacp/hooks' );
            $latest_version = $this->find_latest_repo_tag( $url );
            $this->log( 'Installed version: ' . $installed_version . ', Latest version: ' . $latest_version );
            if ( $installed_version != $latest_version && $latest_version != '' ) {

                // Do a force reset on the repo to avoid merge conflicts, and obtain found latest version
                $cmd = 'cd /etc/hestiacp/hooks && git reset --hard';
                $cmd .= ' && git clean -f -d';
                $cmd .= ' && git fetch origin tag ' . $latest_version . ' && git checkout tags/' . $latest_version;
                $this->log( 'Update HestiaCP-Pluginable from ' . $installed_version . ' to ' . $latest_version);
                $this->log( $cmd );
                $this->log( shell_exec( $cmd ) );

                // Run the post_install.sh script
                $cmd = 'cd /etc/hestiacp/hooks && /etc/hestiacp/hooks/post_install.sh';
                $this->log( shell_exec( $cmd ) );
            }
        }

        /**
         * Append a new line to Hestia's shell formatted table
         * 
         * @param string $table The existing table as a string
         * @param string $new_line The new line to append to the table
         */
        public function shell_table_append( $table, $new_line ) {

            // Append new data
            $table = trim( $table );
            $lines = explode( "\n", $table );
            $lines[] = $new_line;

            // Parse the lines into a 2D array
            $data = array_map( function ( $line ) {
                return preg_split( '/\s+/', $line );
            }, $lines );

            // Determine the maximum width of each column
            $maxWidths = array_reduce( $data, function ( $widths, $row ) {
                foreach ( $row as $i => $cell ) {
                    $widths[ $i ] = max( $widths[ $i ] ?? 0, strlen( $cell ) );
                }
                return $widths;
            }, [] );

            // Format the table with proper column widths
            $formattedTable = array_map( function ( $row ) use ( $maxWidths ) {
                return implode( '  ', array_map( function ( $cell, $i ) use ( $maxWidths ) {
                    return str_pad( $cell, $maxWidths[ $i ] );
                }, $row, array_keys( $row ) ) );
            }, $data );

            // Convert the formatted table back to a string
            return implode( "\n", $formattedTable );
        }

        /**
         * Update plugins from their given git repo.
         */
        public function update_plugins() {
            // Only run if autoupdate is enabled
            if ( strpos( $this->run('v-list-sys-hestia-autoupdate'), 'Enabled') == false ) {
                return;
            }
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
                        $installed_version = $this->get_repo_folder_tag( $subfolder );
                        $latest_version = $this->find_latest_repo_tag( $url );
                        if ( $installed_version != $latest_version && $latest_version != '' && strlen( $installed_version ) < 18 ) {

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
                                $this->log( $cmd );
                                $this->log( shell_exec( $cmd ) );
                            }
                        }
                    }
                }
            }
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
}

// Create the hcpp object if it doesn't already exist
if ( !isset( $hcpp ) || $hcpp === null ) {
    $hcpp = new HCPP();
    require_once( __DIR__ . '/hcpp_hooks.php' );

    // Load any plugins
    $plugins = glob( '/usr/local/hestia/plugins/*' );
    foreach($plugins as $p) {
        if ( $hcpp->str_ends_with( $p, '.disabled' ) ) {
            continue;
        }        
        $plugin_file = $p . '/plugin.php';
        if ( $plugin_file != "/usr/local/hestia/plugins/index.php/plugin.php" ) {
            if ( file_exists( $plugin_file ) ) {
                $prefix = strtolower( $hcpp->getRightMost( $p, '/' ) );
                $hcpp->prefixes[] = $prefix . '_';
                require_once( $plugin_file );
            }
        }
    }

    // Run prepend code for web requests or bin_actions/install/remove for cli
    if ( php_sapi_name() !== 'cli' ) {

        /**
         * Route to any added custom pages
         */
        $hcpp->add_action( 'hcpp_ob_started', function() use ($hcpp) {
            if ( ! isset( $_GET['p'] ) ) {
                return;
            }
            $page = filter_input(INPUT_GET, 'p', FILTER_SANITIZE_STRING);
            if ( isset( $hcpp->custom_pages[ $page ] ) && file_exists( $hcpp->custom_pages[ $page ] ) ) {
                
                // Main include
                $TAB = strtoupper( $page );
                require_once( $_SERVER["DOCUMENT_ROOT"] . "/inc/main.php" );
                require_once( $_SERVER["DOCUMENT_ROOT"] . "/templates/header.php" );
                $panel = top_panel(empty($_SESSION["look"]) ? $_SESSION["user"] : $_SESSION["look"], $TAB);
                require_once( $_SERVER["DOCUMENT_ROOT"] . "/inc/policies.php" );

                // Include custom page
                require_once( $hcpp->custom_pages[ $page ] );
                require_once( $_SERVER["DOCUMENT_ROOT"] . "/templates/footer.php" );
                $hcpp->append();
            }else{

                // Abandon buffer and redirect to 404 page
                ob_end_clean();
                header("Location: /error/404.html");
            }
            exit();

        });
        $hcpp->prepend();

        // Restore jQuery in header
        $hcpp->add_action('hcpp_all_xpath', function($xpath) use ($hcpp) {
            $scriptElement = $xpath->document->createElement('script');
            $scriptElement->setAttribute('src', '/js/dist/jquery-3.7.1.min.js');
            $xpath->query('/html/head')->item(0)->appendChild($scriptElement);        
            return $xpath;
        });

        // List pluginable plugins in the HestiaCP UI's edit server page
        $hcpp->add_action('hcpp_edit_server_xpath', function($xpath) use ($hcpp) {

            // Insert css style for version tag
            $style = '<style>
                        .pversion {
                            font-size: smaller;
                            float: right;
                            font-weight: lighter;
                            margin: 5px;
                    }
                      </style>';
            $xpath = $hcpp->insert_html( $xpath, '/html/head', $style );

            // Process any POST request submissions
            foreach( $_REQUEST as $k => $v ) {
                if ( $hcpp->str_starts_with( $k, 'hcpp_' ) ) {
                    $plugin = substr( $k, 5 );
                    $hcpp->run( 'v-invoke-plugin hcpp_config ' . escapeshellarg( $v ) . ' ' . escapeshellarg( $plugin ) );
                }
            }

            // Gather list of plugins and install state
            $plugins = glob( '/usr/local/hestia/plugins/*' );
            $html = '';
            foreach( $plugins as $p ) {
                
                // Extract name form plugin.php header or default to folder name
                if ( !file_exists( $p . '/plugin.php' ) ) {
                    continue;
                }
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
                if ( is_dir( $p . '/.git' ) ) {
                    $version = $hcpp->run( 'v-invoke-plugin get_plugin_version ' . $name );
                    $version = trim( $version );
                    if ( $version != '' ) {
                        $version = '<span class="pversion">' . $version . '</span>';
                    }
                }

                // Inject the pluginable plugin into the page
                $h = '<div class="u-mb10">
                            <label for="hcpp_%name%" class="form-label">
                                %label%
                            </label>
                            %version%
                            <select class="form-select" name="hcpp_%name%" id="hcpp_%name%">
                                <option value="no">' . _('No') . '</option>
                                <option value="yes">' . _('Yes') . '</option>
                                <option value="uninstall">' . _('Uninstall') . '</option>
                            </select>
                        </div>';
                $h = str_replace( '%name%', $name, $h );
                $h = str_replace( '%label%', $label, $h );
                $h = str_replace( '%version%', $version, $h );
                if ( strpos( $p, '.disabled' ) === false ) {
                    $h = str_replace( 'value="yes"', 'value="yes" selected=true', $h );
                }else{
                    $h = str_replace( 'value="no"', 'value="no" selected=true', $h );
                }
                $h .= "\n";
                $html .= $h;
            }

            // Append the pluginable plugins to the plugins section
            $query = '//select[@id="v_plugin_app_installer"]/ancestor::div[contains(@class, "box-collapse-content")]';
            $xpath = $hcpp->insert_html( $xpath, $query, $html );
            return $xpath;
        });

    }else{

        // Check for the --install option i.e. ( php -f pluginable.php --install )
        if ( isset( $argv[1] ) && $argv[1] == '--install' ) {
            $hcpp->install();
            $hcpp->do_action( 'hcpp_post_install' );
        }

        // Check for the --remove option i.e. ( php -f pluginable.php --remove )
        if ( isset( $argv[1] ) && $argv[1] == '--remove' ) {
            $hcpp->remove();
        }

        // Check for the --rebooted option i.e. ( php -f pluginable.php --rebooted )
        if ( isset( $argv[1] ) && $argv[1] == '--rebooted' ) {
            $hcpp->self_update(); // Update pluginable on reboot
            $hcpp->update_plugins(); // Update plugins on reboot
            $hcpp->do_action( 'hcpp_rebooted' );
        }

        // Check for updates to plugins daily
        $hcpp->add_action( 'v_update_sys_queue', function( $args ) use( $hcpp ) {
            if ( isset( $args[0] ) && $args[0] === 'daily ') {
                $hcpp->self_update();
                $hcpp->update_plugins();
            }
            return $args;
        });

        // Check for updates frequently (every 5 minutes) if logging is enabled
        if ( $hcpp->logging ) {
            $hcpp->add_action( 'v_update_sys_rrd', function( $args ) use( $hcpp ) {
                $hcpp->self_update();
                $hcpp->update_plugins();
                return $args;
            });
        }

        // Append to v-list-sys-hestia-updates our pluginable update/version info
        $hcpp->add_action( 'v_list_sys_hestia_updates_output', function( $output ) use( $hcpp ) {

            // Get CPU architecture (of HestiaCP system; all plugins should be cross platform)
            $arch = php_uname('m') == 'x86_64' ? 'amd64' : (php_uname('m') == 'aarch64' ? 'arm64' : 'unknown');

            // List of folders and their git repo urls to list in the update output
            $git_folder_url = [
                ['/etc/hestiacp/hooks', 'https://github.com/virtuosoft-dev/hestiacp-pluginable.git'] // HestiaCP Pluginable core
            ];

            // Add any plugins from /usr/local/hestia/plugins to list
            $plugins = glob( '/usr/local/hestia/plugins/*' );
            foreach( $plugins as $p ) {
                if ( $hcpp->str_ends_with( $p, '.disabled' ) ) {
                    continue;
                }
                if ( file_exists( $p . '/plugin.php' ) ) {
                    $plugin_php = file_get_contents( $p . '/plugin.php' );
                    if ( strpos( $plugin_php, 'Plugin URI: ') !== false ) {
                        $url = $hcpp->delLeftMost( $plugin_php, 'Plugin URI: ' );
                        $url = trim( $hcpp->getLeftMost( $url, "\n") );
                        if ( str_ends_with( $url, '.git' ) === false ) {
                            $url .= '.git';
                        }
                        $git_folder_url[] = [ $p, $url ];
                    }
                }
            }

            // Loop through each git's folder and url to obtain update/version info
            foreach( $git_folder_url as $folder_url ) {
                $folder = $folder_url[0];
                $url = $folder_url[1];

                // Get current version and timestamp
                $installed = $hcpp->get_repo_folder_tag( $folder );
                $installed = $hcpp->delLeftMost( $installed, '/' );
                $installed = trim( $hcpp->getLeftMost( $installed, "/n" ) );

                // Get the timestamp of the cloned repo, and format it
                $installed_timestamp = shell_exec( 'cd ' . $folder . ' && git log -1 --format=%cd' );
                $installed_timestamp = date( 'Y-m-d H:i:s', strtotime( $installed_timestamp ) );

                // Get latest online tag version
                $latest = $hcpp->find_latest_repo_tag( $url );

                // Determine if the pluginable system is up to date
                $updated = $installed == $latest ? 'yes' : 'no';

                // Gather pluginable system info
                $package_name = '';
                $package_desc = '';
                if ( file_exists( $folder . '/plugin.php') ) {
                    $plugin_php = file_get_contents( $folder . '/plugin.php' );
                    if ( strpos( $plugin_php, 'Description: ') !== false ) {
                        $package_desc = $hcpp->delLeftMost( $plugin_php, 'Description: ' );
                        $package_desc = trim( $hcpp->getLeftMost( $package_desc, "\n") );
                    }
                    if ( strpos( $plugin_php, 'Plugin URI: ') !== false ) {
                        $package_name = $hcpp->delLeftMost( $plugin_php, 'Plugin URI: ' );
                        $package_name = trim( $hcpp->getLeftMost( $package_name, "\n") );
                        $package_name = basename( $package_name, '.git' );
                    }
                }else{
                    $package_name = 'hestiacp-pluginable';
                    $package_desc = 'Hestia control panel plugin system';
                }

                $info = array(
                    'VERSION' => str_replace( ['version', 'v'], ['',''], $installed ),
                    'ARCH' => $arch,
                    'UPDATED' => $updated,
                    'DESCR' => $package_desc,
                    'TIME' => $hcpp->getRightMost( $installed_timestamp, ' ' ),
                    'DATE' => $hcpp->getLeftMost( $installed_timestamp, ' ' )
                );

                // Check if first character is '{' to determine if JSON output
                if ( substr( $output, 0, 1 ) == '{' ) {
                    $output = json_decode( $output, true );
                    $output[$package_name] = $info;
                    $output = json_encode( $output, JSON_PRETTY_PRINT );
                }else{
                    $new_line = "{$package_name} {$info['VERSION']} {$info['ARCH']} {$info['UPDATED']} {$info['DATE']}";
                    $output = $hcpp->shell_table_append( $output, $new_line );
                }
                $output .= "\n";
            }
            return $output;
        });

        // Process plugin invoke requests
        $hcpp->add_action( 'hcpp_invoke_plugin', function( $args ) use( $hcpp ) {

            // Return the version of the given plugin
            if ( $args[0] == 'get_plugin_version' ) {
                $plugin = $args[1];
                if ( is_dir( "/usr/local/hestia/plugins/$plugin" ) ) {
                    $version = shell_exec( "cd /usr/local/hestia/plugins/$plugin && git describe --all" );
                }elseif ( is_dir( "/usr/local/hestia/plugins/$plugin.disabled" ) ) {
                    $version = shell_exec( "cd /usr/local/hestia/plugins/$plugin.disabled && git describe --all" );
                }else{
                    $version = "\n";
                }
                $version = $hcpp->delLeftMost( $version, '/' );
                echo $version;
            }

            // Enable/Disable a plugin
            if ( $args[0] == 'hcpp_config' ) {
                $v = $args[1];
                $plugin = $args[2];
                switch( $v ) {
                    case 'yes':
                        if ( is_dir( "/usr/local/hestia/plugins/$plugin.disabled" ) ) {
                            rename( "/usr/local/hestia/plugins/$plugin.disabled", "/usr/local/hestia/plugins/$plugin" );
                            $hcpp->do_action( 'hcpp_plugin_enabled', $plugin );
                        }
                        break;
                    case 'no':
                        if ( is_dir( "/usr/local/hestia/plugins/$plugin" ) ) {
                            rename( "/usr/local/hestia/plugins/$plugin", "/usr/local/hestia/plugins/$plugin.disabled" );
                            $hcpp->do_action( 'hcpp_plugin_disabled', $plugin );
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
            }
            return $args;
        });

        // Check for a /usr/local/hestia/bin/v- action via /etc/hestiacp/local.conf
        // and invoke any add_actions
        if ( isset( $argv[1] ) && strpos( $argv[1], 'v-' ) === 0 ) {
            $bin_command = str_replace( '-', '_', $argv[1] );

            // Get the remaining arguments after argv[1], if any otherwise set to empty array
            $args = array_slice( $argv, 2 );

            // Remove double slash encoding and double single quotes from arguments
            $args = array_map(function($arg) {
                return str_replace(["''",'\\'], ['',''], $arg);
            }, $args);
            $args = $hcpp->do_action( $bin_command, $args );

            // Escape the remaining arguments
            $args = implode( ' ', array_map( 'escapeshellarg', $args ) );

            // Run the original command with the new arguments
            $cmd = "/usr/local/hestia/bin/$argv[1] $args";
            
            $descriptorspec = array(
                0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
                1 => array("pipe", "w"),  // stdout is a pipe that the child will write to
                2 => array("pipe", "w") // stderr is a file to write to
            );
            $process = proc_open( $cmd, $descriptorspec, $pipes, null, null );
            fclose($pipes[0]);

            // Obtain the original commands output and error stream content
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $return_val = proc_close( $process );

            // Invoke any plugin post_ filters
            $output = $hcpp->do_action( $bin_command . '_output', $output );

            // Return the resulting output, error, and return value to the original caller
            $hOut = fopen( 'php://stdout', 'w' );
            $hErr = fopen( 'php://stderr', 'w' );
            fwrite( $hOut, $output );
            fwrite( $hErr, $error );
            fclose( $hOut );
            fclose( $hErr );
            exit( $return_val );            
        }
    }
}else{

    // Run append code for web requests
    if ( php_sapi_name() !== 'cli' ) {
        $hcpp->append();
    }
}
