<?php
// Aktifkan error reporting untuk debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/transmittal_error.log');

header("Content-Type: application/json");

// --- Include file penting ---
require_once 'db.php';     // Harus return $conn
require_once 'auth.php';   // Harus return authorize()

try {
    $currentUser = authorize();
    $currentName = $currentUser['name'] ?? 'system';
    $method = $_SERVER['REQUEST_METHOD'];

    // Mulai transaksi
    $conn->begin_transaction();

    // === POST: Tambah Data Baru ===
    if ($method === 'POST') {
        $input = json_decode(file_get_contents("php://input"), true);

        if (!is_array($input)) {
            throw new Exception("Input harus berupa JSON valid", 400);
        }

        if (empty($input['date']) || empty($input['from_origin'])) {
            throw new Exception("Date dan From Origin wajib diisi", 400);
        }

        // Auto-generate TA ID: TRJA000201
        $prefix = "TRJA";
        $searchPattern = $prefix . "%";

        $stmt = $conn->prepare("SELECT MAX(ta_id) FROM transmittals_new WHERE ta_id LIKE ?");
        $stmt->bind_param("s", $searchPattern);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_row();
        $lastId = $row[0] ?? null;
        $stmt->close();

        $nextNum = 2000;
        if ($lastId && preg_match('/^TRJA(\d+)$/', $lastId, $matches)) {
            $nextNum = (int)$matches[1] + 1;
        }
        $ta_id = $prefix . str_pad($nextNum, 6, '0', STR_PAD_LEFT);

        // Konversi doc_details ke JSON
        $docDetails = isset($input['doc_details']) ? json_encode($input['doc_details']) : '[]';

        // Insert ke database
        $stmt = $conn->prepare("
            INSERT INTO transmittals_new (
                ta_id, date, from_origin, document_type, attention, company,
                address, state, awb_reg, expeditur, receiver_name, receive_date,
                ras_status, doc_details, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "sssssssssssssss",
            $ta_id,
            $input['date'],
            $input['from_origin'],
            $input['document_type'] ?? null,
            $input['attention'] ?? '',
            $input['company'] ?? '',
            $input['address'] ?? '',
            $input['state'] ?? '',
            $input['awb_reg'] ?? '',
            $input['expeditur'] ?? '',
            $input['receiver_name'] ?? null,
            $input['receive_date'] ?? null,
            $input['ras_status'] ?? 'Pending',
            $docDetails,
            $currentName
        );

        if (!$stmt->execute()) {
            throw new Exception("Gagal menyimpan data: " . $stmt->error, 500);
        }
        $stmt->close();

        $conn->commit();

        echo json_encode([
            "status" => 200,
            "message" => "Transmittal berhasil dibuat",
            "data" => [
                "ta_id" => $ta_id
            ]
        ]);
        exit;
    }

    // === GET: Ambil Data (List atau Detail) ===
    if ($method === 'GET') {
        $ta_id = $_GET['ta_id'] ?? null;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(10, (int)($_GET['limit'] ?? 10)));
        $search = $_GET['search'] ?? null;
        $status = $_GET['status'] ?? null;

        // Jika ada ta_id → ambil satu data
        if ($ta_id) {
            $stmt = $conn->prepare("SELECT * FROM transmittals_new WHERE ta_id = ?");
            $stmt->bind_param("s", $ta_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();

            if (!$row) {
                throw new Exception("Data tidak ditemukan", 404);
            }

            // Parse JSON
            $row['doc_details'] = json_decode($row['doc_details'], true) ?: [];
            $row['document_count'] = count($row['doc_details']);

            $conn->commit();

            echo json_encode([
                "status" => 200,
                "data" => $row
            ]);
            exit;
        }

        // Ambil list
        $sql = "SELECT * FROM transmittals_new WHERE 1=1";
        $params = [];
        $types = '';

        if ($search) {
            $term = "%$search%";
            $sql .= " AND (ta_id LIKE ? OR from_origin LIKE ? OR company LIKE ?)";
            array_push($params, $term, $term, $term);
            $types .= 'sss';
        }
        if ($status) {
            $sql .= " AND ras_status = ?";
            $params[] = $status;
            $types .= 's';
        }

        $sql .= " ORDER BY date DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = ($page - 1) * $limit;
        $types .= 'ii';

        $stmt = $conn->prepare($sql);
        if ($types) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = $result->fetch_all(MYSQLI_ASSOC);

        // Hitung total data
        $countSql = "SELECT COUNT(*) as total FROM transmittals_new WHERE 1=1";
        $countParams = [];
        $countTypes = '';
        if ($search) {
            $term = "%$search%";
            $countSql .= " AND (ta_id LIKE ? OR from_origin LIKE ? OR company LIKE ?)";
            $countParams = [$term, $term, $term];
            $countTypes = 'sss';
        }
        if ($status) {
            $countSql .= " AND ras_status = ?";
            $countParams[] = $status;
            $countTypes .= 's';
        }

        $countStmt = $conn->prepare($countSql);
        if ($countTypes) $countStmt->bind_param($countTypes, ...$countParams);
        $countStmt->execute();
        $total = (int)$countStmt->get_result()->fetch_assoc()['total'];

        // Parse JSON doc_details
        foreach ($items as &$item) {
            $item['doc_details'] = json_decode($item['doc_details'], true) ?: [];
            $item['document_count'] = count($item['doc_details']);
        }

        $conn->commit();

        echo json_encode([
            "status" => 200,
            "data" => [
                "items" => $items,
                "totalCount" => $total,
                "page" => $page,
                "limit" => $limit,
                "totalPages" => ceil($total / $limit)
            ]
        ]);
        exit;
    }

    // === PUT: Update Data ===
    if ($method === 'PUT') {
        $ta_id = $_GET['ta_id'] ?? null;
        if (!$ta_id) throw new Exception("TA ID tidak diberikan", 400);

        $input = json_decode(file_get_contents("php://input"), true);
        if (!is_array($input)) throw new Exception("Input tidak valid", 400);

        // Cek eksistensi
        $stmt = $conn->prepare("SELECT 1 FROM transmittals_new WHERE ta_id = ?");
        $stmt->bind_param("s", $ta_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            throw new Exception("Data tidak ditemukan", 404);
        }

        // Siapkan field untuk update
        $setParts = [];
        $params = [];
        $types = '';

        $fields = ['date', 'from_origin', 'document_type', 'attention', 'company', 'address', 'state', 'awb_reg', 'expeditur', 'receiver_name', 'receive_date', 'ras_status'];
        foreach ($fields as $field) {
            if (isset($input[$field])) {
                $setParts[] = "$field = ?";
                $params[] = $input[$field];
                $types .= 's';
            }
        }

        // Update doc_details jika ada
        if (isset($input['doc_details'])) {
            $setParts[] = "doc_details = ?";
            $params[] = json_encode($input['doc_details']);
            $types .= 's';
        }

        $setParts[] = "updated_at = NOW()";
        $params[] = $ta_id;
        $types .= 's';

        if (empty($setParts)) {
            throw new Exception("Tidak ada data untuk diperbarui", 400);
        }

        $sql = "UPDATE transmittals_new SET " . implode(', ', $setParts) . " WHERE ta_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        $conn->commit();

        echo json_encode([
            "status" => 200,
            "message" => "Data berhasil diperbarui"
        ]);
        exit;
    }

    // === DELETE: Hapus Data ===
    if ($method === 'DELETE') {
        $ta_id = $_GET['ta_id'] ?? null;
        if (!$ta_id) throw new Exception("TA ID tidak diberikan", 400);

        $stmt = $conn->prepare("DELETE FROM transmittals_new WHERE ta_id = ?");
        $stmt->bind_param("s", $ta_id);
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            throw new Exception("Data tidak ditemukan", 404);
        }

        $conn->commit();

        echo json_encode([
            "status" => 200,
            "message" => "Data berhasil dihapus"
        ]);
        exit;
    }

    // Method tidak diizinkan
    throw new Exception("Method tidak didukung", 405);

} catch (Exception $e) {
    $conn->rollback();
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($code);
    error_log("Transmittal API Error: " . $e->getMessage());
    echo json_encode(["error" => $e->getMessage()]);
    exit;
}