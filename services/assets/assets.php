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

// Ambil parameter query
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(100, max(1, (int)($_GET['limit'] ?? 12)));
$search = $_GET['search'] ?? null;
$category = $_GET['category'] ?? null;
$status = $_GET['status'] ?? null;

try {
    $conn->autocommit(FALSE);

    if ($method === 'GET') {
        if ($id) {
            $stmt = $conn->prepare("SELECT a.*, c.name as category_name, l.name as location_name
                FROM assets a
                LEFT JOIN asset_categories c ON a.category_id = c.id
                LEFT JOIN asset_locations l ON a.location_id = l.id
                WHERE a.id = ? AND a.id_company = ?");
            $stmt->bind_param("ii", $id, $id_company);
            $stmt->execute();
            $data = $stmt->get_result()->fetch_assoc();
            echo json_encode(["status" => 200, "data" => $data]);

        } else {
            $sql = "SELECT SQL_CALC_FOUND_ROWS
                        a.*, 
                        c.name as category_name,
                        l.name as location_name
                    FROM assets a
                    LEFT JOIN asset_categories c ON a.category_id = c.id
                    LEFT JOIN asset_locations l ON a.location_id = l.id
                    WHERE a.id_company = ?";
            $params = [$id_company];
            $types = 'i';

            if ($search) {
                $sql .= " AND (a.name LIKE ? OR a.description LIKE ? OR a.serial_number LIKE ?)";
                $like = "%$search%";
                array_push($params, $like, $like, $like);
                $types .= 'sss';
            }

            if ($category) {
                $sql .= " AND a.category_id = ?";
                $params[] = $category;
                $types .= 'i';
            }

            if ($status) {
                $sql .= " AND a.status = ?";
                $params[] = $status;
                $types .= 's';
            }

            $offset = ($page - 1) * $limit;
            $sql .= " ORDER BY a.id DESC LIMIT ? OFFSET ?";
            array_push($params, $limit, $offset);
            $types .= 'ii';

            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $items = [];
            while ($row = $result->fetch_assoc()) {
                $items[] = $row;
            }
            $countResult = $conn->query("SELECT FOUND_ROWS() as total");
            $total = $countResult->fetch_assoc()['total'];

            echo json_encode([
                "status" => 200,
                "data" => [
                    "items" => $items,
                    "totalCount" => $total,
                    "page" => $page,
                    "limit" => $limit,
                    "totalPages" => ceil($total / $limit)
                ]
            ]);
        }
    }

    elseif ($method === 'POST') {
        $data = json_decode(file_get_contents("php://input"), true);
        if (!$data) throw new Exception("Invalid JSON", 400);

        $stmt = $conn->prepare("INSERT INTO assets (id_company, name, category_id, status, purchase_value, purchase_date, location_id, specifications)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isississ", 
            $id_company,
            $data['name'],
            $data['category_id'],
            $data['status'],
            $data['purchase_value'],
            $data['purchase_date'],
            $data['location_id'],
            json_encode($data['specifications'] ?? [])
        );
        $stmt->execute();
        echo json_encode(["status" => 201, "message" => "Asset ditambahkan", "id" => $stmt->insert_id]);
    }

    elseif ($method === 'PUT') {
        if (!$id) throw new Exception("ID aset diperlukan", 400);
        parse_str(file_get_contents("php://input"), $data);

        $stmt = $conn->prepare("UPDATE assets SET name=?, category_id=?, status=?, purchase_value=?, purchase_date=?, location_id=?, specifications=?
                                WHERE id=? AND id_company=?");
        $stmt->bind_param("sisisissi",
            $data['name'],
            $data['category_id'],
            $data['status'],
            $data['purchase_value'],
            $data['purchase_date'],
            $data['location_id'],
            json_encode($data['specifications'] ?? []),
            $id,
            $id_company
        );
        $stmt->execute();
        echo json_encode(["status" => 200, "message" => "Asset diperbarui"]);
    }

    elseif ($method === 'DELETE') {
        if (!$id) throw new Exception("ID aset diperlukan", 400);
        $stmt = $conn->prepare("DELETE FROM assets WHERE id = ? AND id_company = ?");
        $stmt->bind_param("ii", $id, $id_company);
        $stmt->execute();
        echo json_encode(["status" => 200, "message" => "Asset dihapus"]);
    }

    else {
        throw new Exception("Method not allowed", 405);
    }

    $conn->commit();
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
