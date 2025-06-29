<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

// Cek token dan hak akses
authorize(5, ["admin_asset"], [], null);
$user = verifyToken();
$id_company = $user['id_company'] ?? null;

$method = $_SERVER['REQUEST_METHOD'];
$id = $_GET['id'] ?? null;

// Ambil parameter dari query string
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(100, max(1, (int)($_GET['limit'] ?? 12)));
$search = $_GET['search'] ?? null;
$category = $_GET['category'] ?? null;
$status = $_GET['status'] ?? null;

try {
    if ($method !== 'GET') {
        throw new Exception("Method not allowed", 405);
    }

    $conn->autocommit(FALSE);

    if ($id) {
        // [Kode untuk get single asset tetap sama]
    } else {
        // Bangun query dengan filter dinamis
        $sql = "SELECT SQL_CALC_FOUND_ROWS
                    a.*, 
                    c.name as category_name,
                    l.name as location_name,
                    d.name as department_name
                FROM assets a
                LEFT JOIN asset_categories c ON a.category_id = c.id
                LEFT JOIN asset_locations l ON a.location_id = l.id
                LEFT JOIN asset_departments d ON a.department_id = d.id
                WHERE 1=1";
        
        $conditions = [];
        $params = [];
        $types = '';

        // Filter pencarian
        if ($search) {
            $conditions[] = "(a.name LIKE ? OR a.description LIKE ? OR a.serial_number LIKE ?)";
            $searchTerm = "%$search%";
            array_push($params, $searchTerm, $searchTerm, $searchTerm);
            $types .= 'sss';
        }

        // Filter kategori
        if ($category) {
            $conditions[] = "a.category_id = ?";
            $params[] = $category;
            $types .= 'i';
        }

        // Filter status
        if ($status) {
            $conditions[] = "a.status = ?";
            $params[] = $status;
            $types .= 's';
        }

        // Gabungkan kondisi
        if (!empty($conditions)) {
            $sql .= " AND " . implode(" AND ", $conditions);
        }

        // Tambahkan paginasi
        $sql .= " ORDER BY a.id DESC LIMIT ? OFFSET ?";
        array_push($params, $limit, ($page - 1) * $limit);
        $types .= 'ii';

        // Eksekusi query
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
        
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        
        $assets = [];
        while ($row = $result->fetch_assoc()) {
            $assets[] = $row;
        }

        // Hitung total data
        $countResult = $conn->query("SELECT FOUND_ROWS() as total");
        $total = $countResult->fetch_assoc()['total'];

        $conn->commit();
        
        echo json_encode([
            "status" => 200,
            "data" => [
                "items" => $assets,
                "totalCount" => $total,
                "page" => $page,
                "limit" => $limit,
                "totalPages" => ceil($total / $limit)
            ]
        ]);
    }

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