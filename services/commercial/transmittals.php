<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

$user = verifyToken();
$method = $_SERVER['REQUEST_METHOD'];

try {
    $conn->autocommit(false);

    switch ($method) {
        case 'POST':
            // CREATE operation
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (empty($input['date']) || empty($input['from_origin'])) {
                throw new Exception("Date and From Origin are required", 400);
            }
            
            // Generate TA ID
            $prefix = "TRJA";
            $lastId = $conn->query("SELECT MAX(ta_id) FROM transmittals_new WHERE ta_id LIKE '{$prefix}%'")->fetch_row()[0];
            $nextNum = $lastId ? (int)substr($lastId, strlen($prefix)) + 1 : 2001;
            $ta_id = $prefix . str_pad($nextNum, 6, '0', STR_PAD_LEFT);
            
            // Handle NULL dates
            $receive_date = (!empty($input['receive_date'])) ? $input['receive_date'] : null;
            
            $stmt = $conn->prepare("
                INSERT INTO transmittals_new (
                    ta_id, date, from_origin, document_type, attention, company,
                    address, state, awb_reg, expeditur, receiver_name, receive_date,
                    ras_status, description, remarks, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->bind_param(
                "ssssssssssssssss",
                $ta_id,
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
                $receive_date, // Use the processed date value
                $input['ras_status'] ?? 'Pending',
                $input['description'] ?? '',
                $input['remarks'] ?? '',
                $user['name']
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to create transmittal: " . $stmt->error, 500);
            }
            
            echo json_encode([
                "status" => 201,
                "message" => "Transmittal created successfully",
                "ta_id" => $ta_id
            ]);
            break;
            
        case 'PUT':
            // UPDATE operation
            $ta_id = $_GET['ta_id'] ?? null;
            if (!$ta_id) throw new Exception("TA ID is required", 400);
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            // Check if exists
            $check = $conn->prepare("SELECT 1 FROM transmittals_new WHERE ta_id = ?");
            $check->bind_param("s", $ta_id);
            $check->execute();
            if ($check->get_result()->num_rows === 0) {
                throw new Exception("Transmittal not found", 404);
            }
            
            // Handle NULL dates
            $receive_date = (!empty($input['receive_date'])) ? $input['receive_date'] : null;
            
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
                    // Special handling for receive_date
                    $params[] = ($field === 'receive_date') ? $receive_date : $input[$field];
                    $types .= 's';
                }
            }
            
            if (empty($fields)) {
                throw new Exception("No fields to update", 400);
            }
            
            $params[] = $ta_id;
            $types .= 's';
            
            $sql = "UPDATE transmittals_new SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE ta_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update transmittal: " . $stmt->error, 500);
            }
            
            echo json_encode([
                "status" => 200,
                "message" => "Transmittal updated successfully"
            ]);
            break;
            
        // ... [rest of your code remains the same]
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