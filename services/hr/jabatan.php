<?php
header("Content-Type: application/json");
include 'db.php';  // Sesuaikan path ke db.php
include 'auth.php'; // Pastikan ada fungsi verifyToken()

// Hanya boleh method GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed"]);
    exit;
}

// Verifikasi token untuk dapatkan username
$user = verifyToken();
if (!$user) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized: Invalid or missing token"]);
    exit;
}

$username = $user['username'];

// Jika ingin override username (opsional, untuk debug), bisa pakai:
// $username = $_GET['username'] ?? $username;

// Ambil jabatan dari user_profiles
$sql = "SELECT jabatan FROM user_profiles WHERE username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $username);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(["error" => "Database error"]);
    exit;
}

$result = $stmt->get_result();
if ($result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(["error" => "User profile not found"]);
    exit;
}

$row = $result->fetch_assoc();
$jabatan = $row['jabatan'];

// Jika jabatan kosong
if (!$jabatan) {
    http_response_code(404);
    echo json_encode(["error" => "Jabatan not set"]);
    exit;
}

// Sukses
echo json_encode(["jabatan" => $jabatan]);

$conn->close();
?>