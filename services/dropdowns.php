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
  'dropdown/tk_no'        => 'SELECT DISTINCT tk_no FROM down_equipment',
  'dropdown/vehicle_type' => 'SELECT DISTINCT vehicle_type FROM down_equipment',
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

