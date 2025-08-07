<?php
header("Content-Type: application/json");
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'db.php';
include 'utils/compressResize.php';

$method = $_SERVER['REQUEST_METHOD'];
$uploadDir = "/var/www/html/uploads/perizinan/";

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Fungsi ambil username (POST atau Authorization header)
function getAuthUsername($conn) {
    if (isset($_POST['username'])) {
        return $conn->real_escape_string($_POST['username']);
    }
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']);
        // Decode token jika pakai JWT (optional)
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
        // Cek jika approval
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

        // Validasi input utama
        if (!isset($_POST['username'], $_POST['keterangan'], $_POST['izin'])) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Invalid input."]);
            break;
        }

        $username = $conn->real_escape_string($_POST['username']);
        $keterangan = $conn->real_escape_string($_POST['keterangan']);
        $jenis = $conn->real_escape_string($_POST['izin']);
        $isMoved = false;
        $fileName = "";

        // Upload dan resize gambar
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

            try {
                if (!compressAndResizeImage($file['tmp_name'], $uploadPath, 500, 500)) {
                    http_response_code(500);
                    echo json_encode(["status" => 500, "error" => "File resize failed."]);
                    break;
                }
                $isMoved = true;
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(["status" => 500, "error" => "Resize exception: " . $e->getMessage()]);
                break;
            }
        }

        if (!$isMoved) {
            http_response_code(500);
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

        // Ambil jumlah langkah approval dari approval_steps
        $sql = "SELECT COUNT(*) as count FROM approval_steps WHERE request_type = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $jenis);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $total_steps = $row && isset($row['count']) ? (int)$row['count'] : 0;

        if ($total_steps < 1) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Approval steps not defined for '$jenis'"]);
            break;
        }

        // Insert ke hr_perizinan
        $sql = "INSERT INTO hr_perizinan (username, keterangan, jenis, foto, department, total_steps, current_step, approval_status, status) 
                VALUES (?, ?, ?, ?, ?, ?, 1, 'pending', 'pending')";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssssi", $username, $keterangan, $jenis, $fileName, $dept, $total_steps);

        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => "DB Error: " . $stmt->error]);
            break;
        }

        $requestId = $stmt->insert_id;

        // Insert approval step
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

    // ========================
    // METHOD TIDAK VALID
    // ========================
    default:
        http_response_code(405);
        echo json_encode(["status" => 405, "error" => "Invalid request method"]);
        break;
}

$conn->close();
?>
