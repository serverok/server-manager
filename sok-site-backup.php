#!/usr/bin/php
<?php
// Author: ServerOK
// Web: https://serverok.in
// Mail: admin@serverok.in
// Backup a web site in Nginx/Apache Server.

require_once __DIR__ . '/includes/functions.php';

if (posix_getuid() !== 0) {
    sok_log("This script must be run as root or with sudo.", true);
    exit(1);
}

if ($argc < 2) {
    sok_log("Usage: php sok-site-backup.php <domain.tld>", true);
    exit(1);
}

$domainName = $argv[1];
$backupBaseDir = "/usr/serverok/backup";
$siteDataDir = "/usr/serverok/sitedata";
$siteDataFile = "{$siteDataDir}/{$domainName}";

// --- Main Execution ---

// 1. Create a temporary directory for the backup
$timestamp = date('Y-m-d_H-i-s');
$backupTempDir = "{$backupBaseDir}/{$domainName}-{$timestamp}";
if (!mkdir($backupTempDir, 0755, true)) {
    sok_log("Error: Could not create temporary backup directory {$backupTempDir}", true);
    exit(1);
}
sok_log("Created temporary backup directory: {$backupTempDir}", true);


// 2. Gather site information
sok_log("Gathering site information for {$domainName}...", true);
$siteInfo = getSiteInfo($domainName, $siteDataFile);
if (empty($siteInfo)) {
    sok_log("Error: Could not gather site information for {$domainName}", true);
    cleanupAndExit($backupTempDir);
}
sok_log("Successfully gathered site information for {$domainName}: " . json_encode($siteInfo));


// 3. Backup files
sok_log("Starting file backup for {$domainName}...", true);
backupFiles($siteInfo, $backupTempDir);

// 4. Backup database
sok_log("Starting database backup for {$domainName}...", true);
backupDatabase($siteInfo, $backupTempDir);

// 5. Backup web server config
sok_log("Starting web server config backup for {$domainName}...", true);
backupWebServerConfig($siteInfo, $backupTempDir);

// 6. Backup PHP-FPM config
sok_log("Starting PHP-FPM config backup for {$domainName}...", true);
backupPhpFpmConfig($siteInfo, $backupTempDir);

// 7. Compress the final backup
sok_log("Compressing final backup for {$domainName}...", true);
compressBackup($backupTempDir, $backupBaseDir, $domainName, $timestamp);

// 8. Cleanup
cleanupAndExit($backupTempDir, false);

sok_log("Backup completed successfully for {$domainName}!", true);


// --- Functions ---

function getSiteInfo($domainName, $siteDataFile) {
    if (file_exists($siteDataFile)) {
        $data = json_decode(file_get_contents($siteDataFile), true);
        // Add home directory for convenience
        $data['homedir'] = "/home/" . ($data['username'] ?? $domainName);
        return $data;
    }

    sok_log("Site data file not found for {$domainName}. Attempting to infer information...", true);
    
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
        'homedir' => "/home/{$domainName}",
        'documentroot' => "/home/{$domainName}/html",
        'dbname' => "{$username}_db",
        'php_version' => $phpVersion,
    ];
}

function getUsernameFromHomeDir($domainName) {
    $homeDir = "/home/{$domainName}";
    if (!is_dir($homeDir)) {
        sok_log("Error: Home directory {$homeDir} not found.", true);
        return null;
    }
    $ownerId = fileowner($homeDir);
    $ownerInfo = posix_getpwuid($ownerId);
    if ($ownerInfo) {
        sok_log("Found username '{$ownerInfo['name']}' from home directory owner.", true);
        return $ownerInfo['name'];
    }
    sok_log("Error: Could not determine owner of {$homeDir}.", true);
    return null;
}

function findPhpVersionForUser($username) {
    $phpBaseDir = '/etc/php/';
    if (!is_dir($phpBaseDir)) {
        sok_log("Error: PHP directory {$phpBaseDir} not found.", true);
        return null;
    }

    $phpVersions = scandir($phpBaseDir);
    foreach ($phpVersions as $version) {
        if (is_dir("{$phpBaseDir}/{$version}") && preg_match('/^\d\.\d$/', $version)) {
            $poolFile = "{$phpBaseDir}/{$version}/fpm/pool.d/{$username}.conf";
            if (file_exists($poolFile)) {
                sok_log("Found PHP version '{$version}' for user '{$username}'.", true);
                return $version;
            }
        }
    }
    sok_log("Error: Could not find a PHP-FPM pool file for user {$username}.", true);
    return null;
}

