<?php
header("Content-Type: application/json");
include '../../../db.php';

$role = $_GET['role'] ?? '';
$username = $_GET['username'] ?? '';

if (empty($role) || empty($username)) {
    http_response_code(400);
    echo json_encode(["error" => "Missing role or username"]);
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
WHERE a.step_order = p.current_step
  AND a.status = 'pending'
  AND a.role = ?
  AND p.approval_status = 'pending'
ORDER BY p.createdAt DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $role);
$stmt->execute();
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

echo json_encode($requests);
?>