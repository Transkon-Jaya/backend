<?php
require 'vendor/autoload.php';
use Firebase\JWT\JWT;
use Dotenv\Dotenv;

require 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => 405, "error" => "Method Not Allowed"]);
    exit;
}

// Load .env
Dotenv::createImmutable(__DIR__)->load();
$secret_key = $_ENV['JWT_SECRET'] ?? "fallback-secret-key";

// Read input
$data     = json_decode(file_get_contents("php://input"), true);
$username = $data['username'] ?? '';
$password = $data['password'] ?? '';

if (!$username || !$password) {
    http_response_code(400);
    echo json_encode(["status" => 400, "error" => "Username and password are required"]);
    exit;
}

// 1) user_login
$stmt = $conn->prepare("CALL user_login(?)");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $user = $result->fetch_assoc();
} else {
    $user = null;
}

// cleanup login call
if ($result) { $result->free(); }
$stmt->close();
$conn->next_result();   // flush any extra result sets

// 2) permissions (only if login found)
$permissions = [];
if ($user) {
    $stmt = $conn->prepare("CALL user_get_permissions(?)");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $permissions[] = $row['permission'];
    }

    // cleanup permissions call
    $result->free();
    $stmt->close();
    $conn->next_result();
}

// 3) verify + respond
if (!$user || !password_verify($password, $user['passwd'])) {
    http_response_code(401);
    echo json_encode(["status" => 401, "error" => "Invalid username or password"]);
    $conn->close();
    exit;
}

// build and send JWT
$now = time();
$payload = [
    "username"    => $username,
    "name"        => $user['name'],
    "photo"       => $user['photo'],
    "user_level"  => $user['user_level'],
    "permissions" => $permissions,
    "id_company"  => $user['id_company'],
    "exp"         => $now + 60*60*24*6
];

$jwt = JWT::encode($payload, $secret_key, 'HS256');

http_response_code(200);
echo json_encode([
    "status"      => 200,
    "token"       => $jwt,
    "name"        => $user['name'],
    "photo"       => $user['photo'],
    "user_level"  => $user['user_level'],
    "permissions" => $permissions
]);

$conn->close();
