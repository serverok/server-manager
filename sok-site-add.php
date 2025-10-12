#!/usr/bin/php
<?php
// Author: ServerOK
// Web: https://serverok.in
// Mail: admin@serverok.in
// Create web site in Nginx/Apache Server.

require_once 'includes/functions.php';

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

function findLatestPhpVersion() {
    $phpRunDir = '/var/run/php/';
    if (!is_dir($phpRunDir)) {
        return null;
    }
    $files = scandir($phpRunDir);
    $versions = [];

    if ($files === false) {
        return null;
    }

    foreach ($files as $file) {
        if (preg_match('/^php(\d+\.\d+)-fpm\.sock$/', $file, $matches)) {
            $versions[] = $matches[1];
        }
    }

    if (empty($versions)) {
        return null;
    }

    usort($versions, 'version_compare');
    
    return end($versions);
}

function generatePassword() {
    $lowercase = 'abcdefghjkmnpqrstuvwxyz';
    $uppercase = 'ABCDEFGHJKMNPQRSTUVWXYZ';
    $numbers = '234567890';
    $allChars = $lowercase . $uppercase . $numbers;
    
    $password = '';
    
    // Ensure at least one of each type
    $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
    $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
    $password .= $numbers[random_int(0, strlen($numbers) - 1)];
    
    // Fill the rest of the password length
    $remainingLength = 20 - strlen($password);
    $max = strlen($allChars) - 1;
    for ($i = 0; $i < $remainingLength; $i++) {
        $password .= $allChars[random_int(0, $max)];
    }
    
    // Shuffle the password to randomize the position of the guaranteed characters
    return str_shuffle($password);
}

function verifyDomain($domainName) {
    if (!preg_match("/^([A-Za-z0-9-\\.]+)$/", $domainName)) {
        sokLog("Invalid domain name: {$domainName}", true);
        exit(1);
    }
}

function verifyPassword($password) {
    if (!preg_match("/^([A-Za-z0-9-\\.]+)$/", $password)) {
        sokLog("Invalid password: {$password}", true);
        exit(1);
    }
}

function verifyUsername($username) {
    if (strlen($username) > 32) {
        sokLog("Error: username must be less than 32 chars", true);
        exit(1);
    }
    if (!preg_match("/^([A-Za-z0-9]+)$/", $username)) {
        sokLog("Invalid user name {$username}", true);
        exit(1);
    }
}

function linuxUserExists($username) {
    return posix_getpwnam($username) !== false;
}

function linuxAddUser($domainName, $username, $password) {
    $encPass = crypt($password, "22");
    shell_exec("useradd -m -s /bin/bash -d /home/" . escapeshellarg($domainName) . "/ -p " . escapeshellarg($encPass) . " " . escapeshellarg($username));
}



function createPhpfpmConfig($username, $phpVersion) {
    $content = file_get_contents("templates/php-fpm-pool.conf");
    $content = str_replace("POOL_NAME", $username, $content);
    $content = str_replace("FPM_USER", $username, $content);
    $fileLocation = "/etc/php/{$phpVersion}/fpm/pool.d/{$username}.conf";
    file_put_contents($fileLocation, $content);
}

function createNginxConfig($domainName, $username, $appType) {
    if ($appType == "laravel") {
        $content = file_get_contents("templates/nginx-laravel-vhost-ssl.conf");
    } else {
        $content = file_get_contents("templates/nginx-vhost-ssl.conf");
    }
    $content = str_replace("POOL_NAME", $username, $content);
    $content = str_replace("FQDN", $domainName, $content);
    $fileLocation = "/etc/nginx/sites-enabled/{$domainName}.conf";
    file_put_contents($fileLocation, $content);
}

function createApacheConfig($domainName, $username, $appType) {
    if ($appType == "laravel") {
        $content = file_get_contents("templates/apache-vhost-laravel.conf");
    } else {
        $content = file_get_contents("templates/apache-vhost.conf");
    }
    $content = str_replace("POOL_NAME", $username, $content);
    $content = str_replace("FQDN", $domainName, $content);
    $fileLocation = "/etc/apache2/sites-enabled/{$domainName}.conf";
    file_put_contents($fileLocation, $content);
}

function findIp() {
    $ip = file_get_contents("http://checkip.amazonaws.com");
    if ($ip === false) {
        sokLog("Failed to find IP address", true);
        exit(1);
    }
    return trim($ip);
}

function createSiteDataFile($domainName, $username, $docRoot, $phpVersion, $appType) {
    $sitedataDir = "/usr/serverok/sitedata/";
    if (!is_dir($sitedataDir)) {
        mkdir($sitedataDir, 0755, true);
    }

    $data = [
        'servername' => $domainName,
        'serveralias' => 'www.' . $domainName,
        'documentroot' => $docRoot,
        'username' => $username,
        'dbname' => $username . '_db',
        'creation_date' => date('Y-m-d H:i:s'),
        'php_version' => $phpVersion,
        'app_type' => $appType,
    ];

    $jsonData = json_encode($data, JSON_PRETTY_PRINT);
    $filePath = $sitedataDir . $domainName;
    file_put_contents($filePath, $jsonData);
}

