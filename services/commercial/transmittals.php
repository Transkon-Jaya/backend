<?php
// Aktifkan error reporting untuk debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

header("Content-Type: application/json");

require_once 'db.php'; // Harus return $conn
require_once 'auth.php'; // Harus return authorize()

try {
    $currentUser = authorize();
    $currentName = $currentUser['name'] ?? 'system';
    $method = $_SERVER['REQUEST_METHOD'];

    $conn->begin_transaction();

    // === POST: Create New Transmittal ===
    if ($method === 'POST') {
        $input = json_decode(file_get_contents("php://input"), true);

        if (!is_array($input)) {
            throw new Exception("Invalid JSON input", 400);
        }

        if (empty($input['date']) || empty($input['from_origin'])) {
            throw new Exception("Date and From Origin are required", 400);
        }

        // Generate TA ID: TRJA000201
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

        // Simpan doc_details sebagai JSON
        $docDetails = isset($input['doc_details']) ? json_encode($input['doc_details']) : '[]';

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
            throw new Exception("Insert failed: " . $stmt->error, 500);
        }
        $stmt->close();

        $conn->commit();

        echo json_encode([
            "status" => 200,
            "message" => "Transmittal created successfully",
            "data" => ["ta_id" => $ta_id]
        ]);
        exit;
    }

    // === GET: List or Single ===
    if ($method === 'GET') {
        $ta_id = $_GET['ta_id'] ?? null;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(10, (int)($_GET['limit'] ?? 10)));
        $search = $_GET['search'] ?? null;
        $status = $_GET['status'] ?? null;

        if ($ta_id) {
            // Get single
            $stmt = $conn->prepare("SELECT * FROM transmittals_new WHERE ta_id = ?");
            $stmt->bind_param("s", $ta_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();

            if (!$row) {
                throw new Exception("Transmittal not found", 404);
            }

            // Parse JSON
            $row['doc_details'] = json_decode($row['doc_details'], true) ?: [];
            $row['document_count'] = count($row['doc_details']);

            $conn->commit();
            echo json_encode(["status" => 200, "data" => $row]);
            exit;
        } else {
            // Get list
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
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $items = $result->fetch_all(MYSQLI_ASSOC);

            // Hitung total
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

            // Parse doc_details
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
    }

    // === PUT: Update ===
    if ($method === 'PUT') {
        $ta_id = $_GET['ta_id'] ?? null;
        if (!$ta_id) throw new Exception("TA ID required", 400);

        $input = json_decode(file_get_contents("php://input"), true);
        if (!is_array($input)) throw new Exception("Invalid input", 400);

        $stmt = $conn->prepare("SELECT 1 FROM transmittals_new WHERE ta_id = ?");
        $stmt->bind_param("s", $ta_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            throw new Exception("Not found", 404);
        }

        $fields = ['date', 'from_origin', 'document_type', 'attention', 'company', 'address', 'state', 'awb_reg', 'expeditur', 'receiver_name', 'receive_date', 'ras_status'];
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

        if (isset($input['doc_details'])) {
            $setParts[] = "doc_details = ?";
            $params[] = json_encode($input['doc_details']);
            $types .= 's';
        }

        $setParts[] = "updated_at = NOW()";
        $params[] = $ta_id;
        $types .= 's';

        $sql = "UPDATE transmittals_new SET " . implode(', ', $setParts) . " WHERE ta_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        $conn->commit();
        echo json_encode(["status" => 200, "message" => "Updated"]);
        exit;
    }

    // === DELETE ===
    if ($method === 'DELETE') {
        $ta_id = $_GET['ta_id'] ?? null;
        if (!$ta_id) throw new Exception("TA ID required", 400);

        $stmt = $conn->prepare("DELETE FROM transmittals_new WHERE ta_id = ?");
        $stmt->bind_param("s", $ta_id);
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            throw new Exception("Not found", 404);
        }

        $conn->commit();
        echo json_encode(["status" => 200, "message" => "Deleted"]);
        exit;
    }

    throw new Exception("Method not allowed", 405);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["error" => $e->getMessage()]);
    exit;
}