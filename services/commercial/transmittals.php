<?php
header("Content-Type: application/json");
require 'db.php';     
require 'auth.php';     

// authorize(9, ["admin_asset"], [], null);
$user = verifyToken();

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method !== 'GET') {
        throw new Exception("Method not allowed", 405);
    }

    $conn->autocommit(false);

    $sql = "SELECT * FROM transmittals_new WHERE 1=1 ORDER BY date DESC";
    $result = $conn->query($sql);
    if (!$result) {
        throw new Exception("Query failed: " . $conn->error, 500);
    }

    $items = [];
    while ($row = $result->fetch_assoc()) {
        // Kalau doc_details isinya JSON, bisa decode
        $row['doc_details'] = json_decode($row['doc_details'], true) ?: [];
        $items[] = $row;
    }

    $conn->commit();

    echo json_encode([
        "status" => 200,
        "data" => $items
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
