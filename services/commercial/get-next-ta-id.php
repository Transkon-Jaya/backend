// File: get-next-ta-id.php
<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method !== 'GET') {
        throw new Exception("Method tidak diizinkan", 405);
    }

    $conn->begin_transaction();

    // Increment counter dan ambil nilai baru
    $stmt = $conn->prepare("UPDATE transmittal_counter SET current_number = current_number + 1 WHERE id = 1");
    $stmt->execute();

    if ($stmt->affected_rows === 0) {
        throw new Exception("Counter tidak ditemukan", 500);
    }

    $stmt = $conn->prepare("SELECT current_number FROM transmittal_counter WHERE id = 1");
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $nextNumber = (int)$row['current_number'];

    // Format TA ID
    $now = new DateTime();
    $year = $now->format('y');
    $month = $now->format('m');
    $day = $now->format('d');
    $taId = "TRJA{$year}{$month}{$day}-{$nextNumber}";

    $conn->commit();

    echo json_encode([
        "status" => 200,
        "ta_id" => $taId,
        "number" => $nextNumber
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
?>