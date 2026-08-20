#!/usr/bin/php
<?php
// Author: ServerOK
// Web: https://serverok.in
// Mail: admin@serverok.in
// Backup a web site in Nginx/Apache Server.

require_once __DIR__ . '/includes/functions.php';

if (posix_getuid() !== 0) {
    sokLog("This script must be run as root or with sudo.", true);
    exit(1);
}

if ($argc < 2) {
    sokLog("Usage: sok-site-backup <domain.tld>", true);
    exit(1);
}

$domainName = $argv[1];
$backupBaseDir = "/backup";
$siteDataDir = "/usr/serverok/sitedata";
$siteDataFile = "{$siteDataDir}/{$domainName}";

$timestamp = date('Y-m-d_H-i-s');

// Backups hold site files (wp-config.php and friends) and full database dumps.
// Each site runs as its own Linux user, so anything readable by "other" here
// would hand every site a copy of every other site's secrets. Keep it root-only.
umask(0077);

if (!is_dir($backupBaseDir) && !mkdir($backupBaseDir, 0700, true)) {
    sokLog("Error: Could not create backup directory {$backupBaseDir}", true);
    exit(1);
}

// Re-assert the mode in case the directory already existed with looser permissions.
$baseDirPerms = fileperms($backupBaseDir) & 0777;
if ($baseDirPerms !== 0700) {
    if (chmod($backupBaseDir, 0700)) {
        sokLog("Tightened permissions on {$backupBaseDir} from " . decoct($baseDirPerms) . " to 700", true);
    } else {
        sokLog("Warning: Could not set permissions 0700 on {$backupBaseDir}", true);
    }
}

// The timestamp is only accurate to the second, so two runs started together
// would otherwise fight over the same name and the loser would abort. mkdir()
// either creates the directory or fails, with nothing in between, so retrying
// on failure also settles a race between two processes.
$attempt = 0;

while (true) {
    $suffix = $attempt === 0 ? '' : "-{$attempt}";
    $backupName = "{$domainName}-{$timestamp}{$suffix}";
    $backupTempDir = "{$backupBaseDir}/{$backupName}";
    $finalBackupFile = "{$backupBaseDir}/{$backupName}.tar.gz";

    // The archive is checked too, so a finished backup from the same second is
    // never silently overwritten.
    if (!file_exists($finalBackupFile) && @mkdir($backupTempDir, 0700)) {
        break;
    }

    // Nothing is in the way, so this is a real failure such as a bad permission.
    if (!file_exists($backupTempDir) && !file_exists($finalBackupFile)) {
        sokLog("Error: Could not create temporary backup directory {$backupTempDir}", true);
        exit(1);
    }

    if (++$attempt > 100) {
        sokLog("Error: Could not find an unused backup name for {$domainName}", true);
        exit(1);
    }
}

if ($attempt > 0) {
    sokLog("A backup of {$domainName} already exists for this second, using '{$backupName}' instead.", true);
}

$siteInfo = getSiteInfo($domainName, $siteDataFile);
if (empty($siteInfo)) {
    sokLog("Error: Could not gather site information for {$domainName}", true);
    cleanupAndExit($backupTempDir);
}
sokLog("Successfully gathered site information for {$domainName}: " . json_encode($siteInfo));


// 3. Make sure the archive can actually fit before doing any work.
sokLog("\nChecking free disk space for {$domainName}...", true);
if (!checkFreeSpace($siteInfo, $backupBaseDir)) {
    cleanupAndExit($backupTempDir);
}

$failedSteps = [];

// 4. Dump the database into the staging directory. It is left uncompressed
// because the final archive compresses everything in one pass.
sokLog("\nStarting database backup for {$domainName}...", true);
if (!backupDatabase($siteInfo, $backupTempDir)) {
    $failedSteps[] = 'database';
}

// 5. Backup web server config
sokLog("Starting web server config backup for {$domainName}...", true);
backupWebServerConfig($siteInfo, $backupTempDir);

// 6. Backup PHP-FPM config
sokLog("Starting PHP-FPM config backup for {$domainName}...", true);
backupPhpFpmConfig($siteInfo, $backupTempDir);

// 7. Pack the site files and the staging directory into one archive. The site
// files go straight in from /home, so nothing is compressed twice.
sokLog("Creating final backup archive for {$domainName}...", true);
if (!createFinalArchive($siteInfo, $backupTempDir, $finalBackupFile)) {
    $failedSteps[] = 'archive';
}

// 8. Cleanup
if (!empty($failedSteps)) {
    sokLog("Error: Backup FAILED for {$domainName} (" . implode(', ', $failedSteps) . ").", true);
    sokLog("Temporary files kept for inspection at {$backupTempDir}", true);
    exit(1);
}

