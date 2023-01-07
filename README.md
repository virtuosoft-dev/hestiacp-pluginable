# hestiacp-pluginable
Extend Hestia Control Panel via a simple, WordPress-like plugins API.

### Installation
Simply download and unpack the source code files and move the hooks folder to /etc/hestiacp/hooks as root user:

```
sudo -s
cd /tmp
wget https://github.com/Steveorevo/hestiacp-pluginable/archive/refs/heads/main.zip
unzip main.zip
mv hestiacp-pluginable-main/hooks /etc/hestiacp
rm -rf hestiacp-pluginable-main
rm main.zip
```

Run the post_install.sh script. This will automatically be run anytime HestiaCP updates itself. Currently, this project is compatible with HestiaCP v1.6.14.

```
/etc/hestiacp/hooks/post_install.sh
```

### Creating a plugin
Plugins live in a folder of their own name within `/usr/local/hestia/plugins` and must contain a file called plugin.php. For instance, an example plugin would be at:

```
/usr/local/hestia/plugins/example
```
and contain the file plugin.php at:
```
/usr/local/hestia/plugins/example/plugin.php
```

A plugin can hook and respond to actions that HestiaCP invokes whenever an API call or control panel web page is viewed. A simple hook that can intercept whenever the API call v-list-users is invoked, either by the REST API or website control panel would look like:

```
<?php
/**
 * A sample plugin for hestiacp-pluginable 
 */
global $hccp;
$hccp->add_action( 'list-users', function( $args ) {
    file_put_contents( '/tmp/hestia.log', "intercepted in test-plugin\n" . json_encode( $args, JSON_PRETTY_PRINT ) . "\n", FILE_APPEND );
    return $args;
});
```

It is important that an $hccp->add_action hook returns (passes along) the incomming argument (the `$args` parameter above). An optional third parameter can be passed for priority with the default being 10, [just like WordPress](https://developer.wordpress.org/reference/functions/add_action/).

The above sample plugin will write the response to `/tmp/hestia.log`. Note that the old "v-" prefix (that was used to denote the original VestaCP project that HestiaCP was derived from), is not needed to hook the action with the `$hccp->add_action` function. You can view all the possible hook names that the hestiacp-pluginable API can respond to by uncommenting line 52 in pluginable.php:

```
file_put_contents( '/tmp/hestia.log', "add_action " . $tag . " " . substr(json_encode( $args ), 0, 80) . "...\n", FILE_APPEND );
```

This will cause all possible hooks to be logged with a sample of the arguments in the log file at:
`/tmp/hestia.log`. Be sure to re-run the post_install.sh script if you modify the pluginable.php file; as described at the top of this document in the installation section. With the line above uncommented, try browsing the HestiaCP web pages and view the contents of the `/tmp/hestia.log` file:

```
cat /tmp/hestia.log
```

