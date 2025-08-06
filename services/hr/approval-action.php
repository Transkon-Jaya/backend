<?php
global $conn;

$action = $_POST['action']; // 'approve' or 'reject'
$requestId = (int)$_POST['request_id'];
$approverUsername = $conn->real_escape_string($_POST['username']); // dari login
$comment = isset($_POST['comment']) ? $conn->real_escape_string($_POST['comment']) : '';

// Ambil role dari user_profiles
$sql = "SELECT jabatan FROM user_profiles WHERE username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $approverUsername);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows == 0) {
    http_response_code(403);
    echo json_encode(["status" => 403, "error" => "User not found"]);
    exit;
}
$user = $result->fetch_assoc();
$role = strtolower($user['jabatan']); // misal: 'Supervisor' → 'supervisor'

// Ambil current_step dari hr_perizinan
$sql = "SELECT current_step, approval_status, jenis FROM hr_perizinan WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $requestId);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows == 0) {
    http_response_code(404);
    echo json_encode(["status" => 404, "error" => "Request not found"]);
    exit;
}
$request = $result->fetch_assoc();

if ($request['approval_status'] !== 'pending') {
    http_response_code(400);
    echo json_encode(["status" => 400, "error" => "Request already processed"]);
    exit;
}

$currentStep = (int)$request['current_step'];
$requestType = $request['jenis'];

// Cek apakah role ini berhak approve step ini
$sql = "SELECT required_role FROM approval_steps WHERE request_type = ? AND step_order = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("si", $requestType, $currentStep);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows == 0) {
    http_response_code(500);
    echo json_encode(["status" => 500, "error" => "Approval step not configured"]);
    exit;
}
$step = $result->fetch_assoc();
$requiredRole = strtolower($step['required_role']);

if ($role !== $requiredRole) {
    http_response_code(403);
    echo json_encode(["status" => 403, "error" => "You are not authorized to approve this step"]);
    exit;
}

// Update approvals
$status = ($action === 'approve') ? 'approved' : 'rejected';
$sql = "UPDATE approvals SET approver_username = ?, status = ?, comment = ?, approved_at = NOW() 
         WHERE request_id = ? AND step_order = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ssssi", $approverUsername, $status, $comment, $requestId, $currentStep);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(["status" => 500, "error" => "DB Error"]);
    exit;
}

// Jika reject, langsung ubah status global
if ($action === 'rejected') {
    $sql = "UPDATE hr_perizinan SET approval_status = 'rejected', status = 'rejected' WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    echo json_encode(["status" => 200, "message" => "Request rejected"]);
    exit;
}

// Jika approve, naikkan current_step
$sql = "UPDATE hr_perizinan SET current_step = current_step + 1 WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $requestId);
$stmt->execute();

// Cek apakah sudah sampai step terakhir
$sql = "SELECT total_steps, current_step FROM hr_perizinan WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $requestId);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

if ($row['current_step'] > $row['total_steps']) {
    $sql = "UPDATE hr_perizinan SET approval_status = 'approved', status = 'approved' WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    echo json_encode(["status" => 200, "message" => "Request fully approved"]);
} else {
    echo json_encode(["status" => 200, "message" => "Approved, waiting for next level"]);
}
exit;
?>