function createMysqlDatabaseAndUser($username, $passwordMysql) {
    $dbName = $username . '_db';
    $dbUser = $username . '_db';

    // Connect to MySQL as root (passwordless)
    $mysqli = new mysqli('localhost', 'root', '');
    if ($mysqli->connect_error) {
        sokLog("MySQL Connection failed: " . $mysqli->connect_error, true);
        exit(1);
    }

    // Create Database
    if (!$mysqli->query("CREATE DATABASE `{$dbName}`")) {
        sokLog("Error creating database {$dbName}: " . $mysqli->error, true);
        $mysqli->close();
        exit(1);
    }

    // Create User
    if (!$mysqli->query("CREATE USER '{$dbUser}'@'localhost' IDENTIFIED BY '{$passwordMysql}'")) {
        sokLog("Error creating user {$dbUser}: " . $mysqli->error, true);
        $mysqli->close();
        exit(1);
    }

    // Grant Privileges
    if (!$mysqli->query("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$dbUser}'@'localhost'")) {
        sokLog("Error granting privileges to {$dbUser}: " . $mysqli->error, true);
        $mysqli->close();
        exit(1);
    }

    $mysqli->query("FLUSH PRIVILEGES");
    $mysqli->close();
}

$text = 'ServerOK Server Manager.';

$options = getopt("d:u:p:", ["domain:", "user:", "password:", "php:", "app:"]);

if (isset($options['d'])) {
    $domainName = trim($options['d']);
} elseif (isset($options['domain'])) {
    $domainName = trim($options['domain']);
} else {
    $domainName = trim(readline("Enter domain name: "));
}

if (isset($options['u'])) {
    $username = $options['u'];
} elseif (isset($options['user'])) {
    $username = $options['user'];
} else {
    sokLog("ERROR: Please specify username with -u or --user option.", true);
    exit(1);
}

if (isset($options['p'])) {
    $password = $options['p'];
} elseif (isset($options['password'])) {
    $password = $options['password'];
} else {
    $password = generatePassword();
}

if (isset($options['php'])) {
    $phpVersion = $options['php'];
} else {
    $phpVersion = findLatestPhpVersion();
    if ($phpVersion === null) {
        sokLog("ERROR: Could not automatically determine PHP version. Please specify one with --php option.", true);
        exit(1);
    }
    sokLog("PHP version not specified, using latest found version: {$phpVersion}", true);
}

$appType = "wp";
if (isset($options['app'])) {
    $appType = trim($options['app']);
    if ($appType != "laravel") {
        $appType = "wp";
    }
}

$server = getWebServer();
sokLog("Server = {$server}", true);

verifyPhpVersion($phpVersion);
verifyUsername($username);
verifyDomain($domainName);
verifyPassword($password);

if (linuxUserExists($username)) {
    sokLog("ERROR: User {$username} already exists!", true);
    exit(1);
}

$passwordMysql = generatePassword();
createMysqlDatabaseAndUser($username, $passwordMysql);
$ipAddress = findIp();

linuxAddUser($domainName, $username, $password);

createPhpfpmConfig($username, $phpVersion);

if ($server == "nginx") {
    createNginxConfig($domainName, $username, $appType);
} else {
    createApacheConfig($domainName, $username, $appType);
}

$docRoot = "/home/{$domainName}/html/";
shell_exec("mkdir -p " . escapeshellarg($docRoot));
shell_exec("chown -R " . escapeshellarg($username) . ":" . escapeshellarg($username) . " " . escapeshellarg($docRoot));
shell_exec("chmod -R 750 " . escapeshellarg("/home/{$domainName}"));
shell_exec("usermod -aG " . escapeshellarg($username) . " www-data");
shell_exec("openssl genrsa -out " . escapeshellarg("/etc/ssl/{$domainName}.key") . " 2048");
shell_exec("openssl req -new -x509 -key " . escapeshellarg("/etc/ssl/{$domainName}.key") . " -out " . escapeshellarg("/etc/ssl/{$domainName}.crt") . " -days 3650 -subj /CN=" . escapeshellarg($domainName));

if ($appType == "laravel") {
    $docRoot = "/home/{$domainName}/html/public/";
    shell_exec("mkdir -p " . escapeshellarg($docRoot));
    shell_exec("chown -R " . escapeshellarg($username) . ":" . escapeshellarg($username) . " " . escapeshellarg($docRoot));
}

shell_exec("systemctl restart " . escapeshellcmd("php{$phpVersion}-fpm"));

if ($server == "nginx") {
    shell_exec("systemctl restart nginx");
} else {
    shell_exec("systemctl restart apache2");
}

createSiteDataFile($domainName, $username, $docRoot, $phpVersion, $appType);

sokLog("SFTP/SSH {$domainName}\n", true);
sokLog("IP = {$ipAddress}", true);
sokLog("Port = 22", true);
sokLog("User = {$username}", true);
sokLog("PW = {$password}\n", true);

sokLog("MySQL\n", true);

sokLog("DB = {$username}_db", true);
sokLog("User = {$username}_db", true);
sokLog("PW = {$passwordMysql}\n", true);

sokLog("phpMyAdmin\n", true);

sokLog("http://{$ipAddress}:7777", true);
sokLog("User = {$username}_db", true);
sokLog("PW = {$passwordMysql}\n", true);

if ($server == "nginx") {
    sokLog("certbot --authenticator webroot --webroot-path " . escapeshellarg($docRoot) . " --installer nginx -m admin@serverok.in --agree-tos --no-eff-email -d {$domainName} -d www.{$domainName}", true);
} else {
    sokLog("certbot --authenticator webroot --webroot-path " . escapeshellarg($docRoot) . " --installer apache -m admin@serverok.in --agree-tos --no-eff-email -d {$domainName} -d www.{$domainName}", true);
}

?>