cleanupAndExit($backupTempDir, false);

sokLog("Backup completed successfully for {$domainName}!", true);
exit(0);


// --- Functions ---

function getPathSize($path) {
    if (!file_exists($path)) {
        return 0;
    }

    $output = [];
    $returnVar = 1;
    exec("du -sb " . escapeshellarg($path) . " 2>/dev/null", $output, $returnVar);
    if ($returnVar !== 0 || empty($output[0])) {
        return 0;
    }

    return (int) strtok($output[0], "\t");
}

function getDatabaseSize($dbName) {
    if (empty($dbName)) {
        return 0;
    }

    $mysqli = getMysqlConnection();
    if ($mysqli === null) {
        return 0;
    }

    try {
        $stmt = $mysqli->prepare("SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = ?");
        $stmt->bind_param('s', $dbName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        return (int) ($row[0] ?? 0);
    } catch (Throwable $e) {
        sokLog("Warning: Could not determine the size of database '{$dbName}': " . $e->getMessage(), true);
        return 0;
    }
}

function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 1) . ' ' . $units[$i];
}

function checkFreeSpace($siteInfo, $backupBaseDir) {
    $filesSize = getPathSize($siteInfo['homedir']);
    $dbSize = getDatabaseSize($siteInfo['dbname'] ?? null);
    $required = $filesSize + $dbSize;
    $free = disk_free_space($backupBaseDir);

    if ($free === false) {
        sokLog("Warning: Could not determine free space on {$backupBaseDir}, continuing anyway.", true);
        return true;
    }

    sokLog("Site is " . formatBytes($filesSize) . " of files plus " . formatBytes($dbSize) . " of database, " . formatBytes($free) . " free on {$backupBaseDir}.", true);

    // The archive is compressed, so this is a worst case figure: it assumes the
    // data does not shrink at all. Refusing here beats running out of room half
    // way through and leaving a truncated archive behind.
    if ($required > 0 && $free < $required) {
        sokLog("Error: Not enough free space on {$backupBaseDir}. Need up to " . formatBytes($required) . " uncompressed but only " . formatBytes($free) . " is available.", true);
        return false;
    }

    return true;
}

function getSiteInfo($domainName, $siteDataFile) {
    $data = [];

    // The site data file was only introduced recently, so for older sites it is
    // missing. It can also be corrupt or written by an earlier version that did
    // not store every field, so treat it as a hint and detect whatever is absent.
    if (file_exists($siteDataFile)) {
        $decoded = json_decode(file_get_contents($siteDataFile), true);
        if (is_array($decoded)) {
            $data = $decoded;
            sokLog("Site data loaded from {$siteDataFile} for {$domainName}", true);
        } else {
            sokLog("Warning: {$siteDataFile} is not valid JSON. Detecting site information instead.", true);
        }
    } else {
        sokLog("Site data file not found for {$domainName}. Attempting to infer information...", true);
    }

    $data['servername'] = $domainName;
    $data['homedir'] = "/home/{$domainName}";

    // The Linux user is the one thing we cannot work without: it names the
    // PHP-FPM pool, the database and the files we are about to archive.
    if (empty($data['username'])) {
        $data['username'] = getUsernameFromHomeDir($domainName);
    }
    if (empty($data['username'])) {
        sokLog("Error: Could not determine the Linux user that owns {$domainName}.", true);
        return [];
    }
    $username = $data['username'];

    if (empty($data['documentroot'])) {
        $data['documentroot'] = "/home/{$domainName}/html";
    }

    // A missing PHP version only costs us the pool file, so it is not fatal.
    if (empty($data['php_version'])) {
        $data['php_version'] = findPhpVersionForUser($username);
    }

    // A database name recorded in the site data file can be stale, so confirm it
    // really exists before trusting it.
    if (!empty($data['dbname']) && !databaseExists($data['dbname'])) {
        sokLog("Warning: Database '{$data['dbname']}' from the site data file does not exist. Searching for the real one.", true);
        $data['dbname'] = null;
    }
    if (empty($data['dbname'])) {
        $data['dbname'] = findDatabaseForSite($username, $data['homedir'], $data['documentroot']);
    }

    // "This site has no database" and "MySQL could not be reached" both leave
    // dbname empty, but only the first one is safe to skip over.
    $data['db_lookup_failed'] = empty($data['dbname']) && getMysqlConnection() === null;

    return $data;
}

