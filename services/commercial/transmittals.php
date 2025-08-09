<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Content-Type: application/json");

require_once 'db.php';     // pastikan $conn (mysqli) tersedia
require_once 'auth.php';   // pastikan authorize()/verifyToken() tersedia

// helper: call_user_func_array requires references for bind_param
function refValues($arr){
    $refs = [];
    foreach($arr as $k => $v) $refs[$k] = &$arr[$k];
    return $refs;
}

try {
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    // AUTH: pastikan pemanggilan authorize sesuai implementasimu
    // kalau authorize() mengembalikan user atau melempar exception bila tidak ok:
    $currentUser = authorize(); // gunakan authorize() seperti file yang berjalan
    $currentName = $currentUser['name'] ?? 'system';

    // =========================
    // GET single or list
    // =========================
    if ($method === 'GET') {
        // If ta_id provided -> return single
        $ta_id = $_GET['ta_id'] ?? null;
        if ($ta_id) {
            $stmt = $conn->prepare("SELECT * FROM transmittals WHERE ta_id = ?");
            if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
            $stmt->bind_param("s", $ta_id);
            $stmt->execute();
            $trans = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$trans) {
                http_response_code(404);
                echo json_encode(["status" => 404, "error" => "Not found"]);
                exit;
            }
            // docs
            $dstmt = $conn->prepare("SELECT id, no_urut, doc_desc, remarks FROM transmittal_documents WHERE ta_id = ? ORDER BY no_urut");
            if ($dstmt) {
                $dstmt->bind_param("s", $ta_id);
                $dstmt->execute();
                $docs = $dstmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $dstmt->close();
            } else {
                $docs = [];
            }
            $trans['doc_details'] = $docs;
            echo json_encode(["status" => 200, "data" => $trans]);
            exit;
        }

        // LIST
        $search = trim($_GET['search'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $page   = max(1, intval($_GET['page'] ?? 1));
        $limit  = min(100, max(1, intval($_GET['limit'] ?? 10)));
        $offset = ($page - 1) * $limit;

        // main query: use MAX() for non-aggregated columns -> kompatibel ONLY_FULL_GROUP_BY
        $sql = "SELECT
                    t.ta_id,
                    MAX(t.date) AS date,
                    MAX(t.from_origin) AS from_origin,
                    MAX(t.document_type) AS document_type,
                    MAX(t.attention) AS attention,
                    MAX(t.company) AS company,
                    MAX(t.ras_status) AS ras_status,
                    COUNT(d.id) AS document_count,
                    MAX(t.created_by) AS created_by,
                    MAX(t.created_at) AS created_at
                FROM transmittals t
                LEFT JOIN transmittal_documents d ON t.ta_id = d.ta_id
                WHERE 1=1";

        $params = [];
        $types  = '';

        if ($search !== '') {
            $term = "%{$search}%";
            $sql .= " AND (t.ta_id LIKE ? OR t.from_origin LIKE ? OR t.document_type LIKE ?)";
            array_push($params, $term, $term, $term);
            $types .= 'sss';
        }
        if ($status !== '') {
            $sql .= " AND t.ras_status = ?";
            $params[] = $status;
            $types .= 's';
        }

        $sql .= " GROUP BY t.ta_id ORDER BY MAX(t.date) DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';

        // debug: log SQL & params (hapus/comment di production)
        error_log("[transmittals] SQL: {$sql} | params: " . json_encode($params));

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("[transmittals] prepare failed: " . $conn->error);
            throw new Exception("DB prepare failed");
        }

        // bind params if any
        if ($types !== '') {
            $bindParams = array_merge([$types], $params);
            call_user_func_array([$stmt, 'bind_param'], refValues($bindParams));
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // total count for pagination (separate count query)
        $countSql = "SELECT COUNT(DISTINCT t.ta_id) as total FROM transmittals t LEFT JOIN transmittal_documents d ON t.ta_id = d.ta_id WHERE 1=1";
        $countParams = [];
        $countTypes  = '';
        if ($search !== '') {
            $countSql .= " AND (t.ta_id LIKE ? OR t.from_origin LIKE ? OR t.document_type LIKE ?)";
            $countParams = array_merge($countParams, [$term, $term, $term]);
            $countTypes .= 'sss';
        }
        if ($status !== '') {
            $countSql .= " AND t.ras_status = ?";
            $countParams[] = $status;
            $countTypes .= 's';
        }

        error_log("[transmittals] COUNT SQL: {$countSql} | params: " . json_encode($countParams));

        $cstmt = $conn->prepare($countSql);
        if (!$cstmt) throw new Exception("Count prepare failed: " . $conn->error);
        if ($countTypes !== '') {
            $cbind = array_merge([$countTypes], $countParams);
            call_user_func_array([$cstmt, 'bind_param'], refValues($cbind));
        }
        $cstmt->execute();
        $total = (int)$cstmt->get_result()->fetch_assoc()['total'];
        $cstmt->close();

        echo json_encode([
            "status" => 200,
            "data" => [
                "items" => $rows,
                "totalCount" => $total,
                "page" => $page,
                "limit" => $limit,
                "totalPages" => $total ? ceil($total / $limit) : 0
            ]
        ]);
        exit;
    }

    // =========================
    // CREATE (POST)
    // =========================
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) throw new Exception("Invalid input", 400);

        // simple required validation
        foreach (['date','from_origin','document_type'] as $r) {
            if (empty($input[$r])) throw new Exception("$r is required", 400);
        }

        // validate ras_status if provided (optional)
        $validStatus = ['Pending','Received','In Transit','Delivered'];
        if (isset($input['ras_status']) && !in_array($input['ras_status'], $validStatus)) {
            throw new Exception("ras_status invalid", 400);
        }

        $conn->begin_transaction();

        // generate ta_id if absent
        if (empty($input['ta_id'])) {
            $prefix = 'TRJA';
            $ps = $conn->prepare("SELECT MAX(ta_id) AS last_id FROM transmittals WHERE ta_id LIKE ?");
            $like = $prefix . '%';
            $ps->bind_param("s", $like);
            $ps->execute();
            $last = $ps->get_result()->fetch_assoc()['last_id'] ?? null;
            $ps->close();
            if ($last && preg_match('/^TRJA(\d+)$/', $last, $m)) $next = (int)$m[1] + 1;
            else $next = 2000;
            $input['ta_id'] = $prefix . $next;
        }

        $insSql = "INSERT INTO transmittals (
            ta_id, date, from_origin, document_type, attention, company, address, state,
            awb_reg, expeditur, receiver_name, receive_date, ras_status, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($insSql);
        if (!$stmt) throw new Exception("Insert prepare failed: " . $conn->error);

        // replace nulls with empty string to avoid bind_param null issues
        $bindValues = [
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
        ];
        $bind = array_merge(['ssssssssssssss'], $bindValues);
        call_user_func_array([$stmt, 'bind_param'], refValues($bind));
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception("Insert execute failed: " . $stmt->error);
        }
        $stmt->close();

        // insert docs if any
        if (!empty($input['doc_details']) && is_array($input['doc_details'])) {
            $dsql = "INSERT INTO transmittal_documents (ta_id, no_urut, doc_desc, remarks, created_by) VALUES (?, ?, ?, ?, ?)";
            $dstmt = $conn->prepare($dsql);
            if (!$dstmt) throw new Exception("Doc insert prepare failed: " . $conn->error);
            foreach ($input['doc_details'] as $doc) {
                if (!isset($doc['no_urut']) || !isset($doc['doc_desc'])) continue;
                $no = (int)$doc['no_urut'];
                $desc = $doc['doc_desc'];
                $rem = $doc['remarks'] ?? '';
                $bind = ['sisss', $input['ta_id'], $no, $desc, $rem, $currentName];
                call_user_func_array([$dstmt, 'bind_param'], refValues($bind));
                if (!$dstmt->execute()) {
                    $dstmt->close();
                    throw new Exception("Doc insert execute failed: " . $dstmt->error);
                }
            }
            $dstmt->close();
        }

        $conn->commit();
        echo json_encode(["status" => 201, "message" => "Created", "ta_id" => $input['ta_id']]);
        exit;
    }

    // =========================
    // UPDATE (PUT)  -> expects ta_id in query string or in body
    // =========================
    if ($method === 'PUT') {
        // ta_id prefer from querystring
        $ta_id = $_GET['ta_id'] ?? null;
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$ta_id && !empty($input['ta_id'])) $ta_id = $input['ta_id'];
        if (!$ta_id) throw new Exception("ta_id required", 400);
        if (!is_array($input)) throw new Exception("Invalid input", 400);

        // validate ras_status if present
        if (isset($input['ras_status'])) {
            $validStatus = ['Pending','Received','In Transit','Delivered'];
            if (!in_array($input['ras_status'], $validStatus)) throw new Exception("ras_status invalid", 400);
        }

        $fields = ['date','from_origin','document_type','attention','company','address','state','awb_reg','expeditur','receiver_name','receive_date','ras_status'];
        $set = []; $params = []; $types = '';
        foreach ($fields as $f) {
            if (array_key_exists($f, $input)) {
                $set[] = "$f = ?";
                $params[] = $input[$f] ?? '';
                $types .= 's';
            }
        }
        if (empty($set)) throw new Exception("No data to update", 400);

        $set[] = "updated_by = ?";
        $params[] = $currentName;
        $types .= 's';

        $params[] = $ta_id;
        $types .= 's';

        $sql = "UPDATE transmittals SET " . implode(', ', $set) . " WHERE ta_id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Update prepare failed: " . $conn->error);

        $bind = array_merge([$types], $params);
        call_user_func_array([$stmt, 'bind_param'], refValues($bind));
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception("Update execute failed: " . $stmt->error);
        }
        $stmt->close();

        // update docs if provided: delete old and insert new
        if (!empty($input['doc_details']) && is_array($input['doc_details'])) {
            $conn->begin_transaction();
            $del = $conn->prepare("DELETE FROM transmittal_documents WHERE ta_id = ?");
            $del->bind_param("s", $ta_id);
            $del->execute();
            $del->close();

            $ins = $conn->prepare("INSERT INTO transmittal_documents (ta_id, no_urut, doc_desc, remarks, created_by) VALUES (?, ?, ?, ?, ?)");
            foreach ($input['doc_details'] as $doc) {
                $no = (int)($doc['no_urut'] ?? 0);
                $desc = $doc['doc_desc'] ?? '';
                $rem = $doc['remarks'] ?? '';
                $bind = ['sisss', $ta_id, $no, $desc, $rem, $currentName];
                call_user_func_array([$ins, 'bind_param'], refValues($bind));
                if (!$ins->execute()) {
                    $ins->close();
                    throw new Exception("Insert doc failed: " . $ins->error);
                }
            }
            $ins->close();
            $conn->commit();
        }

        echo json_encode(["status" => 200, "message" => "Updated"]);
        exit;
    }

    // =========================
    // DELETE (ta_id in query)
    // =========================
    if ($method === 'DELETE') {
        $ta_id = $_GET['ta_id'] ?? null;
        if (!$ta_id) throw new Exception("ta_id required", 400);
        $conn->begin_transaction();
        $d = $conn->prepare("DELETE FROM transmittal_documents WHERE ta_id = ?");
        $d->bind_param("s", $ta_id);
        $d->execute();
        $d->close();

        $t = $conn->prepare("DELETE FROM transmittals WHERE ta_id = ?");
        $t->bind_param("s", $ta_id);
        $t->execute();
        $t->close();
        $conn->commit();

        echo json_encode(["status" => 200, "message" => "Deleted"]);
        exit;
    }

    http_response_code(405);
    echo json_encode(["status" => 405, "error" => "Method not allowed"]);
    exit;
}
catch (Exception $e) {
    // rollback if transaction active
    if ($conn && $conn->errno) {
        @$conn->rollback();
    }
    // log detail error to error_log for debugging (don't expose stacktrace to client)
    error_log("[transmittals.php] ERROR: " . $e->getMessage() . " | trace: " . $e->getTraceAsString());
    http_response_code($e->getCode() >= 100 && $e->getCode() < 600 ? $e->getCode() : 500);
    echo json_encode(["status" => $e->getCode() ?: 500, "error" => "Server error. See server logs."]);
    exit;
}
finally {
    if (isset($stmt) && $stmt) @$stmt->close();
    if (isset($cstmt) && $cstmt) @$cstmt->close();
    if (isset($dstmt) && $dstmt) @$dstmt->close();
    @$conn->close();
}
