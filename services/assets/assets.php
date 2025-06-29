<?php
require 'db.php';
header("Content-Type: application/json");

$method = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];
$uriParts = explode('/', trim(parse_url($requestUri, PHP_URL_PATH), '/'));
$id = null;

// Deteksi jika URL adalah /api/assets atau /api/assets/5
if (count($uriParts) >= 3 && $uriParts[1] === 'assets') {
    $id = isset($uriParts[2]) ? (int)$uriParts[2] : null;
}

try {
    if ($method === 'GET') {
        if ($id) {
            // GET /assets/5
            $stmt = $conn->prepare("SELECT * FROM assets WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            echo json_encode(["data" => $result]);
            exit;
        } else {
            // GET /assets?page=...&limit=...
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 12;
            $offset = ($page - 1) * $limit;

            $sql = "SELECT a.*, c.name AS category_name, l.name AS location_name 
                    FROM assets a 
                    LEFT JOIN asset_categories c ON a.category_id = c.id 
                    LEFT JOIN locations l ON a.location_id = l.id 
                    LIMIT ?, ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $offset, $limit);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            // Hitung total
            $count = $conn->query("SELECT COUNT(*) as total FROM assets")->fetch_assoc()['total'];

            echo json_encode([
                "items" => $result,
                "totalCount" => (int)$count
            ]);
            exit;
        }
    }

    if ($method === 'POST') {
        $data = json_decode(file_get_contents("php://input"), true);
        $stmt = $conn->prepare("INSERT INTO assets (code, name, category_id, status, purchase_date, purchase_value, location_id, specifications) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $specs = json_encode($data['specifications']);
        $stmt->bind_param("ssissdis", 
            $data['code'], $data['name'], $data['category_id'], $data['status'],
            $data['purchase_date'], $data['purchase_value'], $data['location_id'], $specs
        );
        $stmt->execute();
        echo json_encode(["message" => "Berhasil tambah", "id" => $conn->insert_id]);
        exit;
    }

    if ($method === 'PUT' && $id) {
        $data = json_decode(file_get_contents("php://input"), true);
        $specs = json_encode($data['specifications'] ?? []);

        $stmt = $conn->prepare("UPDATE assets SET name=?, category_id=?, status=?, purchase_date=?, purchase_value=?, location_id=?, specifications=? WHERE id=?");
        $stmt->bind_param("sissdssi",
            $data['name'], $data['category_id'], $data['status'],
            $data['purchase_date'], $data['purchase_value'],
            $data['location_id'], $specs, $id
        );
        $stmt->execute();
        echo json_encode(["message" => "Berhasil update"]);
        exit;
    }

    if ($method === 'DELETE' && $id) {
        $stmt = $conn->prepare("DELETE FROM assets WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        echo json_encode(["message" => "Berhasil hapus"]);
        exit;
    }

    http_response_code(400);
    echo json_encode(["error" => "Bad Request"]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
