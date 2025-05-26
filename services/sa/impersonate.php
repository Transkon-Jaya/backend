<?php

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Reject non-POST methods
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => 405, "error" => "Method Not Allowed"]);
    exit;
}

require 'vendor/autoload.php';
use Firebase\JWT\JWT;
use Dotenv\Dotenv;

require_once 'db.php';
require_once 'auth.php';

// Require level 0 user to access impersonation
authorize(0, [], [], null);

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();
$secret_key = $_ENV['JWT_SECRET'] ?? 'fallback-secret-key';

// Parse request body
$data = json_decode(file_get_contents("php://input"), true);
$username = $data['username'] ?? '';

if (empty($username)) {
    http_response_code(400);
    echo json_encode(["status" => 400, "error" => "Username is required"]);
    exit;
}

// Fetch user data
$sql = "CALL user_login(?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    http_response_code(404);
    echo json_encode(["status" => 404, "error" => "User not found"]);
    exit;
}

// Fetch permissions
$sql = "CALL user_get_permissions(?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
$permissions = [];
while ($row = $result->fetch_assoc()) {
    $permissions[] = $row['permission'];
}
$stmt->close();

$issued_at = time();
$expiration_time = $issued_at + (60 * 60 * 14); // 14 hours

$payload = [
    "username" => $username,
    "photo" => $user['photo'],
    "user_level" => $user['user_level'],
    "permissions" => $permissions,
    "exp" => $expiration_time
];

$jwt = JWT::encode($payload, $secret_key, 'HS256');

http_response_code(200);
echo json_encode([
    "status" => 200,
    "token" => $jwt,
    "payload" => $payload
]);

$conn->close();
