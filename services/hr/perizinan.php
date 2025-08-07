<?php
header("Content-Type: application/json");
include 'db.php';

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['id']) || !isset($input['action'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid input"]);
    exit;
}

$id = intval($input['id']);
$action = $input['action']; // "approve" atau "reject"
$currentRole = $input['role']; // Role user yang sedang login

if (!in_array($action, ['approve', 'reject'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid action"]);
    exit;
}

// Ambil data izin
$sql = "SELECT approval_steps, status FROM hr_perizinan WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(["status" => "error", "message" => "Perizinan tidak ditemukan"]);
    exit;
}

$row = $result->fetch_assoc();
$approvalSteps = json_decode($row['approval_steps'], true);
$status = $row['status'];

if ($status !== 'pending') {
    echo json_encode(["status" => "error", "message" => "Perizinan sudah diproses"]);
    exit;
}

// Temukan langkah approval saat ini
$stepUpdated = false;
for ($i = 0; $i < count($approvalSteps); $i++) {
    if (!$approvalSteps[$i]['approved']) {
        if ($approvalSteps[$i]['required_role'] === $currentRole) {
            if ($action === 'approve') {
                $approvalSteps[$i]['approved'] = true;
            } else {
                // Tolak: set status = rejected
                $approvalSteps[$i]['approved'] = false;
                $status = 'rejected';
            }
            $stepUpdated = true;
            break;
        } else {
            echo json_encode(["status" => "error", "message" => "Anda tidak memiliki izin untuk menyetujui langkah ini"]);
            exit;
        }
    }
}

if (!$stepUpdated) {
    echo json_encode(["status" => "error", "message" => "Langkah approval tidak ditemukan atau sudah selesai"]);
    exit;
}

// Jika semua langkah sudah disetujui, update status
if ($action === 'approve') {
    $allApproved = true;
    foreach ($approvalSteps as $step) {
        if (!$step['approved']) {
            $allApproved = false;
            break;
        }
    }
    if ($allApproved) {
        $status = 'approved';
    }
}

// Simpan kembali data ke database
$approvalStepsJson = json_encode($approvalSteps);
$update = "UPDATE hr_perizinan SET approval_steps = ?, status = ? WHERE id = ?";
$stmt = $conn->prepare($update);
$stmt->bind_param("ssi", $approvalStepsJson, $status, $id);
if ($stmt->execute()) {
    echo json_encode(["status" => "success", "message" => "Perizinan berhasil diproses", "status_izin" => $status]);
} else {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Gagal memperbarui data"]);
}
