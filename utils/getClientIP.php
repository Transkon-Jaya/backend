<?php
function getClientIP() {
    $headers = [
        'HTTP_CF_CONNECTING_IP',     // Cloudflare
        'HTTP_X_FORWARDED_FOR',      // Proxies
        'HTTP_X_REAL_IP',            // Nginx or other proxies
        'REMOTE_ADDR'                // Default
    ];

    foreach ($headers as $key) {
        if (!empty($_SERVER[$key])) {
            $ipList = explode(',', $_SERVER[$key]);
            $ip = trim($ipList[0]);

            // Validate the IP address
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return 'UNKNOWN';
}
