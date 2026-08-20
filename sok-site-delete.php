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

$usage = "Usage: php sok-site-delete.php <domain.tld> [--yes] [--skip-backup]\n"
    . "  --yes, -y      Do not ask for confirmation.\n"
    . "  --skip-backup  Delete without taking a backup first.";

$positional = [];
$assumeYes = false;
$skipBackup = false;

foreach (array_slice($argv, 1) as $arg) {
    switch ($arg) {
        case '--yes':
        case '-y':
            $assumeYes = true;
            break;
        case '--skip-backup':
            $skipBackup = true;
            break;
        default:
            if ($arg !== '' && $arg[0] === '-') {
                sokLog("Unknown option '{$arg}'.\n{$usage}", true);
                exit(1);
            }
            $positional[] = $arg;
    }
}

if (count($positional) !== 1) {
    sokLog($usage, true);
    exit(1);
}

$domainName = $positional[0];
$backupBaseDir = "/backup";
$siteDataFile = "/usr/serverok/sitedata/{$domainName}";

$siteDocRoot = "/home/{$domainName}";

if (! is_dir($siteDocRoot)) {
    die("Site {$domainName} not found.\n");
}

sokLog("--- Starting deletion process for {$domainName} ---", true);

sokLog("\nStep 1: Gathering site information for {$domainName}...", true);

$siteInfo = getSiteInfo($domainName, $siteDataFile);

if (empty($siteInfo)) {
    $msg = "FATAL: Could not gather required site information for {$domainName}. Aborting.";
    sokLog($msg, true);
    exit(1);
}

sokLog("Successfully gathered site information for {$domainName}: " . json_encode($siteInfo));

// Deletion is not a transaction: once the Nginx config is unlinked and the user
// is gone there is no rolling back. Everything that can be checked up front is
// checked here, so a predictable failure cannot leave a half deleted site.
sokLog("\nStep 2: Running pre-flight checks for {$domainName}...", true);

// getWebServer() aborts the script when it cannot detect a running web server.
// Calling it here keeps that abort ahead of the first unlink().
$siteInfo['webserver'] = getWebServer();

$problems = runPreflightChecks($siteInfo);

if (!empty($problems)) {
    sokLog("FATAL: Pre-flight checks failed for {$domainName}. Nothing has been deleted.", true);
    foreach ($problems as $problem) {
        sokLog("  - {$problem}", true);
    }
    exit(1);
}

sokLog("Pre-flight checks passed for {$domainName}.", true);

// Asked before the backup runs, so nobody waits through a long archive only to
// find out they were about to delete the wrong site.
if (!$assumeYes && !confirmDeletion($siteInfo, $skipBackup)) {
    sokLog("Aborted by user. Nothing has been deleted.", true);
    exit(1);
}

sokLog("\nStep 3: Backing up {$domainName}...", true);

if ($skipBackup) {
    sokLog("WARNING: --skip-backup was given, so {$domainName} is being deleted without a backup.", true);
} elseif (!backupSite($domainName)) {
    sokLog("FATAL: Backup failed for {$domainName}. Nothing has been deleted.", true);
    sokLog("Fix the reported problem and run this again, or pass --skip-backup to delete without one.", true);
    exit(1);
} else {
    $backupFile = findLatestBackup($backupBaseDir, $domainName);
    sokLog("Backup completed" . ($backupFile ? ": {$backupFile}" : "") . ".", true);
}

sokLog("\nStep 4: Removing site components for {$domainName}...", true);

removeWebServerConfig($siteInfo);
removePhpFpmConfig($siteInfo);
removeMysqlDatabaseAndUser($siteInfo);
removeLinuxUser($siteInfo);

sokLog("\n--- Site {$domainName} has been successfully deleted. ---", true);

if (!$skipBackup && !empty($backupFile)) {
    sokLog("A backup of the deleted site is at {$backupFile}", true);
}


function confirmDeletion($siteInfo, $skipBackup) {
    $domainName = $siteInfo['servername'];

    sokLog("\nThe following will be permanently deleted:", true);
    sokLog("  Files     : {$siteInfo['homedir']}", true);
    sokLog("  Database  : " . ($siteInfo['dbname'] ?: 'none'), true);
    sokLog("  Linux user: {$siteInfo['username']}", true);
    sokLog("  Web server and PHP-FPM configuration for {$domainName}", true);

    if ($skipBackup) {
        sokLog("\n  NO BACKUP WILL BE TAKEN (--skip-backup).", true);
    }

    // Without a terminal there is nobody to answer, and guessing "yes" on a
    // userdel -r is not a guess worth making.
    if (!posix_isatty(STDIN)) {
        sokLog("\nNot running interactively. Pass --yes to confirm this deletion.", true);
        return false;
    }

    echo PHP_EOL . "Type the domain name to confirm deletion: ";
    $answer = trim((string) fgets(STDIN));

    if ($answer !== $domainName) {
        sokLog("Confirmation did not match '{$domainName}'.", true);
        return false;
    }

    return true;
}

function findLatestBackup($backupBaseDir, $domainName) {
    $matches = glob("{$backupBaseDir}/{$domainName}-*.tar.gz");
    if (empty($matches)) {
        return null;
    }

    usort($matches, function ($a, $b) {
        return filemtime($b) <=> filemtime($a);
    });

    return $matches[0];
}

