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

// Load .env variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$secret_key = $_ENV['JWT_SECRET'] ?? "fallback-secret-key";

// Read JSON input
$data = json_decode(file_get_contents("php://input"), true);
$username = $data['username'] ?? '';
$password = $data['password'] ?? '';

if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(["status" => 400, "error" => "Username and password are required"]);
    exit;
}

// Query database with stored procedure
$sql = "CALL user_login(?)"; 
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
} else {
    $user = null;
}

$stmt->close();

if ($user) {
    $user_level = $user["user_level"];
    $photo = $user["photo"];
    $name = $user["name"];
    $id_company = $user["id_company"];
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

    if (password_verify($password, $user['passwd'])) {
        $issued_at = time();
        $expiration_time = $issued_at + (60 * 60 * 24 * 6); // Token expires in 6 days
        $payload = [
            "username" => $username,
            "name" => $name,
            "photo" => $photo,
            "user_level" => $user_level,
            "permissions" => $permissions,
            "id_company" => $id_company,
            "exp" => $expiration_time
        ];
        http_response_code(200);
        $jwt = JWT::encode($payload, $secret_key, 'HS256');
        echo json_encode(["status" => 200, "token" => $jwt, "name" => $name, "photo" => $photo, "user_level" => $user_level, "permissions" => $permissions]);
    } else {
        http_response_code(401);
        echo json_encode(["status" => 401, "error" => "Invalid username or password"]);
    }
} else {
    http_response_code(401);
    echo json_encode(["status" => 401, "error" => "Invalid username or password"]);
}

$stmt->close();
$conn->close();
?>
