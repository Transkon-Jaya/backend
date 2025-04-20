<?php
// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit();
}

// Require DB connection
require_once __DIR__ . '/../db.php';

$allowed_routes = [
    'dropdowns/customer'    => 'SELECT DISTINCT name FROM customer',
    'dropdowns/tk_no'        => 'SELECT DISTINCT tk_no FROM down_equipment',
    'dropdowns/vehicle_type' => 'SELECT DISTINCT vehicle_type FROM down_equipment',
];

// Get the requested route
$request = $_GET['request'] ?? '';

if (isset($allowed_routes[$request])) {
    $query = $allowed_routes[$request];
    $result = $conn->query($query);

    if ($result) {
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }

        echo json_encode($data);
    } else {
        http_response_code(500);
        echo json_encode([
            'status' => 500,
            'error' => "Query failed: " . $conn->error
        ]);
    }
} else {
    http_response_code(404);
    echo json_encode([
        'status' => 404,
        'error' => "Invalid dropdown request: $request"
    ]);
}
