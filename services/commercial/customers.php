<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$search = $_GET['search'] ?? null;

try {
    if ($method !== 'GET') {
        throw new Exception("Method tidak diizinkan", 405);
    }

    if (!$search) {
        echo json_encode(["status" => 400, "error" => "Parameter 'search' diperlukan"]);
        exit;
    }

    $stmt = $conn->prepare("SELECT * FROM customer WHERE name LIKE ? LIMIT 10");
    $likeSearch = "%$search%";
    $stmt->bind_param("s", $likeSearch);
    $stmt->execute();
    $result = $stmt->get_result();

    $customers = [];
    while ($row = $result->fetch_assoc()) {
        $customers[] = $row;
    }

    echo json_encode(["status" => 200, "data" => $customers]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => $e->getCode() ?: 500, "error" => $e->getMessage()]);
} finally {
    $conn->close();
}