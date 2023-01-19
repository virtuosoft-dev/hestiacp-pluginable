# hestiacp-pluginable
Extend Hestia Control Panel via a simple, WordPress-like plugins API.

> !!! Note: this repo is in progress; when completed, a version 1.0.0 will be released in the releases tab.

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
Plugins live in a folder of their own name within `/usr/local/hestia/web/plugins` and must contain a file called plugin.php. For instance, an example plugin would be at:

```
/usr/local/hestia/web/plugins/example
```
and contain the file plugin.php at:
```
/usr/local/hestia/web/plugins/example/plugin.php
```

A plugin can hook and respond to actions that HestiaCP invokes whenever an API call or control panel web page is viewed. A simple hook that can intercept whenever the API call v-list-users is invoked, either by the REST API or website control panel would look like:

```
<?php
/**
 * A sample plugin for hestiacp-pluginable 
 */
global $hcpp;
$hcpp->add_action( 'list-users', function( $args ) {

    global $hcpp;
    $hcpp->logging = true;
    $hcpp->log( "intercepted in test-plugin\n" . json_encode( $args, JSON_PRETTY_PRINT ) . "\n", FILE_APPEND );
    return $args;

});
```

It is important that an $hcpp->add_action hook returns (passes along) the incomming argument (the `$args` parameter above). An optional third parameter can be passed for priority with the default being 10, [just like WordPress](https://developer.wordpress.org/reference/functions/add_action/).

The above sample plugin will write the response to `/tmp/hestia.log`. Note that the old "v-" prefix (that was used to denote the original VestaCP project that HestiaCP was derived from), is not needed to hook the action with the `$hcpp->add_action` function. You can view all the possible hook names that the hestiacp-pluginable API can respond to by editing line 18 of `/usr/local/hestia/web/pluginable.php`:

```
    public $logging = true;
```

This will cause all possible hooks to be logged with the arguments in the log file at:
`/tmp/hestia.log`. With the line above uncommented, try browsing the HestiaCP web pages and view the contents of the `/tmp/hestia.log` file:

```
cat /tmp/hestia.log
```

Lastly, you can run any of HestiaCP's API commands using the HCPP object's `run` method. For example, the following code will return a JSON object of all the users:

```
global $hcpp;
$all_users = $hcpp->run('list-users');
```

