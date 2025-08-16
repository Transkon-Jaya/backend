<?php

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

shell_exec('cd /var/www/one.transkon-rent.com && sudo -u trja git reset --hard HEAD');
echo shell_exec('cd /var/www/one.transkon-rent.com && sudo -u trja git pull');
// file_put_contents('webhook.log', $output . "\n", FILE_APPEND);
// echo $output;
// echo "Webhook executed\n";
?>
