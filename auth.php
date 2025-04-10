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
        echo json_encode(["status" => 401, "error" => "Unauthorized"]); // No Token Provided
        exit;
    }

    $token = str_replace("Bearer ", "", $headers['Authorization']);

    try {
        $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
        return (array) $decoded; // Convert JWT object to array
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(["status" => 402, "error" => $secret_key); // Invalid Token
        exit;
    }
}

function checkUserLevel($required_level) {
    $user = verifyToken();
    if ($user['user_level'] > $required_level) { // Higher value = lower access
        http_response_code(403);
        echo json_encode(["status" => 403, "error" => "Forbidden - Insufficient permissions"]);
        exit;
    }
    return $user;
}

?>