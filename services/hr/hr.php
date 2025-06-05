<?php

if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json');

$prefix = 'hr/';

$allowed_routes = [
    'timeoff' => __DIR__ 'timeoff.php',
];

$request = $_GET['request'] ?? '';
$subroute = preg_replace('#^' . preg_quote($prefix, '#') . '#', '', $request);

if (isset($allowed_routes[$subroute])) {
    require $allowed_routes[$subroute];
} else {
    http_response_code(404);
    echo json_encode([
        'status' => 404,
        'error' => "Invalid HR API endpoint: $subroute"
    ]);
}
