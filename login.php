<?php
require 'vendor/autoload.php';
use Firebase\JWT\JWT;
use Dotenv\Dotenv;
require 'db.php';

// helper to flush all pending results
function flushAllResults(mysqli $conn) {
    while ($conn->more_results() && $conn->next_result()) { /* noop */ }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status"=>405,"error"=>"Method Not Allowed"]);
    exit;
}
Dotenv::createImmutable(__DIR__)->load();
$secret = $_ENV['JWT_SECRET'] ?? 'fallback-secret-key';

$body     = json_decode(file_get_contents("php://input"), true);
$username = $body['username'] ?? '';
$password = $body['password'] ?? '';

if (!$username || !$password) {
    http_response_code(400);
    echo json_encode(["status"=>400,"error"=>"Username and password are required"]);
    exit;
}

try {
    // 1) login lookup
    $stmt = $conn->prepare("CALL user_login(?)");
    if (!$stmt) throw new Exception($conn->error);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = ($result && $result->num_rows ? $result->fetch_assoc() : null);
    $result->free();
    $stmt->close();
    flushAllResults($conn);

    // 2) permissions
    $permissions = [];
    if ($user) {
        $stmt2 = $conn->prepare("CALL user_get_permissions(?)");
        if (!$stmt2) throw new Exception($conn->error);
        $stmt2->bind_param("s", $username);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        while ($r = $res2->fetch_assoc()) {
            $permissions[] = $r['permission'];
        }
        $res2->free();
        $stmt2->close();
        flushAllResults($conn);
    }

    // 3) auth check
    if (!$user || !password_verify($password, $user['passwd'])) {
        http_response_code(401);
        echo json_encode(["status"=>401,"error"=>"Invalid username or password"]);
        exit;
    }

    // 4) issue token
    $now = time();
    $payload = [
        "username"    => $username,
        "name"        => $user['name'],
        "photo"       => $user['photo'],
        "user_level"  => $user['user_level'],
        "permissions" => $permissions,
        "id_company"  => $user['id_company'],
        "iat"         => $now,
        "exp"         => $now + 60*60*24*6
    ];
    $jwt = JWT::encode($payload, $secret, 'HS256');

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
    echo json_encode([
        "status"  => 500,
        "error"   => "Server error",
        "details" => $e->getMessage()
    ]);
} finally {
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        @$stmt->close();
    }
    if (isset($stmt2) && $stmt2 instanceof mysqli_stmt) {
        @$stmt2->close();
    }
    $conn->close();
}
