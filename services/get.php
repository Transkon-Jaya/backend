<?php
// Handle preflight request (CORS)
if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    http_response_code(200);
    exit();
}

// Require DB connection
require_once __DIR__ . '/../db.php';

// Define allowed routes with query and parameter count
$allowed_routes = [
    'get/myname' => [
        'query' => 'SELECT name FROM user_profiles WHERE username = ?',
        'params' => 1
    ],
    'get/foto' => [
        'query' => 'SELECT foto FROM user_profiles WHERE username = ?',
        'params' => 1
    ],
    'get/de_running_total' => [
        'query' => 'CALL de_running_total(?)',
        'params' => 1
    ],
    'get/plate_no' => [
        'query' => "SELECT plate_no FROM down_equipment WHERE tk_no = ?",
        'params' => 1
    ],
    'get/vehicle_type' => [
        'query' => 'SELECT vehicle_type FROM down_equipment WHERE tk_no = ?',
        'params' => 1
    ],
    'get/total_rental' => [
        'query' => 
            "SELECT count(status_unit_3) AS total FROM down_equipment de 
            WHERE status_unit_3 = 'Rental'
            UNION ALL
            SELECT COUNT(spare_exists) AS total FROM de_site ds WHERE done = 0 AND spare_exists = 0 AND deleted = 0
            UNION ALL
            SELECT COUNT(spare_exists) AS total FROM de_site ds WHERE done = 0 AND spare_exists = 1 AND deleted = 0
            ",
        'params' => 0
    ],
    'get/double' => [
        'query' => 'SELECT vehicle_type FROM down_equipment WHERE tk_no = ? AND hub = ?',
        'params' => 2
    ],
];

// Get the requested route
$request = $_GET['request'] ?? '';

// Fallback: If params[] is not provided, collect numeric keys like 0=, 1=, etc.
if (isset($_GET['params'])) {
    $params = $_GET['params'];
    if (!is_array($params)) $params = [$params];
} else {
    $params = [];
    foreach ($_GET as $key => $val) {
        if (is_numeric($key)) {
            $params[(int)$key] = $val;
        }
    }
    ksort($params); // Ensure proper order: 0,1,2...
    $params = array_values($params); // Reindex
}

if (!is_array($params)) {
    $params = [$params]; // Normalize to array
}

if (isset($allowed_routes[$request])) {
    $route = $allowed_routes[$request];
    $query = $route['query'];

    // Check if required number of params is provided
    if (count($params) != $route['params']) {
        http_response_code(400);
        echo json_encode([
            'status' => 400,
            'error' => "Wrong parameters for '$request'"
        ]);
        exit();
    }

    $stmt = $conn->prepare($query);
    if ($stmt === false) {
        http_response_code(500);
        echo json_encode(['status' => 500, 'error' => "Prepare failed: " . $conn->error]);
        exit();
    }

    // Bind parameters dynamically
    $types = str_repeat('s', count($params)); // Assuming all are strings, adjust as needed
    $stmt->bind_param($types, ...$params);

    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $data = [];
        while ($row = $result->fetch_row()) {
            $data[] = $row[0]; // Return flat array of values
        }
        echo json_encode($data);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 500, 'error' => "Execute failed: " . $stmt->error]);
    }

    $stmt->close();
} else {
    http_response_code(404);
    echo json_encode([
        'status' => 404,
        'error' => "Invalid autoget request: $request"
    ]);
}
