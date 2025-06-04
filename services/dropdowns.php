<?php
// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../db.php';
require_once 'auth.php';
$user = verifyToken();
$id_company = $user["id_company"] ?? null;

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

$prefix = 'dropdowns/';
$allowed_routes = [
    $prefix.'alt_location' => [
        'query' => "SELECT DISTINCT alt_location FROM down_equipment",
    ],
    $prefix.'customer' => [
        'query' => "SELECT DISTINCT name FROM customer",
    ],
    $prefix.'department' => [
        'query' => "SELECT DISTINCT department FROM user_profiles ORDER BY department",
    ],
    $prefix.'location' => [
        'query' => "SELECT DISTINCT nama FROM hr_location ORDER BY nama",
    ],
    $prefix.'name' => [
        'query' => 'SELECT DISTINCT name FROM user_profiles',
    ],
    $prefix.'op_svc_category' => [
        'query' => "SELECT category FROM op_svc_category ORDER BY priority",
    ],
    $prefix.'op_svc_category_engine' => [
        'query' => "SELECT problem FROM op_svc_category_problem WHERE category = 'Engine'",
    ],
    $prefix.'op_svc_category_driveTrain' => [
        'query' => "SELECT problem FROM op_svc_category_problem WHERE category = 'Drive Train'",
    ],
    $prefix.'op_svc_category_chasis' => [
        'query' => "SELECT problem FROM op_svc_category_problem WHERE category = 'Chasis'",
    ],
    $prefix.'op_svc_category_electricalBody' => [
        'query' => "SELECT problem FROM op_svc_category_problem WHERE category = 'Electrical Body'",
    ],
    $prefix.'op_svc_category_acSystem' => [
        'query' => "SELECT problem FROM op_svc_category_problem WHERE category = 'AC System'",
    ],
    $prefix.'op_svc_category_repairElectrical' => [
        'query' => "SELECT problem FROM op_svc_category_problem WHERE category = 'Repair Electrical'",
    ],
    $prefix.'op_svc_category_defact' => [
        'query' => "SELECT problem FROM op_svc_category_problem WHERE category = 'Defact'",
    ],
    $prefix.'op_svc_category_body' => [
        'query' => "SELECT problem FROM op_svc_category_problem WHERE category = 'Body'",
    ],
    $prefix.'position' => [
        'query' => "SELECT DISTINCT jabatan FROM user_profiles",
    ],
    $prefix.'tk_no' => [
        'query' => "SELECT DISTINCT tk_no FROM down_equipment WHERE status_unit_3 = 'Rental' AND tk_no NOT IN (SELECT ds.tk_no FROM de_site ds)",
    ],
    $prefix.'tk_no_spare' => [
        'query' => "SELECT DISTINCT tk_no FROM down_equipment WHERE tk_no NOT IN (SELECT ds.tk_no FROM de_site ds WHERE ds.done = 0 AND ds.deleted = 0)",
    ],
    $prefix.'vehicle_type' => [
        'query' => "SELECT DISTINCT vehicle_type FROM down_equipment",
    ],
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

$companyScoped = in_array($request, [
    $prefix.'name',
    $prefix.'department',
    $prefix.'position'
]);

if (isset($allowed_routes[$request])) {
    $config = array_merge($default_config, $allowed_routes[$request]);

    if($config["auth"]){
        authorize($config["level"], $config["permissions"], $config["not_permissions"], $config["username"]);
    }
    
    if (count($params) != $config['params']) {
        http_response_code(400);
        echo json_encode([
            'status' => 400,
            'error' => "Wrong parameters for '$request'"
        ]);
        exit();
    }

    if ($id_company != 0 && $companyScoped) {
        // Detect and extract ORDER BY clause if it exists
        $orderBy = '';
        if (preg_match('/\s+ORDER\s+BY\s+.+$/i', $config['query'], $matches)) {
            $orderBy = $matches[0]; // e.g., " ORDER BY department"
            $config['query'] = preg_replace('/\s+ORDER\s+BY\s+.+$/i', '', $config['query']); // remove ORDER BY temporarily
        }

        // Add WHERE or AND depending on existing clause
        if (stripos($config['query'], 'where') !== false) {
            $config['query'] .= " AND id_company = ?";
        } else {
            $config['query'] .= " WHERE id_company = ?";
        }

        // Re-append ORDER BY if it was removed
        $config['query'] .= $orderBy;

        // Append param and increment
        $params[] = $id_company;
        $config['params'] += 1;
    }
    // echo json_encode(["adf" => $id_company, "adsf" => $companyScoped]);

    echo json_encode($config);

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
