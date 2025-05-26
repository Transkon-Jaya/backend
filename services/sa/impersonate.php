<?php
// Handle preflight request (CORS)
if ($_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    http_response_code(200);
    exit();
}

require 'vendor/autoload.php';
use Firebase\JWT\JWT;
use Dotenv\Dotenv;

require_once __DIR__ . '/../db.php';
require_once 'auth.php';

authorize(0, [], [], null);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => 405, "error" => "Method Not Allowed"]);
    exit;
}

// Load .env variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$secret_key = $_ENV['JWT_SECRET'] ?? "fallback-secret-key";

// Read JSON input
$data = json_decode(file_get_contents("php://input"), true);
$username = $data['username'] ?? '';

if (empty($username)) {
    http_response_code(400);
    echo json_encode(["status" => 400, "error" => "Username is required"]);
    exit;
}

// Get user info
$sql = "CALL user_login(?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
} else {
    http_response_code(404);
    echo json_encode(["status" => 404, "error" => "User not found"]);
    exit;
}

$stmt->close();

// Get user permissions
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
?>