function getMysqlConnection() {
    static $mysqli = null;
    static $attempted = false;

    if ($attempted) {
        return $mysqli;
    }
    $attempted = true;

    // Since PHP 8.1 mysqli throws on failure instead of setting connect_error,
    // so the connection has to be wrapped rather than checked afterwards.
    try {
        $mysqli = new mysqli('localhost', 'root', '');
    } catch (Throwable $e) {
        sokLog("Warning: Could not connect to MySQL: " . $e->getMessage(), true);
        $mysqli = null;
    }

    return $mysqli;
}

function databaseExists($dbName) {
    if (empty($dbName)) {
        return false;
    }

    $mysqli = getMysqlConnection();
    if ($mysqli === null) {
        return false;
    }

    try {
        $stmt = $mysqli->prepare("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
        $stmt->bind_param('s', $dbName);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $exists;
    } catch (Throwable $e) {
        sokLog("Warning: Could not verify database '{$dbName}': " . $e->getMessage(), true);
        return false;
    }
}

function stripPhpComments($contents) {
    // A commented out define() must not win over the real one, so let PHP's own
    // tokenizer tell us which parts of the file are comments.
    $tokens = @token_get_all($contents);
    if (!is_array($tokens)) {
        return $contents;
    }

    $stripped = '';
    foreach ($tokens as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $stripped .= $token[1];
        } else {
            $stripped .= $token;
        }
    }

    return $stripped;
}

function getDbNameFromWpConfig($homeDir, $docRoot) {
    // WordPress also allows wp-config.php to sit one level above the document root.
    $candidates = [
        rtrim($docRoot, '/') . '/wp-config.php',
        dirname(rtrim($docRoot, '/')) . '/wp-config.php',
        rtrim($homeDir, '/') . '/html/wp-config.php',
        rtrim($homeDir, '/') . '/wp-config.php',
    ];

    foreach (array_unique($candidates) as $wpConfig) {
        if (!is_readable($wpConfig)) {
            continue;
        }
        $contents = stripPhpComments(file_get_contents($wpConfig));
        if (preg_match('/define\s*\(\s*([\'"])DB_NAME\1\s*,\s*([\'"])(.*?)\2\s*\)/s', $contents, $m)) {
            sokLog("Found database '{$m[3]}' in {$wpConfig}.", true);
            return $m[3];
        }
    }

    return null;
}

function findDatabaseForSite($username, $homeDir, $docRoot) {
    // wp-config.php is authoritative when it is there, so try it first.
    $dbName = getDbNameFromWpConfig($homeDir, $docRoot);
    if ($dbName) {
        if (databaseExists($dbName)) {
            return $dbName;
        }
        sokLog("Warning: Database '{$dbName}' from wp-config.php does not exist on this server.", true);
    }

    // Otherwise fall back to the naming conventions this panel creates sites with.
    foreach (["{$username}_db", "{$username}_wp"] as $candidate) {
        if (databaseExists($candidate)) {
            sokLog("Found database '{$candidate}' for user '{$username}'.", true);
            return $candidate;
        }
    }

    sokLog("Warning: No database found for user '{$username}'.", true);
    return null;
}

function getUsernameFromHomeDir($domainName) {
    $homeDir = "/home/{$domainName}";
    if (!is_dir($homeDir)) {
        sokLog("Error: Home directory {$homeDir} not found.", true);
        return null;
    }
    $ownerId = fileowner($homeDir);
    $ownerInfo = posix_getpwuid($ownerId);
    if ($ownerInfo) {
        sokLog("Found username '{$ownerInfo['name']}' from home directory owner.", true);
        return $ownerInfo['name'];
    }
    sokLog("Error: Could not determine owner of {$homeDir}.", true);
    return null;
}

function findPhpVersionForUser($username) {
    $phpBaseDir = '/etc/php/';
    if (!is_dir($phpBaseDir)) {
        sokLog("Error: PHP directory {$phpBaseDir} not found.", true);
        return null;
    }

    $phpVersions = scandir($phpBaseDir);
    foreach ($phpVersions as $version) {
        if (is_dir("{$phpBaseDir}/{$version}") && preg_match('/^\d\.\d$/', $version)) {
            $poolFile = "{$phpBaseDir}/{$version}/fpm/pool.d/{$username}.conf";
            if (file_exists($poolFile)) {
                sokLog("Found PHP version '{$version}' for user '{$username}'.", true);
                return $version;
            }
        }
    }
    sokLog("Warning: Could not find a PHP-FPM pool file for user {$username}.", true);
    return null;
}

