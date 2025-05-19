<?php
header("Content-Type: application/json");
require 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method != 'GET') {
    http_response_code(405);
    echo json_encode(["status" => 405, "message" => "Method not allowed"]);
    exit;
}

try {
    // Query untuk menghitung total karyawan masuk hari ini
    $sql = "SELECT COUNT(DISTINCT username) AS total_masuk 
            FROM hr_absensi 
            WHERE tanggal = CURDATE() 
            AND hour_in IS NOT NULL";
    
    $result = $conn->query($sql);
    
    if (!$result) {
        throw new Exception("Database error: " . $conn->error);
    }
    
    $data = $result->fetch_assoc();
    
    echo json_encode([
        "status" => 200,
        "data" => [
            "total_masuk" => (int)$data['total_masuk']
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => 500,
        "message" => "Server error",
        "error" => $e->getMessage()
    ]);
} finally {
    $conn->close();
}
?>