<?php

if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit();
}

$request = $_GET['request'] ?? '';
$subroute = preg_replace('#^hr/#', '', $request);

$allowed_routes = [
    'timeoff' => __DIR__ . 'timeoff.php',
    'holiday' => __DIR__ . 'holiday.php',
];

$allowed_startswith = [
    'hr' => 'services/hr/hr.php',
    'chart' => 'services/chart.php',
    'dropdowns' => 'services/dropdowns.php',
    'get'   => 'services/get.php',
    'data'  => 'services/data.php'
];


if (isset($allowed_routes[$request])) {
    require $allowed_routes[$request];

// Regex match for scalable prefixes
} elseif (preg_match('#^([a-zA-Z0-9_-]+)(/.*)?$#', $request, $matches)) {
    $prefix = $matches[1]; // 'dropdowns', 'uploads', etc.

    if (isset($allowed_startswith[$prefix])) {
        require $allowed_startswith[$prefix];
    } else {
        http_response_code(404);
        echo json_encode([
            'status' => 404,
            'error' => "No handler found for prefix: $prefix"
        ]);
    }

} else {
    http_response_code(404);
    echo json_encode([
        'status' => 404,
        'error' => "Invalid API endpoint: $request"
    ]);
}

