<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$id_company = $_SESSION['user']['id_company'];

try {
    $conn->autocommit(false);

    // =============================
    // === GET /assets/locations ===
    // =============================
    if ($method === 'GET' && isset($_GET['get_locations'])) {
        $sql = "SELECT id, name FROM asset_locations WHERE id_company = ? ORDER BY name";
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

    // =============================
    // === GET /departments ===
    // =============================
    if ($method === 'GET' && isset($_GET['get_departments'])) {
        $sql = "SELECT id, name FROM asset_departments WHERE id_company = ? ORDER BY name";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id_company);
        $stmt->execute();
        
        $result = $stmt->get_result();
        $departments = [];
        while ($row = $result->fetch_assoc()) {
            $departments[] = $row;
        }
        
        $conn->commit();
        echo json_encode([
            "status" => 200,
            "data" => $departments
        ]);
        exit;
    }

    // =============================
    // === GET /categories ===
    // =============================
    if ($method === 'GET' && isset($_GET['get_categories'])) {
        $sql = "SELECT id, name FROM asset_categories WHERE id_company = ? ORDER BY name";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id_company);
        $stmt->execute();
        
        $result = $stmt->get_result();
        $categories = [];
        while ($row = $result->fetch_assoc()) {
            $categories[] = $row;
        }
        
        $conn->commit();
        echo json_encode([
            "status" => 200,
            "data" => $categories
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