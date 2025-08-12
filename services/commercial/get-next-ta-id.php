<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method !== 'POST') {
        throw new Exception("Method tidak diizinkan", 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);

    // Validasi data
    $required = ['date', 'from_origin', 'description'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new Exception("Field $field wajib diisi", 400);
        }
    }

    $conn->begin_transaction();

    // --- Langkah 1: Dapatkan dan increment counter ---
    $stmt = $conn->prepare("UPDATE transmittal_counter SET current_number = current_number + 1 WHERE id = 1");
    $stmt->execute();

    if ($stmt->affected_rows === 0) {
        throw new Exception("Gagal increment counter", 500);
    }

    // Ambil nomor yang baru digunakan
    $stmt = $conn->prepare("SELECT current_number FROM transmittal_counter WHERE id = 1");
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $nextNumber = (int)$row['current_number'];

    // --- Langkah 2: Format TA ID ---
    $now = new DateTime();
    $year = $now->format('y');
    $month = $now->format('m');
    $day = $now->format('d');
    $ta_id = "TRJA{$year}{$month}{$day}-{$nextNumber}";

    // --- Langkah 3: Simpan ke transmittals ---
    $stmt = $conn->prepare("
        INSERT INTO transmittals (
            ta_id, date, from_origin, document_type, attention,
            company, address, state, awb_reg, expeditur,
            receiver_name, receive_date, ras_status, description, remarks
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "sssssssssssssss",
        $ta_id,
        $input['date'],
        $input['from_origin'],
        $input['document_type'] ?? null,
        $input['attention'] ?? null,
        $input['company'] ?? null,
        $input['address'] ?? null,
        $input['state'] ?? null,
        $input['awb_reg'] ?? null,
        $input['expeditur'] ?? null,
        $input['receiver_name'] ?? null,
        $input['receive_date'] ?? null,
        $input['ras_status'] ?? null,
        $input['description'],
        $input['remarks'] ?? null
    );

    if (!$stmt->execute()) {
        throw new Exception("Gagal simpan data: " . $stmt->error);
    }

    $conn->commit();

    echo json_encode([
        "status" => 201,
        "message" => "Transmittal berhasil dibuat",
        "ta_id" => $ta_id,
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