<?php
header("Content-Type: application/json");
include 'db.php';
include 'utils/compressResize.php';

$method = $_SERVER['REQUEST_METHOD'];
$uploadDir = "/var/www/html/uploads/perizinan/";

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$response = ["status" => "error", "message" => "Unknown error"];

switch ($method) {
    case 'GET':
        if (!isset($_GET['username'])) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "No Username!"]);
            break;
        }
        $username = $conn->real_escape_string($_GET['username']);
        $sql = "SELECT * FROM hr_perizinan WHERE username = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        echo json_encode($data);
        break;

    case 'POST':
        $data = $_POST;
        if (!$data || !isset($data['username'], $data['izin'], $data['keterangan'])) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Invalid input."]);
            break;
        }

        $username = $data['username'];
        $izin = $data['izin'];
        $keterangan = $data['keterangan'];

        $fileName = "";
        $isMoved = false;

        // === Upload Foto ===
        if (isset($_FILES['picture'])) {
            $profilePicture = $_FILES['picture'];
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];

            if (!in_array($profilePicture['type'], $allowedTypes)) {
                http_response_code(400);
                echo json_encode(["status" => 400, "error" => "Invalid file type."]);
                break;
            }

            $ext = pathinfo($profilePicture["name"], PATHINFO_EXTENSION);
            $cleanUsername = preg_replace("/[^a-zA-Z0-9_-]/", "", $username);
            $fileName = $cleanUsername . "_" . time() . "." . $ext;
            $uploadPath = $uploadDir . $fileName;

            $resizeResult = compressAndResizeImage($profilePicture['tmp_name'], $uploadPath, 500, 500);

            if (!$resizeResult) {
                http_response_code(500);
                echo json_encode(["status" => 500, "error" => "File resize and compress failed."]);
                break;
            }
            $isMoved = true;
        }

        // === Insert ke hr_perizinan ===
        $status = 'pending';
        $sql = "INSERT INTO hr_perizinan (username, keterangan, jenis, foto, status) VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssss", $username, $keterangan, $izin, $fileName, $status);

        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => "Insert hr_perizinan failed: " . $stmt->error]);
            break;
        }

        $izinId = $stmt->insert_id;
        $stmt->close();

        // === Ambil approval_steps untuk jenis izin ===
        $stepSql = "SELECT step, approver FROM approval_steps WHERE request_type = ? ORDER BY step ASC";
        $stepStmt = $conn->prepare($stepSql);
        $stepStmt->bind_param("s", $izin);
        $stepStmt->execute();
        $stepsResult = $stepStmt->get_result();

        $approvalSteps = [];
        while ($row = $stepsResult->fetch_assoc()) {
            $approvalSteps[] = $row;
        }
        $stepStmt->close();

        // === Fallback: jika tidak ada approval_steps, anggap 1 langkah disetujui langsung oleh atasan default ===
        if (count($approvalSteps) === 0) {
            $approvalSteps[] = [
                "step" => 1,
                "approver" => "admin" // atau supervisor default
            ];
        }

        // === Insert ke approval_requests ===
        $approvalSql = "INSERT INTO approval_requests (request_id, request_type, step_number, approver_username, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())";
        $approvalStmt = $conn->prepare($approvalSql);

        foreach ($approvalSteps as $step) {
            $stepNum = $step["step"];
            $approver = $step["approver"];
            $stepStatus = ($stepNum === 1) ? "waiting" : "pending"; // hanya step pertama aktif

            $approvalStmt->bind_param("isiss", $izinId, $izin, $stepNum, $approver, $stepStatus);
            if (!$approvalStmt->execute()) {
                http_response_code(500);
                echo json_encode(["status" => 500, "error" => "Insert approval_requests failed: " . $approvalStmt->error]);
                $approvalStmt->close();
                break 2;
            }
        }
        $approvalStmt->close();

        echo json_encode(["status" => 200, "message" => "Success", "izin_id" => $izinId, "foto" => $fileName]);
        break;

    default:
        http_response_code(405);
        echo json_encode(["status" => 405, "error" => "Invalid request method"]);
        break;
}

$conn->close();
?>
