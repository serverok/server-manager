<?php

function sok_log($message, $display = false) {
    $log_file = '/var/log/server-manager.log';
    $formatted_message = date('[Y-m-d H:i:s] ') . $message . PHP_EOL;
    file_put_contents($log_file, $formatted_message, FILE_APPEND);

    if ($display) {
        echo $message . PHP_EOL;
    }
}