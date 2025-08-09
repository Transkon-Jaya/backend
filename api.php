<?php
//header(...); // (optional) add CORS headers here

if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit();
}

$allowed_routes = [
    'ip'             => 'services/ip.php',
    'sa/impersonate' => 'services/sa/impersonate.php',
    'shortener'      => 'services/tools/shortener.php',
    'tools/memo'     => 'services/tools/memo.php',
    'test'           => 'services/test.php',
    'test2'          => 'services/test2.php',
    'login'          => 'login.php',
    'absensi'        => 'services/hr/absensi.php',
    // 'timeoff'        => 'services/hr/timeoff.php',
    'perizinan'      => 'services/hr/perizinan.php',
    'jabatan'        => 'services/hr/jabatan.php',
    'approval-action' => 'services/hr/approval-action.php',
    'get-pending-approvals' => 'services/hr/get-pending-approvals.php',
    'absensi-status' => 'services/hr/absensi-status.php',
    'absensi-gallery'=> 'services/hr/absensi-gallery.php',
    'absensi-latest' => 'services/hr/absensi-latest.php',
    'absensi-summary'=> 'services/hr/absensi-summary.php',
    'transmittal' => 'services/commercial/transmittals.php',
    'transmittal_invoices' => 'services/commercial/transmittal_invoices.php',
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
    'total-masuk'    => 'services/hr/total-masuk.php',
    'holiday'        => 'services/hr/holiday.php',
    'assets'         => 'services/assets/assets.php',
    'asset_age'      => 'services/assets/asset_age.php',
    'asset-categories' => 'services/assets/categories.php',
    'asset-locations' => 'services/assets/locations.php',
    'asset-stocks'    => 'services/assets/stocks.php',
    'asset-upload'   => 'services/assets/upload.php',
    'asset-users'   => 'services/assets/users.php',
    'score'            => 'services/score.php',
    'asset-stock-history' => 'services/assets/stock-history.php'
];

$allowed_startswith = [
    'hr' => 'services/hr/hr.php',
    'chart' => 'services/chart.php',
    'dropdowns' => 'services/dropdowns.php',
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
