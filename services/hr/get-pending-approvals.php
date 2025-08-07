<?php
header("Content-Type: application/json");
error_reporting(E_ALL);
ini_set('display_errors', 0); // Jangan tampilkan error ke user

// Mulai output buffer
ob_start();

include '../../db.php';     // Dari /services/hr/get-pending-approvals.php → /db.php
include '../../auth.php';   // Untuk verifyToken()

// Hanya boleh GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed"]);
    ob_end_flush();
    exit;
}

// Verifikasi token
$user = verifyToken();
if (!$user) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized: Invalid or missing token"]);
    ob_end_flush();
    exit;
}

$role = $_GET['role'] ?? '';
$username = $_GET['username'] ?? '';

if (empty($role) || empty($username)) {
    http_response_code(400);
    echo json_encode(["error" => "Missing role or username"]);
    ob_end_flush();
    exit;
}

// Log untuk debugging
error_log("get-pending-approvals: role='$role', username='$username'");

$role = $conn->real_escape_string($role);
$username = $conn->real_escape_string($username);

$sql = "
SELECT 
    p.id, p.jenis, p.keterangan, p.foto, p.createdAt, p.approval_status, p.current_step, p.total_steps,
    up.name, up.department, up.photo as avatar, up.email
FROM hr_perizinan p
LEFT JOIN user_profiles up ON p.username = up.username
INNER JOIN approvals a ON p.id = a.request_id
WHERE 
    a.step_order = p.current_step
    AND a.status = 'pending'
    AND a.role = ?
    AND p.approval_status = 'pending'
ORDER BY p.createdAt DESC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    http_response_code(500);
    echo json_encode(["error" => "Database prepare error"]);
    ob_end_flush();
    exit;
}

$stmt->bind_param("s", $role);
if (!$stmt->execute()) {
    error_log("Execute failed: " . $stmt->error);
    http_response_code(500);
    echo json_encode(["error" => "Database execute error"]);
    ob_end_flush();
    exit;
}

$result = $stmt->get_result();
$requests = [];
while ($row = $result->fetch_assoc()) {
    $requests[] = [
        'id' => $row['id'],
        'type' => $row['jenis'],
        'status' => $row['approval_status'],
        'createdAt' => $row['createdAt'],
        'current_step' => $row['current_step'],
        'total_steps' => $row['total_steps'],
        'requester' => [
            'name' => $row['name'],
            'email' => $row['email'],
            'department' => $row['department'],
            'avatar' => $row['avatar'] ? "/uploads/profiles/{$row['avatar']}" : "/default.jpeg"
        ],
        'details' => $row['keterangan'],
        'attachments' => $row['foto'] ? [['name' => basename($row['foto']), 'size' => 'Unknown']] : []
    ];
}

// Bersihkan buffer sebelum output
ob_clean();
echo json_encode($requests);
ob_end_flush();
exit;
?>