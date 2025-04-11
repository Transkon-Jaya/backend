<?php
//header("Access-Control-Allow-Origin: *"); 
//header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
//header("Access-Control-Allow-Headers: Content-Type, Authorization");
//header("Access-Control-Allow-Credentials: true");
//header("Content-Type: application/json");

// Handle preflight request (OPTIONS method)
if ($_SERVER['REQUEST_METHOD'] == "OPTIONS") {
    http_response_code(200);
    exit();
}

$allowed_routes = [
  'test'        => 'test.php',
  'login'       => 'login.php',
  'absensi'     => 'services/hr/absensi.php',
  'absensi-status'=> 'services/hr/absensi-status.php',
  'hr-lokasi'   => 'services/hr/lokasi.php',
  'pengumuman'  => 'services/pengumuman.php',
  'users'       => 'services/users.php',
  'customer'    => 'services/customer.php',
  'marketing'   => 'services/marketing.php',
  'po-unit'     => 'services/po_unit.php',
  'location'    => 'services/location.php',
  'webhook-be'  => 'webhook-be.php',
  'webhook-fe'  => 'webhook-fe.php',
];

$request = $_GET['request'] ?? '';

if (isset($allowed_routes[$request])) {
  require $allowed_routes[$request];
} else {
  http_response_code(404);
  echo json_encode([
      'status' => 404,
      'error' => "Invalid API endpoint: $request"
  ]);
}
?>

