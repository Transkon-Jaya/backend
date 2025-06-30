<?php
header("Content-Type: application/json");
require '../../db.php';  // Adjust path as needed
require '../../auth.php'; // Adjust path as needed

$method = $_SERVER['REQUEST_METHOD'];
$id_company = $_SESSION['user']['id_company'] ?? null;

try {
    // Locations Endpoint
    if ($method === 'GET' && isset($_GET['get_locations'])) {
        $sql = "SELECT id, name FROM asset_locations WHERE id_company = ? ORDER BY name";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id_company);
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

    // Add other dropdown endpoints here...

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