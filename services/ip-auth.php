<?php
header('Content-Type: application/json');
require_once 'utils/getClientIP.php';

require 'auth.php';

authorize(2, ['admin_absensi'], ['no_absensi']);

$response = [
    'ip' => getClientIP()
];

echo json_encode($response);
