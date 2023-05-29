# hestiacp-pluginable
Extend [Hestia Control Panel](https://hestiacp.com) via a simple, WordPress-like plugins API. 

## Requirements

* Hestia Control Panel
* Ubuntu Linux OS

## Installation
First, back up your system! This install process will patch (__Read__: ___Permanently Alter___) HestiaCP files and templates. The files in the following folders will be altered during installation and after every update:

* /usr/local/hestia/data/templates/web/php-fpm
* /usr/local/hestia/web/templates
* /usr/local/hestia/web/api
* /usr/local/hestia/func

Simply download and unpack the source code files and move the hooks folder to /etc/hestiacp/hooks as root user. First switch to root user:

```
sudo -s
```

Then the rest:

```
cd /tmp
wget https://github.com/virtuosoft-dev/hestiacp-pluginable/archive/refs/heads/main.zip
unzip main.zip
mv hestiacp-pluginable-main/hooks /etc/hestiacp
rm -rf hestiacp-pluginable-main
rm main.zip
```

Lastly, run the post_install.sh script and restart Hestia: 

```
/etc/hestiacp/hooks/post_install.sh
service hestia restart
```

This will automatically be run anytime HestiaCP updates itself. You may wish to re-run it if you have created new templates in /usr/local/hestia/data/templates/web/php-fpm, as this will include the patches for open_basedir, auto_prepend/append (see the call to `patch_file` in the script for a list of changes). Currently, this project is compatible with HestiaCP v1.6.X-v1.7.X in Nginx + Apache2 with Multi-PHP installation options.

---

&nbsp;
## Notable Plugins
A number of plugins that use HestiaCP-Pluginable have been authored by [Stephen J. Carnam @ Virtuosoft](https://virtuosoft.com/donate) and can be found under the HCPP prefix on Virtuosoft's GitHub repo (Note: some repos may still be in development):

* [HCPP-NodeApp](https://github.com/virtuosoft-dev/hcpp-nodeapp)

*Important Note: The following plugins are dependent on [HCPP-NodeApp](https://github.com/virtuosoft-dev/hcpp-nodeapp), ensure you install [HCPP-NodeApp](https://github.com/virtuosoft-dev/hcpp-nodeapp) first!*

* [HCPP-MailCatcher](https://github.com/virtuosoft-dev/hcpp-mailcatcher)
* [HCPP-NodeRED](https://github.com/virtuosoft-dev/hcpp-nodered)
* [HCPP-NodeBB](https://github.com/virtuosoft-dev/hcpp-nodebb)
* [HCPP-VSCode](https://github.com/virtuosoft-dev/hcpp-vscode)
* [HCPP-Ghost](https://github.com/virtuosoft-dev/hcpp-ghost)
* [HCPP-Go](https://github.com/virtuosoft-dev/hcpp-go)


---

&nbsp;
## Creating a plugin
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
global $hcpp;
$hcpp->add_action( 'list_users', function( $args ) {

    global $hcpp;
    $hcpp->logging = true;
    $hcpp->log( $args );
    return $args;

});
```

It is important that an $hcpp->add_action hook returns (passes along) the incoming argument (the `$args` parameter above). An optional third parameter can be passed for priority with the default being 10, [just like how WordPress does it](https://developer.wordpress.org/reference/functions/add_action/).

The above sample plugin will write the arguments to `/tmp/hcpp.log` (if logging is on, see 'Debug Logging' below). 

Note that the old "v-" prefix (that was used to denote the original VestaCP project that HestiaCP was derived from), is not needed to hook the action with the `$hcpp->add_action` function and that hyphens/dashes must be replaced with underscores. 

&nbsp;
### Registering Install and Uninstall Scripts
Often times a plugin may wish to add files and folders to the HestiaCP runtime; specifically the data/templates folder. In these cases, it's in the plugin's best interest to copy over its own template files upon installation. Likewise, it's important to remove such files upon un-installation or plugin deletion. HestiaCP Pluginable API provides two such methods to aid authors in running plugin installation and un-installation scripts:

```
// From within plugin.php

global $hcpp;

$hcpp->register_install_script( dirname(__FILE__) . '/install.sh' );
$hcpp->register_uninstall_script( dirname(__FILE__) . '/uninstall.sh' );
```

You should define your optional install and uninstall scripts at the start of your plugin.php file to ensure they are properly registered. HestiaCP Pluginable will invoke the install.sh script only once; the next time a user login event occurs in Hestia and the plugin folder exists in `/usr/local/hestia/plugins`. The install script will run within the context of the current working directory of the plugin's folder to make it easy to define copy commands from the plugin's current folder. 

The uninstall.sh script is only run when the plugin has been deleted from the system (from `/usr/local/hestia/plugins` directory or if the user has selected 'Uninstall' from HestiaCP Server's [gear icon] Configure -> Plugins section to uninstall a plugin). Because the script itself is removed; Hestia Pluginable will copy the uninstall.sh script from the plugin folder when it is registered via the `register_uninstall_script` method. The uninstall.sh script is copied to the `/usr/local/hestia/data/hcpp/uninstallers/` folder and renamed to the same name as the plugin's original parent folder name. HestiaCP Pluginable executes the script in the context of the aforementioned uninstallers folder when the 'Uninstall' option is selected from HestiaCP Server's Configure -> Plugins menu. Lastly, the script itself is destroyed after it has been executed. 

Its not recommended to alter the existing files that HestiaCP comes with because they can be overwritten when HestiaCP self-updates. In those cases, (again, NOT recommended) you can utilitize HestiaCP Pluginable's API's `patch_file` function and `post_install` action hook to re-apply any changes to core files. Care should be taken as this can become complicated and difficult to undo with plugin uninstallation (and if other plugins have applied changes prior). Because Pluginable itself has already patched a number of HestiaCP core files; chances are an action hook already exists for you to customize HestiaCP without the need to alter core files. 

&nbsp;
### Invoking Plugins via Hestia API
Plugins also have the ability to execute code on behalf of invoking Hestia's API. An additional bin file called `v-invoke-plugin` can take an arbitrary number of arguments and will in turn execute the `invoke_plugin` action. A plugin author can subscribe to this message and execute custom code. Results can be returned, altering any values passed to other subscribers; or an author can echo results back to the caller as clear text or optionally, as JSON (or as JSON if an argument contains the string `json` as by convention like other Hestia API bin commands).
<br><br>
### Calling Other API Methods
You can run any of HestiaCP's API commands using the HCPP object's `run` method. For example, the following code will return an object (JSON already decoded) of all the users:

```
global $hcpp;
$all_users = $hcpp->run('list-users json');
```

&nbsp;
### Hosted Site Prepends and Appends 
The HestiaCP Pluginable project includes special functionality for processing [PHP auto prepend and auto append directives](https://www.php.net/manual/en/ini.core.php#ini.auto-prepend-file). This functionality allows a plugin to execute isolated code that is not apart of Hestia Control Panel actions, nor has access to the global $hcpp object; but rather as apart of all hosted sites running PHP. This feature is commonly used by anti-malware scanning applications (such as [WordFence](https://www.wordfence.com/help/firewall/optimizing-the-firewall/), [ISPProtect](https://ispprotect.com/ispprotect-bandaemon/), etc.), performance metric/tuning apps, or freemium hosting providers that wish to inject ads and other functionality into existing websites. 

A plugin author can execute custom PHP for hosted sites by simply including a file named 'prepend.php' for execution before any hosted site scripts; and/or include a file named 'append.php' to execute code after any hosted site scripts. 

Execution priority within plugins can occur earlier or later (with competing plugins) by simply including an underscore followed by a priority number. Like the priority number in [WordPress' action API](https://developer.wordpress.org/reference/functions/add_action/), a file with a name and lower number will execute before files named with a larger number. For example, `prepend_1.php` (priority #1) executes before `prepend_99.php` (priority #99). The default is 10, therefore the file named `prepend.php` is essentially the same as `prepend_10.php`.

&nbsp;
### Allocating Ports for Additional Services
HestiaCP Pluginable's API includes methods for allocating unique server ports. Using the methods below, your plugin can reserve ports on the server for domain or user specific based services (i.e. hosting a NodeJS Express based app, or setting up a Xdebug user session, etc) or a unique system wide service (i.e. an XMPP server, MQTT broker, or a Gitea server for all clients, etc). 


```
$port = $hcpp->allocate_port( $name, $user, $domain );

$port = $hcpp->get_port( $name, $user, $domain );

$hcpp->delete_port( $name, $user, $domain );
```

All the methods above expect three parameters:

| Parameter | Description |
|---|---|
| name | The service name (will be used as a variable name)|
| user | The username associated with the port; or system wide port if omitted|
| domain | The domain associated with the port; or user defined port if ommitted|

Use the `allocate_port` method to reserve a port for a service. This could be invoked when an action hook occurs for adding a domain. For instance, if you wish to allocate a port for a NodeJS Express app for a given domain name (i.e. example.com); invoke the method like this:

```
global $hcpp;
$hcpp->add_action( 'pre_add_web_domain_backend', function( $args ) {
    $user = $args[0];    // johnsmith
    $domain = $args[1];  // example.com
    $hcpp->allocate_port( 'myapp', $user, $domain );
});

```

The code above will generate a configuration file at `/usr/local/hestia/data/hcpp/ports/johnsmitg/example.com.ports`. The file will contain the following port defintion in an Nginx conf file format that defines a variable value:

```
set $myapp_port 50000;
```

An Nginx Proxy template can then use the `include` directive to directly include the file and utilize the variable `$myapp_port` to setup a reverse proxy to serve the NodeJS Express app. By using the Pluginable API, you are guaranteed a unique port number across all domains, users, and the Hestia Control Panel system. Likewise, an Nginx Proxy template could reference a user allocated port from any domain, by including the file (i.e. where username is johnsmith) at `/usr/local/hestia/data/hcpp/ports/johnsmith/user.ports`. System wide defined ports can be referenced from `/usr/local/hestia/data/hcpp/ports/system.ports`. 

While the `.ports` files are in Nginx conf format for convenience, any application or service can easily parse the variable and port number to leverage a unique port allocation for their service (i.e. an Xdebug port could be configured via ini_set). The `/usr/local/hestia/data/hcpp/ports` path is apart of the open_basedir path which allows hosted PHP processes read-only access to the files. For user and domain .ports files; the files can only be read by the given HestiaCP user.

&nbsp;
### Debug Logging
You can view all the possible hook names that the hestiacp-pluginable API can respond to by editing line 18 to turn logging on in the file at `/usr/local/hestia/web/pluginable.php`:

```
    public $logging = true;
```

This will cause all possible hooks to be logged with an excerpt of the arguments in the log file at: `/tmp/hcpp.log`. With the line above uncommented, try browsing the HestiaCP web pages and view the contents of the `/tmp/hcpp.log` file:

```
cat /tmp/hcpp.log
```

Note: the hcpp.log file is automatically created with open permissions for writing by both trusted root and admin users because Hestia sometimes executes privileged processes. Also, HestiaCP UI process does not have PHP access to /var/log/hestia due to open_basedir
restrictions. /tmp/hcpp.log is a 'safe' file path; DO NOT delete this file as it can break runtime/logging/debugging. It is recommended you turn logging off for performance purposes. If you need to self-truncate the log simply use the command:

```
truncate -s 0 /tmp/hcpp.log
```
or
```
: > /tmp/hcpp.log
```

## Support the creator
You can help this author's open source development endeavors by donating any amount to Stephen J. Carnam @ Virtuosoft. Your donation, no matter how large or small helps pay for essential time and resources to create MIT and GPL licensed projects that you and the world can benefit from. Click the link below to donate today :)
<div>
         

[<kbd> <br> Donate to this Project <br> </kbd>][KBD]


</div>


<!---------------------------------------------------------------------------->

[KBD]: https://virtuosoft.com/donate

https://virtuosoft.com/donate
