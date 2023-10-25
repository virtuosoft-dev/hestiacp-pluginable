#!/bin/bash

## Comment out disable_functions in php.ini 
## (undo https://github.com/hestiacp/hestiacp/blob/main/CHANGELOG.md#1810---service-release)
sed -i 's/^disable_functions =/;disable_functions =/g' /etc/php/*/fpm/php.ini
sed -i 's/^disable_functions =/;disable_functions =/g' /etc/php/*/cli/php.ini

## Execute the patch files script
php -f /etc/hestiacp/hooks/patch_files.php
