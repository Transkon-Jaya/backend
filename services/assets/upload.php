<?php
header('Content-Type: application/json');
require_once '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$assetId = $_POST['asset_id'] ?? null;
if (!$assetId) {
    http_response_code(400);
    echo json_encode(['error' => 'Asset ID is required']);
    exit;
}

if (!isset($_FILES['image'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

$uploadDir = '../../uploads/assets/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$file = $_FILES['image'];
$filename = 'asset_' . $assetId . '_' . time() . '.' . pathinfo($file['name'], PATHINFO_EXTENSION);
$targetPath = $uploadDir . $filename;

if (move_uploaded_file($file['tmp_name'], $targetPath)) {
    // Update database with image path
    $db = new Database();
    $pdo = $db->getConnection();
    
    $stmt = $pdo->prepare("UPDATE assets SET image_path = ? WHERE id = ?");
    $stmt->execute([$filename, $assetId]);
    
    echo json_encode([
        'success' => true,
        'image_path' => $filename,
        'full_url' => 'https://www.transkon-rent.com/uploads/assets/' . $filename
    ]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to upload file']);
}