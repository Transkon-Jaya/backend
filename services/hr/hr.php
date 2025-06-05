<?php

if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit();
}

$prefix = 'hr/'

$allowed_routes = [
    'timeoff'        => 'services/hr/timeoff.php',
];

$allowed_startswith = [
];

$request = $_GET['request'] ?? '';
echo json_encode($request)
$subroute = preg_replace('#^' . preg_quote($prefix, '#') . '/#', '', $request);
echo json_encode($subroute)
// Direct match
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
