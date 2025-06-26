<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

authorize(5, ["admin_asset"], [], null);
$user = verifyToken();

$method = $_SERVER['REQUEST_METHOD'];
$assetId = $_GET['asset_id'] ?? null;

try {
    if ($method !== 'GET') {
        throw new Exception("Method not allowed", 405);
    }

    if (!$assetId) {
        throw new Exception("Asset ID is required", 400);
    }

    $stmt = $conn->prepare("
        SELECT * FROM asset_stock_history 
        WHERE asset_id = ? 
        ORDER BY date DESC
    ");
    $stmt->bind_param("i", $assetId);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $history = [];
    
    while ($row = $result->fetch_assoc()) {
        $history[] = $row;
    }

    echo json_encode([
        "status" => 200,
        "data" => $history
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => $e->getCode() ?: 500,
        "error" => $e->getMessage()
    ]);
}

$conn->close();
?>