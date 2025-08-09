<?php
require_once '../../db.php';
require_once '../../auth.php';

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
    if ($method === 'POST') {
        $input = json_decode(file_get_contents("php://input"), true);
        if (!is_array($input)) throw new Exception("Input tidak valid", 400);

        $required = ['date', 'from_origin', 'document_type'];
        foreach ($required as $field) {
            if (empty($input[$field])) throw new Exception("Field $field wajib diisi", 400);
        }

        $validStatus = ['Pending', 'Received', 'In Transit', 'Delivered'];
        if (!empty($input['ras_status']) && !in_array($input['ras_status'], $validStatus)) {
            throw new Exception("ras_status harus salah satu dari: " . implode(', ', $validStatus), 400);
        }

        // Auto-generate TA ID: TRJA002000, TRJA002001, ...
        if (empty($input['ta_id'])) {
            $prefix = "TRJA";
            $stmt = $conn->prepare("SELECT MAX(ta_id) as last_id FROM transmittals WHERE ta_id LIKE ?");
            $pattern = $prefix . "%";
            $stmt->bind_param("s", $pattern);
            $stmt->execute();
            $result = $stmt->get_result();
            $lastId = $result->fetch_assoc()['last_id'] ?? null;

            if ($lastId && preg_match('/^TRJA(\d+)$/', $lastId, $matches)) {
                $nextNum = (int)$matches[1] + 1;
            } else {
                $nextNum = 2000; // TRJA002000
            }
            $input['ta_id'] = $prefix . str_pad($nextNum, 6, '0', STR_PAD_LEFT);
        }

        $sql = "INSERT INTO transmittals (
            ta_id, date, from_origin, document_type, attention, 
            company, address, state, awb_reg, expeditur, receiver_name, receive_date, ras_status, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "ssssssssssssss",
            $input['ta_id'],
            $input['date'],
            $input['from_origin'],
            $input['document_type'],
            $input['attention'] ?? '',
            $input['company'] ?? '',
            $input['address'] ?? '',
            $input['state'] ?? '',
            $input['awb_reg'] ?? '',
            $input['expeditur'] ?? '',
            $input['receiver_name'] ?? '',
            $input['receive_date'] ?? '',
            $input['ras_status'] ?? 'Pending',
            $currentName
        );
        $stmt->execute();

        // Insert dokumen
        if (!empty($input['doc_details']) && is_array($input['doc_details'])) {
            $docSql = "INSERT INTO transmittal_documents (ta_id, no_urut, doc_desc, remarks, created_by) VALUES (?, ?, ?, ?, ?)";
            $docStmt = $conn->prepare($docSql);
            foreach ($input['doc_details'] as $doc) {
                if (!isset($doc['no_urut']) || !isset($doc['doc_desc'])) continue;
                $docStmt->bind_param(
                    "sisss",
                    $input['ta_id'],
                    (int)$doc['no_urut'],
                    $doc['doc_desc'],
                    $doc['remarks'] ?? '',
                    $currentName
                );
                $docStmt->execute();
            }
        }

        $conn->commit();
        return [
            "status" => 201,
            "message" => "Transmittal berhasil dibuat",
            "ta_id" => $input['ta_id']
        ];
    }

    // === UPDATE ===
    if ($method === 'PUT') {
        if (!$ta_id) throw new Exception("TA ID tidak diberikan", 400);

        $input = json_decode(file_get_contents("php://input"), true);
        if (!is_array($input)) throw new Exception("Input tidak valid", 400);

        if (isset($input['ras_status'])) {
            $validStatus = ['Pending', 'Received', 'In Transit', 'Delivered'];
            if (!in_array($input['ras_status'], $validStatus)) {
                throw new Exception("ras_status tidak valid", 400);
            }
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

        // Update dokumen: hapus dulu, lalu insert baru
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
        return ["status" => 200, "message" => "Transmittal berhasil diperbarui"];
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

        $conn->commit();
        return ["status" => 200, "message" => "Transmittal berhasil dihapus"];
    }

    // === GET SINGLE ===
    if ($method === 'GET' && $ta_id) {
        $stmt = $conn->prepare("
            SELECT * FROM transmittals WHERE ta_id = ?
        ");
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
        return ["status" => 200, "data" => $trans];
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

        $totalResult = $conn->query("SELECT FOUND_ROWS() as total");
        $total = (int) $totalResult->fetch_assoc()['total'];

        $conn->commit();

        return [
            "status" => 200,
            "data" => [
                "items" => $items,
                "totalCount" => $total,
                "page" => $page,
                "limit" => $limit,
                "totalPages" => ceil($total / $limit)
            ]
        ];
    }

    throw new Exception("Method tidak diizinkan", 405);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code($e->getCode() ?: 500);
    return [
        "error" => $e->getMessage(),
        "trace" => $e->getTraceAsString()
    ];
}