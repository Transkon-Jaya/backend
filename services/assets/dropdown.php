<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET' && isset($_GET['get_dropdowns'])) {
        // Ambil lokasi
        $sqlLocation = "SELECT id, name FROM asset_locations WHERE is_active = 1 ORDER BY name";
        $stmtLocation = $conn->prepare($sqlLocation);
        $stmtLocation->execute();
        $resultLocation = $stmtLocation->get_result();
        $locations = [];
        while ($row = $resultLocation->fetch_assoc()) {
            $locations[] = $row;
        }

        // Ambil user
        $sqlUser = "SELECT name FROM user_profiles WHERE id_company = '1' ORDER BY name";
        $stmtUser = $conn->prepare($sqlUser);
        $stmtUser->execute();
        $resultUser = $stmtUser->get_result();
        $users = [];
        while ($row = $resultUser->fetch_assoc()) {
            $users[] = $row;
        }

        echo json_encode([
            "status" => 200,
            "locations" => $locations,
            "users" => $users
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
