<?php
header("Content-Type: application/json");
require 'db.php';     
require 'auth.php';     

//authorize(9, ["admin_asset"], [], null);
$user = verifyToken();

$method = $_SERVER['REQUEST_METHOD'];

try {
    // === GET by ta_id ===
if ($method === 'GET' && $ta_id) {
    $stmt = $conn->prepare("SELECT * FROM transmittals WHERE ta_id = ?");
    $stmt->bind_param("s", $ta_id);
    $stmt->execute();
    $trans = $stmt->get_result()->fetch_assoc();

    if (!$trans) {
        throw new Exception("Tidak ditemukan", 404);
    }

    $stmt = $conn->prepare("SELECT * FROM transmittal_documents WHERE ta_id = ? ORDER BY no_urut");
    $stmt->bind_param("s", $ta_id);
    $stmt->execute();
    $docs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $trans['doc_details'] = $docs;

    echo json_encode([
        "status" => 200,
        "data" => $trans
    ]);
    exit;
}

// === GET list ===
if ($method === 'GET') {
    $sql = "SELECT SQL_CALC_FOUND_ROWS 
                t.ta_id, t.date, t.from_origin, t.document_type, t.attention, 
                t.company, t.ras_status, COUNT(d.id) as document_count, 
                t.created_by, t.created_at
            FROM transmittals t
            LEFT JOIN transmittal_documents d ON t.ta_id = d.ta_id
            WHERE 1=1";

    $params = [];
    $types = '';

    if (!empty($search)) {
        $term = "%$search%";
        $sql .= " AND (t.ta_id LIKE ? OR t.from_origin LIKE ? OR t.document_type LIKE ?)";
        $params = array_merge($params, [$term, $term, $term]);
        $types .= 'sss';
    }

    if (!empty($status)) {
        $sql .= " AND t.ras_status = ?";
        $params[] = $status;
        $types .= 's';
    }

    $sql .= " GROUP BY t.ta_id ORDER BY t.date DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = ($page - 1) * $limit;
    $types .= 'ii';

    $stmt = $conn->prepare($sql);

    // Bind hanya jika ada params
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $items = $result->fetch_all(MYSQLI_ASSOC);

    $total = $conn->query("SELECT FOUND_ROWS() as total")->fetch_assoc()['total'];

    echo json_encode([
        "status" => 200,
        "data" => [
            "items" => $items,
            "totalCount" => (int) $total,
            "page" => $page,
            "limit" => $limit,
            "totalPages" => ceil($total / $limit)
        ]
    ]);
    exit;
}
}
