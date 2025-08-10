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
        $input = json_decode(file_get_contents("php://input"), true);
        if (!is_array($input)) throw new Exception("Input tidak valid", 400);

        $required = ['date', 'from_origin'];
        foreach ($required as $field) {
            if (empty(trim($input[$field] ?? ''))) {
                throw new Exception("Field $field wajib diisi", 400);
            }
        }

        $validStatus = ['', 'Pending', 'Received', 'In Transit', 'Delivered'];
        if (isset($input['ras_status']) && $input['ras_status'] !== '' && !in_array($input['ras_status'], $validStatus)) {
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
            $row = $result->fetch_assoc();
            $lastId = $row['last_id'] ?? null;

            if ($lastId && preg_match('/^TRJA(\d+)$/', $lastId, $matches)) {
                $nextNum = (int)$matches[1] + 1;
            } else {
                $nextNum = 2000; // TRJA002000
            }
            $input['ta_id'] = $prefix . str_pad($nextNum, 6, '0', STR_PAD_LEFT);
        }

        // Cek duplikat TA ID
        $stmt = $conn->prepare("SELECT 1 FROM transmittals WHERE ta_id = ?");
        $stmt->bind_param("s", $input['ta_id']);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            throw new Exception("TA ID sudah ada: " . $input['ta_id'], 400);
        }

        $sql = "INSERT INTO transmittals (
            ta_id, date, from_origin, document_type, attention, 
            company, address, state, awb_reg, expeditur, receiver_name, receive_date, ras_status, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Prepare gagal: " . $conn->error, 500);

        $stmt->bind_param(
            "ssssssssssssss",
            $input['ta_id'],
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
            $input['ras_status'] ?? null,
            $currentName
        );

        if (!$stmt->execute()) {
            throw new Exception("Gagal menyimpan transmittal: " . $stmt->error, 500);
        }

        // Insert dokumen
        if (!empty($input['doc_details']) && is_array($input['doc_details'])) {
            $docSql = "INSERT INTO transmittal_documents (ta_id, no_urut, doc_desc, remarks, created_by) VALUES (?, ?, ?, ?, ?)";
            $docStmt = $conn->prepare($docSql);
            if (!$docStmt) throw new Exception("Prepare dokumen gagal: " . $conn->error, 500);

            foreach ($input['doc_details'] as $doc) {
                if (!isset($doc['no_urut']) || !isset($doc['doc_desc'])) continue;

                $docStmt->bind_param(
                    "sisss",
                    $input['ta_id'],
                    (int)$doc['no_urut'],
                    $doc['doc_desc'],
                    $doc['remarks'] ?? null,
                    $currentName
                );
                if (!$docStmt->execute()) {
                    throw new Exception("Gagal simpan dokumen: " . $docStmt->error, 500);
                }
            }
        }

        $conn->commit();

        http_response_code(201);
        echo json_encode([
            "status" => 201,
            "message" => "Transmittal berhasil dibuat",
            "ta_id" => $input['ta_id']
        ]);
        exit;
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

        if (empty($setParts)) {
            throw new Exception("Tidak ada data untuk diperbarui", 400);
        }

        $setParts[] = "updated_by = ?";
        $params[] = $currentName;
        $types .= 's';

        $params[] = $ta_id;
        $types .= 's';

        $sql = "UPDATE transmittals SET " . implode(', ', $setParts) . " WHERE ta_id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Prepare update gagal: " . $conn->error, 500);

        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            throw new Exception("Update gagal: " . $stmt->error, 500);
        }

        // Update dokumen: hapus dan insert ulang
        if (isset($input['doc_details'])) {
            $delStmt = $conn->prepare("DELETE FROM transmittal_documents WHERE ta_id = ?");
            $delStmt->bind_param("s", $ta_id);
            if (!$delStmt->execute()) {
                throw new Exception("Gagal hapus dokumen lama", 500);
            }

            if (!empty($input['doc_details'])) {
                $insStmt = $conn->prepare("INSERT INTO transmittal_documents (ta_id, no_urut, doc_desc, remarks, created_by) VALUES (?, ?, ?, ?, ?)");
                if (!$insStmt) throw new Exception("Prepare insert dokumen gagal", 500);

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
                    if (!$insStmt->execute()) {
                        throw new Exception("Gagal simpan dokumen baru: " . $insStmt->error, 500);
                    }
                }
            }
        }

        $conn->commit();

        http_response_code(200);
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

        http_response_code(200);
        echo json_encode([
            "status" => 200,
            "message" => "Transmittal berhasil dihapus"
        ]);
        exit;
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

        http_response_code(200);
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
        if (!$stmt) throw new Exception("Prepare query gagal: " . $conn->error, 500);

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = $result->fetch_all(MYSQLI_ASSOC);

        // Hitung total
        $countSql = "SELECT COUNT(*) as total FROM transmittals t WHERE 1=1";
        $countParams = [];
        $countTypes = '';
        if ($search) {
            $term = "%$search%";
            $countSql .= " AND (t.ta_id LIKE ? OR t.from_origin LIKE ? OR t.document_type LIKE ? OR t.company LIKE ?)";
            $countParams = [$term, $term, $term, $term];
            $countTypes = 'ssss';
        }
        if ($status) {
            $countSql .= " AND t.ras_status = ?";
            $countParams[] = $status;
            $countTypes .= 's';
        }
        $countStmt = $conn->prepare($countSql);
        $countStmt->bind_param($countTypes, ...$countParams);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];

        $conn->commit();

        http_response_code(200);
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
    if (isset($conn) && $conn->connect_errno === 0) {
        $conn->rollback();
    }
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "error" => $e->getMessage(),
        "trace" => $e->getTraceAsString()
    ]);
    exit;
}