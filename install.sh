#!/bin/bash

# Exit immediately if a command exits with a non-zero status.
set -e

# Check if running as root
if [ "$(id -u)" -ne 0 ]; then
  echo "This script must be run as root or with sudo." >&2
  exit 1
fi

# Create the directory if it doesn't exist
if [ ! -d "/usr/serverok/" ]; then
  echo "Creating directory /usr/serverok/"
  mkdir -p /usr/serverok/
fi

# Install git
echo "Updating package list and installing git..."
apt-get update
apt-get install -y git

# Clone the repository or pull updates
if [ ! -d "/usr/serverok/server-manager" ]; then
  echo "Cloning server-manager repository..."
  git clone https://github.com/serverok/server-manager /usr/serverok/server-manager
else
  echo "Directory /usr/serverok/server-manager already exists. Pulling latest changes..."
  cd /usr/serverok/server-manager
  git pull
fi

cd /tmp
wget https://cdn.serverok.in/sok-log-analyzer.tgz -O sok-log-analyzer.tgz 
tar -xzvf sok-log-analyzer.tgz
mv sok-log-analyzer /usr/local/bin/
rm -f sok-log-analyzer.tgz

echo "Making scripts in /usr/serverok/server-manager/bin executable..."
chmod +x /usr/serverok/server-manager/bin/*

rm -f /usr/local/bin/sok-site-add

echo "Creating symlinks in /usr/local/bin..."
for file in /usr/serverok/server-manager/bin/*; do
  if [ -f "$file" ]; then
    ln -sf "$file" "/usr/local/bin/$(basename "$file")"
  fi
done

echo "Installation complete."