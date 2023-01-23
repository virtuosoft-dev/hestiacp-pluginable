#!/bin/bash

user=$1
domain=$2

# Wait for up to 15 seconds for the conf folder and public_html folder to be created
for i in {1..15}; do

    if [ -d "/home/$user/conf/web/$domain" ] && [ -d "/home/$user/web/$domain/public_html" ]; then

        # Invoke pluginable.php again with v-invoke-plugin (that will then throw new_web_domain_ready action hook )
        /usr/local/hestia/bin/v-invoke-plugin new_web_domain_ready $user $domain
        exit 0;
    fi
    sleep 1
done

echo "Error: $folder not found or not writable" >&2
exit 1
