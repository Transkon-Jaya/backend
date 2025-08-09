<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

$currentUser = authorize();
$currentName = $currentUser['name'] ?? 'system';
$method = $_SERVER['REQUEST_METHOD'];

$ta_id = $_GET['ta_id'] ?? null;
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(100, max(1, (int)($_GET['limit'] ?? 12)));
$search = $_GET['search'] ?? null;
$status = $_GET['status'] ?? null;

try {
    $conn->autocommit(false);

    // === POST (Create) ===
    if ($method === 'POST') {
        $input = json_decode(file_get_contents("php://input"), true);
        if (!is_array($input)) throw new Exception("Input tidak valid", 400);

        $required = ['date', 'from_origin', 'document_type'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                throw new Exception("Field $field wajib diisi", 400);
            }
        }

        // Validasi ras_status
        $validStatus = ['Pending', 'Received', 'In Transit', 'Delivered'];
        if (isset($input['ras_status']) && !in_array($input['ras_status'], $validStatus)) {
            throw new Exception("ras_status harus: " . implode(', ', $validStatus), 400);
        }

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
                $nextNum = 2000;
            }
            $input['ta_id'] = $prefix . $nextNum;
        }

        // Insert transmittal
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
            $input['attention'] ?? null,
            $input['company'] ?? null,
            $input['address'] ?? null,
            $input['state'] ?? null,
            $input['awb_reg'] ?? null,
            $input['expeditur'] ?? null,
            $input['receiver_name'] ?? null,
            $input['receive_date'] ?? null,
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
                    $doc['no_urut'],
                    $doc['doc_desc'],
                    $doc['remarks'] ?? null,
                    $currentName
                );
                $docStmt->execute();
            }
        }

        $conn->commit();
        echo json_encode(["status" => 201, "message" => "Berhasil dibuat", "ta_id" => $input['ta_id']]);
        exit;
    }

    // === PUT (Update) ===
    if ($method === 'PUT') {
        if (!$ta_id) throw new Exception("TA ID tidak valid", 400);
        $input = json_decode(file_get_contents("php://input"), true);
        if (!is_array($input)) throw new Exception("Input tidak valid", 400);

        if (isset($input['ras_status'])) {
            $validStatus = ['Pending', 'Received', 'In Transit', 'Delivered'];
            if (!in_array($input['ras_status'], $validStatus)) {
                throw new Exception("ras_status harus: " . implode(', ', $validStatus), 400);
            }
        }

        $fields = ['date', 'from_origin', 'document_type', 'attention', 'company', 'address', 'state', 'awb_reg', 'receiver_name', 'expeditur', 'receive_date', 'ras_status'];
        $set = []; $params = []; $types = '';
        foreach ($fields as $f) {
            if (isset($input[$f])) {
                $set[] = "$f = ?";
                $params[] = $input[$f];
                $types .= is_numeric($input[$f]) ? 'd' : 's';
            }
        }
        $set[] = "updated_by = ?"; $params[] = $currentName; $types .= 's';
        if (empty($set)) throw new Exception("Tidak ada data", 400);

        $sql = "UPDATE transmittals SET " . implode(', ', $set) . " WHERE ta_id = ?";
        $params[] = $ta_id; $types .= 's';

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        if (!empty($input['doc_details'])) {
            $del = $conn->prepare("DELETE FROM transmittal_documents WHERE ta_id = ?");
            $del->bind_param("s", $ta_id); $del->execute();

            $ins = $conn->prepare("INSERT INTO transmittal_documents (ta_id, no_urut, doc_desc, remarks, created_by) VALUES (?, ?, ?, ?, ?)");
            foreach ($input['doc_details'] as $doc) {
                $ins->bind_param("sisss", $ta_id, $doc['no_urut'], $doc['doc_desc'], $doc['remarks'] ?? null, $currentName);
                $ins->execute();
            }
        }

        $conn->commit();
        echo json_encode(["status" => 200, "message" => "Berhasil diperbarui"]);
        exit;
    }

    // === DELETE ===
    if ($method === 'DELETE') {
        if (!$ta_id) throw new Exception("TA ID tidak valid", 400);
        $stmt = $conn->prepare("DELETE FROM transmittal_documents WHERE ta_id = ?"); $stmt->bind_param("s", $ta_id); $stmt->execute();
        $stmt = $conn->prepare("DELETE FROM transmittals WHERE ta_id = ?"); $stmt->bind_param("s", $ta_id); $stmt->execute();
        $conn->commit();
        echo json_encode(["status" => 200, "message" => "Berhasil dihapus"]);
        exit;
    }

    // === GET by ta_id ===
    if ($method === 'GET' && $ta_id) {
        $stmt = $conn->prepare("SELECT * FROM transmittals WHERE ta_id = ?"); $stmt->bind_param("s", $ta_id); $stmt->execute();
        $trans = $stmt->get_result()->fetch_assoc();
        if (!$trans) throw new Exception("Tidak ditemukan", 404);

        $stmt = $conn->prepare("SELECT * FROM transmittal_documents WHERE ta_id = ? ORDER BY no_urut");
        $stmt->bind_param("s", $ta_id); $stmt->execute();
        $docs = []; while ($row = $stmt->get_result()->fetch_assoc()) $docs[] = $row;
        $trans['doc_details'] = $docs;

        echo json_encode(["status" => 200, "data" => $trans]);
        exit;
    }

    // === GET list ===
    if ($method === 'GET') {
        $sql = "SELECT SQL_CALC_FOUND_ROWS 
                t.ta_id, t.date, t.from_origin, t.document_type, t.attention, t.company, t.ras_status,
                COUNT(d.id) as document_count, t.created_by, t.created_at
            FROM transmittals t
            LEFT JOIN transmittal_documents d ON t.ta_id = d.ta_id
            WHERE 1=1";

        $params = []; $types = '';
        if ($search) {
            $term = "%$search%";
            $sql .= " AND (t.ta_id LIKE ? OR t.from_origin LIKE ? OR t.document_type LIKE ?)";
            array_push($params, $term, $term, $term); $types .= 'sss';
        }
        if ($status) {
            $sql .= " AND t.ras_status = ?";
            $params[] = $status; $types .= 's';
        }

        $sql .= " GROUP BY t.ta_id ORDER BY t.date DESC LIMIT ? OFFSET ?";
        $params[] = $limit; $params[] = ($page - 1) * $limit; $types .= 'ii';

        $stmt = $conn->prepare($sql); $stmt->bind_param($types, ...$params); $stmt->execute();
        $result = $stmt->get_result(); $items = [];
        while ($row = $result->fetch_assoc()) $items[] = $row;

        $total = $conn->query("SELECT FOUND_ROWS() as total")->fetch_assoc()['total'];
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
    echo json_encode(["status" => $e->getCode() ?: 500, "error" => $e->getMessage()]);
} finally {
    $conn->close();
}