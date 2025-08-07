<?php
ob_start();
header("Content-Type: application/json");
error_reporting(E_ALL);
ini_set('display_errors', 0);

include 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    ob_clean();
    http_response_code(405);
    echo json_encode(["status" => 405, "error" => "Method not allowed"]);
    ob_end_flush();
    exit;
}

$role = isset($_GET['role']) ? $conn->real_escape_string($_GET['role']) : null;
$username = isset($_GET['username']) ? $conn->real_escape_string($_GET['username']) : null;

// Jika tidak ada role dan username dikirim, tolak request
if (!$role && !$username) {
    ob_clean();
    http_response_code(400);
    echo json_encode(["status" => 400, "error" => "Role atau Username harus disertakan"]);
    ob_end_flush();
    exit;
}

$data = [];

if ($role) {
    // Approver: ambil perizinan yang masih pending berdasarkan role
    $sql = "
        SELECT 
            p.id, p.username, u.name, p.jenis, p.keterangan, p.department, p.foto, 
            p.createdAt, p.status, p.feedback, p.current_step, 
            a.step_order, a.role, a.status AS approval_status
        FROM hr_perizinan p
        JOIN approvals a ON a.request_id = p.id AND a.step_order = p.current_step
        JOIN user_profiles u ON u.username = p.username
        WHERE p.status = 'pending' 
          AND a.status = 'pending' 
          AND a.role = ?
        ORDER BY p.createdAt DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $role);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
} elseif ($username) {
    // Pengaju: ambil semua perizinan milik user
    $sql = "
        SELECT 
            p.id, p.username, u.name, p.jenis, p.keterangan, p.department, p.foto, 
            p.createdAt, p.status, p.feedback
        FROM hr_perizinan p
        JOIN user_profiles u ON u.username = p.username
        WHERE p.username = ?
        ORDER BY p.createdAt DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
}

ob_clean();
echo json_encode([
    "status" => 200,
    "count" => count($data),
    "data" => $data
]);
ob_end_flush();
$conn->close();
