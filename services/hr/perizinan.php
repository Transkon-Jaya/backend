<?php
ob_start();
header("Content-Type: application/json");
error_reporting(E_ALL);
ini_set('display_errors', 0); // Jangan tampilkan error di output

include 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    ob_clean();
    http_response_code(405);
    echo json_encode(["status" => 405, "error" => "Method not allowed"]);
    ob_end_flush();
    exit;
}

// Ambil role dari parameter (misalnya: Manager, Supervisor)
$role = isset($_GET['role']) ? $conn->real_escape_string($_GET['role']) : null;
if (!$role) {
    ob_clean();
    http_response_code(400);
    echo json_encode(["status" => 400, "error" => "Role diperlukan"]);
    ob_end_flush();
    exit;
}

// Ambil semua request yang pending & role saat ini sesuai
$sql = "
    SELECT 
        p.id, p.username, u.name, p.jenis, p.keterangan, p.department, p.foto, 
        p.createdAt, p.current_step, a.step_order, a.role, a.status AS approval_status
    FROM hr_perizinan p
    JOIN approvals a ON a.request_id = p.id AND a.step_order = p.current_step
    JOIN user_profiles u ON u.username = p.username
    WHERE p.status = 'pending' 
      AND p.approval_status = 'pending' 
      AND a.status = 'pending' 
      AND a.role = ?
    ORDER BY p.createdAt DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $role);
$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

ob_clean();
echo json_encode([
    "status" => 200,
    "count" => count($data),
    "data" => $data
]);
ob_end_flush();
exit;

$conn->close();
ob_end_flush();
?>
