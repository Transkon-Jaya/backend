<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';
require 'utils/compressResize.php';

// Cek token dan hak akses
authorize(9, ["admin_asset"], [], null);
$user = verifyToken();

$uploadDir = "/var/www/html/uploads/assets/";
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$response = [
    "status" => "error",
    "message" => "Unknown error"
];

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

    // Validasi tipe file (diperbarui seperti profile.php)
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
    $fileType = mime_content_type($file['tmp_name']);
    if (!in_array($fileType, $allowedTypes)) {
        throw new Exception("Hanya file JPEG, PNG, atau GIF yang diperbolehkan", 400);
    }

    // Validasi ukuran file (diperbarui)
    $maxSize = 2 * 1024 * 1024; // 2MB
    if ($file['size'] > $maxSize) {
        throw new Exception("Ukuran file maksimal 2MB", 400);
    }

    // Generate nama file (format diperbarui)
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = 'asset_' . preg_replace('/[^a-zA-Z0-9]/', '_', $assetId) . '_' . time() . '.' . $ext;
    $uploadPath = $uploadDir . $filename;

    // Kompresi dan resize gambar (parameter disesuaikan)
    $resizeResult = compressAndResizeImage(
        $file['tmp_name'], 
        $uploadPath, 
        500,  // Lebar maksimal (disamakan dengan profile.php)
        500,  // Tinggi maksimal
        80    // Kualitas (ditingkatkan dari 75)
    );

    if (!$resizeResult) {
        throw new Exception("Gagal memproses gambar", 500);
    }

    // Update database dengan transaksi (dipertahankan)
    $conn->autocommit(FALSE);
    
    $imagePath = $filename;
    $stmt = $conn->prepare("UPDATE assets SET image_path = ? WHERE id = ?");
    $stmt->bind_param("si", $imagePath, $assetId);
    
    if (!$stmt->execute()) {
        throw new Exception("Gagal update database: " . $stmt->error, 500);
    }

    $conn->commit();

    // Response sukses (format disesuaikan)
    $response = [
        "status" => 200,
        "message" => "Gambar berhasil diupload",
        "data" => [
            "image_url" => "https://www.transkon-rent.com/uploads/assets/" . $filename,
            "file_name" => $filename,
            "timestamp" => time()
        ]
    ];

    echo json_encode($response);

} catch (Exception $e) {
    // Error handling (diperbarui)
    if (isset($conn)) $conn->rollback();
    
    $response = [
        "status" => $e->getCode() ?: 500,
        "error" => $e->getMessage(),
        "debug" => [
            "file" => $e->getFile(),
            "line" => $e->getLine()
        ]
    ];

    http_response_code($e->getCode() ?: 500);
    echo json_encode($response);
    
} finally {
    if (isset($conn)) $conn->close();
}