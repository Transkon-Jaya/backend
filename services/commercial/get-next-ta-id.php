<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php'; // Pastikan otorisasi tetap ada

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method !== 'GET') {
        throw new Exception("Method tidak diizinkan", 405);
    }

    // Mulai transaksi untuk mencegah race condition
    $conn->begin_transaction();

    // 1. Ambil nomor terakhir (dengan SELECT ... FOR UPDATE untuk mengunci baris)
    $stmt = $conn->prepare("SELECT current_number FROM transmittal_counter WHERE id = 1 FOR UPDATE");
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    if (!$row) {
        throw new Exception("Counter tidak ditemukan", 500);
    }

    $currentNumber = (int)$row['current_number'];
    $nextNumber = $currentNumber + 1;

    // 2. Update nomor di database
    $updateStmt = $conn->prepare("UPDATE transmittal_counter SET current_number = ? WHERE id = 1");
    $updateStmt->bind_param("i", $nextNumber);
    $updateStmt->execute();

    if ($updateStmt->affected_rows === 0) {
        throw new Exception("Gagal mengupdate counter", 500);
    }

    // Commit transaksi
    $conn->commit();

    // 3. Kirim nomor yang baru saja didapat (bukan yang sudah di-increment)
    echo json_encode([
        "status" => 200,
        "number" => $currentNumber
    ]);

} catch (Exception $e) {
    // Rollback jika ada error
    $conn->rollback();
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => $e->getCode() ?: 500,
        "error" => $e->getMessage()
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>