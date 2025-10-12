#!/usr/bin/php
<?php
// Author: ServerOK
// Web: https://serverok.in
// Mail: admin@serverok.in
// Delete a web site after taking a backup.

require_once __DIR__ . '/includes/functions.php';

if (posix_getuid() !== 0) {
    $msg = "This script must be run as root or with sudo.";
    sok_log($msg, true);
    exit(1);
}

if ($argc < 2) {
    $msg = "Usage: php sok-site-delete.php <domain.tld>";
    sok_log($msg, true);
    exit(1);
}

$domainName = $argv[1];
$siteDataFile = "/usr/serverok/sitedata/{$domainName}";

// --- Main Execution ---

sok_log("--- Starting deletion process for {$domainName} ---", true);

// 1. Take a backup first
sok_log("\nStep 1: Attempting to back up the site before deletion...", true);
if (!backupSite($domainName)) {
    $msg = "FATAL: Backup failed for {$domainName}. Aborting deletion process to prevent data loss.";
    sok_log($msg, true);
    exit(1);
}
sok_log("Backup completed successfully for {$domainName}", true);

// 2. Gather site information
sok_log("\nStep 2: Gathering site information for {$domainName}...", true);
$siteInfo = getSiteInfo($domainName, $siteDataFile);
if (empty($siteInfo)) {
    $msg = "FATAL: Could not gather required site information for {$domainName}. Aborting.";
    sok_log($msg, true);
    exit(1);
}
sok_log("Successfully gathered site information for {$domainName}: " . json_encode($siteInfo));


// 3. Remove Web Server Config
sok_log("\nStep 3: Removing web server configuration for {$domainName}...", true);
removeWebServerConfig($siteInfo);

// 4. Remove PHP-FPM Config
sok_log("\nStep 4: Removing PHP-FPM configuration for {$domainName}...", true);
removePhpFpmConfig($siteInfo);

// 5. Remove MySQL Database and User
sok_log("\nStep 5: Removing MySQL database and user for {$domainName}...", true);
removeMysqlDatabaseAndUser($siteInfo);

// 6. Remove Linux User and Files
sok_log("\nStep 6: Removing Linux user and all associated files for {$domainName}...", true);
removeLinuxUser($siteInfo);

sok_log("\n--- Site {$domainName} has been successfully deleted. ---", true);


// --- Functions ---

function backupSite($domainName) {
    $backupScript = __DIR__ . '/sok-site-backup.php';
    if (!file_exists($backupScript)) {
        $msg = "Error: Backup script '{$backupScript}' not found.";
        sok_log($msg, true);
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
            sok_log("Site data loaded from {$siteDataFile} for {$domainName}");
            return $data;
        }
    }

    sok_log("Site data file not found or incomplete for {$domainName}. Attempting to infer information...", true);
    
    $username = getUsernameFromHomeDir($domainName);
    if (!$username) {
        sok_log("Failed to get username from home directory for {$domainName}");
        return [];
    }

    $phpVersion = findPhpVersionForUser($username);
    if (!$phpVersion) {
        sok_log("Failed to find PHP version for user {$username}");
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
        sok_log($msg, true);
        return null;
    }
    $ownerId = fileowner($homeDir);
    $ownerInfo = posix_getpwuid($ownerId);
    if ($ownerInfo) {
        sok_log("Found username '{$ownerInfo['name']}' from home directory owner for {$domainName}", true);
        return $ownerInfo['name'];
    }
    $msg = "Error: Could not determine owner of {$homeDir}.";
    sok_log($msg, true);
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
                sok_log("Found PHP version {$version} for user {$username}");
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
    $webServer = getConfiguredWebServer();

    $nginxConf = "/etc/nginx/sites-enabled/{$domainName}.conf";
    if (file_exists($nginxConf)) {
        sok_log("Removing Nginx config: {$nginxConf}", true);
        unlink($nginxConf);
        $configRemoved = true;
        
        if ($webServer === 'nginx') {
            shell_exec("systemctl restart nginx");
            sok_log("Restarted Nginx after removing config for {$domainName}");
        }
    }

    $apacheConf = "/etc/apache2/sites-enabled/{$domainName}.conf";
    if (file_exists($apacheConf)) {
        sok_log("Removing Apache config: {$apacheConf}", true);
        unlink($apacheConf);
        $configRemoved = true;
        
        if ($webServer === 'apache' || $webServer === 'apache2') {
            shell_exec("systemctl restart apache2");
            sok_log("Restarted Apache after removing config for {$domainName}");
        }
    }

    if (!$configRemoved) {
        sok_log("No web server config found for {$domainName}", true);
    }
    
    if ($configRemoved && !$webServer) {
        sok_log("Web server config removed but server not restarted (no webserver configured in config file)");
    }
}

function removePhpFpmConfig($siteInfo) {
    if (!isset($siteInfo['username']) || !isset($siteInfo['php_version'])) {
        $msg = "Warning: Username or PHP version not defined, cannot remove PHP-FPM config.";
        sok_log($msg, true);
        return;
    }
    $username = $siteInfo['username'];
    $phpVersion = $siteInfo['php_version'];
    
    $poolFile = "/etc/php/{$phpVersion}/fpm/pool.d/{$username}.conf";
    if (file_exists($poolFile)) {
        sok_log("Removing PHP-FPM config: {$poolFile}", true);
        unlink($poolFile);
        shell_exec("systemctl restart php{$phpVersion}-fpm");
        sok_log("Restarted PHP {$phpVersion} FPM after removing config for {$username}");
    } else {
        sok_log("No PHP-FPM config found for user {$username} with PHP version {$phpVersion}", true);
    }
}

function removeMysqlDatabaseAndUser($siteInfo) {
    if (!isset($siteInfo['dbname'])) {
        $msg = "Warning: DB name not defined, cannot remove MySQL database/user.";
        sok_log($msg, true);
        return;
    }
    $dbName = $siteInfo['dbname'];
    $dbUser = $siteInfo['dbname'];

    $mysqli = new mysqli('localhost', 'root', '');
    if ($mysqli->connect_error) {
        $msg = "Error: MySQL Connection failed: " . $mysqli->connect_error;
        sok_log($msg, true);
        return;
    }

    sok_log("Dropping MySQL database `{$dbName}`...", true);
    $mysqli->query("DROP DATABASE IF EXISTS `{$dbName}`");

    sok_log("Dropping MySQL user '{$dbUser}'@'localhost'...", true);
    $mysqli->query("DROP USER IF EXISTS '{$dbUser}'@'localhost'");
    
    $mysqli->query("FLUSH PRIVILEGES");
    $mysqli->close();
    sok_log("MySQL cleanup complete for {$dbName}", true);
}

function removeLinuxUser($siteInfo) {
    if (!isset($siteInfo['username'])) {
        $msg = "Warning: Username not defined, cannot remove Linux user.";
        sok_log($msg, true);
        return;
    }
    $username = $siteInfo['username'];

    if (posix_getpwnam($username) === false) {
        sok_log("Linux user {$username} does not exist. Skipping.", true);
        exit;
    }

    sok_log("Deleting user '{$username}' and their home directory...", true);
    $command = "userdel -r " . escapeshellarg($username);
    shell_exec($command);
    sok_log("User '{$username}' removed successfully", true);
}

?>
