<?php
header('Content-Type: application/json');
require_once 'utils/getClientIP.php';

require 'auth.php';

authorize(2, [], ['no_absensi']);

$response = [
    'ip' => getClientIP()
];

echo json_encode($response);
