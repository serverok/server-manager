#!/usr/bin/php
<?php
// Author: ServerOK
// Web: https://serverok.in
// Mail: admin@serverok.in
// Delete a web site after taking a backup.

if (posix_getuid() !== 0) {
    echo "This script must be run as root or with sudo.\n";
    exit(1);
}

if ($argc < 2) {
    echo "Usage: php sok-site-delete.php <domain.tld>\n";
    exit(1);
}

$domainName = $argv[1];
$siteDataFile = "/usr/serverok/sitedata/{$domainName}";

// --- Main Execution ---

echo "--- Starting deletion process for {$domainName} ---\n";

// 1. Take a backup first
echo "\nStep 1: Attempting to back up the site before deletion...\n";
if (!backupSite($domainName)) {
    echo "FATAL: Backup failed. Aborting deletion process to prevent data loss.\n";
    exit(1);
}
echo "Backup completed successfully.\n";

// 2. Gather site information
echo "\nStep 2: Gathering site information...\n";
$siteInfo = getSiteInfo($domainName, $siteDataFile);
if (empty($siteInfo)) {
    echo "FATAL: Could not gather required site information for {$domainName}. Aborting.\n";
    exit(1);
}
echo "Successfully gathered site information.\n";
print_r($siteInfo);

// 3. Remove Web Server Config
echo "\nStep 3: Removing web server configuration...\n";
removeWebServerConfig($siteInfo);

// 4. Remove PHP-FPM Config
echo "\nStep 4: Removing PHP-FPM configuration...\n";
removePhpFpmConfig($siteInfo);

// 5. Remove MySQL Database and User
echo "\nStep 5: Removing MySQL database and user...\n";
removeMysqlDatabaseAndUser($siteInfo);

// 6. Remove Linux User and Files
echo "\nStep 6: Removing Linux user and all associated files...\n";
removeLinuxUser($siteInfo);

echo "\n--- Site {$domainName} has been successfully deleted. ---\n";


// --- Functions ---

function backupSite($domainName) {
    $backupScript = __DIR__ . '/sok-site-backup.php';
    if (!file_exists($backupScript)) {
        echo "Error: Backup script '{$backupScript}' not found.\n";
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
            return $data;
        }
    }

    echo "Site data file not found or incomplete. Attempting to infer information...\n";
    
    $username = getUsernameFromHomeDir($domainName);
    if (!$username) return [];

    $phpVersion = findPhpVersionForUser($username);
    if (!$phpVersion) return [];

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
        echo "Error: Home directory not found for {$domainName}.\n";
        return null;
    }
    $ownerId = fileowner($homeDir);
    $ownerInfo = posix_getpwuid($ownerId);
    if ($ownerInfo) {
        echo "Found username '{$ownerInfo['name']}' from home directory owner.\n";
        return $ownerInfo['name'];
    }
    echo "Error: Could not determine owner of {$homeDir}.\n";
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
                return $version;
            }
        }
    }
    return null; // Return null if not found
}

function removeWebServerConfig($siteInfo) {
    $domainName = $siteInfo['servername'];
    $restartedNginx = false;
    $restartedApache = false;

    $nginxConf = "/etc/nginx/sites-enabled/{$domainName}.conf";
    if (file_exists($nginxConf)) {
        echo "Removing Nginx config: {$nginxConf}\n";
        unlink($nginxConf);
        shell_exec("systemctl restart nginx");
        $restartedNginx = true;
    }

    $apacheConf = "/etc/apache2/sites-enabled/{$domainName}.conf";
    if (file_exists($apacheConf)) {
        echo "Removing Apache config: {$apacheConf}\n";
        unlink($apacheConf);
        shell_exec("systemctl restart apache2");
        $restartedApache = true;
    }

    if (!$restartedNginx && !$restartedApache) {
        echo "No web server config found for {$domainName}.\n";
    }
}

function removePhpFpmConfig($siteInfo) {
    if (!isset($siteInfo['username']) || !isset($siteInfo['php_version'])) {
        echo "Warning: Username or PHP version not defined, cannot remove PHP-FPM config.\n";
        return;
    }
    $username = $siteInfo['username'];
    $phpVersion = $siteInfo['php_version'];
    
    $poolFile = "/etc/php/{$phpVersion}/fpm/pool.d/{$username}.conf";
    if (file_exists($poolFile)) {
        echo "Removing PHP-FPM config: {$poolFile}\n";
        unlink($poolFile);
        shell_exec("systemctl restart php{$phpVersion}-fpm");
    } else {
        echo "No PHP-FPM config found for user {$username} with PHP version {$phpVersion}.\n";
    }
}

function removeMysqlDatabaseAndUser($siteInfo) {
    if (!isset($siteInfo['dbname'])) {
        echo "Warning: DB name not defined, cannot remove MySQL database/user.\n";
        return;
    }
    $dbName = $siteInfo['dbname'];
    $dbUser = $siteInfo['dbname']; // Assuming db user is same as db name

    $mysqli = new mysqli('localhost', 'root', '');
    if ($mysqli->connect_error) {
        echo "Error: MySQL Connection failed: " . $mysqli->connect_error . "\n";
        return;
    }

    echo "Dropping MySQL database `{$dbName}`...\n";
    $mysqli->query("DROP DATABASE IF EXISTS `{$dbName}`");

    echo "Dropping MySQL user '{$dbUser}'@'localhost'...\n";
    $mysqli->query("DROP USER IF EXISTS '{$dbUser}'@'localhost'");
    
    $mysqli->query("FLUSH PRIVILEGES");
    $mysqli->close();
    echo "MySQL cleanup complete.\n";
}

function removeLinuxUser($siteInfo) {
    if (!isset($siteInfo['username'])) {
        echo "Warning: Username not defined, cannot remove Linux user.\n";
        return;
    }
    $username = $siteInfo['username'];

    if (posix_getpwnam($username) === false) {
        echo "Linux user {$username} does not exist. Skipping.\n";
        exit;
    }

    echo "Deleting user '{$username}' and their home directory...\n";
    $command = "userdel -r " . escapeshellarg($username);
    shell_exec($command);
    echo "User '{$username}' removed.\n";
}

?>
