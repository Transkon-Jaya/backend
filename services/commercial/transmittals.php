<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

// 🔐 Ambil user dari token
$currentUser = authorize(); // Akan auto 401 jika token tidak valid
$createdBy = $currentUser['name'] ?? 'System';

$method = $_SERVER['REQUEST_METHOD'];

// Handle method override (untuk frontend)
$originalMethod = $method;
if ($method === 'POST') {
    $input = json_decode(file_get_contents("php://input"), true);
    $overrideMethod = $_POST['_method'] ?? ($input['_method'] ?? null);
    if ($overrideMethod) {
        $method = strtoupper($overrideMethod);
    }
}

$ta_id = $_GET['ta_id'] ?? null;
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = min(50, max(10, (int)($_GET['limit'] ?? 10)));

try {
    $conn->autocommit(false);

    // ========================
    // === POST: Create Transmittal
    // ========================
    if ($method === 'POST') {
        $input = json_decode(file_get_contents("php://input"), true);
        if (!is_array($input)) {
            throw new Exception("Data tidak valid", 400);
        }

        // Validasi field wajib (receive_date TIDAK termasuk)
        $required = ['ta_id', 'date', 'from_origin'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                throw new Exception(ucfirst(str_replace('_', ' ', $field)) . " wajib diisi", 400);
            }
        }

        // Siapkan data — receive_date boleh null
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
        $receive_date   = $input['receive_date'] ?? null; // ✅ Boleh null
        $ras_status     = $input['ras_status'] ?? null;
        $description    = $input['description'] ?? '';
        $remarks        = $input['remarks'] ?? '';

        // Cek duplikasi
        $check = $conn->prepare("SELECT 1 FROM transmittals_new WHERE ta_id = ?");
        $check->bind_param("s", $ta_id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            throw new Exception("TA ID sudah ada", 409);
        }

        // Insert
        $sql = "INSERT INTO transmittals_new (
                    ta_id, date, from_origin, document_type, attention, company,
                    address, state, awb_reg, expeditur, receiver_name, receive_date,
                    ras_status, description, remarks, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "ssssssssssssssss",
            $ta_id, $date, $from_origin, $document_type, $attention, $company,
            $address, $state, $awb_reg, $expeditur, $receiver_name, $receive_date,
            $ras_status, $description, $remarks, $createdBy
        );

        if (!$stmt->execute()) {
            throw new Exception("Gagal simpan: " . $stmt->error);
        }

        $conn->commit();

        echo json_encode([
            "status" => 201,
            "message" => "Transmittal berhasil dibuat",
            "ta_id" => $ta_id
        ]);
        exit;
    }

    // ========================
    // === PUT: Update Transmittal
    // ========================
    if ($method === 'PUT') {
        if (!$ta_id) throw new Exception("TA ID diperlukan", 400);

        $input = json_decode(file_get_contents("php://input"), true);
        if (!is_array($input)) throw new Exception("Data tidak valid", 400);

        // Cek eksistensi
        $check = $conn->prepare("SELECT 1 FROM transmittals_new WHERE ta_id = ?");
        $check->bind_param("s", $ta_id);
        $check->execute();
        if ($check->get_result()->num_rows === 0) {
            throw new Exception("Transmittal tidak ditemukan", 404);
        }

        // Field yang bisa diupdate
        $updatable = [
            'date', 'from_origin', 'document_type', 'attention', 'company',
            'address', 'state', 'awb_reg', 'expeditur', 'receiver_name',
            'receive_date', 'ras_status', 'description', 'remarks'
        ];

        $setParts = [];
        $params = [];
        $types = '';

        foreach ($updatable as $field) {
            if (isset($input[$field])) {
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
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        $conn->commit();

        echo json_encode([
            "status" => 200,
            "message" => "Transmittal diperbarui"
        ]);
        exit;
    }

    // ========================
    // === DELETE
    // ========================
    if ($method === 'DELETE') {
        if (!$ta_id) throw new Exception("TA ID diperlukan", 400);

        $stmt = $conn->prepare("DELETE FROM transmittals_new WHERE ta_id = ?");
        $stmt->bind_param("s", $ta_id);
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            throw new Exception("Transmittal tidak ditemukan", 404);
        }

        $conn->commit();

        echo json_encode([
            "status" => 200,
            "message" => "Transmittal dihapus"
        ]);
        exit;
    }

    // ========================
    // === GET: Single
    // ========================
    if ($method === 'GET' && $ta_id) {
        $stmt = $conn->prepare("SELECT * FROM transmittals_new WHERE ta_id = ?");
        $stmt->bind_param("s", $ta_id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();

        if (!$data) throw new Exception("Transmittal tidak ditemukan", 404);

        echo json_encode(["status" => 200, "data" => $data]);
        $conn->commit();
        exit;
    }

   // ========================
// === GET: List
// ========================
if ($method === 'GET') {
    $offset = ($page - 1) * $limit;
    
    // Ambil parameter filter
    $start_date = $_GET['start_date'] ?? null;
    $end_date = $_GET['end_date'] ?? null;
    
    // Bangun query dinamis
    $baseQuery = "FROM transmittals_new";
    $whereClauses = [];
    $params = [];
    $types = '';
    
    // Tambahkan kondisi filter tanggal jika ada
    if ($start_date) {
        $whereClauses[] = "date >= ?";
        $params[] = $start_date;
        $types .= 's';
    }
    if ($end_date) {
        $whereClauses[] = "date <= ?";
        $params[] = $end_date;
        $types .= 's';
    }
    
    $where = $whereClauses ? "WHERE " . implode(" AND ", $whereClauses) : "";
    
    // Query untuk count total
    $countQuery = "SELECT COUNT(*) as total $baseQuery $where";
    $countStmt = $conn->prepare($countQuery);
    
    if ($params) {
        $countStmt->bind_param($types, ...$params);
    }
    
    $countStmt->execute();
    $count = $countStmt->get_result()->fetch_assoc()['total'];
    $totalPages = ceil($count / $limit);
    
    // Query untuk data
    $dataQuery = "
        SELECT ta_id, date, from_origin, company, ras_status, description, 
               receive_date, created_by, created_at 
        $baseQuery 
        $where
        ORDER BY date DESC 
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';
    
    $stmt = $conn->prepare($dataQuery);
    $stmt->bind_param($types, ...$params);
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
        "totalCount" => (int)$count,
        "totalPages" => (int)$totalPages,
        "page" => $page,
        "limit" => $limit
    ]);
    exit;
}
    }

    throw new Exception("Method tidak didukung", 405);

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