#!/usr/bin/php
<?php
// Author: ServerOK
// Web: https://serverok.in
// Mail: admin@serverok.in
// Convert Nginx virtual hosts to Apache backends and proxy Nginx to Apache.

require_once __DIR__ . '/includes/functions.php';

const NGINX_SITE_DIR = '/etc/nginx/sites-enabled';
const APACHE_SITE_DIR = '/etc/apache2/sites-enabled';
const APACHE_PORTS_FILE = '/etc/apache2/ports.conf';
const APACHE_HTTP_PORT = 8080;
const APACHE_HTTPS_PORT = 8443;

$options = getopt('', ['dry-run', 'help']);
$dryRun = isset($options['dry-run']);

if (isset($options['help'])) {
    echo "Usage: php proxy2apache.php [--dry-run]\n";
    exit(0);
}

if (posix_getuid() !== 0) {
    sokLog("This script must be run as root or with sudo.", true);
    exit(1);
}

if (!is_dir(NGINX_SITE_DIR)) {
    sokLog("ERROR: Nginx site directory not found: " . NGINX_SITE_DIR, true);
    exit(1);
}

if (!is_dir(APACHE_SITE_DIR)) {
    sokLog("ERROR: Apache site directory not found: " . APACHE_SITE_DIR, true);
    exit(1);
}

$nginxConfigs = glob(NGINX_SITE_DIR . '/*.conf');
if ($nginxConfigs === false || empty($nginxConfigs)) {
    sokLog("ERROR: No Nginx config files found in " . NGINX_SITE_DIR, true);
    exit(1);
}

$convertedCount = 0;

foreach ($nginxConfigs as $nginxConfigFile) {
    if (!is_file($nginxConfigFile)) {
        continue;
    }

    $content = file_get_contents($nginxConfigFile);
    if ($content === false) {
        sokLog("WARNING: Failed to read {$nginxConfigFile}. Skipping.", true);
        continue;
    }

    $serverBlocks = extractServerBlocks($content);
    if (empty($serverBlocks)) {
        sokLog("WARNING: No server block found in {$nginxConfigFile}. Skipping.", true);
        continue;
    }

    $sites = [];
    foreach ($serverBlocks as $block) {
        $site = parseNginxServerBlock($block);
        if ($site === null) {
            continue;
        }
        $sites[] = $site;
    }

    if (empty($sites)) {
        sokLog("WARNING: No convertible site found in {$nginxConfigFile}. Skipping.", true);
        continue;
    }

    $apacheConfigFile = APACHE_SITE_DIR . '/' . basename($nginxConfigFile);
    $apacheConfig = '';
    $nginxProxyConfig = '';

    foreach ($sites as $site) {
        $apacheConfig .= buildApacheConfig($site) . "\n";
        $nginxProxyConfig .= buildNginxProxyConfig($site) . "\n";
        $convertedCount++;
    }

    writeConfigFile($apacheConfigFile, trim($apacheConfig) . "\n", $dryRun);
    writeConfigFile($nginxConfigFile, trim($nginxProxyConfig) . "\n", $dryRun);

    sokLog("Converted " . basename($nginxConfigFile) . " to Apache backend on ports " . APACHE_HTTP_PORT . "/" . APACHE_HTTPS_PORT, true);
}

if ($convertedCount === 0) {
    sokLog("ERROR: No Nginx virtual hosts were converted.", true);
    exit(1);
}

ensureApachePorts($dryRun);

if ($dryRun) {
    sokLog("Dry run complete. No files were changed.", true);
    exit(0);
}


runCommand('apache2ctl configtest', 'Apache config test failed.');
runCommand('nginx -t', 'Nginx config test failed.');
runCommand('systemctl restart apache2', 'Failed to restart Apache.');
runCommand('systemctl restart nginx', 'Failed to restart Nginx.');

sokLog("proxy2apache completed. Nginx now proxies to Apache on ports " . APACHE_HTTP_PORT . " and " . APACHE_HTTPS_PORT . ".", true);

function ensureNginxIsCurrentServer() {
    $server = getWebServer();
    if ($server !== 'nginx') {
        sokLog("ERROR: Current web server is '{$server}', expected nginx.", true);
        exit(1);
    }
}

