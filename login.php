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

$user = null;
$permissions = [];

try {
    // Login lookup
    $stmt = $conn->prepare("CALL user_login(?)");
    if (!$stmt) {
        throw new Exception("Failed to prepare user_login");
    }
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();
    }
    $stmt->close();

    // If user found, get permissions
    if ($user) {
        $stmt_permissions = $conn->prepare("CALL user_get_permissions(?)");
        if (!$stmt_permissions) {
            throw new Exception("Failed to prepare user_get_permissions");
        }
        $stmt_permissions->bind_param("s", $username);
        $stmt_permissions->execute();
        $result_permissions = $stmt_permissions->get_result();

        while ($row = $result_permissions->fetch_assoc()) {
            $permissions[] = $row['permission'];
        }
        $stmt_permissions->close();

        // Verify password
        if (password_verify($password, $user['passwd'])) {
            $issued_at = time();
            $expiration_time = $issued_at + (60 * 60 * 24 * 6); // 6 days
            $payload = [
                "username"     => $username,
                "name"         => $user["name"],
                "photo"        => $user["photo"],
                "user_level"   => $user["user_level"],
                "permissions"  => $permissions,
                "id_company"   => $user["id_company"],
                "exp"          => $expiration_time
            ];

            $jwt = JWT::encode($payload, $secret_key, 'HS256');

            http_response_code(200);
            echo json_encode([
                "status"      => 200,
                "token"       => $jwt,
                "name"        => $user["name"],
                "photo"       => $user["photo"],
                "user_level"  => $user["user_level"],
                "permissions" => $permissions
            ]);
        } else {
            http_response_code(401);
            echo json_encode(["status" => 401, "error" => "Invalid username or password"]);
        }
    } else {
        http_response_code(401);
        echo json_encode(["status" => 401, "error" => "Invalid username or password"]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => 500, "error" => "Server error", "details" => $e->getMessage()]);
} finally {
    // Cleanup
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        @$stmt->close();
    }
    if (isset($stmt_permissions) && $stmt_permissions instanceof mysqli_stmt) {
        @$stmt_permissions->close();
    }
    $conn->close();
}

?>
