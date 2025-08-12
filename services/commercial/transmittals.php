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
// === GET: List (dengan filter)
// ========================
if ($method === 'GET' && !$ta_id) {
    $offset = ($page - 1) * $limit;

    // Build WHERE clause dari filter
    $where = [];
    $params = [];
    $types = '';

    // Filter: ta_id
    if (!empty($_GET['ta_id'])) {
        $where[] = "ta_id LIKE ?";
        $params[] = '%' . $_GET['ta_id'] . '%';
        $types .= 's';
    }

    // Filter: from_origin
    if (!empty($_GET['from_origin'])) {
        $where[] = "from_origin LIKE ?";
        $params[] = '%' . $_GET['from_origin'] . '%';
        $types .= 's';
    }

    // Filter: description
    if (!empty($_GET['description'])) {
        $where[] = "description LIKE ?";
        $params[] = '%' . $_GET['description'] . '%';
        $types .= 's';
    }

    // Filter: company
    if (!empty($_GET['company'])) {
        $where[] = "company LIKE ?";
        $params[] = '%' . $_GET['company'] . '%';
        $types .= 's';
    }

    // Filter: ras_status
    if (!empty($_GET['ras_status'])) {
        $where[] = "ras_status = ?";
        $params[] = $_GET['ras_status'];
        $types .= 's';
    }

    // Filter: start_date
    if (!empty($_GET['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start_date'])) {
        $where[] = "date >= ?";
        $params[] = $_GET['start_date'];
        $types .= 's';
    }

    // Filter: end_date (opsional)
    if (!empty($_GET['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end_date'])) {
        $where[] = "date <= ?";
        $params[] = $_GET['end_date'];
        $types .= 's';
    }

    // Gabungkan WHERE
    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    // Hitung total dengan filter
    $countSql = "SELECT COUNT(*) as total FROM transmittals_new $whereClause";
    $countStmt = $conn->prepare($countSql);
    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $count = $countStmt->get_result()->fetch_assoc()['total'];
    $totalPages = ceil($count / $limit);

    // Ambil data dengan filter
    $sql = "
        SELECT ta_id, date, from_origin, company, ras_status, description, 
               receive_date, created_by, created_at 
        FROM transmittals_new 
        $whereClause
        ORDER BY date DESC 
        LIMIT ? OFFSET ?
    ";
    $types .= 'ii'; // tambah tipe untuk limit & offset
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $conn->prepare($sql);
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