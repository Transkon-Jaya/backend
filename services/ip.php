<?php
header('Content-Type: application/json');
require_once 'utils/getClientIP.php';
require 'auth.php';

$username = $_GET['username'] ?? null;
authorize(10, [], [], $username);

$response = [
    'ip' => getClientIP()
];

echo json_encode($response);
