<?php
// file_put_contents('webhook.log', date('Y-m-d H:i:s') . " - Webhook received\n", FILE_APPEND);

// if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
//     http_response_code(403);
//     echo "Forbidden";
//     exit;
// }

echo shell_exec('git reset --hard HEAD && git pull');
// file_put_contents('webhook.log', $output . "\n", FILE_APPEND);
// echo $output;
// echo "Webhook executed\n";
?>
