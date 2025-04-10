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


$request = $_GET['request'] ?? '';

switch ($request) {
    case 'test':
      require 'test.php';
      break;
    case 'login':
      require 'login.php';
      break;
    case 'pengumuman':
      require './services/pengumuman.php';
      break;
    case 'users':
      require './services/users.php';
      break;
    case 'customer':
        require './services/customer.php';
        break;
    case 'marketing':
        require './services/marketing.php';
        break;
    case 'po-unit':
        require './services/po_unit.php';
        break;
    case 'location':
        require './services/location.php';
        break;
    case 'webhook':
        require './webhook.php';
        break;
    default:
        http_response_code(404);
        echo json_encode([
            "status" => 404, 
            "error" => "Invalid API endpoint"
        ]);
}

?>