function extractServerBlocks($content) {
    $blocks = [];
    $offset = 0;

    while (($serverPos = strpos($content, 'server', $offset)) !== false) {
        $before = $serverPos === 0 ? '' : $content[$serverPos - 1];
        $after = $content[$serverPos + 6] ?? '';
        if (($before !== '' && preg_match('/[A-Za-z0-9_-]/', $before)) || preg_match('/[A-Za-z0-9_-]/', $after)) {
            $offset = $serverPos + 6;
            continue;
        }

        $bracePos = strpos($content, '{', $serverPos);
        if ($bracePos === false) {
            break;
        }

        $between = substr($content, $serverPos + 6, $bracePos - ($serverPos + 6));
        if (trim($between) !== '') {
            $offset = $bracePos + 1;
            continue;
        }

        $depth = 1;
        $pos = $bracePos + 1;
        $length = strlen($content);

        while ($pos < $length && $depth > 0) {
            if ($content[$pos] === '{') {
                $depth++;
            } elseif ($content[$pos] === '}') {
                $depth--;
            }
            $pos++;
        }

        if ($depth === 0) {
            $blocks[] = substr($content, $serverPos, $pos - $serverPos);
        }

        $offset = $pos;
    }

    return $blocks;
}

function parseNginxServerBlock($block) {
    $serverName = getNginxDirective($block, 'server_name');
    $documentRoot = getNginxDirective($block, 'root');

    if ($serverName === null || $documentRoot === null) {
        return null;
    }

    $names = preg_split('/\s+/', trim($serverName));
    $names = array_values(array_filter($names, function ($name) {
        return $name !== '' && $name !== '_';
    }));

    if (empty($names)) {
        return null;
    }

    return [
        'server_name' => $names[0],
        'server_aliases' => array_slice($names, 1),
        'server_names' => $names,
        'document_root' => rtrim($documentRoot, '/'),
        'has_ssl' => hasNginxSslListen($block),
        'ssl_certificate' => getNginxDirective($block, 'ssl_certificate'),
        'ssl_certificate_key' => getNginxDirective($block, 'ssl_certificate_key'),
        'access_log' => getFirstToken(getNginxDirective($block, 'access_log')),
        'error_log' => getFirstToken(getNginxDirective($block, 'error_log')),
        'client_max_body_size' => getNginxDirective($block, 'client_max_body_size') ?? '1000M',
        'proxy_read_timeout' => getNginxDirective($block, 'proxy_read_timeout') ?? '600s',
        'fastcgi_socket' => getFastcgiSocket($block),
    ];
}

function getNginxDirective($block, $directive) {
    if (preg_match('/^\s*' . preg_quote($directive, '/') . '\s+([^;]+);/mi', $block, $matches)) {
        return trim($matches[1]);
    }

    return null;
}

function hasNginxSslListen($block) {
    if (preg_match('/^\s*listen\s+[^;]*(443|ssl)[^;]*;/mi', $block)) {
        return true;
    }

    return getNginxDirective($block, 'ssl_certificate') !== null || getNginxDirective($block, 'ssl_certificate_key') !== null;
}

function getFirstToken($value) {
    if ($value === null) {
        return null;
    }

    $tokens = preg_split('/\s+/', trim($value));
    return $tokens[0] ?? null;
}

function getFastcgiSocket($block) {
    if (preg_match('/fastcgi_pass\s+unix:([^;]+);/i', $block, $matches)) {
        return trim($matches[1]);
    }

    return null;
}

function buildApacheConfig($site) {
    $serverAlias = '';
    if (!empty($site['server_aliases'])) {
        $serverAlias = "\n    ServerAlias " . implode(' ', $site['server_aliases']);
    }

    $phpHandler = '';
    if (!empty($site['fastcgi_socket'])) {
        $phpHandler = <<<APACHE

    <FilesMatch \.php$>
        SetHandler "proxy:unix:{$site['fastcgi_socket']}|fcgi://localhost/"
    </FilesMatch>
APACHE;
    }

    $accessLog = $site['access_log'] ?: '${APACHE_LOG_DIR}/' . $site['server_name'] . '.log';
    $errorLog = $site['error_log'] ?: '${APACHE_LOG_DIR}/' . $site['server_name'] . '-error.log';
    $sslVirtualHost = '';

    if ($site['has_ssl']) {
        if (empty($site['ssl_certificate']) || empty($site['ssl_certificate_key'])) {
            sokLog("ERROR: {$site['server_name']} listens on SSL but ssl_certificate or ssl_certificate_key was not found in Nginx config.", true);
            exit(1);
        }

        $sslCertificate = $site['ssl_certificate'];
        $sslCertificateKey = $site['ssl_certificate_key'];

        $sslVirtualHost = <<<APACHE

<VirtualHost *:8443>
    ServerName {$site['server_name']}{$serverAlias}
    ServerAdmin admin@{$site['server_name']}
    DocumentRoot {$site['document_root']}
    CustomLog {$accessLog} combined
    ErrorLog {$errorLog}
    SSLEngine on
    SSLProtocol all -SSLv2 -SSLv3 -TLSv1 -TLSv1.1
    SSLCertificateFile {$sslCertificate}
    SSLCertificateKeyFile {$sslCertificateKey}{$phpHandler}
    <Directory "{$site['document_root']}">
        Options All -Indexes
        AllowOverride All
        Require all granted
        Order allow,deny
        allow from all
    </Directory>
</VirtualHost>
APACHE;
    }

    return <<<APACHE
<VirtualHost *:8080>
    ServerName {$site['server_name']}{$serverAlias}
    ServerAdmin admin@{$site['server_name']}
    DocumentRoot {$site['document_root']}
    CustomLog {$accessLog} combined
    ErrorLog {$errorLog}{$phpHandler}
    <Directory "{$site['document_root']}">
        Options All -Indexes
        AllowOverride All
        Require all granted
        Order allow,deny
        allow from all
    </Directory>
</VirtualHost>
{$sslVirtualHost}
APACHE;
}

