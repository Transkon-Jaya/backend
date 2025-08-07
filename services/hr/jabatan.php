<?php
header("Content-Type: application/json");
include 'db.php';     // ✅ Diperbaiki: dari /api ke root
include 'auth.php';   // ✅ Diperbaiki

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed"]);
    exit;
}

$user = verifyToken();
if (!$user) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized: Invalid or missing token"]);
    exit;
}

$username = $user['username'];

// Override username via GET (opsional, untuk testing)
// $username = $_GET['username'] ?? $username;

$sql = "SELECT jabatan FROM user_profiles WHERE username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $username);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(["error" => "Database error: " . $conn->error]);
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

if (!$jabatan) {
    http_response_code(404);
    echo json_encode(["error" => "Jabatan not set"]);
    exit;
}

echo json_encode(["jabatan" => $jabatan]);
$conn->close();
?>