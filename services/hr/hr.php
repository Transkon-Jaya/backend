<?php

if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json');

$prefix = 'hr'; // No slash, because we're matching 'hr' as prefix
echo json_encode("hr.php\n");

$allowed_routes = [
    'timeoff' => 'services/hr/timeoff.php',
];

$request = $_GET['request'] ?? '';
echo json_encode("Request: $request\n");

// Remove 'hr/' from the beginning of the request
$subroute = preg_replace('#^' . preg_quote($prefix, '#') . '/#', '', $request);
echo json_encode("Subroute: $subroute\n");

// Direct match to subroute
if (isset($allowed_routes[$subroute])) {
    require $allowed_routes[$subroute];
} else {
    http_response_code(404);
    echo json_encode([
        'status' => 404,
        'error' => "Invalid HR API endpoint: $subroute"
    ]);
}