function runPreflightChecks($siteInfo) {
    $problems = [];

    if (empty($siteInfo['username'])) {
        $problems[] = "Could not determine the Linux user that owns this site.";
    } elseif (posix_getpwnam($siteInfo['username']) === false) {
        // Not fatal: the files may still be there even if the account is gone.
        sokLog("Warning: Linux user '{$siteInfo['username']}' does not exist.", true);
    }

    if (empty($siteInfo['dbname'])) {
        $problems[] = "Could not determine the database name for this site.";
    }

    // Connect now rather than after the web server and PHP-FPM configs have
    // already been unlinked.
    $mysqli = getMysqlConnection();
    if ($mysqli === null) {
        $problems[] = "Cannot connect to MySQL, so the database could not be dropped.";
    } elseif (!empty($siteInfo['dbname'])) {
        try {
            $stmt = $mysqli->prepare("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
            $stmt->bind_param('s', $siteInfo['dbname']);
            $stmt->execute();
            $exists = $stmt->get_result()->num_rows > 0;
            $stmt->close();

            if ($exists) {
                sokLog("Database `{$siteInfo['dbname']}` found and MySQL is reachable.", true);
            } else {
                // Already gone, so there is nothing to drop and nothing to stop.
                sokLog("Warning: Database `{$siteInfo['dbname']}` does not exist, it may already have been removed.", true);
            }
        } catch (Throwable $e) {
            $problems[] = "Could not query MySQL: " . $e->getMessage();
        }
    }

    return $problems;
}

function getMysqlConnection() {
    static $mysqli = null;
    static $attempted = false;

    if ($attempted) {
        return $mysqli;
    }
    $attempted = true;

    // Since PHP 8.1 mysqli throws on failure instead of setting connect_error,
    // so an unreachable server has to be caught rather than checked afterwards.
    try {
        $mysqli = new mysqli('localhost', 'root', '');
    } catch (Throwable $e) {
        sokLog("Error: MySQL connection failed: " . $e->getMessage(), true);
        $mysqli = null;
    }

    return $mysqli;
}

function backupSite($domainName) {
    $backupScript = __DIR__ . '/sok-site-backup.php';
    if (!file_exists($backupScript)) {
        $msg = "Error: Backup script '{$backupScript}' not found.";
        sokLog($msg, true);
        return false;
    }
    
    // PHP_BINARY is the interpreter running this script, which is not
    // necessarily the "php" that happens to be first on root's PATH.
    $command = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($backupScript) . " " . escapeshellarg($domainName);
    passthru($command, $return_var);

    return $return_var === 0;
}

function getSiteInfo($domainName, $siteDataFile) {
    if (file_exists($siteDataFile)) {
        $data = json_decode(file_get_contents($siteDataFile), true);
        if (isset($data['username'])) {
            sokLog("Site data loaded from {$siteDataFile} for {$domainName}");
            // The account is created with -d /home/{domain}, so the home
            // directory is named after the site and not after the user.
            $data['homedir'] = "/home/{$domainName}";
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
        'homedir' => "/home/{$domainName}",
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

    // Resolved during the pre-flight checks.
    $webServer = $siteInfo['webserver'] ?? getWebServer();

    $nginxConf = "/etc/nginx/sites-enabled/{$domainName}.conf";
    if (file_exists($nginxConf)) {
        sokLog("Removing Nginx config: {$nginxConf}", true);
        unlink($nginxConf);
        $configRemoved = true;
        
        if ($webServer === 'nginx') {
            runCommand("systemctl restart nginx 2>&1", "Restarting Nginx");
            sokLog("Restarted Nginx after removing config for {$domainName}");
        }
    }

    $apacheConf = "/etc/apache2/sites-enabled/{$domainName}.conf";
    if (file_exists($apacheConf)) {
        sokLog("Removing Apache config: {$apacheConf}", true);
        unlink($apacheConf);
        $configRemoved = true;
        
        if ($webServer === 'apache' || $webServer === 'apache2') {
            runCommand("systemctl restart apache2 2>&1", "Restarting Apache");
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
        runCommand("systemctl restart php{$phpVersion}-fpm 2>&1", "Restarting PHP {$phpVersion} FPM");
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

    // Already proven reachable by the pre-flight checks.
    $mysqli = getMysqlConnection();
    if ($mysqli === null) {
        sokLog("Error: MySQL connection unavailable, skipping database removal.", true);
        return;
    }

    try {
        sokLog("Dropping MySQL database `{$dbName}`...", true);
        $mysqli->query("DROP DATABASE IF EXISTS `{$dbName}`");

        sokLog("Dropping MySQL user '{$dbUser}'@'localhost'...", true);
        $mysqli->query("DROP USER IF EXISTS '{$dbUser}'@'localhost'");

        $mysqli->query("FLUSH PRIVILEGES");
        sokLog("MySQL cleanup complete for {$dbName}", true);
    } catch (Throwable $e) {
        sokLog("Error: MySQL cleanup failed for {$dbName}: " . $e->getMessage(), true);
    }
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
        return;
    }

    sokLog("Deleting user '{$username}' and their home directory...", true);

    // userdel -r is what removes the site files, so a silent failure here would
    // leave the whole home directory behind while reporting success.
    if (!runCommand("userdel -r " . escapeshellarg($username) . " 2>&1", "Deleting user '{$username}'")) {
        sokLog("Error: User '{$username}' was not removed. Home directory may still exist.", true);
        return;
    }

    // userdel usually takes the matching group with it, so a failure here is
    // normal and only worth a note.
    if (!runCommand("groupdel " . escapeshellarg($username) . " 2>&1", "Deleting group '{$username}'")) {
        sokLog("Group '{$username}' was not removed separately, it was most likely removed with the user.", true);
    }

    sokLog("User '{$username}' removed successfully", true);
}
