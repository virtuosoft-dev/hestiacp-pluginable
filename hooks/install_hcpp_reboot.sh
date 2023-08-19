#!/bin/bash

# Define the path to the target script
target_script="/usr/local/bin/hcpp_rebooted.sh"

# Check if the target script already exists
if [ ! -f "$target_script" ]; then
    cat <<EOT >> "$target_script"
#!/bin/bash

# Wait for the mount point to be available
while [ ! -d "/media/appFolder" ]; do
    sleep 1
done

# Throw a reboot event to trigger the HCPP rebooted hook
/usr/local/hestia/bin/v-invoke-plugin hcpp_rebooted

EOT
    chmod +x "$target_script"
    echo "Installed hcpp_rebooted.sh"
else
    echo "hcpp_rebooted.sh already exists. Skipping installation."
fi

# Check if the service file already exists
service_file="/etc/systemd/system/hcpp_rebooted.service"
if [ ! -f "$service_file" ]; then
    cat <<EOT >> "$service_file"
[Unit]
Description=Trigger hcpp_rebooted hook
After=network.target

[Service]
Type=oneshot
ExecStartPre=/bin/sleep 10
ExecStart=$target_script

[Install]
WantedBy=multi-user.target

EOT
    systemctl enable hcpp_rebooted.service
    echo "Installed hcpp_rebooted.service"
else
    echo "hcpp_rebooted.service already exists. Skipping installation."
fi
