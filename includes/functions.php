<?php

function sok_log($message, $display = false) {
    $log_file = '/var/log/server-manager.log';
    $formatted_message = date('[Y-m-d H:i:s] ') . $message . PHP_EOL;
    file_put_contents($log_file, $formatted_message, FILE_APPEND);

    if ($display) {
        echo $message . PHP_EOL;
    }
}

function detectServer() {
    $output = shell_exec('ss -nltp');
    if ($output === null) {
        sok_log("Error executing ss command", true);
        exit(1);
    }

    $outputStr = strtolower($output);

    if (strpos($outputStr, '*:80') !== false || strpos($outputStr, '0.0.0.0:80') !== false) {
        if (strpos($outputStr, 'apache2') !== false) {
            return "apache";
        }
        if (strpos($outputStr, 'nginx') !== false) {
            return "nginx";
        }
    }
    
    sok_log("Error: Neither Apache nor Nginx is listening on port 80 or 443.", true);
    exit(1);
}

function getWebServer() {
    $configFile = '/usr/serverok/okpanel/config/webserver';
    
    // Try to read from config file first
    if (file_exists($configFile)) {
        $webServer = trim(file_get_contents($configFile));
        if (!empty($webServer) && in_array($webServer, ['nginx', 'apache'])) {
            sok_log("Web server read from config: {$webServer}");
            return $webServer;
        }
    }
    
    // Config file doesn't exist or invalid, detect the server
    sok_log("Config file not found, detecting web server...");
    $webServer = detectServer();
    
    // Save detected server to config file for future use
    $configDir = dirname($configFile);
    if (!is_dir($configDir)) {
        mkdir($configDir, 0755, true);
    }
    file_put_contents($configFile, $webServer);
    sok_log("Detected web server '{$webServer}' saved to {$configFile}");
    
    return $webServer;
}