function buildNginxProxyConfig($site) {
    $serverNames = implode(' ', $site['server_names']);
    $accessLog = $site['access_log'] ? "    access_log {$site['access_log']};\n" : '';
    $errorLog = $site['error_log'] ? "    error_log {$site['error_log']};\n" : '';
    $sslServer = '';

    if ($site['has_ssl']) {
        if (empty($site['ssl_certificate']) || empty($site['ssl_certificate_key'])) {
            sokLog("ERROR: {$site['server_name']} listens on SSL but ssl_certificate or ssl_certificate_key was not found in Nginx config.", true);
            exit(1);
        }

        $sslServer = <<<NGINX

server {
    listen 443 ssl http2;
    server_name {$serverNames};
    ssl_certificate {$site['ssl_certificate']};
    ssl_certificate_key {$site['ssl_certificate_key']};
{$accessLog}{$errorLog}    client_max_body_size {$site['client_max_body_size']};

    location / {
        proxy_pass https://127.0.0.1:8443;
        proxy_ssl_server_name on;
        proxy_ssl_name \$host;
        proxy_ssl_verify off;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
        proxy_set_header X-Forwarded-Port \$server_port;
        proxy_read_timeout {$site['proxy_read_timeout']};
        proxy_send_timeout {$site['proxy_read_timeout']};
        proxy_redirect off;
    }
}
NGINX;
    }

    return <<<NGINX
server {
    listen 80;
    server_name {$serverNames};
{$accessLog}{$errorLog}    client_max_body_size {$site['client_max_body_size']};

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
        proxy_set_header X-Forwarded-Port \$server_port;
        proxy_read_timeout {$site['proxy_read_timeout']};
        proxy_send_timeout {$site['proxy_read_timeout']};
        proxy_redirect off;
    }
}
{$sslServer}
NGINX;
}

function writeConfigFile($file, $content, $dryRun) {
    if ($dryRun) {
        sokLog("DRY RUN: Would write {$file}", true);
        return;
    }

    backupFile($file);

    if (file_put_contents($file, $content) === false) {
        sokLog("ERROR: Failed to write {$file}", true);
        exit(1);
    }
}

function backupFile($file) {
    if (!file_exists($file)) {
        return;
    }

    $backupFile = $file . '.proxy2apache.' . date('YmdHis') . '.bak';
    if (!copy($file, $backupFile)) {
        sokLog("ERROR: Failed to back up {$file} to {$backupFile}", true);
        exit(1);
    }

    sokLog("Backed up {$file} to {$backupFile}", true);
}

function ensureApachePorts($dryRun) {
    if (!file_exists(APACHE_PORTS_FILE)) {
        sokLog("ERROR: Apache ports file not found: " . APACHE_PORTS_FILE, true);
        exit(1);
    }

    $content = file_get_contents(APACHE_PORTS_FILE);
    if ($content === false) {
        sokLog("ERROR: Failed to read " . APACHE_PORTS_FILE, true);
        exit(1);
    }

    $updated = $content;
    if (!preg_match('/^\s*Listen\s+' . APACHE_HTTP_PORT . '\b/m', $updated)) {
        $updated .= "\n# Added by proxy2apache\nListen " . APACHE_HTTP_PORT . "\n";
    }

    if (!preg_match('/^\s*Listen\s+' . APACHE_HTTPS_PORT . '\b/m', $updated)) {
        $updated .= "<IfModule ssl_module>\n    Listen " . APACHE_HTTPS_PORT . "\n</IfModule>\n";
    }

    if ($updated === $content) {
        return;
    }

    writeConfigFile(APACHE_PORTS_FILE, $updated, $dryRun);
    sokLog("Configured Apache to listen on ports " . APACHE_HTTP_PORT . " and " . APACHE_HTTPS_PORT, true);
}

function runCommand($command, $errorMessage) {
    passthru($command, $returnCode);
    if ($returnCode !== 0) {
        sokLog("ERROR: {$errorMessage}", true);
        exit(1);
    }
}

?>
