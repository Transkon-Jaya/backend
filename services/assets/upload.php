<?php
header("Content-Type: application/json");
require '../../db.php';
require '../../auth.php';

// Authorize dengan level akses yang sesuai
authorize(5, ["admin_asset"], [], null);
$user = verifyToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => 405, "error" => "Method not allowed"]);
    exit;
}

if (!isset($_FILES['image']) || !isset($_POST['asset_id'])) {
    http_response_code(400);
    echo json_encode(["status" => 400, "error" => "Bad request"]);
    exit;
}

$assetId = (int)$_POST['asset_id'];
$file = $_FILES['image'];

try {
    // Validasi asset_id
    $stmt = $conn->prepare("SELECT id FROM assets WHERE id = ?");
    $stmt->bind_param("i", $assetId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("Asset not found");
    }

    // Validasi file
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    if (!in_array($file['type'], $allowedTypes)) {
        throw new Exception("Invalid file type");
    }

    if ($file['size'] > 5 * 1024 * 1024) { // 5MB max
        throw new Exception("File too large");
    }

    // Buat direktori upload jika belum ada
    $uploadDir = '../../uploads/assets/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Generate nama file unik
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'asset_' . $assetId . '_' . time() . '.' . $ext;
    $targetPath = $uploadDir . $filename;

    // Upload file
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new Exception("Failed to upload file");
    }

    // Update database
    $updateStmt = $conn->prepare("UPDATE assets SET image_path = ? WHERE id = ?");
    $updateStmt->bind_param("si", $filename, $assetId);
    
    if (!$updateStmt->execute()) {
        throw new Exception("Database update failed");
    }

    // Response sukses
    echo json_encode([
        "status" => 200,
        "message" => "File uploaded successfully",
        "filename" => $filename,
        "url" => "https://www.transkon-rent.com/uploads/assets/" . $filename
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => 500, "error" => $e->getMessage()]);
}

$conn->close();
?>