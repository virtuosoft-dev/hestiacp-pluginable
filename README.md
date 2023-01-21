# hestiacp-pluginable
Extend Hestia Control Panel via a simple, WordPress-like plugins API.

> :warning: !!! Note: this repo is in progress; when completed, a release will appear in the release tab.

## Installation
First, back up your system! This install process with patch (__Read__: ___Permanently Alter___) HestiaCP files and templates. The files in the following folders will be altered during installation and after every update:

* /usr/local/hestia/data/templates/web/php-fpm
* /usr/local/hestia/web/templates
* /usr/local/hestia/web/api
* /usr/local/hestia/func

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

Run the post_install.sh script. This will automatically be run anytime HestiaCP updates itself. You may wish to re-run it if you have created new templates in /usr/local/hestia/data/templates/web/php-fpm, as this will include the patches for open_basedir, auto_prepend/append (see the call to `patch_file` in the script for a list of changes). Currently, this project is compatible with HestiaCP v1.6.14.

```
/etc/hestiacp/hooks/post_install.sh
```

---
&nbsp;

## Creating a plugin
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
    $hcpp->log( $args );
    return $args;

});
```

It is important that an $hcpp->add_action hook returns (passes along) the incomming argument (the `$args` parameter above). An optional third parameter can be passed for priority with the default being 10, [just like how WordPress does it](https://developer.wordpress.org/reference/functions/add_action/).

The above sample plugin will write the arguments to `/var/log/hestia/pluginable.log` (if logging is on, see 'Debug Logging' below). 

Notice that the old "v-" prefix (that was used to denote the original VestaCP project that HestiaCP was derived from), is not needed to hook the action with the `$hcpp->add_action` function. 

### Debug Logging

You can view all the possible hook names that the hestiacp-pluginable API can respond to by editing line 18 to turn logging on in the file at `/usr/local/hestia/web/pluginable.php`:

```
    public $logging = true;
```

This will cause all possible hooks to be logged with an excerpt of the arguments in the log file at: `/var/log/hestia/pluginable.log`. With the line above uncommented, try browsing the HestiaCP web pages and view the contents of the `/var/log/hestia/pluginable.log` file:

```
cat /var/log/hestia/pluginable.log
```

Note: the pluginable.log file is self purging and purposely only displays the last 8000 lines. It is automatically created with open permissions for writing by both trusted root and admin users because Hestia sometimes executes privileged processes; DO NOT delete this file as it can break logging/debugging. It is recommended you turn logging off for performance purposes. If you need to self-truncate the log simply use the command:

```
truncate -s 0 /var/log/hestia/pluginable.log
```

### Invoking Plugins via Hestia API
Plugins also have the ability to execute code on behalf of invoking Hestia's API. An additional bin file called `v-invoke-plugin` can take an arbituary number of arguments and will in turn execute the `invoke_plugin` action. A plugin author can subscribe to this message and execute custom code. Results can be returned, altering any values passed to other subscribers; or an author can echo results back to the caller as clear text or optionally, as JSON (or as JSON if an argument contains the string `json` as by convention like other Hestia API bin commands).

### Calling Other API Methods
Lastly, you can run any of HestiaCP's API commands using the HCPP object's `run` method. For example, the following code will return an object (JSON already decoded) of all the users:

```
global $hcpp;
$all_users = $hcpp->run('list-users json');
```

### Hosted Site Prepends and Appends 
The HestiaCP Pluginable project includes special functionality for processing [PHP auto prepend and auto append directives](https://www.php.net/manual/en/ini.core.php#ini.auto-prepend-file). This functionality allows a plugin to execute isolated code that is not apart of Hestia Control Panel actions, nor has access to the global $hcpp object; but rather as apart of all hosted sites running PHP. This feature is commonly used by anti-malware scanning applications (such as WordFence, ISPProtect, etc.), performance metric/tuning apps, or freemium hosting providers that wish to inject ads and other functionality into existing websites. 

A plugin author can execute custom PHP for hosted sites by simply including a file named 'prepend.php' for execution before any hosted site scripts; and/or include a file named 'append.php' to execute code after any hosted site scripts. 

Execution priority within plugins can occur earlier or later (with competeting plugins) by simply including an underscore followed by a priority number. Like the priority number in [WordPress' action API](https://developer.wordpress.org/reference/functions/add_action/), a file with a name and lower number will execute before files named with a larger number. For example, `prepend_1.php` (priority #1) executes before `prepend_99.php` (priority #99). The default is 10, therefore the file named `prepend.php` is essentially the same as `prepend_10.php`.
