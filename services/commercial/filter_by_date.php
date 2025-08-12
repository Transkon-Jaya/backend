<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

// 🔐 Pastikan user login
$currentUser = authorize();
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = min(50, max(10, (int)($_GET['limit'] ?? 10)));
$offset = ($page - 1) * $limit;

$start_date = $_GET['start'] ?? null;
$end_date = $_GET['end'] ?? null;

// Validasi format tanggal
$validStart = $start_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date);
$validEnd = $end_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date);

if (!$validStart && !$validEnd) {
    http_response_code(400);
    echo json_encode([
        "status" => 400,
        "error" => "Parameter 'start' atau 'end' harus diisi dengan format YYYY-MM-DD"
    ]);
    exit;
}

try {
    // Build WHERE clause
    $whereParts = [];
    $params = [];
    $types = '';

    if ($validStart) {
        $whereParts[] = "date >= ?";
        $params[] = $start_date;
        $types .= 's';
    }
    if ($validEnd) {
        $whereParts[] = "date <= ?";
        $params[] = $end_date;
        $types .= 's';
    }

    $whereClause = "WHERE " . implode(" AND ", $whereParts);

    // Hitung total
    $countSql = "SELECT COUNT(*) as total FROM transmittals_new $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $count = $countStmt->get_result()->fetch_assoc()['total'];
    $totalPages = ceil($count / $limit);

    // Ambil data
    $sql = "
        SELECT ta_id, date, from_origin, company, ras_status, description, 
               receive_date, created_by, created_at 
        FROM transmittals_new 
        $whereClause
        ORDER BY date DESC 
        LIMIT ? OFFSET ?
    ";

    $stmt = $conn->prepare($sql);
    $types .= 'ii';
    $stmt->bind_param($types, ...array_merge($params, [(int)$limit, (int)$offset]));
    $stmt->execute();
    $result = $stmt->get_result();

    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }

    echo json_encode([
        "status" => 200,
        "items" => $items,
        "totalCount" => (int)$count,
        "totalPages" => (int)$totalPages,
        "page" => $page,
        "limit" => $limit,
        "filters" => [
            "start_date" => $start_date,
            "end_date" => $end_date
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => 500,
        "error" => "Gagal memproses filter: " . $e->getMessage()
    ]);
} finally {
    $conn->close();
}
?>