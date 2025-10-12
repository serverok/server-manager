#!/usr/bin/php
<?php
// Author: ServerOK
// Web: https://serverok.in
// Mail: admin@serverok.in
// Change PHP version for a website

require_once __DIR__ . '/includes/functions.php';

if (posix_getuid() !== 0) {
    sokLog("This script must be run as root or with sudo.", true);
    exit(1);
}

function verifyPhpVersion($phpVersion) {
    $phpSocket = "/var/run/php/php" . $phpVersion . "-fpm.sock";
    if (!file_exists($phpSocket)) {
        sokLog("ERROR: PHP version {$phpVersion} not found. Missing socket {$phpSocket}", true);
        exit(1);
    }
    return true;
}

function getSiteInfo($domainName) {
    $siteDataFile = "/usr/serverok/sitedata/{$domainName}";
    
    if (!file_exists($siteDataFile)) {
        sokLog("ERROR: Site data file not found for {$domainName}", true);
        exit(1);
    }
    
    $data = json_decode(file_get_contents($siteDataFile), true);
    if (!$data || !isset($data['username']) || !isset($data['php_version'])) {
        sokLog("ERROR: Invalid site data file for {$domainName}", true);
        exit(1);
    }
    
    return $data;
}

function updateSiteDataFile($domainName, $newPhpVersion) {
    $siteDataFile = "/usr/serverok/sitedata/{$domainName}";
    
    $data = json_decode(file_get_contents($siteDataFile), true);
    $data['php_version'] = $newPhpVersion;
    $data['php_version_updated'] = date('Y-m-d H:i:s');
    
    $jsonData = json_encode($data, JSON_PRETTY_PRINT);
    file_put_contents($siteDataFile, $jsonData);
    
    sokLog("Updated site data file with new PHP version: {$newPhpVersion}");
}

// Parse command line options
$options = getopt("d:", ["domain:", "php:"]);

if (isset($options['d'])) {
    $domainName = trim($options['d']);
} elseif (isset($options['domain'])) {
    $domainName = trim($options['domain']);
} else {
    sokLog("ERROR: Please specify domain with -d or --domain option.", true);
    sokLog("Usage: change-php-version -d <domain> --php <version>", true);
    exit(1);
}

if (!isset($options['php'])) {
    sokLog("ERROR: Please specify PHP version with --php option.", true);
    sokLog("Usage: change-php-version -d <domain> --php <version>", true);
    exit(1);
}

$newPhpVersion = trim($options['php']);

// Verify new PHP version exists
verifyPhpVersion($newPhpVersion);

// Get site information
sokLog("Getting site information for {$domainName}...", true);
$siteInfo = getSiteInfo($domainName);
$username = $siteInfo['username'];
$currentPhpVersion = $siteInfo['php_version'];

sokLog("Current PHP version: {$currentPhpVersion}", true);
sokLog("New PHP version: {$newPhpVersion}", true);

if ($currentPhpVersion === $newPhpVersion) {
    sokLog("Site is already using PHP {$newPhpVersion}. No changes needed.", true);
    exit(0);
}

// Check if current PHP-FPM pool file exists
$currentPoolFile = "/etc/php/{$currentPhpVersion}/fpm/pool.d/{$username}.conf";
if (!file_exists($currentPoolFile)) {
    sokLog("WARNING: Current PHP-FPM pool file not found at {$currentPoolFile}", true);
} else {
    sokLog("Found current PHP-FPM pool file: {$currentPoolFile}", true);
}

// Move PHP-FPM pool file to new location
$newPoolFile = "/etc/php/{$newPhpVersion}/fpm/pool.d/{$username}.conf";

if (file_exists($currentPoolFile)) {
    sokLog("Moving PHP-FPM pool file from {$currentPhpVersion} to {$newPhpVersion}...", true);
    
    // Copy the pool file to new location
    if (!copy($currentPoolFile, $newPoolFile)) {
        sokLog("ERROR: Failed to copy pool file to {$newPoolFile}", true);
        exit(1);
    }
    sokLog("Pool file copied to {$newPoolFile}", true);
    
    // Remove old pool file
    unlink($currentPoolFile);
    sokLog("Removed old pool file: {$currentPoolFile}", true);
} else {
    // Create new pool file from template
    sokLog("Creating new PHP-FPM pool file at {$newPoolFile}...", true);
    $templateFile = __DIR__ . "/templates/php-fpm-pool.conf";
    
    if (!file_exists($templateFile)) {
        sokLog("ERROR: Template file not found at {$templateFile}", true);
        exit(1);
    }
    
    $content = file_get_contents($templateFile);
    $content = str_replace("POOL_NAME", $username, $content);
    $content = str_replace("FPM_USER", $username, $content);
    file_put_contents($newPoolFile, $content);
    sokLog("Created new pool file from template", true);
}

// Restart PHP-FPM services
sokLog("Restarting PHP-FPM services...", true);

// Restart current PHP version (if pool file existed)
if (file_exists("/var/run/php/php{$currentPhpVersion}-fpm.sock")) {
    sokLog("Restarting PHP {$currentPhpVersion} FPM...", true);
    shell_exec("systemctl restart php{$currentPhpVersion}-fpm");
    sokLog("PHP {$currentPhpVersion} FPM restarted", true);
}

// Restart new PHP version
sokLog("Restarting PHP {$newPhpVersion} FPM...", true);
shell_exec("systemctl restart php{$newPhpVersion}-fpm");
sokLog("PHP {$newPhpVersion} FPM restarted", true);

// Update site data file
sokLog("Updating site data file...", true);
updateSiteDataFile($domainName, $newPhpVersion);

sokLog("PHP version changed successfully from {$currentPhpVersion} to {$newPhpVersion} for {$domainName}!", true);
sokLog("Please verify the site is working correctly.", true);

