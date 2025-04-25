<?php
header('Content-Type: application/json');
require_once 'utils/getClientIP.php';

$response = [
    'ip' => getClientIP()
];

echo json_encode($response);
