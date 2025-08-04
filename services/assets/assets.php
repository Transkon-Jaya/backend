<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';


$method = $_SERVER['REQUEST_METHOD'];
// 🌐 Override _method dari POST/JSON agar bisa DELETE
$originalMethod = $method;
if ($method === 'POST') {
    $overrideMethod = $_POST['_method'] ?? $_GET['_method'] ?? null;

    if (!$overrideMethod) {
        $body = json_decode(file_get_contents("php://input"), true);
        $overrideMethod = $body['_method'] ?? null;
    }

    if ($overrideMethod) {
        $method = strtoupper($overrideMethod);
    }
}

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
    $input = [];
    $imagePath = null;

    // Cek apakah ini multipart/form-data (upload file)
    if (strpos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false) {
        // Ambil data dari $_POST
        $input = $_POST;

        // Handle upload gambar
        if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
            $uploadDir = 'uploads/assets/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $fileName = uniqid('asset_') . '_' . basename($_FILES['image']['name']);
            $targetPath = $uploadDir . $fileName;

            if (move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
                $imagePath = $fileName; // simpan nama file saja, atau path relatif
            } else {
                throw new Exception("Gagal upload gambar", 500);
            }
        }
    } 
    // Jika JSON (untuk testing)
    else {
        $json = json_decode(file_get_contents("php://input"), true);
        if (!is_array($json)) {
            throw new Exception("Input tidak valid", 400);
        }
        $input = $json;
        $imagePath = $input['image_path'] ?? null;
    }

    // Validasi field wajib
    $required = ['name', 'category_id', 'status'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new Exception("Field $field wajib diisi", 400);
        }
    }

    // Siapkan data
    $code = $input['code'] ?? null;
    $name = $input['name'];
    $categoryId = $input['category_id'];
    $status = $input['status'];
    $purchaseValue = $input['purchase_value'] ?? 0;
    $purchaseDate = $input['purchase_date'] ?? null;
    $locationId = $input['location_id'] ?? null;
    $departmentId = $input['department_id'] ?? null;
    $specifications = $input['specifications'] ?? null;
    $user = $input['user'];

    // Jika specifications adalah array (misal dari form JSON), encode ke string
    if (is_array($specifications)) {
        $specifications = json_encode($specifications);
    } 
    // Jika string, pastikan valid JSON (opsional)
    elseif ($specifications && is_string($specifications)) {
        json_decode($specifications);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Bisa pilih: tolak atau biarkan sebagai string biasa
            // Kita biarkan saja, simpan sebagai string
        }
    }
    if ($specifications && strlen($specifications) > 255) {
    $specifications = substr($specifications, 0, 255); 
    }

    // Insert ke database
    $sql = "INSERT INTO assets 
    (name, code, category_id, status, purchase_value, purchase_date, location_id, department_id, specifications, image_path, user, created_by, updated_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare gagal: " . $conn->error);
    }

    $stmt->bind_param(
    "ssisdsiisssss",
    $name,
    $code,
    $categoryId,
    $status,
    $purchaseValue,
    $purchaseDate,
    $locationId,
    $departmentId,
    $specifications,
    $imagePath,
    $user,
    $auth['username'], // created_by
    $auth['username']  // updated_by (awal sama)
    );


    $stmt->execute();
    $insertId = $stmt->insert_id;

    $conn->commit();

    echo json_encode([
        "status" => 201,
        "message" => "Asset berhasil ditambahkan",
        "id" => $insertId,
        "data" => [
            "id" => $insertId,
            "name" => $name,
            "code" => $code,
            "specifications" => $specifications,
            "image_path" => $imagePath
        ]
    ]);
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

        $fields = ['code','name', 'category_id', 'status', 'purchase_value', 'purchase_date', 'location_id', 'department_id', 'specifications', 'user'];
        $set[] = "updated_by = ?";
        $params[] = $auth['username'];
        $types .= 's';

        foreach ($fields as $field) {
            if (array_key_exists($field, $input)) {
                $set[] = "$field = ?";

                 if ($field === 'specifications') {
            $value = is_array($input[$field]) ? json_encode($input[$field]) : $input[$field];
            if (strlen($value) > 255) {
                $value = substr($value, 0, 255); // Potong teks
                // Atau bisa juga beri error:
                // throw new Exception("Spesifikasi terlalu panjang. Maksimal 255 karakter", 400);
            }
            $params[] = $value;
        } else {
            $params[] = $input[$field];
        }
        
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
    // Ambil ID dari $_POST, $_GET, atau body JSON
    $id = $_POST['id'] ?? $_GET['id'] ?? null;

    if (!$id) {
        $input = json_decode(file_get_contents("php://input"), true);
        $id = $input['id'] ?? null;
    }

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
            $specs = json_decode($asset['specifications'], true);
            $asset['specifications'] = (json_last_error() === JSON_ERROR_NONE) ? $specs : $asset['specifications'];
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
        c.name AS category_name,
        l.name AS location_name,
        d.name AS department_name,

        -- Nilai terhitung (angka murni, desimal 2 digit)
        ROUND(
            IF(
                DATEDIFF(CURDATE(), a.purchase_date) >= (c.depreciation_rate * 365),
                0,
                a.purchase_value * (1 - (DATEDIFF(CURDATE(), a.purchase_date) / (c.depreciation_rate * 365)))
            ),
            2
        ) AS calculated_current_value,

        -- Nilai terformat: Rp 8.000.123
        CONCAT(
            'Rp ',
            REPLACE(FORMAT(
                FLOOR(
                    IF(
                        DATEDIFF(CURDATE(), a.purchase_date) >= (c.depreciation_rate * 365),
                        0,
                        a.purchase_value * (1 - (DATEDIFF(CURDATE(), a.purchase_date) / (c.depreciation_rate * 365)))
                    )
                ), 0), ',', '.')
        ) AS formatted_current_value

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
            // if (!empty($row['specifications'])) {
            //     $row['specifications'] = json_decode($row['specifications'], true);
            // }
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
    $stmt->bind_param("i", $id);
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