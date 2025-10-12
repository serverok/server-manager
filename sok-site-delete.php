#!/usr/bin/php
<?php
// Author: ServerOK
// Web: https://serverok.in
// Mail: admin@serverok.in
// Delete a web site after taking a backup.

require_once __DIR__ . '/includes/functions.php';

if (posix_getuid() !== 0) {
    $msg = "This script must be run as root or with sudo.";
    sokLog($msg, true);
    exit(1);
}

if ($argc < 2) {
    $msg = "Usage: php sok-site-delete.php <domain.tld>";
    sokLog($msg, true);
    exit(1);
}

$domainName = $argv[1];
$siteDataFile = "/usr/serverok/sitedata/{$domainName}";

// --- Main Execution ---

sokLog("--- Starting deletion process for {$domainName} ---", true);

// 1. Take a backup first
sokLog("\nStep 1: Attempting to back up the site before deletion...", true);
if (!backupSite($domainName)) {
    $msg = "FATAL: Backup failed for {$domainName}. Aborting deletion process to prevent data loss.";
    sokLog($msg, true);
    exit(1);
}
sokLog("Backup completed successfully for {$domainName}", true);

// 2. Gather site information
sokLog("\nStep 2: Gathering site information for {$domainName}...", true);
$siteInfo = getSiteInfo($domainName, $siteDataFile);
if (empty($siteInfo)) {
    $msg = "FATAL: Could not gather required site information for {$domainName}. Aborting.";
    sokLog($msg, true);
    exit(1);
}
sokLog("Successfully gathered site information for {$domainName}: " . json_encode($siteInfo));


// 3. Remove Web Server Config
sokLog("\nStep 3: Removing web server configuration for {$domainName}...", true);
removeWebServerConfig($siteInfo);

// 4. Remove PHP-FPM Config
sokLog("\nStep 4: Removing PHP-FPM configuration for {$domainName}...", true);
removePhpFpmConfig($siteInfo);

// 5. Remove MySQL Database and User
sokLog("\nStep 5: Removing MySQL database and user for {$domainName}...", true);
removeMysqlDatabaseAndUser($siteInfo);

// 6. Remove Linux User and Files
sokLog("\nStep 6: Removing Linux user and all associated files for {$domainName}...", true);
removeLinuxUser($siteInfo);

sokLog("\n--- Site {$domainName} has been successfully deleted. ---", true);


// --- Functions ---

function backupSite($domainName) {
    $backupScript = __DIR__ . '/sok-site-backup.php';
    if (!file_exists($backupScript)) {
        $msg = "Error: Backup script '{$backupScript}' not found.";
        sokLog($msg, true);
        return false;
    }
    
    $command = "php " . escapeshellarg($backupScript) . " " . escapeshellarg($domainName);
    passthru($command, $return_var);

    return $return_var === 0;
}

function getSiteInfo($domainName, $siteDataFile) {
    if (file_exists($siteDataFile)) {
        $data = json_decode(file_get_contents($siteDataFile), true);
        if (isset($data['username'])) {
            sokLog("Site data loaded from {$siteDataFile} for {$domainName}");
            return $data;
        }
    }

    sokLog("Site data file not found or incomplete for {$domainName}. Attempting to infer information...", true);
    
    $username = getUsernameFromHomeDir($domainName);
    if (!$username) {
        sokLog("Failed to get username from home directory for {$domainName}");
        return [];
    }

    $phpVersion = findPhpVersionForUser($username);
    if (!$phpVersion) {
        sokLog("Failed to find PHP version for user {$username}");
        return [];
    }

    return [
        'servername' => $domainName,
        'username' => $username,
        'dbname' => "{$username}_db",
        'php_version' => $phpVersion,
    ];
}

function getUsernameFromHomeDir($domainName) {
    $homeDir = "/home/{$domainName}";
    if (!is_dir($homeDir)) {
        // If the domain is the username itself
        $homeDirUser = "/home/{$domainName}";
        if (is_dir($homeDirUser)) {
             $ownerId = fileowner($homeDirUser);
             $ownerInfo = posix_getpwuid($ownerId);
             if ($ownerInfo) return $ownerInfo['name'];
        }
        $msg = "Error: Home directory not found for {$domainName}.";
        sokLog($msg, true);
        return null;
    }
    $ownerId = fileowner($homeDir);
    $ownerInfo = posix_getpwuid($ownerId);
    if ($ownerInfo) {
        sokLog("Found username '{$ownerInfo['name']}' from home directory owner for {$domainName}", true);
        return $ownerInfo['name'];
    }
    $msg = "Error: Could not determine owner of {$homeDir}.";
    sokLog($msg, true);
    return null;
}