function backupDatabase($siteInfo, $backupDir) {
    if (empty($siteInfo['dbname'])) {
        if (!empty($siteInfo['db_lookup_failed'])) {
            sokLog("Error: Could not reach MySQL to determine this site's database.", true);
            return false;
        }
        sokLog("No database associated with this site, skipping database backup.", true);
        return true;
    }

    $dbName = $siteInfo['dbname'];
    $sqlFile = "{$backupDir}/database_{$dbName}.sql";

    sokLog("Backing up database '{$dbName}'...", true);

    // Dumped uncompressed on purpose: the final archive gzips it along with
    // everything else, so compressing here would only mean doing it twice.
    // Piping into gzip would also hide mysqldump's exit code.
    // Note: This assumes root can connect to MySQL without a password.
    $command = "mysqldump " . escapeshellarg($dbName) . " 2>&1 > " . escapeshellarg($sqlFile);
    if (!runCommand($command, "Dumping database '{$dbName}'")) {
        sokLog("Error: Database backup failed for {$dbName}. mysqldump returned an error.", true);
        if (file_exists($sqlFile)) unlink($sqlFile);
        return false;
    }

    if (!file_exists($sqlFile) || filesize($sqlFile) === 0) {
        sokLog("Error: Database backup failed for {$dbName}. The dump is empty or could not be created.", true);
        if (file_exists($sqlFile)) unlink($sqlFile);
        return false;
    }

    sokLog("Database backup created successfully for {$dbName}.", true);
    return true;
}

function backupWebServerConfig($siteInfo, $backupDir) {
    $domainName = $siteInfo['servername'];
    $configBackupDir = "{$backupDir}/config/webserver";
    mkdir($configBackupDir, 0700, true);

    sokLog("Backing up web server config for '{$domainName}'...", true);
    
    // Check for Nginx
    $nginxConf = "/etc/nginx/sites-enabled/{$domainName}.conf";
    if (file_exists($nginxConf)) {
        copy($nginxConf, "{$configBackupDir}/nginx_{$domainName}.conf");
        sokLog("Nginx config backed up for {$domainName}.", true);
    }

    // Check for Apache
    $apacheConf = "/etc/apache2/sites-enabled/{$domainName}.conf";
    if (file_exists($apacheConf)) {
        copy($apacheConf, "{$configBackupDir}/apache_{$domainName}.conf");
        sokLog("Apache config backed up for {$domainName}.", true);
    }
}

function backupPhpFpmConfig($siteInfo, $backupDir) {
    $username = $siteInfo['username'];

    if (empty($siteInfo['php_version'])) {
        sokLog("No PHP version known for user '{$username}', skipping PHP-FPM config backup.", true);
        return;
    }

    $phpVersion = $siteInfo['php_version'];
    $configBackupDir = "{$backupDir}/config/php-fpm";
    mkdir($configBackupDir, 0700, true);

    sokLog("Backing up PHP-FPM config for user '{$username}'...", true);
    $poolFile = "/etc/php/{$phpVersion}/fpm/pool.d/{$username}.conf";
    if (file_exists($poolFile)) {
        copy($poolFile, "{$configBackupDir}/{$username}.conf");
        sokLog("PHP-FPM config backed up for {$username}.", true);
    } else {
        sokLog("Warning: PHP-FPM pool file not found at {$poolFile}.", true);
    }
}

function createFinalArchive($siteInfo, $stagingDir, $finalBackupFile) {
    $homeDir = $siteInfo['homedir'];

    sokLog("Writing final backup archive to '{$finalBackupFile}'...", true);

    if (!is_dir($homeDir)) {
        sokLog("Error: Home directory {$homeDir} not found.", true);
        return false;
    }

    // One tar invocation, two source directories: the live site files straight
    // out of /home, and the staged database dump and configs. Everything is
    // compressed exactly once.
    $command = "tar -czpf " . escapeshellarg($finalBackupFile)
        . " -C " . escapeshellarg(dirname($homeDir)) . " " . escapeshellarg(basename($homeDir))
        . " -C " . escapeshellarg($stagingDir) . " ."
        . " 2>&1";

    if (!runCommand($command, "Creating the backup archive") || !file_exists($finalBackupFile)) {
        sokLog("Error: Final backup archive creation failed.", true);
        return false;
    }

    chmod($finalBackupFile, 0600);

    // A tar that cannot be listed is not a backup.
    if (!runCommand("tar -tzf " . escapeshellarg($finalBackupFile) . " 2>&1 > /dev/null", "Verifying the backup archive")) {
        sokLog("Error: Final backup archive is unreadable: {$finalBackupFile}", true);
        return false;
    }

    sokLog("Final backup archive created successfully: {$finalBackupFile} (" . formatBytes(filesize($finalBackupFile)) . ")", true);
    return true;
}

function cleanupAndExit($tempDir, $exitWithError = true) {
    sokLog("Cleaning up temporary files...", true);
    runCommand("rm -rf " . escapeshellarg($tempDir) . " 2>&1", "Removing the temporary directory");
    if ($exitWithError) {
        exit(1);
    }
}

?>
