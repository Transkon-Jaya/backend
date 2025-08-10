<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

$user = verifyToken();

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method !== 'GET') {
        throw new Exception("Method not allowed", 405);
    }

    // Ambil parameter pagination
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 12;
    $offset = ($page - 1) * $limit;

    $conn->autocommit(false);

    // Hitung total data
    $countResult = $conn->query("SELECT COUNT(*) as total FROM transmittals_new");
    if (!$countResult) throw new Exception("Count query failed: " . $conn->error);
    $totalRows = $countResult->fetch_assoc()['total'];
    $totalPages = ceil($totalRows / $limit);

    // Ambil data sesuai pagination
    $sql = "SELECT ta_id, date, from_origin, document_type, company, ras_status
            FROM transmittals_new
            ORDER BY date DESC
            LIMIT $limit OFFSET $offset";
    $result = $conn->query($sql);
    if (!$result) throw new Exception("Query failed: " . $conn->error);

    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }

    $conn->commit();

    echo json_encode([
        "items" => $items,
        "totalPages" => $totalPages
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
