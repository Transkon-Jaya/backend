<?php
// if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
//     http_response_code(403);
//     echo "Forbidden";
//     exit;
// }
// $output = shell_exec('cd /var/www/html/api && git reset --hard HEAD && git pull 2>&1');
shell_exec('git pull');
echo "Webhook executed\n";
?>
