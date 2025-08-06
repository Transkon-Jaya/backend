<?php
header("Content-Type: application/json");
require '../../../db.php';
require '../../../auth.php'; // Pastikan file auth.php ada untuk fungsi authorize()

// 🔐 Ambil user dari JWT
$currentUser = authorize(); // <- fungsi ini menguraikan token dan ambil user

$level = $currentUser['user_level'] ?? null;
$username = $currentUser['username'] ?? null;

if (empty($level) || empty($username)) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized: Missing level or username"]);
    exit;
}

$sql = "
SELECT 
    p.id, p.jenis, p.keterangan, p.foto, p.createdAt, p.approval_status, p.current_step, p.total_steps,
    up.name, up.department, up.photo as avatar, up.email
FROM hr_perizinan p
LEFT JOIN user_profiles up ON p.username = up.username
INNER JOIN approvals a ON p.id = a.request_id
WHERE a.step_order = p.current_step
  AND a.status = 'pending'
  AND a.level = ?
  AND p.approval_status = 'pending'
ORDER BY p.createdAt DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $level);
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
            'avatar' => $row['avatar'] ? "/uploads/profile/{$row['avatar']}" : "/default.jpeg"
        ],
        'details' => $row['keterangan'],
        'attachments' => $row['foto'] ? [['name' => basename($row['foto']), 'size' => 'Unknown']] : []
    ];
}

echo json_encode($requests);
