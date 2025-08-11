<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

// 🔐 Ambil data user dari token JWT — SAMA SEPERTI assets.php
$currentUser = authorize(); // Return array user, atau exit jika tidak valid
$currentName = $currentUser['name'] ?? 'system';

$method = $_SERVER['REQUEST_METHOD'];

// 🌐 Override _method dari POST/JSON agar bisa PUT/DELETE
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

try {
    $conn->autocommit(false);

    switch ($method) {
        case 'GET':
            // READ operation
            $ta_id = $_GET['ta_id'] ?? null;
            
            if ($ta_id) {
                // Get single transmittal
                $stmt = $conn->prepare("SELECT * FROM transmittals_new WHERE ta_id = ?");
                $stmt->bind_param("s", $ta_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows === 0) {
                    throw new Exception("Transmittal not found", 404);
                }
                
                $data = $result->fetch_assoc();
                
                echo json_encode([
                    "status" => 200,
                    "data" => $data
                ]);
            } else {
                // List transmittals with pagination
                $page = max(1, (int)($_GET['page'] ?? 1));
                $limit = min(50, max(10, (int)($_GET['limit'] ?? 10)));
                $offset = ($page - 1) * $limit;
                
                // Count total
                $countStmt = $conn->query("SELECT COUNT(*) as total FROM transmittals_new");
                $total = $countStmt->fetch_assoc()['total'];
                $totalPages = ceil($total / $limit);
                
                // Get paginated data
                $stmt = $conn->prepare("
                    SELECT ta_id, date, from_origin, document_type, company, ras_status, 
                           description, remarks, created_by, updated_at
                    FROM transmittals_new 
                    ORDER BY date DESC 
                    LIMIT ? OFFSET ?
                ");
                $stmt->bind_param("ii", $limit, $offset);
                $stmt->execute();
                $result = $stmt->get_result();
                
                $items = [];
                while ($row = $result->fetch_assoc()) {
                    $items[] = $row;
                }
                
                echo json_encode([
                    "status" => 200,
                    "data" => [
                        "items" => $items,
                        "totalCount" => (int)$total,
                        "page" => $page,
                        "limit" => $limit,
                        "totalPages" => $totalPages
                    ]
                ]);
            }
            break;
            
        case 'POST':
            // CREATE operation
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (empty($input['ta_id']) || empty($input['date']) || empty($input['from_origin'])) {
                throw new Exception("TA ID, Date and From Origin are required", 400);
            }
            
            $stmt = $conn->prepare("
                INSERT INTO transmittals_new (
                    ta_id, date, from_origin, document_type, attention, company,
                    address, state, awb_reg, expeditur, receiver_name, receive_date,
                    ras_status, description, remarks, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->bind_param(
                "ssssssssssssssss",
                $input['ta_id'],
                $input['date'],
                $input['from_origin'],
                $input['document_type'] ?? null,
                $input['attention'] ?? '',
                $input['company'] ?? '',
                $input['address'] ?? '',
                $input['state'] ?? '',
                $input['awb_reg'] ?? '',
                $input['expeditur'] ?? '',
                $input['receiver_name'] ?? null,
                $input['receive_date'] ?? null,
                $input['ras_status'] ?? null,
                $input['description'] ?? '',
                $input['remarks'] ?? '',
                $currentName  // ✅ SUDAH SAMA DENGAN assets.php
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to create transmittal: " . $stmt->error, 500);
            }
            
            $conn->commit();
            
            echo json_encode([
                "status" => 201,
                "message" => "Transmittal created successfully",
                "ta_id" => $input['ta_id'],
                "created_by" => $currentName
            ]);
            exit;
            break;
            
        case 'PUT':
            // UPDATE operation
            $ta_id = $_GET['ta_id'] ?? ($_POST['ta_id'] ?? null);
            if (!$ta_id) throw new Exception("TA ID is required", 400);
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            // Check if exists
            $check = $conn->prepare("SELECT 1 FROM transmittals_new WHERE ta_id = ?");
            $check->bind_param("s", $ta_id);
            $check->execute();
            if ($check->get_result()->num_rows === 0) {
                throw new Exception("Transmittal not found", 404);
            }
            
            // Build update query
            $fields = [];
            $params = [];
            $types = '';
            
            $updatableFields = [
                'date', 'from_origin', 'document_type', 'attention', 'company',
                'address', 'state', 'awb_reg', 'expeditur', 'receiver_name',
                'receive_date', 'ras_status', 'description', 'remarks'
            ];
            
            foreach ($updatableFields as $field) {
                if (array_key_exists($field, $input)) {
                    $fields[] = "$field = ?";
                    $params[] = $input[$field];
                    $types .= 's';
                }
            }
            
            // Tambahkan updated_by dan updated_at
            $fields[] = "updated_at = NOW(), updated_by = ?";
            $params[] = $currentName;  // ✅ Sama seperti di assets.php
            $types .= 's';
            
            if (empty($fields)) {
                throw new Exception("No fields to update", 400);
            }
            
            $params[] = $ta_id;
            $types .= 's';
            
            $sql = "UPDATE transmittals_new SET " . implode(', ', $fields) . " WHERE ta_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update transmittal: " . $stmt->error, 500);
            }
            
            $conn->commit();
            
            echo json_encode([
                "status" => 200,
                "message" => "Transmittal updated successfully",
                "updated_by" => $currentName
            ]);
            exit;
            break;
            
        case 'DELETE':
            // DELETE operation
            $ta_id = $_GET['ta_id'] ?? ($_POST['ta_id'] ?? null);
            if (!$ta_id) throw new Exception("TA ID is required", 400);
            
            $stmt = $conn->prepare("DELETE FROM transmittals_new WHERE ta_id = ?");
            $stmt->bind_param("s", $ta_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to delete transmittal: " . $stmt->error, 500);
            }
            
            if ($stmt->affected_rows === 0) {
                throw new Exception("Transmittal not found", 404);
            }
            
            $conn->commit();
            
            echo json_encode([
                "status" => 200,
                "message" => "Transmittal deleted successfully"
            ]);
            exit;
            break;
            
        default:
            throw new Exception("Method not allowed", 405);
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