function findPhpVersionForUser($username) {
    $phpBaseDir = '/etc/php/';
    if (!is_dir($phpBaseDir)) return null;

    $phpVersions = scandir($phpBaseDir);
    foreach ($phpVersions as $version) {
        if (is_dir("{$phpBaseDir}/{$version}") && preg_match('/^\d\.\d$/', $version)) {
            $poolFile = "{$phpBaseDir}/{$version}/fpm/pool.d/{$username}.conf";
            if (file_exists($poolFile)) {
                sokLog("Found PHP version {$version} for user {$username}");
                return $version;
            }
        }
    }
    return null;
}

function removeWebServerConfig($siteInfo) {
    $domainName = $siteInfo['servername'];
    $configRemoved = false;

    // Get the configured web server
    $webServer = getWebServer();

    $nginxConf = "/etc/nginx/sites-enabled/{$domainName}.conf";
    if (file_exists($nginxConf)) {
        sokLog("Removing Nginx config: {$nginxConf}", true);
        unlink($nginxConf);
        $configRemoved = true;
        
        if ($webServer === 'nginx') {
            shell_exec("systemctl restart nginx");
            sokLog("Restarted Nginx after removing config for {$domainName}");
        }
    }

    $apacheConf = "/etc/apache2/sites-enabled/{$domainName}.conf";
    if (file_exists($apacheConf)) {
        sokLog("Removing Apache config: {$apacheConf}", true);
        unlink($apacheConf);
        $configRemoved = true;
        
        if ($webServer === 'apache' || $webServer === 'apache2') {
            shell_exec("systemctl restart apache2");
            sokLog("Restarted Apache after removing config for {$domainName}");
        }
    }

    if (!$configRemoved) {
        sokLog("No web server config found for {$domainName}", true);
    }
    
    if ($configRemoved && !$webServer) {
        sokLog("Web server config removed but server not restarted (no webserver configured in config file)");
    }
}

function removePhpFpmConfig($siteInfo) {
    if (!isset($siteInfo['username']) || !isset($siteInfo['php_version'])) {
        $msg = "Warning: Username or PHP version not defined, cannot remove PHP-FPM config.";
        sokLog($msg, true);
        return;
    }
    $username = $siteInfo['username'];
    $phpVersion = $siteInfo['php_version'];
    
    $poolFile = "/etc/php/{$phpVersion}/fpm/pool.d/{$username}.conf";
    if (file_exists($poolFile)) {
        sokLog("Removing PHP-FPM config: {$poolFile}", true);
        unlink($poolFile);
        shell_exec("systemctl restart php{$phpVersion}-fpm");
        sokLog("Restarted PHP {$phpVersion} FPM after removing config for {$username}");
    } else {
        sokLog("No PHP-FPM config found for user {$username} with PHP version {$phpVersion}", true);
    }
}

function removeMysqlDatabaseAndUser($siteInfo) {
    if (!isset($siteInfo['dbname'])) {
        $msg = "Warning: DB name not defined, cannot remove MySQL database/user.";
        sokLog($msg, true);
        return;
    }
    $dbName = $siteInfo['dbname'];
    $dbUser = $siteInfo['dbname'];

    $mysqli = new mysqli('localhost', 'root', '');
    if ($mysqli->connect_error) {
        $msg = "Error: MySQL Connection failed: " . $mysqli->connect_error;
        sokLog($msg, true);
        return;
    }

    sokLog("Dropping MySQL database `{$dbName}`...", true);
    $mysqli->query("DROP DATABASE IF EXISTS `{$dbName}`");

    sokLog("Dropping MySQL user '{$dbUser}'@'localhost'...", true);
    $mysqli->query("DROP USER IF EXISTS '{$dbUser}'@'localhost'");
    
    $mysqli->query("FLUSH PRIVILEGES");
    $mysqli->close();
    sokLog("MySQL cleanup complete for {$dbName}", true);
}

function removeLinuxUser($siteInfo) {
    if (!isset($siteInfo['username'])) {
        $msg = "Warning: Username not defined, cannot remove Linux user.";
        sokLog($msg, true);
        return;
    }
    $username = $siteInfo['username'];

    if (posix_getpwnam($username) === false) {
        sokLog("Linux user {$username} does not exist. Skipping.", true);
        exit;
    }

    sokLog("Deleting user '{$username}' and their home directory...", true);
    $command = "userdel -r " . escapeshellarg($username);
    shell_exec($command);
    sokLog("User '{$username}' removed successfully", true);
}

?>
