<?php
ob_start(); // Start buffer
header("Content-Type: application/json");
error_reporting(E_ALL);
ini_set('display_errors', 0); // Jangan tampilkan error

include 'db.php';
include 'utils/compressResize.php';

$method = $_SERVER['REQUEST_METHOD'];
$uploadDir = __DIR__ . '/../../uploads/perizinan/'; // Pastikan path benar

if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0777, true)) {
        ob_clean();
        http_response_code(500);
        echo json_encode(["status" => 500, "error" => "Gagal buat folder upload"]);
        ob_end_flush();
        exit;
    }
}

switch ($method) {
    case 'GET':
        if (!isset($_GET['username'])) {
            ob_clean();
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "No Username!"]);
            ob_end_flush();
            exit;
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
        ob_clean();
        echo json_encode($data);
        ob_end_flush();
        exit;

    case 'POST':
        // Validasi input
        $requiredFields = ['username', 'keterangan', 'izin'];
        foreach ($requiredFields as $field) {
            if (!isset($_POST[$field]) || empty($_POST[$field])) {
                ob_clean();
                http_response_code(400);
                echo json_encode(["status" => 400, "error" => "Field $field diperlukan"]);
                ob_end_flush();
                exit;
            }
        }

        $username = $conn->real_escape_string($_POST['username']);
        $keterangan = $conn->real_escape_string($_POST['keterangan']);
        $jenis = $conn->real_escape_string($_POST['izin']);
        $fileName = null;

        if (isset($_FILES['picture']) && $_FILES['picture']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['picture'];
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
            
            if (!in_array($file['type'], $allowedTypes)) {
                ob_clean();
                http_response_code(400);
                echo json_encode(["status" => 400, "error" => "Hanya file JPG, PNG, GIF yang diperbolehkan"]);
                ob_end_flush();
                exit;
            }

            $ext = pathinfo($file["name"], PATHINFO_EXTENSION);
            $fileName = preg_replace("/[^a-zA-Z0-9_-]/", "", $username) . "_" . time() . "." . $ext;
            $uploadPath = $uploadDir . $fileName;

            if (!compressAndResizeImage($file['tmp_name'], $uploadPath, 500, 500)) {
                ob_clean();
                http_response_code(500);
                echo json_encode(["status" => 500, "error" => "Gagal memproses gambar"]);
                ob_end_flush();
                exit;
            }
        }

        $conn->begin_transaction();
        try {
            $dept = "Unknown";
            $sql = "SELECT department FROM user_profiles WHERE username = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $dept = $row['department'];
            }

            $sql = "SELECT COUNT(*) as count FROM approval_steps WHERE request_type = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $jenis);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $total_steps = $row && isset($row['count']) ? (int)$row['count'] : 0;

            if ($total_steps < 1) {
                throw new Exception("Approval steps not defined for '$jenis'");
            }

            $sql = "INSERT INTO hr_perizinan (username, keterangan, jenis, foto, department, total_steps, current_step, approval_status, status) 
                    VALUES (?, ?, ?, ?, ?, ?, 1, 'pending', 'pending')";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssssi", $username, $keterangan, $jenis, $fileName, $dept, $total_steps);

            if (!$stmt->execute()) {
                throw new Exception("Gagal menyimpan data perizinan: " . $stmt->error);
            }

            $requestId = $stmt->insert_id;

            $sql2 = "INSERT INTO approvals (request_id, step_order, role, status) 
                     SELECT ?, step_order, required_role, 'pending' FROM approval_steps 
                     WHERE request_type = ? ORDER BY step_order";
            $stmt2 = $conn->prepare($sql2);
            $stmt2->bind_param("is", $requestId, $jenis);
            $stmt2->execute();

            $conn->commit();

            ob_clean();
            echo json_encode([
                "status" => 200,
                "message" => "Berhasil diajukan",
                "data" => ["id" => $requestId, "jenis" => $jenis]
            ]);
            ob_end_flush();
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            if ($fileName && file_exists($uploadPath)) {
                unlink($uploadPath);
            }
            ob_clean();
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => $e->getMessage()]);
            ob_end_flush();
            exit;
        }

    default:
        ob_clean();
        http_response_code(405);
        echo json_encode(["status" => 405, "error" => "Invalid request method"]);
        ob_end_flush();
        exit;
}

$conn->close();
ob_end_flush();
?>