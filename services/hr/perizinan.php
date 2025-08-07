<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'db.php';
include 'utils/compressResize.php';

// Logging awal untuk debugging
file_put_contents('/tmp/perizinan_debug.log', "\n" . date('Y-m-d H:i:s') . " - New request: " . $_SERVER['REQUEST_METHOD'] . "\n", FILE_APPEND);

$method = $_SERVER['REQUEST_METHOD'];
$uploadDir = "/var/www/html/uploads/perizinan/";

// Pastikan direktori upload ada
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0777, true)) {
        $error = "Failed to create upload directory";
        file_put_contents('/tmp/perizinan_debug.log', date('Y-m-d H:i:s') . " - " . $error . "\n", FILE_APPEND);
        http_response_code(500);
        echo json_encode(["status" => 500, "error" => $error]);
        exit;
    }
}

function logError($message) {
    file_put_contents('/tmp/perizinan_debug.log', date('Y-m-d H:i:s') . " - ERROR: " . $message . "\n", FILE_APPEND);
}

function validateFileUpload($file) {
    $allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/jpg' => 'jpg'];
    
    // Cek error code
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ["status" => false, "error" => "File upload error code: " . $file['error']];
    }
    
    // Cek tipe file
    if (!array_key_exists($file['type'], $allowedTypes)) {
        return ["status" => false, "error" => "Invalid file type. Only JPG, PNG, GIF allowed"];
    }
    
    // Cek ukuran file (max 5MB)
    if ($file['size'] > 5 * 1024 * 1024) {
        return ["status" => false, "error" => "File size too large. Max 5MB allowed"];
    }
    
    return ["status" => true, "ext" => $allowedTypes[$file['type']]];
}

switch ($method) {
    // ========================
    // GET: Riwayat Perizinan
    // ========================
    case 'GET':
        if (!isset($_GET['username'])) {
            logError("GET request without username");
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Parameter username required"]);
            break;
        }

        $username = $conn->real_escape_string($_GET['username']);
        
        try {
            $sql = "SELECT p.*, 
                   (SELECT GROUP_CONCAT(CONCAT(a.step_order, ':', a.status) SEPARATOR ',') 
                    FROM approvals a WHERE a.request_id = p.id) as approval_steps
                   FROM hr_perizinan p 
                   WHERE p.username = ? 
                   ORDER BY p.createdAt DESC";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $data = [];
            while ($row = $result->fetch_assoc()) {
                // Parse approval steps
                $approvalSteps = [];
                if (!empty($row['approval_steps'])) {
                    $steps = explode(',', $row['approval_steps']);
                    foreach ($steps as $step) {
                        list($order, $status) = explode(':', $step);
                        $approvalSteps[$order] = $status;
                    }
                }
                $row['approval_steps'] = $approvalSteps;
                $data[] = $row;
            }
            
            echo json_encode(["status" => 200, "data" => $data]);
            
        } catch (Exception $e) {
            logError("GET query failed: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => "Database error"]);
        }
        break;

    // ========================
    // POST: Submit Perizinan
    // ========================
    case 'POST':
        // Cek jika ini request approval
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
            logError("Missing required fields");
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Username, keterangan, and jenis izin are required"]);
            break;
        }

        $username = $conn->real_escape_string($_POST['username']);
        $keterangan = $conn->real_escape_string($_POST['keterangan']);
        $jenis = $conn->real_escape_string($_POST['izin']);
        $fileName = null;

        // Proses upload file jika ada
        if (isset($_FILES['picture']) && $_FILES['picture']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['picture'];
            $validation = validateFileUpload($file);
            
            if (!$validation['status']) {
                logError("File validation failed: " . $validation['error']);
                http_response_code(400);
                echo json_encode(["status" => 400, "error" => $validation['error']]);
                break;
            }

            $ext = $validation['ext'];
            $cleanUsername = preg_replace("/[^a-zA-Z0-9_-]/", "", $username);
            $fileName = $cleanUsername . "_" . time() . "." . $ext;
            $uploadPath = $uploadDir . $fileName;

            try {
                if (!compressAndResizeImage($file['tmp_name'], $uploadPath, 500, 500)) {
                    logError("Image compression failed");
                    http_response_code(500);
                    echo json_encode(["status" => 500, "error" => "Failed to process image"]);
                    break;
                }
            } catch (Exception $e) {
                logError("Image processing exception: " . $e->getMessage());
                http_response_code(500);
                echo json_encode(["status" => 500, "error" => "Image processing error"]);
                break;
            }
        } elseif (in_array($jenis, ['izin', 'cuti', 'dinas'])) {
            // Jenis izin tertentu wajib ada fotonya
            logError("Photo required for this request type");
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Photo evidence is required for this request type"]);
            break;
        }

        // Mulai transaksi database
        $conn->begin_transaction();

        try {
            // 1. Ambil department user
            $dept = "Unknown";
            $sql = "SELECT department FROM user_profiles WHERE username = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $dept = $row['department'];
            }

            // 2. Ambil total langkah approval
            $sql = "SELECT COUNT(*) as count FROM approval_steps WHERE request_type = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $jenis);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $total_steps = $row ? (int)$row['count'] : 0;

            if ($total_steps < 1) {
                throw new Exception("No approval steps defined for request type: " . $jenis);
            }

            // 3. Insert data perizinan utama
            $sql = "INSERT INTO hr_perizinan (
                    username, keterangan, jenis, foto, department, 
                    total_steps, current_step, approval_status, status
                ) VALUES (?, ?, ?, ?, ?, ?, 1, 'pending', 'pending')";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssssi", $username, $keterangan, $jenis, $fileName, $dept, $total_steps);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to insert main record: " . $stmt->error);
            }

            $requestId = $stmt->insert_id;

            // 4. Buat record approval untuk setiap step
            $sql = "INSERT INTO approvals (request_id, step_order, role, status)
                    SELECT ?, step_order, required_role, 'pending' 
                    FROM approval_steps 
                    WHERE request_type = ?
                    ORDER BY step_order";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("is", $requestId, $jenis);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to create approval steps: " . $stmt->error);
            }

            // Commit transaksi jika semua sukses
            $conn->commit();

            // Response sukses
            echo json_encode([
                "status" => 200, 
                "message" => "Request submitted successfully",
                "data" => [
                    "request_id" => $requestId,
                    "total_steps" => $total_steps
                ]
            ]);

        } catch (Exception $e) {
            // Rollback jika ada error
            $conn->rollback();
            logError("Transaction failed: " . $e->getMessage());
            
            // Hapus file yang sudah diupload jika ada
            if ($fileName && file_exists($uploadDir . $fileName)) {
                unlink($uploadDir . $fileName);
            }
            
            http_response_code(500);
            echo json_encode([
                "status" => 500, 
                "error" => "System error processing your request",
                "debug" => $e->getMessage() // Hanya untuk development, hapus di production
            ]);
        }
        break;

    // ========================
    // METHOD TIDAK DIKENALI
    // ========================
    default:
        http_response_code(405);
        echo json_encode(["status" => 405, "error" => "Method not allowed"]);
        break;
}

$conn->close();
?>