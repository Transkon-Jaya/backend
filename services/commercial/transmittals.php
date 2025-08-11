<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

// 🔐 Ambil user dari token
$currentUser = authorize(); // Akan exit jika token tidak valid
$createdBy = $currentUser['name'] ?? 'System'; // Gunakan nama user, fallback ke 'System'

$method = $_SERVER['REQUEST_METHOD'];

// Jika POST dengan _method=PUT/DELETE, override method
$originalMethod = $method;
if ($method === 'POST') {
    $overrideMethod = $_POST['_method'] ?? null;
    if (!$overrideMethod) {
        $input = json_decode(file_get_contents("php://input"), true);
        $overrideMethod = $input['_method'] ?? null;
    }
    if ($overrideMethod) {
        $method = strtoupper($overrideMethod);
    }
}

// Ambil parameter
$ta_id = $_GET['ta_id'] ?? null;
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = min(50, max(10, (int)($_GET['limit'] ?? 10)));

try {
    $conn->autocommit(false);

    // ========================
    // === POST: Create New Transmittal
    // ========================
    if ($method === 'POST') {
        $input = json_decode(file_get_contents("php://input"), true);
        if (!is_array($input)) {
            throw new Exception("Data input tidak valid", 400);
        }

        // Validasi field wajib
        if (empty($input['ta_id'])) {
            throw new Exception("TA ID wajib diisi", 400);
        }
        if (empty($input['date'])) {
            throw new Exception("Tanggal wajib diisi", 400);
        }
        if (empty($input['from_origin'])) {
            throw new Exception("From Origin wajib diisi", 400);
        }

        // Siapkan data
        $ta_id          = $input['ta_id'];
        $date           = $input['date'];
        $from_origin    = $input['from_origin'];
        $document_type  = $input['document_type'] ?? null;
        $attention      = $input['attention'] ?? '';
        $company        = $input['company'] ?? '';
        $address        = $input['address'] ?? '';
        $state          = $input['state'] ?? '';
        $awb_reg        = $input['awb_reg'] ?? '';
        $expeditur      = $input['expeditur'] ?? '';
        $receiver_name  = $input['receiver_name'] ?? null;
        $receive_date   = $input['receive_date'] ?? null;
        $ras_status     = $input['ras_status'] ?? null;
        $description    = $input['description'] ?? '';
        $remarks        = $input['remarks'] ?? '';

        // Cek duplikasi TA ID
        $checkStmt = $conn->prepare("SELECT 1 FROM transmittals_new WHERE ta_id = ?");
        $checkStmt->bind_param("s", $ta_id);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            throw new Exception("TA ID sudah ada: $ta_id", 409);
        }

        // Insert ke database
        $sql = "INSERT INTO transmittals_new (
                    ta_id, date, from_origin, document_type, attention, company,
                    address, state, awb_reg, expeditur, receiver_name, receive_date,
                    ras_status, description, remarks, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Gagal prepare query: " . $conn->error);
        }

        $stmt->bind_param(
            "ssssssssssssssss",
            $ta_id,
            $date,
            $from_origin,
            $document_type,
            $attention,
            $company,
            $address,
            $state,
            $awb_reg,
            $expeditur,
            $receiver_name,
            $receive_date,
            $ras_status,
            $description,
            $remarks,
            $createdBy
        );

        if (!$stmt->execute()) {
            throw new Exception("Gagal menyimpan data: " . $stmt->error);
        }

        $conn->commit();

        echo json_encode([
            "status" => 201,
            "message" => "Transmittal berhasil dibuat",
            "ta_id" => $ta_id,
            "created_by" => $createdBy
        ]);
        exit;
    }

    // ========================
    // === PUT: Update Transmittal
    // ========================
    if ($method === 'PUT') {
        if (!$ta_id) {
            throw new Exception("TA ID diperlukan", 400);
        }

        $input = json_decode(file_get_contents("php://input"), true);
        if (!is_array($input)) {
            throw new Exception("Data tidak valid", 400);
        }

        // Cek eksistensi
        $check = $conn->prepare("SELECT 1 FROM transmittals_new WHERE ta_id = ?");
        $check->bind_param("s", $ta_id);
        $check->execute();
        if ($check->get_result()->num_rows === 0) {
            throw new Exception("Transmittal tidak ditemukan", 404);
        }

        // Field yang bisa diupdate
        $fields = [
            'date', 'from_origin', 'document_type', 'attention', 'company',
            'address', 'state', 'awb_reg', 'expeditur', 'receiver_name',
            'receive_date', 'ras_status', 'description', 'remarks'
        ];

        $setParts = [];
        $params = [];
        $types = '';

        foreach ($fields as $field) {
            if (array_key_exists($field, $input)) {
                $setParts[] = "$field = ?";
                $params[] = $input[$field];
                $types .= 's';
            }
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
        if (!$stmt) {
            throw new Exception("Gagal prepare query: " . $conn->error);
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        $conn->commit();

        echo json_encode([
            "status" => 200,
            "message" => "Transmittal berhasil diperbarui"
        ]);
        exit;
    }

    // ========================
    // === DELETE: Hapus Transmittal
    // ========================
    if ($method === 'DELETE') {
        if (!$ta_id) {
            throw new Exception("TA ID diperlukan", 400);
        }

        $stmt = $conn->prepare("DELETE FROM transmittals_new WHERE ta_id = ?");
        $stmt->bind_param("s", $ta_id);
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            throw new Exception("Transmittal tidak ditemukan", 404);
        }

        $conn->commit();

        echo json_encode([
            "status" => 200,
            "message" => "Transmittal berhasil dihapus"
        ]);
        exit;
    }

    // ========================
    // === GET: Ambil Satu Data
    // ========================
    if ($method === 'GET' && $ta_id) {
        $stmt = $conn->prepare("
            SELECT * FROM transmittals_new WHERE ta_id = ?
        ");
        $stmt->bind_param("s", $ta_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();

        if (!$data) {
            throw new Exception("Transmittal tidak ditemukan", 404);
        }

        $conn->commit();

        echo json_encode([
            "status" => 200,
            "data" => $data
        ]);
        exit;
    }

    // ========================
    // === GET: List Semua Transmittal (Paginated)
    // ========================
    if ($method === 'GET') {
        $offset = ($page - 1) * $limit;

        // Hitung total
        $countStmt = $conn->query("SELECT COUNT(*) as total FROM transmittals_new");
        $total = $countStmt->fetch_assoc()['total'];
        $totalPages = ceil($total / $limit);

        // Ambil data
        $stmt = $conn->prepare("
            SELECT ta_id, date, from_origin, document_type, company, 
                   ras_status, description, remarks, created_by, created_at
            FROM transmittals_new 
            ORDER BY date DESC 
            LIMIT ? OFFSET ?
        ");
        $stmt->bind_param("ii", $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }

        $conn->commit();

        echo json_encode([
            "status" => 200,
            "items" => $items,
            "totalCount" => (int)$total,
            "totalPages" => (int)$totalPages,
            "page" => $page,
            "limit" => $limit
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