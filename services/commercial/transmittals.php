<?php
header("Content-Type: application/json");
require_once 'db.php';
require_once 'auth.php';

try {
    $currentUser = authorize();
    $currentName = $currentUser['name'] ?? 'system';
    $method = $_SERVER['REQUEST_METHOD'];

    $ta_id  = $_GET['ta_id'] ?? null;
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = min(100, max(1, (int)($_GET['limit'] ?? 12)));
    $search = $_GET['search'] ?? null;
    $status = $_GET['status'] ?? null;

    $conn->begin_transaction();

   // === CREATE ===
if ($method === 'POST' && !$ta_id) {
    try {
        $input = json_decode(file_get_contents("php://input"), true);
        
        // Debug: Log input received
        error_log("Input received: " . print_r($input, true));
        
        if (!is_array($input)) {
            throw new Exception("Input harus berupa JSON valid", 400);
        }

        // Validasi field wajib
        $required = ['date', 'from_origin'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                throw new Exception("Field $field wajib diisi", 400);
            }
        }

        // Auto-generate TA ID
        $prefix = "TRJA";
        $nextNum = 2000;

        $stmt = $conn->prepare("SELECT MAX(ta_id) FROM transmittals WHERE ta_id LIKE ?");
        $stmt->bind_param("s", $prefix."%");
        if (!$stmt->execute()) {
            throw new Exception("Gagal query TA ID terakhir: " . $stmt->error, 500);
        }
        
        $result = $stmt->get_result();
        $row = $result->fetch_row();
        $lastId = $row[0] ?? null;
        $stmt->close();

        if ($lastId && preg_match('/^TRJA(\d+)$/', $lastId, $matches)) {
            $nextNum = (int)$matches[1] + 1;
        }

        $input['ta_id'] = $prefix . str_pad($nextNum, 6, '0', STR_PAD_LEFT);

        // Debug: Log generated TA_ID
        error_log("Generated TA_ID: " . $input['ta_id']);

        // ... [rest of your code]

    } catch (Exception $e) {
        // Debug: Log the full error
        error_log("Error in CREATE: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        throw $e; // Re-throw untuk ditangkap oleh handler utama
    }
}
    // === UPDATE ===
    if ($method === 'PUT') {
        if (!$ta_id) throw new Exception("TA ID tidak diberikan", 400);

        $input = json_decode(file_get_contents("php://input"), true);
        if (!is_array($input)) throw new Exception("Input tidak valid", 400);

        if (isset($input['ras_status']) && $input['ras_status'] !== '') {
            $validStatus = ['Pending', 'Received', 'In Transit', 'Delivered'];
            if (!in_array($input['ras_status'], $validStatus)) {
                throw new Exception("ras_status tidak valid", 400);
            }
        }

        // Cek eksistensi
        $stmt = $conn->prepare("SELECT 1 FROM transmittals WHERE ta_id = ?");
        $stmt->bind_param("s", $ta_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            throw new Exception("Transmittal tidak ditemukan", 404);
        }

        $fields = ['date', 'from_origin', 'document_type', 'attention', 'company', 'address', 'state', 'awb_reg', 'receiver_name', 'expeditur', 'receive_date', 'ras_status'];
        $setParts = [];
        $params = [];
        $types = '';

        foreach ($fields as $f) {
            if (isset($input[$f])) {
                $setParts[] = "$f = ?";
                $params[] = $input[$f];
                $types .= 's';
            }
        }

        if (empty($setParts)) throw new Exception("Tidak ada data untuk diperbarui", 400);

        $setParts[] = "updated_by = ?";
        $params[] = $currentName;
        $types .= 's';

        $params[] = $ta_id;
        $types .= 's';

        $sql = "UPDATE transmittals SET " . implode(', ', $setParts) . " WHERE ta_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        // Update dokumen
        if (isset($input['doc_details'])) {
            $delStmt = $conn->prepare("DELETE FROM transmittal_documents WHERE ta_id = ?");
            $delStmt->bind_param("s", $ta_id);
            $delStmt->execute();

            $insStmt = $conn->prepare("INSERT INTO transmittal_documents (ta_id, no_urut, doc_desc, remarks, created_by) VALUES (?, ?, ?, ?, ?)");
            foreach ($input['doc_details'] as $doc) {
                if (!isset($doc['no_urut']) || !isset($doc['doc_desc'])) continue;
                $insStmt->bind_param(
                    "sisss",
                    $ta_id,
                    (int)$doc['no_urut'],
                    $doc['doc_desc'],
                    $doc['remarks'] ?? '',
                    $currentName
                );
                $insStmt->execute();
            }
        }

        $conn->commit();
        echo json_encode([
            "status" => 200,
            "message" => "Transmittal berhasil diperbarui"
        ]);
        exit;
    }

    // === DELETE ===
    if ($method === 'DELETE') {
        if (!$ta_id) throw new Exception("TA ID tidak diberikan", 400);

        $stmt = $conn->prepare("DELETE FROM transmittal_documents WHERE ta_id = ?");
        $stmt->bind_param("s", $ta_id);
        $stmt->execute();

        $stmt = $conn->prepare("DELETE FROM transmittals WHERE ta_id = ?");
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

    // === GET SINGLE ===
    if ($method === 'GET' && $ta_id) {
        $stmt = $conn->prepare("SELECT * FROM transmittals WHERE ta_id = ?");
        $stmt->bind_param("s", $ta_id);
        $stmt->execute();
        $trans = $stmt->get_result()->fetch_assoc();
        if (!$trans) throw new Exception("Transmittal tidak ditemukan", 404);

        $stmt = $conn->prepare("SELECT * FROM transmittal_documents WHERE ta_id = ? ORDER BY no_urut");
        $stmt->bind_param("s", $ta_id);
        $stmt->execute();
        $docs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $trans['doc_details'] = $docs;

        $conn->commit();
        echo json_encode([
            "status" => 200,
            "data" => $trans
        ]);
        exit;
    }

    // === GET LIST ===
    if ($method === 'GET') {
        $sql = "
            SELECT 
                t.ta_id, t.date, t.from_origin, t.document_type, t.attention, t.company, t.ras_status,
                COUNT(d.id) as document_count, t.created_by, t.created_at
            FROM transmittals t
            LEFT JOIN transmittal_documents d ON t.ta_id = d.ta_id
            WHERE 1=1
        ";

        $params = [];
        $types = '';

        if ($search) {
            $term = "%$search%";
            $sql .= " AND (t.ta_id LIKE ? OR t.from_origin LIKE ? OR t.document_type LIKE ? OR t.company LIKE ?)";
            array_push($params, $term, $term, $term, $term);
            $types .= 'ssss';
        }
        if ($status) {
            $sql .= " AND t.ras_status = ?";
            $params[] = $status;
            $types .= 's';
        }

        $sql .= " GROUP BY t.ta_id ORDER BY t.date DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = ($page - 1) * $limit;
        $types .= 'ii';

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = $result->fetch_all(MYSQLI_ASSOC);

        // Hitung total
        $countSql = "SELECT COUNT(*) as total FROM transmittals t WHERE 1=1";
        if ($search) {
            $countSql .= " AND (t.ta_id LIKE ? OR t.from_origin LIKE ? OR t.document_type LIKE ? OR t.company LIKE ?)";
        }
        if ($status) {
            $countSql .= " AND t.ras_status = ?";
        }
        $countStmt = $conn->prepare($countSql);
        if ($search) {
            $term = "%$search%";
            $countStmt->bind_param('ssss' . ($status ? 's' : ''), $term, $term, $term, $term, ...($status ? [$status] : []));
        }
        $countStmt->execute();
        $total = (int)$countStmt->get_result()->fetch_assoc()['total'];

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

    throw new Exception("Method tidak diizinkan", 405);

} catch (Exception $e) {
    if (isset($conn) && $conn->connect_errno === 0) {
        $conn->rollback();
    }
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "error" => $e->getMessage()
        // "trace" => $e->getTraceAsString() // Hapus saat production
    ]);
    exit;
}