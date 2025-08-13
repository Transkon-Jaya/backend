<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php'; // Pastikan otorisasi ada

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method !== 'POST') {
        throw new Exception("Method tidak diizinkan", 405);
    }

    $data = json_decode(file_get_contents("php://input"), true);

    // Validasi data yang diperlukan
    if (empty($data['date']) || empty($data['from_origin'])) {
        throw new Exception("Data 'date' dan 'from_origin' wajib diisi", 400);
    }

    // Mulai transaksi
    $conn->begin_transaction();

    // 1. Ambil nomor terakhir dan kunci baris
    $stmt = $conn->prepare("SELECT current_number FROM transmittal_counter WHERE id = 1 FOR UPDATE");
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    if (!$row) {
        throw new Exception("Counter tidak ditemukan", 500);
    }

    $currentNumber = (int)$row['current_number'];
    $nextNumber = $currentNumber + 1;
    
    // 2. Format TA ID baru
    $taId = "TRJA" . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

    // 3. Masukkan data transmittal baru ke database
    $stmt = $conn->prepare("INSERT INTO transmittals (ta_id, date, from_origin, document_type, attention, company, address, state, awb_reg, expeditur, receiver_name, receive_date, ras_status, description, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmt->bind_param("sssssssssssssss",
        $taId,
        $data['date'],
        $data['from_origin'],
        $data['document_type'],
        $data['attention'],
        $data['company'],
        $data['address'],
        $data['state'],
        $data['awb_reg'],
        $data['expeditur'],
        $data['receiver_name'],
        $data['receive_date'],
        $data['ras_status'],
        $data['description'],
        $data['remarks']
    );

    $stmt->execute();

    if ($stmt->affected_rows === 0) {
        throw new Exception("Gagal menyimpan transmittal", 500);
    }

    // 4. Update counter di database
    $updateStmt = $conn->prepare("UPDATE transmittal_counter SET current_number = ? WHERE id = 1");
    $updateStmt->bind_param("i", $nextNumber);
    $updateStmt->execute();

    if ($updateStmt->affected_rows === 0) {
        throw new Exception("Gagal mengupdate counter", 500);
    }

    // Commit transaksi
    $conn->commit();

    echo json_encode([
        "status" => 201,
        "message" => "Transmittal berhasil dibuat",
        "ta_id" => $taId
    ]);

} catch (Exception $e) {
    // Rollback jika ada error
    if (isset($conn) && $conn->in_transaction) {
        $conn->rollback();
    }
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