function backupFiles($siteInfo, $backupDir) {
    $username = $siteInfo['username'];
    $homeDir = $siteInfo['homedir'];
    $targetFile = "{$backupDir}/files_{$username}.tar.gz";
    
    sok_log("Backing up files for user '{$username}' from '{$homeDir}'...", true);
    $command = "tar -czpf " . escapeshellarg($targetFile) . " -C " . escapeshellarg(dirname($homeDir)) . " " . escapeshellarg(basename($homeDir));
    shell_exec($command);

    if (file_exists($targetFile)) {
        sok_log("File backup created successfully for {$username}.", true);
    } else {
        sok_log("Error: File backup failed for {$username}.", true);
    }
}

function backupDatabase($siteInfo, $backupDir) {
    $dbName = $siteInfo['dbname'];
    $targetFile = "{$backupDir}/database_{$dbName}.sql.gz";

    sok_log("Backing up database '{$dbName}'...", true);
    // Note: This assumes root can connect to MySQL without a password.
    $command = "mysqldump " . escapeshellarg($dbName) . " | gzip > " . escapeshellarg($targetFile);
    shell_exec($command);

    if (file_exists($targetFile) && filesize($targetFile) > 0) {
        sok_log("Database backup created successfully for {$dbName}.", true);
    } else {
        sok_log("Error: Database backup failed for {$dbName}. The file is empty or could not be created.", true);
        // Clean up empty file
        if (file_exists($targetFile)) unlink($targetFile);
    }
}

function backupWebServerConfig($siteInfo, $backupDir) {
    $domainName = $siteInfo['servername'];
    $configBackupDir = "{$backupDir}/config/webserver";
    mkdir($configBackupDir, 0755, true);

    sok_log("Backing up web server config for '{$domainName}'...", true);
    
    // Check for Nginx
    $nginxConf = "/etc/nginx/sites-enabled/{$domainName}.conf";
    if (file_exists($nginxConf)) {
        copy($nginxConf, "{$configBackupDir}/nginx_{$domainName}.conf");
        sok_log("Nginx config backed up for {$domainName}.", true);
    }

    // Check for Apache
    $apacheConf = "/etc/apache2/sites-enabled/{$domainName}.conf";
    if (file_exists($apacheConf)) {
        copy($apacheConf, "{$configBackupDir}/apache_{$domainName}.conf");
        sok_log("Apache config backed up for {$domainName}.", true);
    }
}

function backupPhpFpmConfig($siteInfo, $backupDir) {
    $username = $siteInfo['username'];
    $phpVersion = $siteInfo['php_version'];
    $configBackupDir = "{$backupDir}/config/php-fpm";
    mkdir($configBackupDir, 0755, true);

    sok_log("Backing up PHP-FPM config for user '{$username}'...", true);
    $poolFile = "/etc/php/{$phpVersion}/fpm/pool.d/{$username}.conf";
    if (file_exists($poolFile)) {
        copy($poolFile, "{$configBackupDir}/{$username}.conf");
        sok_log("PHP-FPM config backed up for {$username}.", true);
    } else {
        sok_log("Warning: PHP-FPM pool file not found at {$poolFile}.", true);
    }
}

function compressBackup($sourceDir, $targetBaseDir, $domainName, $timestamp) {
    $finalBackupFile = "{$targetBaseDir}/{$domainName}-{$timestamp}.tar.gz";
    sok_log("Compressing final backup archive to '{$finalBackupFile}'...", true);
    
    $command = "tar -czpf " . escapeshellarg($finalBackupFile) . " -C " . escapeshellarg(dirname($sourceDir)) . " " . escapeshellarg(basename($sourceDir));
    shell_exec($command);

    if (file_exists($finalBackupFile)) {
        sok_log("Final backup archive created successfully: {$finalBackupFile}", true);
    } else {
        sok_log("Error: Final backup archive creation failed.", true);
    }
}

function cleanupAndExit($tempDir, $exitWithError = true) {
    sok_log("Cleaning up temporary files...", true);
    shell_exec("rm -rf " . escapeshellarg($tempDir));
    if ($exitWithError) {
        exit(1);
    }
}

?>
