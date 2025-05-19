<?php
// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit();
}

// Require DB connection
require_once __DIR__ . '/../db.php';
require_once 'auth.php';

$prefix = 'ddn/';
$allowed_routes = [
    $prefix.'customer' => [
        'query' => 'SELECT DISTINCT name FROM customer',
        'params' => 0,
        'level' => 8,
        'permissions' => ["admin_absensi"],
        'not_permissions' => ["no_absensi"],
        'username' => $_GET['username'] ?? null
    ],
    $prefix.'hr_absensi' => [
        'query' => 'SELECT * from hr_absensi WHERE username = ?',
        'params' => 1,
        'level' => 8,
        'permissions' => ["admin_absensi"],
        'not_permissions' => ["no_absensi"],
        'username' => $_GET['0'] ?? null
    ],
    
];

// Get the requested route
$request = $_GET['request'] ?? '';

if (isset($allowed_routes[$request])) {
    $config = $allowed_routes[$request];

    // Authorize user
    authorize($config["level"], $config["permissions"], $config["not_permissions"], $config["username"]);

    // Prepare the query
    $stmt = $conn->prepare($config["query"]);
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['status' => 500, 'error' => 'Prepare failed: ' . $conn->error]);
        exit();
    }

    // Bind parameters dynamically if needed
    if ($config["params"] > 0) {
        $types = str_repeat('s', $config["params"]); // all params as strings
        $params = [];

        for ($i = 0; $i < $config["params"]; $i++) {
            $paramKey = (string) $i;
            $params[] = $_GET[$paramKey] ?? '';
        }

        $stmt->bind_param($types, ...$params);
    }

    // Execute and fetch result
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $data = [];

        while ($row = $result->fetch_row()) {
            $data[] = (count($row) === 1) ? $row[0] : $row;
        }

        echo json_encode($data);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 500, 'error' => 'Execution failed: ' . $stmt->error]);
    }

    $stmt->close();
} else {
    http_response_code(404);
    echo json_encode([
        'status' => 404,
        'error' => "Invalid dropdown request: $request"
    ]);
}
