<?php
header("Content-Type: application/json");
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 🔽 Tambahkan log
error_log("=== START get-pending-approvals.php ===");
error_log("GET: " . print_r($_GET, true));

include '../../db.php';

// 🔽 Cek koneksi
if (!$conn) {
    error_log("DB Connection failed");
    http_response_code(500);
    echo json_encode(["error" => "DB Connection failed"]);
    exit;
}

$role = $_GET['role'] ?? '';
$username = $_GET['username'] ?? '';

error_log("Role: $role, Username: $username");

if (empty($role) || empty($username)) {
    http_response_code(400);
    echo json_encode(["error" => "Missing role or username", "received" => $_GET]);
    exit;
}

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

error_log("SQL: $sql");
error_log("Role parameter: $role");

$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    http_response_code(500);
    echo json_encode(["error" => "Prepare failed", "sql_error" => $conn->error]);
    exit;
}

$stmt->bind_param("s", $role);
if (!$stmt->execute()) {
    error_log("Execute failed: " . $stmt->error);
    http_response_code(500);
    echo json_encode(["error" => "Execute failed", "sql_error" => $stmt->error]);
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

error_log("Found " . count($requests) . " pending approvals");
echo json_encode($requests);
?>