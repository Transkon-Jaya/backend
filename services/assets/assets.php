<?php
header('Content-Type: application/json');
require_once '../../config/database.php';
require_once '../../lib/jwt_utils.php';

$method = $_SERVER['REQUEST_METHOD'];

// Database connection
$db = new Database();
$pdo = $db->getConnection();

switch ($method) {
    case 'GET':
        // Get all assets or single asset
        if (isset($_GET['id'])) {
            $id = $_GET['id'];
            $stmt = $pdo->prepare("
                SELECT a.*, c.name as category_name, l.name as location_name 
                FROM assets a
                LEFT JOIN asset_categories c ON a.category_id = c.id
                LEFT JOIN locations l ON a.location_id = l.id
                WHERE a.id = ?
            ");
            $stmt->execute([$id]);
            $asset = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($asset) {
                // Get specifications
                $stmt = $pdo->prepare("SELECT spec_key, spec_value FROM asset_specifications WHERE asset_id = ?");
                $stmt->execute([$id]);
                $specs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $asset['specifications'] = array_reduce($specs, function($carry, $item) {
                    $carry[$item['spec_key']] = $item['spec_value'];
                    return $carry;
                }, []);

                echo json_encode($asset);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Asset not found']);
            }
        } else {
            // Get all assets with filters
            $filters = [];
            $params = [];
            
            $sql = "
                SELECT a.*, c.name as category_name, l.name as location_name 
                FROM assets a
                LEFT JOIN asset_categories c ON a.category_id = c.id
                LEFT JOIN locations l ON a.location_id = l.id
            ";

            if (isset($_GET['category'])) {
                $filters[] = "a.category_id = ?";
                $params[] = $_GET['category'];
            }

            if (isset($_GET['status'])) {
                $filters[] = "a.status = ?";
                $params[] = $_GET['status'];
            }

            if (isset($_GET['search'])) {
                $filters[] = "(a.name LIKE ? OR a.code LIKE ?)";
                $searchTerm = '%' . $_GET['search'] . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            if (!empty($filters)) {
                $sql .= " WHERE " . implode(" AND ", $filters);
            }

            $sql .= " ORDER BY a.created_at DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode($assets);
        }
        break;

    case 'POST':
        // Create new asset
        $data = json_decode(file_get_contents("php://input"), true);
        
        try {
            $pdo->beginTransaction();

            // Insert asset
            $stmt = $pdo->prepare("
                INSERT INTO assets 
                (code, name, description, category_id, status, purchase_date, 
                 purchase_value, current_value, supplier, serial_number, 
                 location_id, department_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['code'],
                $data['name'],
                $data['description'],
                $data['category_id'],
                $data['status'],
                $data['purchase_date'],
                $data['purchase_value'],
                $data['current_value'],
                $data['supplier'],
                $data['serial_number'],
                $data['location_id'],
                $data['department_id']
            ]);
            $assetId = $pdo->lastInsertId();

            // Insert specifications
            if (!empty($data['specifications'])) {
                $stmt = $pdo->prepare("
                    INSERT INTO asset_specifications 
                    (asset_id, spec_key, spec_value)
                    VALUES (?, ?, ?)
                ");
                
                foreach ($data['specifications'] as $key => $value) {
                    $stmt->execute([$assetId, $key, $value]);
                }
            }

            $pdo->commit();
            http_response_code(201);
            echo json_encode(['message' => 'Asset created', 'id' => $assetId]);
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'PUT':
        // Update asset
        $id = $_GET['id'];
        $data = json_decode(file_get_contents("php://input"), true);
        
        try {
            $pdo->beginTransaction();

            // Update asset
            $stmt = $pdo->prepare("
                UPDATE assets SET
                  code = ?, name = ?, description = ?, category_id = ?,
                  status = ?, purchase_date = ?, purchase_value = ?,
                  current_value = ?, supplier = ?, serial_number = ?,
                  location_id = ?, department_id = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $data['code'],
                $data['name'],
                $data['description'],
                $data['category_id'],
                $data['status'],
                $data['purchase_date'],
                $data['purchase_value'],
                $data['current_value'],
                $data['supplier'],
                $data['serial_number'],
                $data['location_id'],
                $data['department_id'],
                $id
            ]);

            // Delete existing specifications
            $stmt = $pdo->prepare("DELETE FROM asset_specifications WHERE asset_id = ?");
            $stmt->execute([$id]);

            // Insert updated specifications
            if (!empty($data['specifications'])) {
                $stmt = $pdo->prepare("
                    INSERT INTO asset_specifications 
                    (asset_id, spec_key, spec_value)
                    VALUES (?, ?, ?)
                ");
                
                foreach ($data['specifications'] as $key => $value) {
                    $stmt->execute([$id, $key, $value]);
                }
            }

            $pdo->commit();
            echo json_encode(['message' => 'Asset updated']);
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'DELETE':
        // Delete asset
        $id = $_GET['id'];
        
        try {
            $pdo->beginTransaction();

            // Delete specifications first
            $stmt = $pdo->prepare("DELETE FROM asset_specifications WHERE asset_id = ?");
            $stmt->execute([$id]);

            // Then delete asset
            $stmt = $pdo->prepare("DELETE FROM assets WHERE id = ?");
            $stmt->execute([$id]);

            $pdo->commit();
            echo json_encode(['message' => 'Asset deleted']);
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}