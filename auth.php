<?php
require 'vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

function verifyToken() {
    $secret_key = $_ENV['JWT_SECRET'];
    $headers = getallheaders();

    if (!isset($headers['Authorization'])) {
        http_response_code(401);
        echo json_encode(["status" => 401, "error" => "Unauthorized - No token provided"]);
        exit;
    }

    $token = str_replace("Bearer ", "", $headers['Authorization']);

    try {
        $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
        return (array) $decoded;
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(["status" => 401, "error" => "Invalid token"]);
        exit;
    }
}

function checkUserLevel($required_level) {
    $user = verifyToken();
    if (!isset($user['user_level']) || $user['user_level'] > $required_level) {
        http_response_code(403);
        echo json_encode(["status" => 403, "error" => "Forbidden - Insufficient user level"]);
        exit;
    }
    return $user;
}

function checkPermissions($required_permissions = [], $forbidden_permissions = []) {
    $user = verifyToken();

    if (!isset($user['permissions']) || !is_array($user['permissions'])) {
        http_response_code(403);
        echo json_encode(["status" => 403, "error" => "Forbidden - Permissions data missing"]);
        exit;
    }

    $user_permissions = $user['permissions'];

    // Required permissions
    foreach ($required_permissions as $perm) {
        if (!in_array($perm, $user_permissions)) {
            http_response_code(403);
            echo json_encode([
                "status" => 403,
                "error" => "Forbidden - Missing required permission: $perm"
            ]);
            exit;
        }
    }

    // Forbidden permissions
    foreach ($forbidden_permissions as $perm) {
        if (in_array($perm, $user_permissions)) {
            http_response_code(403);
            echo json_encode([
                "status" => 403,
                "error" => "Forbidden - Permission not allowed: $perm"
            ]);
            exit;
        }
    }

    return $user;
}

/**
 * Combines token verification, user level, required & forbidden permissions.
 */
function authorize($required_level = null, $required_permissions = [], $forbidden_permissions = [], $match_username = null) {
    // Token check
    $user = verifyToken();

    // Level check
    if ($required_level !== null && (!isset($user['user_level']) || $user['user_level'] > $required_level)) {
        http_response_code(403);
        echo json_encode(["status" => 403, "error" => "Forbidden - Insufficient user level"]);
        exit;
    }

    // Permissions check
    if (!isset($user['permissions']) || !is_array($user['permissions'])) {
        http_response_code(403);
        echo json_encode(["status" => 403, "error" => "Forbidden - Permissions data missing"]);
        exit;
    }

        // Bypass permission checks for user_level 0
    if (isset($user['user_level']) && ($user['user_level'] === 1 || $user['user_level'] === 0)) {
        return $user; // Admin bypass
    }

        // Username match check (if specified)
    if ($match_username !== null && (!isset($user['username']) || $user['username'] !== strtolower($match_username))) {
        http_response_code(403);
        echo json_encode(["status" => 403, "error" => "Forbidden - Username mismatch"]);
        exit;
    }

    foreach ($required_permissions as $perm) {
        if (!in_array($perm, $user['permissions'])) {
            http_response_code(403);
            echo json_encode([
                "status" => 403,
                "error" => "Forbidden - Missing required permission: $perm"
            ]);
            exit;
        }
    }

    foreach ($forbidden_permissions as $perm) {
        if (in_array($perm, $user['permissions'])) {
            http_response_code(403);
            echo json_encode([
                "status" => 403,
                "error" => "Forbidden - Permission not allowed: $perm"
            ]);
            exit;
        }
    }

    return $user;
}
?>
