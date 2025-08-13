<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$ta_id = $_GET['ta_id'] ?? null;

try {
    $conn->autocommit(false);

    // ========================
    // === GET: Ambil Semua Invoice
    // ========================
    if ($method === 'GET') {
        $sql = "
            SELECT ta_id, description 
FROM transmittals_new 
WHERE 
    ta_id RLIKE '^TRJA[0-9]{4,}$'
    AND CAST(SUBSTRING(ta_id, 5) AS UNSIGNED) >= 2000
ORDER BY 
    CAST(SUBSTRING(ta_id, 5) AS UNSIGNED) DESC;
        ";

        $stmt = $conn->prepare($sql);
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

        $conn->commit();

        echo json_encode([
            "status" => 200,
            "data" => $invoices
        ]);
        exit;
    }

    // ========================
    // === PUT: Update Status Tracking
    // ========================
    if ($method === 'PUT') {
        if (!$ta_id) {
            throw new Exception("TA ID diperlukan", 400);
        }

        $input = json_decode(file_get_contents("php://input"), true);
        if (!is_array($input)) {
            throw new Exception("Data input tidak valid", 400);
        }

        // Cek apakah transmittal ada
        $check = $conn->prepare("SELECT 1 FROM transmittals_new WHERE ta_id = ?");
        $check->bind_param("s", $ta_id);
        $check->execute();
        if ($check->get_result()->num_rows === 0) {
            throw new Exception("Transmittal tidak ditemukan", 404);
        }

        // Siapkan field yang bisa diupdate
        $setParts = [];
        $params = [];
        $types = '';

        $fields = [
            'acct' => 'i',
            'tax_dept' => 'i',
            'admin' => 'i',
            'expedisi' => 'i'
        ];

        foreach ($fields as $field => $type) {
            if (isset($input[$field])) {
                $value = $input[$field] ? 1 : 0;
                $setParts[] = "$field = ?";
                $params[] = $value;
                $types .= $type;
            }
        }

        if (isset($input['remarks'])) {
            $setParts[] = "tracking_remarks = ?";
            $params[] = $input['remarks'];
            $types .= 's';
        }

        if (empty($setParts)) {
            throw new Exception("Tidak ada data untuk diperbarui", 400);
        }

        // Tambahkan updated_at
        $setParts[] = "updated_at = NOW()";
        $sql = "UPDATE transmittals_new SET " . implode(', ', $setParts) . " WHERE ta_id = ?";
        $params[] = $ta_id;
        $types .= 's';

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);

        if (!$stmt->execute()) {
            throw new Exception("Gagal update tracking: " . $stmt->error);
        }

        $conn->commit();

        echo json_encode([
            "status" => 200,
            "message" => "Status tracking berhasil diperbarui"
        ]);
        exit;
    }

    // Method tidak didukung
    throw new Exception("Method tidak diizinkan", 405);

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