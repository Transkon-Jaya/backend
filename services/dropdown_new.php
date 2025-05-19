<?php
// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit();
}

// Require DB connection
require_once __DIR__ . '/../db.php';

$prefix = 'ddn/';
$allowed_routes = [
    $prefix.'customer'     => 'SELECT DISTINCT name FROM customer',
    $prefix.'name'         => 'SELECT DISTINCT name FROM user_profiles',
    $prefix.'department'   => 'SELECT DISTINCT department FROM user_profiles',
    $prefix.'alt_location'  => 'SELECT DISTINCT alt_location FROM down_equipment',
    $prefix.'position'     => 'SELECT DISTINCT jabatan FROM user_profiles',
    $prefix.'tk_no'        => "SELECT DISTINCT tk_no FROM down_equipment WHERE status_unit_3 = 'Rental' AND tk_no NOT IN (SELECT ds.tk_no FROM de_site ds)",
    $prefix.'tk_no_spare'  => "SELECT DISTINCT tk_no FROM down_equipment WHERE tk_no NOT IN (SELECT ds.tk_no FROM de_site ds)",
    $prefix.'vehicle_type' => 'SELECT DISTINCT vehicle_type FROM down_equipment',
    $prefix.'op_svc_category' => 'SELECT category FROM op_svc_category ORDER BY priority',
    $prefix.'op_svc_category_engine' => "SELECT problem FROM op_svc_category_problem WHERE category = 'Engine'",
    $prefix.'op_svc_category_driveTrain' => "SELECT problem FROM op_svc_category_problem WHERE category = 'Drive Train'",
    $prefix.'op_svc_category_chasis' => "SELECT problem FROM op_svc_category_problem WHERE category = 'Chasis'",
    $prefix.'op_svc_category_electricalBody' => "SELECT problem FROM op_svc_category_problem WHERE category = 'Electrical Body'",
    $prefix.'op_svc_category_acSystem' => "SELECT problem FROM op_svc_category_problem WHERE category = 'AC System'",
    $prefix.'op_svc_category_repairElectrical' => "SELECT problem FROM op_svc_category_problem WHERE category = 'Repair Electrical'",
    $prefix.'op_svc_category_defact' => "SELECT problem FROM op_svc_category_problem WHERE category = 'Defact'",
    $prefix.'op_svc_category_body' => "SELECT problem FROM op_svc_category_problem WHERE category = 'Body'"
];

// Get the requested route
$request = $_GET['request'] ?? '';

if (isset($allowed_routes[$request])) {
    $query = $allowed_routes[$request];
    $result = $conn->query($query);

    if ($result) {
        $data = [];
        // while ($row = $result->fetch_assoc()) {
        //     $data[] = $row;
        // }
        while ($row = $result->fetch_row()) { // fetch_row gives indexed array instead of associative
            $data[] = $row[0];
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
