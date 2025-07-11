<?php
// Handle preflight request (CORS)
if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    http_response_code(200);
    exit();
}

// Require DB connection
require_once __DIR__ . '/../db.php';
require_once 'auth.php';

$user = verifyToken();
$id_company = $user['id_company'] ?? -1;

if ($id_company == -1) {
    http_response_code(400);
    echo json_encode(["status" => 400, "error" => "Missing company ID"]);
    exit();
}
// Define allowed routes with query and parameter count
$allowed_routes = [
    "data/absensi_all" => [
        "query" => "CALL hr_absensi_all(?, ?, ?)",
        "params" => 3
    ],
    "data/hr_absensi_timesheet" => [
        "query" => "CALL hr_absensi_timesheet($id_company, ?, ?, ?, ?, ?)",
        "params" => 5
    ]
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
        while ($row = $result->fetch_assoc()) {
            $data[] = $row; // Return flat array of values
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
