<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';
require 'utils/compressResize.php';

// Cek token dan hak akses
authorize(5, ["admin_asset"], [], null);
$user = verifyToken();

$uploadDir = "/var/www/html/uploads/assets/";
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Method not allowed", 405);
    }

    // Validasi input
    if (empty($_POST['asset_id'])) {
        throw new Exception("ID asset diperlukan", 400);
    }

    if (empty($_FILES['image'])) {
        throw new Exception("File gambar diperlukan", 400);
    }

    $assetId = (int)$_POST['asset_id'];
    $file = $_FILES['image'];

    // Validasi tipe file
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    if (!in_array($file['type'], $allowedTypes)) {
        throw new Exception("Hanya file JPEG, PNG, atau GIF yang diperbolehkan", 400);
    }

    // Validasi ukuran file (max 2MB)
    if ($file['size'] > 2 * 1024 * 1024) {
        throw new Exception("Ukuran file maksimal 2MB", 400);
    }

    // Generate nama file unik
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'asset_' . $assetId . '_' . time() . '.' . $ext;
    $uploadPath = $uploadDir . $filename;

    // Kompresi dan resize gambar
    $resizeResult = compressAndResizeImage(
        $file['tmp_name'], 
        $uploadPath, 
        800,  // Lebar maksimal
        800,  // Tinggi maksimal
        75    // Kualitas (0-100)
    );

    if (!$resizeResult) {
        throw new Exception("Gagal memproses gambar", 500);
    }

    // Update database
    $conn->autocommit(FALSE);
    
    $imagePath = 'uploads/assets/' . $filename;
    $stmt = $conn->prepare("UPDATE assets SET image_path = ? WHERE id = ?");
    $stmt->bind_param("si", $imagePath, $assetId);
    
    if (!$stmt->execute()) {
        throw new Exception("Gagal update database: " . $stmt->error, 500);
    }

    $conn->commit();

    // Response sukses
    echo json_encode([
        "status" => 200,
        "message" => "Gambar berhasil diupload",
        "image_path" => $imagePath,
        "file_name" => $filename
    ]);

} catch (Exception $e) {
    if (isset($conn)) $conn->rollback();
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => $e->getCode() ?: 500,
        "error" => $e->getMessage()
    ]);
} finally {
    if (isset($conn)) $conn->close();
}