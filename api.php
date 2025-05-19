<?php
//header(...); // (optional) add CORS headers here

if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit();
}

$allowed_routes = [
    'ip'             => 'services/ip.php',
    'ip-auth'        => 'services/ip-auth.php',
    'test'           => 'test.php',
    'login'          => 'login.php',
    'absensi'        => 'services/hr/absensi.php',
    'perizinan'      => 'services/hr/perizinan.php',
    'absensi-status' => 'services/hr/absensi-status.php',
    'absensi-latest' => 'services/hr/absensi-latest.php',
    'de_site'        => 'services/de/de_site.php',
    'down_equipment' => 'services/de/down_equipment.php',
    'hr-lokasi'      => 'services/hr/lokasi.php',
    'pengumuman'     => 'services/pengumuman.php',
    'users'          => 'services/users.php',
    'customer'       => 'services/customer.php',
    'marketing'      => 'services/marketing.php',
    'po-unit'        => 'services/po_unit.php',
    'profile'        => 'services/profile.php',
    'location'       => 'services/location.php',
    'webhook-be'     => 'webhook-be.php',
    'webhook-fe'     => 'webhook-fe.php',
    'table-user'     => 'services/hr/table-user.php',
];

$allowed_startswith = [
    'chart' => 'services/chart.php',
    'dropdowns' => 'services/dropdowns.php',
    'ddn' => "services/dropdown_new.php",
    'get'   => 'services/get.php',
    'data'  => 'services/data.php'
];

$request = $_GET['request'] ?? '';

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
