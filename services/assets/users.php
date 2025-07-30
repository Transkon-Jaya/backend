<?php
header("Content-Type: application/json");
require 'db.php';     
require 'auth.php';     

authorize(9, ["admin_asset"], [], null);
$user = verifyToken();

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method !== 'GET') {
        throw new Exception("Method not allowed", 405);
    }

    $conn->autocommit(false);

    $sql = "SELECT username , name FROM user_profiles WHERE id_company = '1' ORDER BY name";
    $result = $conn->query($sql);
    if (!$result) throw new Exception("Query failed: " . $conn->error);

    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }

    $conn->commit();

    echo json_encode([
        "status" => 200,
        "data" => $users
    ]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => $e->getCode() ?: 500,
        "error" => $e->getMessage()
    ]);
} finally {
    $conn->close();
}
