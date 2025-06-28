<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

// Cek token dan hak akses
authorize(5, ["admin_asset"], [], null);
$user = verifyToken();

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

try {
    if ($method !== 'PUT' && $method !== 'POST') {
        throw new Exception("Method not allowed", 405);
    }

    if (empty($input['id'])) {
        throw new Exception("ID asset diperlukan", 400);
    }

    $conn->autocommit(FALSE);

    $id = $conn->real_escape_string($input['id']);
    $updates = [];
    $params = [];
    $types = '';

    // Bangun query update dinamis
    $allowedFields = [
        'name' => 's',
        'category_id' => 'i',
        'status' => 's',
        'purchase_value' => 'd',
        'purchase_date' => 's',
        'location_id' => 'i',
        'specifications' => 's'
    ];

    foreach ($allowedFields as $field => $type) {
        if (isset($input[$field])) {
            $updates[] = "$field = ?";
            $params[] = $input[$field];
            $types .= $type;
        }
    }

    if (empty($updates)) {
        throw new Exception("Tidak ada data yang diupdate", 400);
    }

    $sql = "UPDATE assets SET " . implode(', ', $updates) . " WHERE id = ?";
    $types .= 'i';
    $params[] = $id;

    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
    
    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    if ($stmt->affected_rows === 0) {
        throw new Exception("Tidak ada perubahan data", 400);
    }

    $conn->commit();
    
    echo json_encode([
        "status" => 200,
        "message" => "Asset berhasil diupdate",
        "data" => ["id" => $id]
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