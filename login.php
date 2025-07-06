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
$body = json_decode(file_get_contents("php://input"), true);
$username = $body['username'] ?? '';
$password = $body['password'] ?? '';

if (!$username || !$password) {
    http_response_code(400);
    echo json_encode(["status" => 400, "error" => "Username and password are required"]);
    exit;
}

$user = null;
$permissions = [];

try {
    //
    // 1) CALL user_login
    //
    $stmt = $conn->prepare("CALL user_login(?)");
    if (!$stmt) throw new Exception("Prepare user_login failed: " . $conn->error);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();
    }
    // **cleanup after PROC**
    if ($result) { $result->free(); }
    $stmt->close();
    $conn->next_result();

    //
    // 2) CALL user_get_permissions
    //
    if ($user) {
        $stmt2 = $conn->prepare("CALL user_get_permissions(?)");
        if (!$stmt2) throw new Exception("Prepare user_get_permissions failed: " . $conn->error);
        $stmt2->bind_param("s", $username);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        while ($row = $res2->fetch_assoc()) {
            $permissions[] = $row['permission'];
        }
        // cleanup
        if ($res2) { $res2->free(); }
        $stmt2->close();
        $conn->next_result();
    }

    //
    // 3) Verify & respond
    //
    if (!$user || !password_verify($password, $user['passwd'])) {
        http_response_code(401);
        echo json_encode(["status" => 401, "error" => "Invalid username or password"]);
        exit;
    }

    // build JWT
    $iat = time();
    $exp = $iat + 60*60*24*6;
    $payload = [
        "username"    => $username,
        "name"        => $user['name'],
        "photo"       => $user['photo'],
        "user_level"  => $user['user_level'],
        "permissions" => $permissions,
        "id_company"  => $user['id_company'],
        "exp"         => $exp
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

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => 500, "error" => "Server error", "details" => $e->getMessage()]);
} finally {
    // ensure any leftover stmts are closed
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        @$stmt->close();
    }
    if (isset($stmt2) && $stmt2 instanceof mysqli_stmt) {
        @$stmt2->close();
    }
    $conn->close();
}
