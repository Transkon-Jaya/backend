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

// Define allowed routes with query and parameter count
$prefix = "chart/";
$allowed_routes = [
    //--ABSENSI START--//
    $prefix."absensi_avg_hw" => [
        "query" => "CALL absensi_avg_hw($id_company, ?, ?, ?, ?, ?)", // limit_day, day_start, name, department, site
        "params" => 5
    ],
    $prefix."absensi_avg_hi" => [
        "query" => "CALL absensi_avg_hi(?, ?, ?, ?, ?)", // limit_day, day_start, name, department, site
        "params" => 5
    ],
    $prefix."absensi_avg_ho" => [
        "query" => "CALL absensi_avg_ho(?, ?, ?, ?, ?)", // limit_day, day_start, name, department, site
        "params" => 5
    ],
    $prefix."absensi_avg_hio" => [
        "query" => "CALL absensi_avg_hio($id_company, ?, ?, ?, ?, ?)", // limit_day, day_start, name, department, site
        "params" => 5
    ],
    $prefix."absensi_leaderboard_hi_asc" => [
        "query" => "CALL absensi_leaderboard_hi_asc($id_company, ?, ?, ?, ?, ?)", //start_date, end_date, department, location, placement
        "params" => 5,
        "level" => 8,
        "permissions" => ["admin_absensi"],
    ],
    $prefix."absensi_leaderboard_hi_desc" => [
        "query" => "CALL absensi_leaderboard_hi_desc($id_company, ?, ?, ?, ?, ?)", //start_date, end_date, department, location, placement
        "params" => 5,
        "level" => 8,
        "permissions" => ["admin_absensi"],
    ],
    $prefix."absensi_leaderboard_ho_asc" => [
        "query" => "CALL absensi_leaderboard_ho_asc($id_company, ?, ?, ?, ?, ?)", //start_date, end_date, department, location, placement
        "params" => 5,
        "level" => 8,
        "permissions" => ["admin_absensi"],
    ],
    $prefix."absensi_leaderboard_ho_desc" => [
        "query" => "CALL absensi_leaderboard_ho_desc($id_company, ?, ?, ?, ?, ?)", //start_date, end_date, department, location, placement
        "params" => 5,
        "level" => 8,
        "permissions" => ["admin_absensi"],
    ],
    $prefix."absensi_leaderboard_hw_asc" => [
        "query" => "CALL absensi_leaderboard_hw_asc($id_company, ?, ?, ?, ?, ?)", //start_date, end_date, department, location, placement
        "params" => 5,
        "level" => 8,
        "permissions" => ["admin_absensi"],
    ],
    $prefix."absensi_leaderboard_hw_desc" => [
        "query" => "CALL absensi_leaderboard_hw_desc($id_company, ?, ?, ?, ?, ?)", //start_date, end_date, department, location, placement
        "params" => 5,
        "level" => 8,
        "permissions" => ["admin_absensi"],
    ],
    //--ABSENSI END--//
    //--DE START--//
    $prefix.'de_running_total' => [
        'query' => 'CALL de_running_total(?, ?, ?)',
        'params' => 3
    ],
    $prefix.'de_total_rental' => [
        'query' => 'CALL de_total_rental(?, ?)',
        'params' => 2
    ],
    $prefix.'de_customer_summary' => [
        'query' => 'CALL de_customer_summary()',
        'params' => 0
    ],
    $prefix.'de_location_summary' => [
        'query' => 'CALL de_location_summary()',
        'params' => 0
    ],
    //--DE END--//
];

$default_config = [
    'params' => 0,
    'auth' => true,
    'level' => 9,
    'permissions' => [],
    'not_permissions' => [],
    'username' => null
];

// Get the requested route
$request = $_GET['request'] ?? '';

if (isset($allowed_routes[$request])) {
    $config = array_merge($default_config, $allowed_routes[$request]);
    
    if($config["auth"]){
        authorize($config["level"], $config["permissions"], $config["not_permissions"], $config["username"]);
    }

    // Check if required number of params is provided
    if (count($params) != $config['params']) {
        http_response_code(400);
        echo json_encode([
            'status' => 400,
            'error' => "Wrong parameters for '$request'"
        ]);
        exit();
    }
    $stmt = $conn->prepare($config['query']);
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
