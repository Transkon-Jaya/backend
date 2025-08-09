<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Content-Type: application/json");

require_once 'db.php'; // pastikan koneksi MySQLi ada di sini

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============================
// GET: Ambil daftar transmittals
// ============================
if ($method === 'GET') {
    try {
        // Ambil parameter
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        $status = isset($_GET['status']) ? trim($_GET['status']) : '';
        $page   = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit  = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
        $offset = ($page - 1) * $limit;

        // Query utama
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

        // Filter search
        if ($search !== '') {
            $term = "%$search%";
            $sql .= " AND (t.ta_id LIKE ? OR t.from_origin LIKE ? OR t.document_type LIKE ?)";
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $types   .= 'sss';
        }

        // Filter status
        if ($status !== '') {
            $sql .= " AND t.ras_status = ?";
            $params[] = $status;
            $types   .= 's';
        }

        // Group & order
        $sql .= " GROUP BY t.ta_id ORDER BY MAX(t.date) DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types   .= 'ii';

        $stmt = $conn->prepare($sql);
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $data   = $result->fetch_all(MYSQLI_ASSOC);

        // Query total untuk pagination
        $countSql = "SELECT COUNT(DISTINCT t.ta_id) AS total 
                     FROM transmittals t 
                     LEFT JOIN transmittal_documents d ON t.ta_id = d.ta_id 
                     WHERE 1=1";
        $countParams = [];
        $countTypes  = '';

        if ($search !== '') {
            $countSql .= " AND (t.ta_id LIKE ? OR t.from_origin LIKE ? OR t.document_type LIKE ?)";
            $countParams[] = $term;
            $countParams[] = $term;
            $countParams[] = $term;
            $countTypes   .= 'sss';
        }
        if ($status !== '') {
            $countSql .= " AND t.ras_status = ?";
            $countParams[] = $status;
            $countTypes   .= 's';
        }

        $countStmt = $conn->prepare($countSql);
        if ($countTypes !== '') {
            $countStmt->bind_param($countTypes, ...$countParams);
        }
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $totalRows = $countResult->fetch_assoc()['total'];

        // Response JSON
        echo json_encode([
            'data'  => $data,
            'total' => intval($totalRows),
            'page'  => $page,
            'limit' => $limit
        ]);
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// ============================
// POST, PUT, DELETE bisa ditambah di sini
// ============================

else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}
