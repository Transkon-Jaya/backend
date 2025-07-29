<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET' && isset($_GET['get_locations'])) {
        $sql = "SELECT id, name FROM asset_locations WHERE is_active = 1 ORDER BY name";
        $stmt = $conn->prepare($sql);
        
        // ❌ Tidak perlu bind param lagi
        $stmt->execute();
        
        $result = $stmt->get_result();
        $locations = [];
        while ($row = $result->fetch_assoc()) {
            $locations[] = $row;
        }
        
        echo json_encode([
            "status" => 200,
            "data" => $locations
        ]);
        exit;
    }

    http_response_code(405);
    echo json_encode([
        "status" => 405,
        "error" => "Method not allowed or invalid parameters"
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => 500,
        "error" => $e->getMessage()
    ]);
}