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

    // Validasi field wajib — TA ID DIHASILKAN OLEH SERVER, JADI TIDAK PERLU DARI FRONTEND
    $required = ['date', 'from_origin'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new Exception(ucfirst(str_replace('_', ' ', $field)) . " wajib diisi", 400);
        }
    }

    // --- 🔥 LANGKAH 1: AMBIL & INCREMENT COUNTER ---
    $conn->prepare("UPDATE transmittal_counter SET current_number = current_number + 1 WHERE id = 1")->execute();

    $result = $conn->prepare("SELECT current_number FROM transmittal_counter WHERE id = 1")->execute();
    $counterRow = $result->get_result()->fetch_assoc();
    if (!$counterRow) {
        throw new Exception("Gagal baca counter setelah increment", 500);
    }
    $nextNumber = (int)$counterRow['current_number'];

    // --- 🔥 LANGKAH 2: BUAT TA ID OTOMATIS ---
    $now = new DateTime();
    $year = $now->format('y');
    $month = $now->format('m');
    $day = $now->format('d');
    $ta_id = "TRJA{$year}{$month}{$day}-{$nextNumber}";

    // --- LANGKAH 3: AMBIL DATA DARI INPUT ---
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

    // --- LANGKAH 4: CEK DUPLIKASI (Opsional, karena ta_id unik) ---
    $check = $conn->prepare("SELECT 1 FROM transmittals_new WHERE ta_id = ?");
    $check->bind_param("s", $ta_id);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        throw new Exception("TA ID sudah ada (duplikat)", 409);
    }

    // --- LANGKAH 5: SIMPAN KE DATABASE ---
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
        throw new Exception("Gagal menyimpan data: " . $stmt->error);
    }

    // --- 🔥 COMMIT TRANSAKSI ---
    $conn->commit();

    // --- ✅ RESPON SUKSES ---
    http_response_code(201);
    echo json_encode([
        "status" => 201,
        "message" => "Transmittal berhasil dibuat",
        "ta_id" => $ta_id,
        "number" => $nextNumber,
        "date" => $date
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
if (isset($_GET['start_date']) && !empty(trim($_GET['start_date']))) {
    $start_date = trim($_GET['start_date']);
    // Validasi format YYYY-MM-DD
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
        $where[] = "date >= ?";
        $params[] = $start_date;
        $types .= 's';
        error_log("Filter start_date diterapkan: $start_date"); // Log
    } else {
        error_log("Filter start_date format salah: $start_date");
    }
}

// Filter: end_date
if (isset($_GET['end_date']) && !empty(trim($_GET['end_date']))) {
    $end_date = trim($_GET['end_date']);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
        $where[] = "date <= ?";
        $params[] = $end_date;
        $types .= 's';
        error_log("Filter end_date diterapkan: $end_date");
    } else {
        error_log("Filter end_date format salah: $end_date");
    }
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