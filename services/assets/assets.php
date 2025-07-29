<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';


$method = $_SERVER['REQUEST_METHOD'];
$id = $_GET['id'] ?? ($_POST['id'] ?? null);

$page     = max(1, (int)($_GET['page'] ?? 1));
$limit    = min(100, max(1, (int)($_GET['limit'] ?? 12)));
$search   = $_GET['search'] ?? null;
$category = $_GET['category'] ?? null;
$status   = $_GET['status'] ?? null;

try {
    $conn->autocommit(false);

    // ========================
    // === POST (Create) ======
    // ========================
    if ($method === 'POST') {
        $input = json_decode(file_get_contents("php://input"), true);
        if (!is_array($input)) throw new Exception("Input tidak valid", 400);

        $required = ['name', 'category_id', 'status'];
        foreach ($required as $key) {
            if (empty($input[$key])) throw new Exception("Field $key wajib diisi", 400);
        }

        $sql = "INSERT INTO assets 
            (name, code, category_id, status, purchase_value, purchase_date, location_id, department_id, specifications)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Prepare gagal: " . $conn->error);

        $spec = isset($input['specifications']) && is_array($input['specifications'])
            ? json_encode($input['specifications'])
            : null;

        $purchase_date = $input['purchase_date'] ?? null;

        $stmt->bind_param(
        "ssisdsiis",  // 9 parameter: name, code, cat_id, status, value, date, loc_id, dept_id, spec
        $input['name'],
        $input['code'],
        $input['category_id'],
        $input['status'],
        $input['purchase_value'] ?? 0,
        $purchase_date,
        $input['location_id'] ?? null,
        $input['department_id'] ?? null,
        $spec
    );

        $stmt->execute();
        $insertId = $stmt->insert_id;

        $conn->commit();
        echo json_encode(["status" => 201, "message" => "Asset berhasil ditambahkan", "id" => $insertId]);
        exit;
    }

    // ====================
    // === PUT (Update) ===
    // ====================
    if ($method === 'PUT') {
        if (!$id || !is_numeric($id)) {
            throw new Exception("ID asset tidak valid", 400);
        }

        $input = json_decode(file_get_contents("php://input"), true);
        if (!is_array($input)) throw new Exception("Input tidak valid", 400);

        $fields = ['code','name', 'category_id', 'status', 'purchase_value', 'purchase_date', 'location_id', 'department_id', 'specifications'];
        $set = [];
        $params = [];
        $types = '';

        foreach ($fields as $field) {
            if (array_key_exists($field, $input)) {
                $set[] = "$field = ?";
                $params[] = $field === 'specifications' && is_array($input[$field])
                    ? json_encode($input[$field])
                    : $input[$field];
                $types .= is_numeric(end($params)) ? 'd' : 's';
            }
        }

        if (empty($set)) {
            throw new Exception("Tidak ada data untuk diperbarui", 400);
        }

        $sql = "UPDATE assets SET " . implode(', ', $set) . " WHERE id = ?";
        $params[] = $id;
        $types .= 'i';

        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Prepare gagal: " . $conn->error);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        $conn->commit();
        echo json_encode(["status" => 200, "message" => "Asset berhasil diperbarui"]);
        exit;
    }

    // =====================
    // === DELETE /{id} ====
    // =====================
    if ($method === 'DELETE') {
        if (!$id || !is_numeric($id)) {
            throw new Exception("ID asset tidak valid", 400);
        }

        $stmt = $conn->prepare("DELETE FROM assets WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();

        $conn->commit();
        echo json_encode(["status" => 200, "message" => "Asset berhasil dihapus"]);
        exit;
    }

    // ======================
    // === GET /assets/{id} =
    // ======================
    if ($method === 'GET' && $id) {
        $stmt = $conn->prepare("
            SELECT 
                a.*, 
                c.name as category_name,
                l.name as location_name,
                d.name as department_name
            FROM assets a
            LEFT JOIN asset_categories c ON a.category_id = c.id
            LEFT JOIN asset_locations l ON a.location_id = l.id
            LEFT JOIN asset_departments d ON a.department_id = d.id
            WHERE a.id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $asset = $result->fetch_assoc();

        if (!$asset) throw new Exception("Asset tidak ditemukan", 404);

        if (!empty($asset['specifications'])) {
            $asset['specifications'] = json_decode($asset['specifications'], true);
        }

        echo json_encode(["status" => 200, "data" => $asset]);
        $conn->commit();
        exit;
    }

    // ========================
    // === GET /assets list ===
    // ========================
    if ($method === 'GET') {
        $sql = "
            SELECT SQL_CALC_FOUND_ROWS 
                a.*, 
                c.name as category_name,
                l.name as location_name,
                d.name as department_name
            FROM assets a
            LEFT JOIN asset_categories c ON a.category_id = c.id
            LEFT JOIN asset_locations l ON a.location_id = l.id
            LEFT JOIN asset_departments d ON a.department_id = d.id
            WHERE 1=1
        ";

        $conditions = [];
        $params = [];
        $types = '';

        if ($id_company) {
            $conditions[] = "a.id_company = ?";
            $params[] = $id_company;
            $types .= 'i';
        }

        if ($search) {
            $conditions[] = "(a.name LIKE ? OR a.description LIKE ? OR a.serial_number LIKE ?)";
            $term = "%$search%";
            array_push($params, $term, $term, $term);
            $types .= 'sss';
        }

        if ($category) {
            $conditions[] = "a.category_id = ?";
            $params[] = $category;
            $types .= 'i';
        }

        if ($status) {
            $conditions[] = "a.status = ?";
            $params[] = $status;
            $types .= 's';
        }

        if ($conditions) {
            $sql .= " AND " . implode(" AND ", $conditions);
        }

        $sql .= " ORDER BY a.id DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = ($page - 1) * $limit;
        $types .= 'ii';

        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Query gagal: " . $conn->error);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        $result = $stmt->get_result();
        $items = [];
        while ($row = $result->fetch_assoc()) {
            if (!empty($row['specifications'])) {
                $row['specifications'] = json_decode($row['specifications'], true);
            }
            $items[] = $row;
        }

        $totalResult = $conn->query("SELECT FOUND_ROWS() as total");
        $total = $totalResult->fetch_assoc()['total'];

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
    echo json_encode([
        "status" => $e->getCode() ?: 500,
        "error" => $e->getMessage()
    ]);
} finally {
    $conn->close();
}

// =============================
// === GET /assets/locations ===
// =============================
if ($method === 'GET' && isset($_GET['get_locations'])) {
    $sql = "SELECT id, name FROM asset_locations WHERE id = ? ORDER BY name";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id_company);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $locations = [];
    while ($row = $result->fetch_assoc()) {
        $locations[] = $row;
    }
    
    $conn->commit();
    echo json_encode([
        "status" => 200,
        "data" => $locations
    ]);
    exit;
}