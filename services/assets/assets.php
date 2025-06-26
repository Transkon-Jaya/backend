<?php
header("Content-Type: application/json");
require '../../db.php';
require '../../auth.php';

authorize(5, ["admin_asset"], [], null);
$user = verifyToken();
$id_company = $user['id_company'] ?? null;

$method = $_SERVER['REQUEST_METHOD'];
$id = $_GET['id'] ?? null;

try {
    if ($method !== 'GET') {
        throw new Exception("Method not allowed", 405);
    }

    $conn->autocommit(FALSE);

    if ($id) {
        // Mode: detail satu asset
        $sql = "SELECT 
                    a.*, 
                    c.name as category_name,
                    l.name as location_name,
                    d.name as department_name
                FROM assets a
                LEFT JOIN asset_categories c ON a.category_id = c.id
                LEFT JOIN locations l ON a.location_id = l.id
                LEFT JOIN departments d ON a.department_id = d.id
                WHERE a.id = ?";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
        
        $stmt->bind_param("i", $id);
        if (!$stmt->execute()) throw new Exception("Execute failed: " . $stmt->error);

        $result = $stmt->get_result();
        if ($result->num_rows === 0) throw new Exception("Asset not found", 404);

        $asset = $result->fetch_assoc();

        // Spesifikasi
        $specStmt = $conn->prepare("SELECT spec_key, spec_value FROM asset_specifications WHERE asset_id = ?");
        $specStmt->bind_param("i", $id);
        $specStmt->execute();
        $specResult = $specStmt->get_result();
        $specs = [];
        while ($row = $specResult->fetch_assoc()) {
            $specs[$row['spec_key']] = $row['spec_value'];
        }
        $asset['specifications'] = $specs;

        // Maintenance
        $maintStmt = $conn->prepare("SELECT * FROM maintenance_records WHERE asset_id = ? ORDER BY completion_date DESC");
        $maintStmt->bind_param("i", $id);
        $maintStmt->execute();
        $maintResult = $maintStmt->get_result();
        $asset['maintenance_history'] = $maintResult->fetch_all(MYSQLI_ASSOC);

        $conn->commit();

        echo json_encode([
            "status" => 200,
            "data" => $asset
        ]);
    } else {
        // Mode: semua asset
        $sql = "SELECT 
                    a.*, 
                    c.name as category_name,
                    l.name as location_name,
                    d.name as department_name
                FROM assets a
                LEFT JOIN asset_categories c ON a.category_id = c.id
                LEFT JOIN locations l ON a.location_id = l.id
                LEFT JOIN departments d ON a.department_id = d.id
                ORDER BY a.id DESC";

        $result = $conn->query($sql);
        if (!$result) throw new Exception("Query failed: " . $conn->error);

        $assets = $result->fetch_all(MYSQLI_ASSOC);
        $conn->commit();

        echo json_encode([
            "status" => 200,
            "data" => $assets
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
