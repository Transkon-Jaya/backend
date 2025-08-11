<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    // Hanya GET yang diizinkan
    if ($method !== 'GET') {
        throw new Exception("Method tidak diizinkan", 405);
    }

    // Ambil semua transmittal dengan document_type = 'Invoice' atau description mengandung invoice number
    $stmt = $conn->prepare("
        SELECT 
            ta_id,
            description AS invoiceNo,
            date AS invoiceDate,
            acct,
            tax_dept,
            admin,
            expedisi,
            tracking_remarks AS remarks
        FROM transmittals_new 
        WHERE description IS NOT NULL 
          AND description != ''
          AND (document_type = 'Invoice' OR description REGEXP '^[A-Z]{1,2}[0-9]{6,8}')
        ORDER BY date DESC
    ");
    $stmt->execute();
    $result = $stmt->get_result();

    $invoices = [];
    while ($row = $result->fetch_assoc()) {
        $invoices[] = [
            'ta_id' => $row['ta_id'],
            'invoiceNo' => $row['invoiceNo'],
            'invoiceDate' => $row['invoiceDate'],
            'acct' => (bool)$row['acct'],
            'tax_dept' => (bool)$row['tax_dept'],
            'admin' => (bool)$row['admin'],
            'expedisi' => (bool)$row['expedisi'],
            'remarks' => $row['remarks'] ?? ''
        ];
    }

    echo json_encode([
        "status" => 200,
        "data" => $invoices
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