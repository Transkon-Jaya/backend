<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

// Cek token dan hak akses
authorize(5, ["admin_asset"], [], null);
$user = verifyToken();
$id_company = $user['id_company'] ?? null;

$method = $_SERVER['REQUEST_METHOD'];
$id = $_GET['id'] ?? ($_POST['id'] ?? null);

// Ambil parameter dari query string
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(100, max(1, (int)($_GET['limit'] ?? 12)));
$search = $_GET['search'] ?? null;
$category = $_GET['category'] ?? null;
$status = $_GET['status'] ?? null;

try {
    $conn->autocommit(false);

    // ==== HANDLE PUT (Update asset) ====
    if ($method === 'PUT') {
        // Ambil body PUT (karena tidak otomatis seperti POST)
        $input = json_decode(file_get_contents("php://input"), true);

        if (!$id || !is_numeric($id)) {
            throw new Exception("ID asset tidak valid", 400);
        }

        $fields = ['name', 'category_id', 'status', 'purchase_value', 'purchase_date', 'location_id', 'specifications'];
        $params = [];
        $types = '';
        $set = [];

        foreach ($fields as $field) {
            if (isset($input[$field])) {
                $set[] = "$field = ?";
                $params[] = $input[$field];
                $types .= is_numeric($input[$field]) ? 'd' : 's'; // d = double/string tergantung tipe
            }
        }

        if (empty($set)) {
            throw new Exception("Tidak ada data yang dikirim untuk diubah", 400);
        }

        $sql = "UPDATE assets SET " . implode(', ', $set) . " WHERE id = ?";
        $params[] = $id;
        $types .= 'i';

        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        $conn->commit();
        echo json_encode(["status" => 200, "message" => "Asset berhasil diperbarui"]);
        exit;
    }

    // ==== HANDLE DELETE ====
    if ($method === 'DELETE') {
        if (!$id || !is_numeric($id)) {
            throw new Exception("ID asset tidak valid untuk dihapus", 400);
        }

        $stmt = $conn->prepare("DELETE FROM assets WHERE id = ?");
        if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
        $stmt->bind_param("i", $id);
        $stmt->execute();

        $conn->commit();
        echo json_encode(["status" => 200, "message" => "Asset berhasil dihapus"]);
        exit;
    }

    // ==== HANDLE GET ====
    if ($method === 'GET') {
        if ($id) {
            // Ambil single asset
            $stmt = $conn->prepare("SELECT * FROM assets WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $asset = $result->fetch_assoc();

            if (!$asset) {
                throw new Exception("Asset tidak ditemukan", 404);
            }

            echo json_encode(["status" => 200, "data" => $asset]);
            $conn->commit();
            exit;
        }

        // List asset dengan filter & paginasi
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

        // Filter perusahaan (jika ada)
        if ($id_company) {
            $conditions[] = "a.id_company = ?";
            $params[] = $id_company;
            $types .= 'i';
        }

        // Filter pencarian
        if ($search) {
            $conditions[] = "(a.name LIKE ? OR a.description LIKE ? OR a.serial_number LIKE ?)";
            $searchTerm = "%$search%";
            array_push($params, $searchTerm, $searchTerm, $searchTerm);
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

        if (!empty($conditions)) {
            $sql .= " AND " . implode(" AND ", $conditions);
        }

        $sql .= " ORDER BY a.id DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = ($page - 1) * $limit;
        $types .= 'ii';

        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        $result = $stmt->get_result();
        $assets = [];
        while ($row = $result->fetch_assoc()) {
            $assets[] = $row;
        }

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
        exit;
    }

    throw new Exception("Method tidak didukung", 405);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code($e->getCode() ?: 500);
    error_log("Asset API Error: " . $e->getMessage()); // Tulis ke log PHP
    echo json_encode([
        "status" => $e->getCode() ?: 500,
        "error" => $e->getMessage()
    ]);
} finally {
    $conn->close();
}
