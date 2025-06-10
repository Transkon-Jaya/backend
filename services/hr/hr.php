<?php

if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit();
}

$request = $_GET['request'] ?? '';
$subroute = preg_replace('#^hr/#', '', $request);

// echo json_encode($subroute);

$allowed_routes = [
    'timeoff' => 'services/hr/timeoff.php',
    'holiday' => 'services/hr/holiday.php',
];

$allowed_startswith = [
    'chart' => 'services/chart.php',
    'dropdowns' => 'services/dropdowns.php',
    'get' => 'services/get.php',
    'data' => 'services/data.php'
];

// Match full subroute (e.g. "timeoff" from "hr/timeoff")
if (isset($allowed_routes[$subroute])) {
    require $allowed_routes[$subroute];

} elseif (preg_match('#^([a-zA-Z0-9_-]+)(/.*)?$#', $request, $matches)) {
    $prefix = $matches[1];

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
