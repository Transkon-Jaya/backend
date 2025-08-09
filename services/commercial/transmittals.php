<?php
header("Content-Type: application/json");
require '../db.php';
require '../auth.php';

// 🔐 Ambil data user dari token JWT
$currentUser = authorize();
$currentName = $currentUser['name'] ?? 'system';
$method = $_SERVER['REQUEST_METHOD'];

// 🌐 Override _method dari POST/JSON agar bisa DELETE
$originalMethod = $method;
if ($method === 'POST') {
    $overrideMethod = $_POST['_method'] ?? $_GET['_method'] ?? null;
    if (!$overrideMethod) {
        $body = json_decode(file_get_contents("php://input"), true);
        $overrideMethod = $body['_method'] ?? null;
    }
    if ($overrideMethod) {
        $method = strtoupper($overrideMethod);
    }
}

$ta_id = $_GET['ta_id'] ?? ($_POST['ta_id'] ?? null);
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(100, max(1, (int)($_GET['limit'] ?? 12));
$search = $_GET['search'] ?? null;
$status = $_GET['status'] ?? null;

try {
    $conn->autocommit(false);

    // ========================
    // === POST (Create) ======
    // ========================
    if ($method === 'POST') {
        $input = json_decode(file_get_contents("php://input"), true);
        if (!is_array($input)) {
            throw new Exception("Input tidak valid", 400);
        }

        // Validasi field wajib
        $required = ['date', 'from_origin', 'document_type'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                throw new Exception("Field $field wajib diisi", 400);
            }
        }

        // Generate TA ID jika tidak ada
        if (empty($input['ta_id'])) {
            $prefix = "TRJA";
            $stmt = $conn->prepare("SELECT MAX(ta_id) as last_id FROM transmittals WHERE ta_id LIKE ?");
            $searchPattern = $prefix . "%";
            $stmt->bind_param("s", $searchPattern);
            $stmt->execute();
            $result = $stmt->get_result();
            $lastId = $result->fetch_assoc()['last_id'] ?? null;

            if ($lastId && preg_match('/^TRJA(\d+)$/', $lastId, $matches)) {
                $nextNum = (int)$matches[1] + 1;
            } else {
                $nextNum = 2000; // Mulai dari TRJA2000
            }

            $input['ta_id'] = $prefix . $nextNum;
        }

        // Insert transmittal utama
        $sql = "INSERT INTO transmittals (
            ta_id, date, from_origin, document_type, attention, 
            company, address, state, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "sssssssss",
            $input['ta_id'],
            $input['date'],
            $input['from_origin'],
            $input['document_type'],
            $input['attention'] ?? null,
            $input['company'] ?? null,
            $input['address'] ?? null,
            $input['state'] ?? null,
            $currentName
        );
        $stmt->execute();
        $ta_id = $input['ta_id'];

        // Insert dokumen terkait
        if (!empty($input['doc_details']) && is_array($input['doc_details'])) {
            $docSql = "INSERT INTO transmittal_documents (
                ta_id, no_urut, doc_desc, remarks, created_by
            ) VALUES (?, ?, ?, ?, ?)";

            $docStmt = $conn->prepare($docSql);
            foreach ($input['doc_details'] as $doc) {
                $docStmt->bind_param(
                    "sisss",
                    $ta_id,
                    $doc['no_urut'],
                    $doc['doc_desc'],
                    $doc['remarks'] ?? null,
                    $currentName
                );
                $docStmt->execute();
            }
        }

        $conn->commit();

        echo json_encode([
            "status" => 201,
            "message" => "Transmittal berhasil dibuat",
            "ta_id" => $ta_id
        ]);
        exit;
    }

    // ====================
    // === PUT (Update) ===
    // ====================
    if ($method === 'PUT') {
        if (!$ta_id) {
            throw new Exception("TA ID tidak valid", 400);
        }

        $input = json_decode(file_get_contents("php://input"), true);
        if (!is_array($input)) throw new Exception("Input tidak valid", 400);

        $fields = [
            'date', 'from_origin', 'document_type', 'attention', 'company',
            'address', 'state', 'awb_reg', 'receiver_name', 'expeditur',
            'receive_date', 'ras_status'
        ];

        $set = [];
        $params = [];
        $types = '';

        foreach ($fields as $field) {
            if (array_key_exists($field, $input)) {
                $set[] = "$field = ?";
                $params[] = $input[$field];
                $types .= is_numeric($input[$field]) ? 'd' : 's';
            }
        }

        // Tambahkan updated_by
        $set[] = "updated_by = ?";
        $params[] = $currentName;
        $types .= 's';

        if (empty($set)) {
            throw new Exception("Tidak ada data untuk diperbarui", 400);
        }

        $sql = "UPDATE transmittals SET " . implode(', ', $set) . " WHERE ta_id = ?";
        $params[] = $ta_id;
        $types .= 's';

        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Prepare gagal: " . $conn->error);

        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        // Update dokumen jika ada
        if (!empty($input['doc_details'])) {
            // Hapus yang lama
            $deleteStmt = $conn->prepare("DELETE FROM transmittal_documents WHERE ta_id = ?");
            $deleteStmt->bind_param("s", $ta_id);
            $deleteStmt->execute();

            // Insert yang baru
            $docSql = "INSERT INTO transmittal_documents (
                ta_id, no_urut, doc_desc, remarks, created_by
            ) VALUES (?, ?, ?, ?, ?)";

            $docStmt = $conn->prepare($docSql);
            foreach ($input['doc_details'] as $doc) {
                $docStmt->bind_param(
                    "sisss",
                    $ta_id,
                    $doc['no_urut'],
                    $doc['doc_desc'],
                    $doc['remarks'] ?? null,
                    $currentName
                );
                $docStmt->execute();
            }
        }

        $conn->commit();

        echo json_encode(["status" => 200, "message" => "Transmittal berhasil diperbarui"]);
        exit;
    }

    // =====================
    // === DELETE /{ta_id} =
    // =====================
    if ($method === 'DELETE') {
        if (!$ta_id) {
            throw new Exception("TA ID tidak valid", 400);
        }

        // Hapus dokumen terkait terlebih dahulu
        $stmt = $conn->prepare("DELETE FROM transmittal_documents WHERE ta_id = ?");
        $stmt->bind_param("s", $ta_id);
        $stmt->execute();

        // Hapus transmittal utama
        $stmt = $conn->prepare("DELETE FROM transmittals WHERE ta_id = ?");
        $stmt->bind_param("s", $ta_id);
        $stmt->execute();

        $conn->commit();

        echo json_encode(["status" => 200, "message" => "Transmittal berhasil dihapus"]);
        exit;
    }

    // ========================
    // === GET /{ta_id} ======
    // ========================
    if ($method === 'GET' && $ta_id) {
        // Ambil data transmittal utama
        $stmt = $conn->prepare("
            SELECT * FROM transmittals WHERE ta_id = ?
        ");
        $stmt->bind_param("s", $ta_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $transmittal = $result->fetch_assoc();

        if (!$transmittal) throw new Exception("Transmittal tidak ditemukan", 404);

        // Ambil dokumen terkait
        $stmt = $conn->prepare("
            SELECT * FROM transmittal_documents 
            WHERE ta_id = ?
            ORDER BY no_urut
        ");
        $stmt->bind_param("s", $ta_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $doc_details = [];
        while ($row = $result->fetch_assoc()) {
            $doc_details[] = $row;
        }

        $transmittal['doc_details'] = $doc_details;

        $conn->commit();

        echo json_encode(["status" => 200, "data" => $transmittal]);
        exit;
    }

    // ========================
    // === GET / (list) ======
    // ========================
    if ($method === 'GET') {
        $sql = "SELECT SQL_CALC_FOUND_ROWS 
                t.ta_id, t.date, t.from_origin, t.document_type, 
                t.attention, t.company, t.ras_status,
                COUNT(d.id) as document_count,
                t.created_by, t.created_at
            FROM transmittals t
            LEFT JOIN transmittal_documents d ON t.ta_id = d.ta_id
            WHERE 1=1";

        $conditions = [];
        $params = [];
        $types = '';

        if ($search) {
            $term = "%$search%";
            $conditions[] = "(t.ta_id LIKE ? OR t.from_origin LIKE ? OR t.document_type LIKE ?)";
            array_push($params, $term, $term, $term);
            $types .= 'sss';
        }

        if ($status) {
            $conditions[] = "t.ras_status = ?";
            $params[] = $status;
            $types .= 's';
        }

        if ($conditions) {
            $sql .= " AND " . implode(" AND ", $conditions);
        }

        $sql .= " GROUP BY t.ta_id ORDER BY t.date DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = ($page - 1) * $limit;
        $types .= 'ii';

        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Query gagal: " . $conn->error);

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = [];

        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }

        $totalResult = $conn->query("SELECT FOUND_ROWS() as total");
        $total = $totalResult->fetch_assoc()['total'];

        $conn->commit();

        echo json_encode([
            "status" => 200,
            "data" => [
                "items" => $items,
                "totalCount" => (int)$total,
                "page" => $page,
                "limit" => $limit,
                "totalPages" => ceil($total / $limit)
            ]
        ]);
        exit;
    }

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