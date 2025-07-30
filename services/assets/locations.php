<?php
header("Content-Type: application/json");
require 'db.php';     
require 'auth.php';     

authorize(5, ["admin_asset"], [], null);
$user = verifyToken();

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method !== 'GET') {
        throw new Exception("Method not allowed", 405);
    }

    $conn->autocommit(false);

    $sql = "SELECT id, name FROM asset_locations ORDER BY name ASC";
    $result = $conn->query($sql);
    if (!$result) throw new Exception("Query failed: " . $conn->error);

    $locations = [];
    while ($row = $result->fetch_assoc()) {
        $locations[] = $row;
    }

    $conn->commit();

    echo json_encode([
        "status" => 200,
        "data" => $locations
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
