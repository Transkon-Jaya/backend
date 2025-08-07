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
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Ambil username dari Authorization atau POST (untuk demo)
function getAuthUsername($conn) {
    // Cek dari POST dulu (untuk demo)
    if (isset($_POST['username'])) {
        return $conn->real_escape_string($_POST['username']);
    }
    // Atau dari header Authorization (lebih aman)
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']);
        // Di sini Anda bisa decode JWT atau cek session
        // Untuk demo, kita skip
    }
    return null;
}

switch ($method) {
    // ========================
    // GET: Riwayat Perizinan
    // ========================
    case 'GET':
        if (!isset($_GET['username'])) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "No Username!"]);
            break;
        }
        $username = $conn->real_escape_string($_GET['username']);
        $sql = "SELECT * FROM hr_perizinan WHERE username = ? ORDER BY createdAt DESC";
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

    // ========================
    // POST: Submit Perizinan
    // ========================
    case 'POST':
        // Cek apakah ini approval atau submit baru
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'approve':
                case 'reject':
                    include 'approval_action.php';
                    break;
                default:
                    http_response_code(400);
                    echo json_encode(["status" => 400, "error" => "Invalid action"]);
            }
            break;
        }

        // Jika bukan action, maka submit perizinan baru
        $data = $_POST;
        if (!$data || !isset($data['username'])) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Invalid input."]);
            break;
        }

        $username = $data['username'];
        $keterangan = $data['keterangan'];
        $jenis = $data['izin']; // dari frontend: 'izin', 'cuti', 'dinas'
        $isMoved = false;
        $fileName = "";

        // Upload file
        if (isset($_FILES['picture'])) {
            $file = $_FILES['picture'];
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
            if (!in_array($file['type'], $allowedTypes)) {
                http_response_code(400);
                echo json_encode(["status" => 400, "error" => "Invalid file type."]);
                break;
            }

            $ext = pathinfo($file["name"], PATHINFO_EXTENSION);
            $cleanUsername = preg_replace("/[^a-zA-Z0-9_-]/", "", $username);
            $fileName = $cleanUsername . "_" . time() . "." . $ext;
            $uploadPath = $uploadDir . $fileName;

            if (!compressAndResizeImage($file['tmp_name'], $uploadPath, 500, 500)) {
                http_response_code(500);
                echo json_encode(["status" => 500, "error" => "File resize failed."]);
                break;
            }
            $isMoved = true;
        }

        if (!$isMoved) {
            echo json_encode(["status" => 500, "error" => "Photo Move Error"]);
            break;
        }

        // Ambil department dari user_profiles
        $dept = "Unknown";
        $sql = "SELECT department FROM user_profiles WHERE username = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $dept = $row['department'];
        }

        // Ambil total steps dari approval_steps
        $sql = "SELECT COUNT(*) as count FROM approval_steps WHERE request_type = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $jenis);
        $stmt->execute();
        $result = $stmt->get_result();
        $total_steps = $result->fetch_assoc()['count'] ?: 1;

        // Insert ke hr_perizinan
        $sql = "INSERT INTO hr_perizinan (username, keterangan, jenis, foto, department, total_steps, current_step, approval_status, status) 
                VALUES (?, ?, ?, ?, ?, ?, 1, 'pending', 'pending')";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssssi", $username, $keterangan, $jenis, $fileName, $dept, $total_steps);
        
        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => "DB Error: " . $stmt->error]);
            break;
        }

        $requestId = $stmt->insert_id;

        // Buat entri di approvals untuk setiap step
        for ($step = 1; $step <= $total_steps; $step++) {
            $sql2 = "INSERT INTO approvals (request_id, step_order, role, status) 
                     SELECT ?, ?, required_role, 'pending' FROM approval_steps 
                     WHERE request_type = ? AND step_order = ?";
            $stmt2 = $conn->prepare($sql2);
            $stmt2->bind_param("iiss", $requestId, $step, $jenis, $step);
            $stmt2->execute();
        }

        echo json_encode(["status" => 200, "message" => "Success", "id" => $requestId]);
        break;

    default:
        http_response_code(405);
        echo json_encode(["status" => 405, "error" => "Invalid request method"]);
        break;
}
$conn->close();
?>