#!/usr/bin/php
<?php
// Author: ServerOK
// Web: https://serverok.in
// Mail: admin@serverok.in
// Change PHP version for a website

require_once __DIR__ . '/includes/functions.php';

if (posix_getuid() !== 0) {
    sok_log("This script must be run as root or with sudo.", true);
    exit(1);
}

function verifyPhpVersion($phpVersion) {
    $phpSocket = "/var/run/php/php" . $phpVersion . "-fpm.sock";
    if (!file_exists($phpSocket)) {
        sok_log("ERROR: PHP version {$phpVersion} not found. Missing socket {$phpSocket}", true);
        exit(1);
    }
    return true;
}

function getSiteInfo($domainName) {
    $siteDataFile = "/usr/serverok/sitedata/{$domainName}";
    
    if (!file_exists($siteDataFile)) {
        sok_log("ERROR: Site data file not found for {$domainName}", true);
        exit(1);
    }
    
    $data = json_decode(file_get_contents($siteDataFile), true);
    if (!$data || !isset($data['username']) || !isset($data['php_version'])) {
        sok_log("ERROR: Invalid site data file for {$domainName}", true);
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
    
    sok_log("Updated site data file with new PHP version: {$newPhpVersion}");
}

// Parse command line options
$options = getopt("d:", ["domain:", "php:"]);

if (isset($options['d'])) {
    $domainName = trim($options['d']);
} elseif (isset($options['domain'])) {
    $domainName = trim($options['domain']);
} else {
    sok_log("ERROR: Please specify domain with -d or --domain option.", true);
    sok_log("Usage: change-php-version -d <domain> --php <version>", true);
    exit(1);
}

if (!isset($options['php'])) {
    sok_log("ERROR: Please specify PHP version with --php option.", true);
    sok_log("Usage: change-php-version -d <domain> --php <version>", true);
    exit(1);
}

$newPhpVersion = trim($options['php']);

// Verify new PHP version exists
verifyPhpVersion($newPhpVersion);

// Get site information
sok_log("Getting site information for {$domainName}...", true);
$siteInfo = getSiteInfo($domainName);
$username = $siteInfo['username'];
$currentPhpVersion = $siteInfo['php_version'];

sok_log("Current PHP version: {$currentPhpVersion}", true);
sok_log("New PHP version: {$newPhpVersion}", true);

if ($currentPhpVersion === $newPhpVersion) {
    sok_log("Site is already using PHP {$newPhpVersion}. No changes needed.", true);
    exit(0);
}

// Check if current PHP-FPM pool file exists
$currentPoolFile = "/etc/php/{$currentPhpVersion}/fpm/pool.d/{$username}.conf";
if (!file_exists($currentPoolFile)) {
    sok_log("WARNING: Current PHP-FPM pool file not found at {$currentPoolFile}", true);
} else {
    sok_log("Found current PHP-FPM pool file: {$currentPoolFile}", true);
}

// Move PHP-FPM pool file to new location
$newPoolFile = "/etc/php/{$newPhpVersion}/fpm/pool.d/{$username}.conf";

if (file_exists($currentPoolFile)) {
    sok_log("Moving PHP-FPM pool file from {$currentPhpVersion} to {$newPhpVersion}...", true);
    
    // Copy the pool file to new location
    if (!copy($currentPoolFile, $newPoolFile)) {
        sok_log("ERROR: Failed to copy pool file to {$newPoolFile}", true);
        exit(1);
    }
    sok_log("Pool file copied to {$newPoolFile}", true);
    
    // Remove old pool file
    unlink($currentPoolFile);
    sok_log("Removed old pool file: {$currentPoolFile}", true);
} else {
    // Create new pool file from template
    sok_log("Creating new PHP-FPM pool file at {$newPoolFile}...", true);
    $templateFile = __DIR__ . "/templates/php-fpm-pool.conf";
    
    if (!file_exists($templateFile)) {
        sok_log("ERROR: Template file not found at {$templateFile}", true);
        exit(1);
    }
    
    $content = file_get_contents($templateFile);
    $content = str_replace("POOL_NAME", $username, $content);
    $content = str_replace("FPM_USER", $username, $content);
    file_put_contents($newPoolFile, $content);
    sok_log("Created new pool file from template", true);
}

// Restart PHP-FPM services
sok_log("Restarting PHP-FPM services...", true);

// Restart current PHP version (if pool file existed)
if (file_exists("/var/run/php/php{$currentPhpVersion}-fpm.sock")) {
    sok_log("Restarting PHP {$currentPhpVersion} FPM...", true);
    shell_exec("systemctl restart php{$currentPhpVersion}-fpm");
    sok_log("PHP {$currentPhpVersion} FPM restarted", true);
}

// Restart new PHP version
sok_log("Restarting PHP {$newPhpVersion} FPM...", true);
shell_exec("systemctl restart php{$newPhpVersion}-fpm");
sok_log("PHP {$newPhpVersion} FPM restarted", true);

// Update site data file
sok_log("Updating site data file...", true);
updateSiteDataFile($domainName, $newPhpVersion);

sok_log("PHP version changed successfully from {$currentPhpVersion} to {$newPhpVersion} for {$domainName}!", true);
sok_log("Please verify the site is working correctly.", true);

