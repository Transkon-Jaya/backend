<?php
require_once '../../config/db.php'; // Pastikan path ke koneksi database

header('Content-Type: application/json');

try {
    $stmt = $pdo->query("SELECT id, name FROM asset_categories ORDER BY name");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 200,
        'data' => $categories
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 500,
        'error' => $e->getMessage()
    ]);
}
