# hestiacp-pluginable
Extend [Hestia Control Panel](https://hestiacp.com) via a simple, WordPress-like plugins API. 

Version 2.x, now with leaner API that uses HestiaCP 'sanctioned' /etc/hestiacp/local.conf and php.ini's native prepend/append system to extend HestiaCP with less modifications to core files. This makes install/uninstall a lot easier and less intrusive. You will also find that version 2.x displays plugins and updates in the existing HestiaCP UI (see 'Updates' and 'Configure' in HestiaCP). Both the pluginable project and plugins that use it can receive updates directly from their respective git repositories.

## Requirements

* Hestia Control Panel version 1.9.2 or greater
* Ubuntu or Debian Linux OS

## Installation
First, back up your system! This install process will patch (__Read__: ___Permanently Alter___) HestiaCP files and templates. A backup of the original file is created with a timestamp extension, i.e. `domain.sh.bak_2023_06_10_21_02`. The files in the following folders will be altered during installation and after every update:

* /etc/hestiacp/hooks
* /usr/local/hestia/data/templates/web/php-fpm
* /usr/local/hestia/php/lib
* /usr/local/hestia/func

***Note: Pluginable uses the /etc/hestiacp/hooks folder in Hestia (not used in default installations). If you are using the hooks folder; backup it up! You'll need to manually merge any existing post_install.sh files if you are using them.***

Clone the latest release version (see v2.0.0 below) to the hooks folder:
```
sudo git clone --branch v2.0.0 https://github.com/virtuosoft-dev/hestiacp-pluginable /etc/hestiacp/hooks
```

Run the post_install.sh script:

```
/etc/hestiacp/hooks/post_install.sh
```

This will automatically be run anytime HestiaCP updates itself. You may wish to re-run it if you have created new templates in /usr/local/hestia/data/templates/web/php-fpm, as this will include the patches for open_basedir, auto_prepend/append. Currently, this project is compatible with HestiaCP v1.9.X in Nginx + Apache2 with Multi-PHP installation options.

---

## Uninstallation
Uninstallation of Version 2.X is greatly simplified, removing the risk of perminently altering your HestiaCP install. Follow these steps to restore your stock HestiaCP installation:

### Automatic Uninstall
Run the following from the command line:
```
sudo php -f /etc/hestiacp/hooks/pluginable.php -- --uninstall
```

### Manual Uninstall

1) Restore the original patched files with the .bak extension. i.e. if you see `domain.sh.bak_2023_06_10_21_02`, remove the existing `domain.sh` and rename `domain.sh.bak_2023_06_10_21_02` to `domain.sh`. Be sure to choose the .bak extension file with the most recent date if you see more than one. This will need to be performed for the following folders:

* /usr/local/hestia/data/templates/web/php-fpm
* /usr/local/hestia/php/lib
* /usr/local/hestia/func

2) Remove the pluginable files and the local.conf via sudo:

```
sudo rm /usr/local/hestia/bin/v-invoke-plugin
sudo rm /etc/hestiacp/local.conf
sudo rm /etc/hestiacp/hooks/*
```

3) Optionally remove Pluginable's data and plugin files:

```
sudo rm -rf /usr/local/hestia/plugins
sudo rm -rf /usr/local/hestia/data/hcpp
```

&nbsp;
&nbsp;

---

&nbsp;
&nbsp;

