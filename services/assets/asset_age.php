<?php
header("Content-Type: application/json");
require '../../api/db.php';
require '../../api/auth.php';

$currentUser = authorize();

// Parameter filter
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(100, max(1, (int)($_GET['limit'] ?? 10)));
$status = $_GET['status'] ?? null;
$category_id = $_GET['category_id'] ?? null;

try {
    $conn->autocommit(false);

    // Base query
    $sql = "SELECT SQL_CALC_FOUND_ROWS 
                *, 
                TIMESTAMPDIFF(YEAR, purchase_date, CURDATE()) AS asset_age
            FROM assets
            WHERE 1=1";
    
    $conditions = [];
    $params = [];
    $types = '';
    
    // Filter by status
    if ($status) {
        $conditions[] = "status = ?";
        $params[] = $status;
        $types .= 's';
    }
    
    // Filter by category
    if ($category_id) {
        $conditions[] = "category_id = ?";
        $params[] = $category_id;
        $types .= 'i';
    }
    
    // Combine conditions
    if (!empty($conditions)) {
        $sql .= " AND " . implode(" AND ", $conditions);
    }
    
    // Add pagination
    $sql .= " LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = ($page - 1) * $limit;
    $types .= 'ii';
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare gagal: " . $conn->error);
    }
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $assets = [];
    while ($row = $result->fetch_assoc()) {
        $assets[] = $row;
    }
    
    // Get total count
    $totalResult = $conn->query("SELECT FOUND_ROWS() as total");
    $total = $totalResult->fetch_assoc()['total'];
    
    $conn->commit();
    
    echo json_encode([
        "status" => 200,
        "data" => $assets,
        "pagination" => [
            "page" => $page,
            "limit" => $limit,
            "total_items" => (int)$total,
            "total_pages" => ceil($total / $limit)
        ]
    ]);

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