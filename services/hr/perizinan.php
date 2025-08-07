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
        // Enable error logging
        ini_set('display_errors', 1);
        error_reporting(E_ALL);
        file_put_contents('perizinan_debug.log', "\n" . date('Y-m-d H:i:s') . " - New request\n", FILE_APPEND);
        file_put_contents('perizinan_debug.log', "POST: " . print_r($_POST, true) . "\n", FILE_APPEND);
        file_put_contents('perizinan_debug.log', "FILES: " . print_r($_FILES, true) . "\n", FILE_APPEND);

        // Validasi input
        $requiredFields = ['username', 'keterangan', 'izin'];
        foreach ($requiredFields as $field) {
            if (!isset($_POST[$field]) || empty($_POST[$field])) {
                $error = "Field $field diperlukan";
                file_put_contents('perizinan_debug.log', date('Y-m-d H:i:s') . " - $error\n", FILE_APPEND);
                http_response_code(400);
                echo json_encode(["status" => 400, "error" => $error]);
                exit;
            }
        }

        $username = $conn->real_escape_string($_POST['username']);
        $keterangan = $conn->real_escape_string($_POST['keterangan']);
        $jenis = $conn->real_escape_string($_POST['izin']);
        $fileName = null;

        // Handle file upload
        if (isset($_FILES['picture']) && $_FILES['picture']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['picture'];
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];

            if (!in_array($file['type'], $allowedTypes)) {
                http_response_code(400);
                echo json_encode(["status" => 400, "error" => "Hanya file JPG, PNG, GIF yang diperbolehkan"]);
                exit;
            }

            // Generate unique filename
            $ext = pathinfo($file["name"], PATHINFO_EXTENSION);
            $fileName = preg_replace("/[^a-zA-Z0-9_-]/", "", $username) . "_" . time() . "." . $ext;
            $uploadPath = $uploadDir . $fileName;

            // Process image
            if (!compressAndResizeImage($file['tmp_name'], $uploadPath, 500, 500)) {
                http_response_code(500);
                echo json_encode(["status" => 500, "error" => "Gagal memproses gambar"]);
                exit;
            }
        }

        // Mulai transaksi database
        $conn->begin_transaction();

        try {
            // 1. Ambil department dari user_profiles
            $dept = "Unknown";
            $sql_dept = "SELECT department FROM user_profiles WHERE username = ?";
            $stmt_dept = $conn->prepare($sql_dept);
            $stmt_dept->bind_param("s", $username);
            $stmt_dept->execute();
            $result_dept = $stmt_dept->get_result();
            if ($row = $result_dept->fetch_assoc()) {
                $dept = $row['department'];
            }
            $stmt_dept->close();

            // 2. Ambil total_steps dari approval_steps
            $sql_steps = "SELECT COUNT(*) as count FROM approval_steps WHERE request_type = ?";
            $stmt_steps = $conn->prepare($sql_steps);
            $stmt_steps->bind_param("s", $jenis);
            $stmt_steps->execute();
            $result_steps = $stmt_steps->get_result();
            $row = $result_steps->fetch_assoc();
            $total_steps = $row && isset($row['count']) ? (int)$row['count'] : 0;
            $stmt_steps->close();

            if ($total_steps < 1) {
                throw new Exception("Approval steps not defined for '$jenis'");
            }

            // 3. Insert ke hr_perizinan
            $sql_insert = "INSERT INTO hr_perizinan 
                          (username, keterangan, jenis, foto, department, total_steps, current_step, approval_status, status) 
                           VALUES (?, ?, ?, ?, ?, ?, 1, 'pending', 'pending')";
            $stmt_insert = $conn->prepare($sql_insert);
            $stmt_insert->bind_param("sssssi", $username, $keterangan, $jenis, $fileName, $dept, $total_steps);

            if (!$stmt_insert->execute()) {
                throw new Exception("Gagal menyimpan data perizinan: " . $stmt_insert->error);
            }

            $requestId = $stmt_insert->insert_id;
            $stmt_insert->close();

            // 4. Insert ke approvals untuk setiap step
            $sql_approval = "INSERT INTO approvals (request_id, step_order, role, status) 
                             SELECT ?, step_order, required_role, 'pending' 
                             FROM approval_steps 
                             WHERE request_type = ? 
                             ORDER BY step_order";
            $stmt_approval = $conn->prepare($sql_approval);
            $stmt_approval->bind_param("is", $requestId, $jenis);

            if (!$stmt_approval->execute()) {
                throw new Exception("Gagal insert approval steps: " . $stmt_approval->error);
            }
            $stmt_approval->close();

            // 5. Commit transaksi
            $conn->commit();

            echo json_encode([
                "status" => 200,
                "message" => "Berhasil diajukan",
                "data" => [
                    "id" => $requestId,
                    "jenis" => $jenis,
                    "total_steps" => $total_steps
                ]
            ]);

        } catch (Exception $e) {
            $conn->rollback();

            // Hapus file jika ada error
            if ($fileName && file_exists($uploadPath)) {
                unlink($uploadPath);
            }

            file_put_contents('perizinan_debug.log', date('Y-m-d H:i:s') . " - DB Error: " . $e->getMessage() . "\n", FILE_APPEND);
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => $e->getMessage()]);
        }

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