## Notable Plugins
A number of plugins that use HestiaCP-Pluginable have been updated to the 2.X API and authored by [Stephen J. Carnam @ Virtuosoft](https://virtuosoft.com/donate). They can be found under the HCPP prefix on Virtuosoft's GitHub repo:

* [HCPP-NodeApp](https://github.com/virtuosoft-dev/hcpp-nodeapp)

<!--
* [HCPP-WebDAV](https://github.com/virtuosoft-dev/hcpp-webdav)
* [HCPP-Collabora](https://github.com/virtuosoft-dev/hcpp-collabora)

*Important Note: The following plugins are dependent on [HCPP-NodeApp](https://github.com/virtuosoft-dev/hcpp-nodeapp), ensure you install [HCPP-NodeApp](https://github.com/virtuosoft-dev/hcpp-nodeapp) first!*

* [HCPP-MailCatcher](https://github.com/virtuosoft-dev/hcpp-mailcatcher)
* [HCPP-VitePress](https://github.com/virtuosoft-dev/hcpp-vitepress)
* [HCPP-NodeRED](https://github.com/virtuosoft-dev/hcpp-nodered)
* [HCPP-NodeBB](https://github.com/virtuosoft-dev/hcpp-nodebb)
* [HCPP-VSCode](https://github.com/virtuosoft-dev/hcpp-vscode)
* [HCPP-Ghost](https://github.com/virtuosoft-dev/hcpp-ghost)
* [HCPP-Go](https://github.com/virtuosoft-dev/hcpp-go)
-->

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

A plugin can hook and respond to actions that HestiaCP invokes whenever an API call or control panel web page is viewed. A simple hook that can intercept whenever the API call [v-list-users](https://hestiacp.com/docs/reference/cli.html#v-list-user) is invoked, either by the REST API or website control panel would look like:

```
<?php
/**
 * Plugin Name: Sample Plugin
 * Plugin URI: https://domain.tld/username/repo
 * Description: A sample plugin.
 */
global $hcpp;
$hcpp->add_action( 'v_list_users', function( $args ) {

    global $hcpp;
    $hcpp->logging = true;
    $hcpp->log( $args );
    return $args;

});
```

It is important that an $hcpp->add_action hook returns (passes along) the incoming argument (the `$args` parameter above). An optional third parameter can be passed for priority with the default being 10, [just like how WordPress does it](https://developer.wordpress.org/reference/functions/add_action/).

The above sample plugin will write the arguments to `/tmp/hcpp.log` (if logging is on, see 'Debug Logging' below). 

Note that the hook is the same name as the HestiaCP command line API *but with underscores in place of hyphens*.

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

Its not recommended to alter the existing files that HestiaCP comes with because they can be overwritten when HestiaCP self-updates. In those cases, (again, NOT recommended) you can utilitize HestiaCP Pluginable's API's `patch_file` function and `hcpp_post_install` action hook to re-apply any changes to core files. Care should be taken as this can become complicated and difficult to undo with plugin uninstallation (and if other plugins have applied changes prior). Because Pluginable itself has already patched a number of HestiaCP core files; chances are an action hook already exists for you to customize HestiaCP without the need to alter core files. 

&nbsp;
### Invoking Plugins via Hestia API
Plugins also have the ability to execute code on behalf of invoking Hestia's API. An additional bin file called `v-invoke-plugin` can take an arbitrary number of arguments and will in turn execute the `hcpp_invoke_plugin` action. A plugin author can subscribe to this message and execute custom code. Results can be returned, altering any values passed to other subscribers; or an author can echo results back to the caller as clear text or optionally, as JSON (or as JSON if an argument contains the string `json` as by convention like other Hestia API bin commands).
<br><br>
### Calling Other API Methods
You can run any of HestiaCP's API commands using the HCPP object's `run` method. For example, the following code will return an object (JSON already decoded) of all the users:

```
global $hcpp;
$all_users = $hcpp->run('v-list-users json');
```

You also have access to the `runuser` method that can be used to run any arbituary command as a given Linux user; the following would list the contents of the user's home folder:

```
global $hcpp;
$results = $hcpp->run( 'username', 'ls -laF' );
```

&nbsp;
### Noteworthy Action Hooks
You can invoke your plugins early by hooking the `hcpp_prepend` and/or `hcpp_ob_started` actions as these are fired with every UI screen of the HestiaCP web interface. You can scan the source code of pluginable.php and look for source that invokes the `do_action` method. Additional actions, their parameters, and their descriptions are listed below:

* `hcpp_prepend` - Occurs at start, when a HestiaCP UI's web page is requested.
* `hcpp_append` - Occurs at end, when a HestiaCP UI's web page is about to be sent.
* `hcpp_ob_started` - Occurs at after initial output buffer is started, when a HestiaCP UI's web page is requested.
* `hcpp_plugin_installed` - $plugin_name, Occurs when a plugin is installed.
* `hcpp_plugin_uninstalled` - $plugin_name, Occurs when a plugin is uninstalled.
* `hcpp_runuser` - [$user, $cmd], Occurs when $hcpp->runuser method is invoked.
* `hcpp_runuser_exec` - $cmd, Occurs when $hcpp->runuser method is invoked and the command is about to be executed.
* `hcpp_runuser_result` - $result, Occurs after $hcpp->runuser executes a command and the results are returned. 
* `hcpp_post_install` - Occurs when the HestiaCP system has been updated and a new version has finished installing. 
* `hcpp_rebooted` - Occurs after the operating system has been rebooted.
* `hcpp_plugin_enabled` - $plugin, Occurs when the given plugin has been enabled.
* `hcpp_plugin_disabled` - $plugin, Occurs when the given plugin has been disabled.

All HestiaCP web UI pages can be altered using the `_xpath` and `_html` based action hooks. For instance, when the user requests the URL from a HestiaCP instance at https://cp.example.com/list/web. Notice that the slashes after the domain has been changed to underscores and the `_xpath` and `_html` extension has been added to the action name. Therefore plugin developers can alter the output of the HestiaCP's listing of websites on the web tab by implementing hooks for one or more of the following actions:

* `list_web_xpath` - $xpath, Invoked when a HestiaCP web page is about to be sent; the $xpath contains a PHP DOMXPath object that can be used to modify the output.  
* `list_web_html` - $html, Invoked when a HestiaCP web page is about to be sent; the $html contains the raw HTML source code that can be modified before it is sent.  
* `hcpp_all_xpath` - $xpath, Occurs for every HestiaCP web page that is requested; the $xpath contains a PHP DOMXPath object that can be used to modify the output.
* `hcpp_all_html` - $html, Occurs for every HestiaCP web page that is requested; the $html contains the raw HTML source code that can be modified before it is sent.


All [HestiaCP CLI commands](https://hestiacp.com/docs/reference/cli.html) can be hooked by their given name. In most cases you can alter the parameters passed to the command before the command is actually executed. This powerful method allows plugins do alter or enhance the behavior of HestiaCP. 

For example: If the CLI command to list details of a user account by name were invoked via the example CLI: `v-list-user admin`; the following action hooks will be called:

* `v_list_user` - $args would contain the paramters passed to the command as an array; i.e. $args[0] would contain `admin` given the example above. It is important to return the $args array (with optional modifications) to be executed by HestiaCP's original CLI command.
* `v_list_user_output` - $output would contain the output of the HestiaCP's v-list-user CLI command. It is important to return the $output variable for callers to receive the results from invoking the original HestiaCP CLI command.

The example above illustrates how a HestiaCP Pluginable plugin can use action hooks to receive and alter arguments destined for HestiaCP's native CLI API as well as receive and alter the resultes of those commands before these are returned to the caller.

### Adding a Custom Page to Hestia's UI
Pluginable features an easy way to add a custom page to HestiaCP web UI, for example:

```
global $hcpp;
$hcpp->add_custom_page( 'nodeapp', __DIR__ . '/pages/nodeapp.php' );
```

This would add a custom page to display the contents of the nodeapp.php when the user visits the URL https://cp.example.com/?p=nodeapp. The standard HestiaCP header, menu tabs, and footer, stylesheets, etc. will automatically be prepended and appended to the output. The plugin developer only needs to worry about including the optional toolbar and container div tags. See the NodeApp's implementation of logs at: https://github.com/virtuosoft-dev/hcpp-nodeapp/blob/main/pages/nodeapplog.php

&nbsp;
### Hosted Site Prepends and Appends 
The HestiaCP Pluginable project includes special functionality for processing [PHP auto prepend and auto append directives](https://www.php.net/manual/en/ini.core.php#ini.auto-prepend-file) ***on the hosted sites***. This functionality allows a plugin to execute isolated code that is not apart of Hestia Control Panel actions, nor has access to the global $hcpp object; but rather as apart of all hosted sites running PHP. This feature is commonly used by anti-malware scanning applications (such as [WordFence](https://www.wordfence.com/help/firewall/optimizing-the-firewall/), [ISPProtect](https://ispprotect.com/ispprotect-bandaemon/), etc.), performance metric/tuning apps, or freemium hosting providers that wish to inject ads and other functionality into existing websites. 

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
### Automatic Updates
Plugins can leverage obtaining automatic updates from publicly hosted git repos (i.e. GitHub, GitLab, etc.). Plugins that are disabled from the configuration panel will not update. To implement this feature is simple; just provide a valid, publicly accesible `Plugin URI` field in the header of the `plugin.php` file. The most recent tag release that matches the nomenclature of `v#.#.#` (i.e. `v1.0.0`) will be queried and obtained on a daily basis. Matches that fail the expression (i.e. `v1.0.0-beta1` or `v2.0.1b3`) will be ignored.

The plugin folder must have been initially installed using git and therefore should have a .git folder present for automatic update checking to work. When the HCPP object's `public $logging = true` option is set (see next section ***Debug Logging***); update checking will occur at a higher frequency of every 5 minutes (vs once daily) to assist with testing.

An optional update script can be included with the plugin. Unlike the install and uninstall scripts; the update script does not need to be registered. Updates do not trigger the install script; but you may wish to invoke it on update's behalf. The update script will be passed two parameters; the current installed version (i.e. `v1.0.0`) and the newly installed version (i.e. `v2.0.0`). The optional update script is executed if present and only after after the repo has been updated. The update script feature allows plugin authors to make critical changes and apply patches if necessary to accomodate specific upgrade version migrations.


&nbsp;
### Debug Logging
You can view all the possible hook names that the hestiacp-pluginable API can respond to by turning logging on. Logging logs operations via $hcpp->log function to /tmp/hcpp.log. To turn on logging, simply include a file named logging in the hooks folder.

To turn on logging:
```
sudo touch /etc/hestiacp/hooks/logging
```

To turn off logging:
```
sudo rm /etc/hestiacp/hooks/logging
```

Optionally, remove the log file:
```
sudo rm /tmp/hcpp.log
```

Note: the hcpp.log file is automatically created for writing by both trusted root and admin users because Hestia sometimes executes privileged processes. Also, HestiaCP UI process does not have PHP access to /var/log/hestia due to open_basedir restrictions. /tmp/hcpp.log is a 'safe' file path. If you need to self-truncate the log simply use the command:

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
