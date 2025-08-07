<?php
header("Content-Type: application/json");
include 'db.php';
include 'utils/compressResize.php'; // Pastikan file ini ada dan berfungsi

$method = $_SERVER['REQUEST_METHOD'];
$uploadDir = "/var/www/html/uploads/perizinan/";

// Buat direktori jika belum ada
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0777, true)) {
        http_response_code(500);
        echo json_encode(["status" => 500, "error" => "Gagal membuat direktori upload"]);
        exit;
    }
}

// Validasi izin tulis
if (!is_writable($uploadDir)) {
    http_response_code(500);
    echo json_encode(["status" => 500, "error" => "Direktori upload tidak dapat ditulis"]);
    exit;
}

switch ($method) {
    // ========================
    // GET: Riwayat Perizinan
    // ========================
    case 'GET':
        if (!isset($_GET['username'])) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Username tidak diberikan"]);
            break;
        }

        $username = trim($_GET['username']);
        if (empty($username)) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Username tidak valid"]);
            break;
        }

        $sql = "SELECT * FROM hr_perizinan WHERE username = ? ORDER BY createdAt DESC";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => "DB Prepare error: " . $conn->error]);
            break;
        }

        $stmt->bind_param("s", $username);
        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => "Eksekusi query gagal: " . $stmt->error]);
            break;
        }

        $result = $stmt->get_result();
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }

        echo json_encode($data);
        break;

    // ========================
    // POST: Submit Perizinan Baru
    // ========================
    case 'POST':
        // Cek apakah ini approval action
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'approve':
                case 'reject':
                    include 'approval_action.php';
                    break;
                default:
                    http_response_code(400);
                    echo json_encode(["status" => 400, "error" => "Aksi tidak valid"]);
            }
            break;
        }

        // Validasi input dasar
        if (!isset($_POST['username']) || !isset($_POST['keterangan']) || !isset($_POST['izin'])) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Data tidak lengkap: username, keterangan, atau izin kosong"]);
            break;
        }

        $username = trim($_POST['username']);
        $keterangan = trim($_POST['keterangan']);
        $jenis = trim($_POST['izin']); // frontend kirim 'izin' bukan 'jenis'

        $allowedJenis = ['datang telat', 'pulang cepat', 'izin', 'cuti', 'dinas'];
        if (!in_array($jenis, $allowedJenis)) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Jenis perizinan tidak valid"]);
            break;
        }

        if (empty($username) || empty($keterangan)) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Username atau keterangan kosong"]);
            break;
        }

        $fileName = "";
        $isMoved = false;

        // Handle upload file
        if (isset($_FILES['picture']) && $_FILES['picture']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['picture'];
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
            $fileType = mime_content_type($file['tmp_name']);

            if (!in_array($fileType, $allowedTypes)) {
                http_response_code(400);
                echo json_encode(["status" => 400, "error" => "Tipe file tidak didukung. Harus JPG, PNG, atau GIF"]);
                break;
            }

            $ext = pathinfo($file["name"], PATHINFO_EXTENSION);
            $cleanUsername = preg_replace("/[^a-zA-Z0-9_-]/", "", $username);
            $fileName = $cleanUsername . "_" . time() . "." . strtolower($ext);
            $uploadPath = $uploadDir . $fileName;

            // Resize & compress gambar
            if (function_exists('compressAndResizeImage')) {
                if (compressAndResizeImage($file['tmp_name'], $uploadPath, 500, 500)) {
                    $isMoved = true;
                } else {
                    error_log("Gagal resize gambar: $uploadPath");
                    http_response_code(500);
                    echo json_encode(["status" => 500, "error" => "Gagal memproses gambar"]);
                    break;
                }
            } else {
                // Fallback: pindahkan tanpa resize (tidak disarankan)
                if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                    $isMoved = true;
                } else {
                    http_response_code(500);
                    echo json_encode(["status" => 500, "error" => "Gagal upload file"]);
                    break;
                }
            }
        } else {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "File gambar tidak ditemukan atau gagal upload"]);
            break;
        }

        if (!$isMoved) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => "Upload gambar gagal"]);
            break;
        }

        // Ambil department dari user_profiles
        $dept = "Unknown";
        $sql = "SELECT department FROM user_profiles WHERE username = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $dept = $row['department'];
            }
        }

        // Hitung total steps
        $total_steps = 1;
        $sql = "SELECT COUNT(*) as count FROM approval_steps WHERE request_type = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("s", $jenis);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $total_steps = (int)$row['count'] > 0 ? (int)$row['count'] : 1;
        }

        // Insert ke hr_perizinan
        $sql = "INSERT INTO hr_perizinan 
                (username, keterangan, jenis, foto, department, total_steps, current_step, approval_status, status) 
                VALUES (?, ?, ?, ?, ?, ?, 1, 'pending', 'pending')";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => "Prepare query gagal: " . $conn->error]);
            break;
        }

        $stmt->bind_param("sssssi", $username, $keterangan, $jenis, $fileName, $dept, $total_steps);

        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => "Gagal simpan ke database: " . $stmt->error]);
            break;
        }

        $requestId = $stmt->insert_id;

        // Insert ke approvals (setiap step)
        for ($step = 1; $step <= $total_steps; $step++) {
            $sql2 = "INSERT INTO approvals (request_id, step_order, role, status) 
                     SELECT ?, ?, required_role, 'pending' 
                     FROM approval_steps 
                     WHERE request_type = ? AND step_order = ?";
            $stmt2 = $conn->prepare($sql2);
            if ($stmt2) {
                $stmt2->bind_param("iiss", $requestId, $step, $jenis, $step);
                $stmt2->execute();
                $stmt2->close();
            }
        }

        // Sukses
        http_response_code(200);
        echo json_encode([
            "status" => 200,
            "message" => "Pengajuan perizinan berhasil",
            "id" => $requestId,
            "data" => [
                "username" => $username,
                "jenis" => $jenis,
                "foto" => $fileName
            ]
        ]);
        break;

    default:
        http_response_code(405);
        echo json_encode(["status" => 405, "error" => "Metode tidak diizinkan"]);
        break;
}

$conn->close();
?>