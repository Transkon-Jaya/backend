<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method !== 'GET') {
        throw new Exception("Method tidak diizinkan", 405);
    }

    // Hanya baca — TIDAK UPDATE
    $stmt = $conn->prepare("SELECT current_number FROM transmittal_counter WHERE id = 1 FOR UPDATE");
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    if (!$row) {
        throw new Exception("Counter tidak ditemukan", 500);
    }

    $nextNumber = (int)$row['current_number'];

    $now = new DateTime();
    $year = $now->format('y');
    $month = $now->format('m');
    $day = $now->format('d');

    $taId = "TRJA{$year}{$month}{$day}-{$nextNumber}";

    echo json_encode([
        "status" => 200,
        "ta_id" => $taId,
        "number" => $nextNumber
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => $e->getCode() ?: 500,
        "error" => $e->getMessage()
    ]);
} finally {
    $conn->close();
}
?>