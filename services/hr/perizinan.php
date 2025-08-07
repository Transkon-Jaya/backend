<?php
ob_start();
header("Content-Type: application/json");
error_reporting(E_ALL);
ini_set('display_errors', 0);

include 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

// === POST: Ajukan perizinan baru ===
if ($method === 'POST') {
    $username   = $_POST['username'] ?? null;
    $keterangan = $_POST['keterangan'] ?? null;
    $jenis      = $_POST['izin'] ?? null;
    $foto       = $_FILES['picture'] ?? null;

    if (!$username || !$keterangan || !$jenis) {
        http_response_code(400);
        echo json_encode(["status" => 400, "error" => "Semua data wajib diisi"]);
        exit;
    }

    // Simpan foto jika ada
    $fotoName = null;
    if ($foto && $foto['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($foto['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];

        if (!in_array($ext, $allowed)) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Format gambar tidak diizinkan"]);
            exit;
        }

        if ($foto['size'] > 5 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Ukuran gambar maksimal 5MB"]);
            exit;
        }

        $uploadDir = __DIR__ . '/uploads/perizinan/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

        $fotoName = uniqid("izin_") . "." . $ext;
        move_uploaded_file($foto['tmp_name'], $uploadDir . $fotoName);
    }

    // Cek department user
    $department = null;
    $sqlDept = $conn->prepare("SELECT department FROM user_profiles WHERE username = ?");
    $sqlDept->bind_param("s", $username);
    $sqlDept->execute();
    $resDept = $sqlDept->get_result();
    if ($rowDept = $resDept->fetch_assoc()) {
        $department = $rowDept['department'];
    }

    // Simpan ke tabel hr_perizinan
    $stmt = $conn->prepare("
        INSERT INTO hr_perizinan (username, jenis, keterangan, foto, department, createdAt, status, current_step)
        VALUES (?, ?, ?, ?, ?, NOW(), 'pending', 1)
    ");
    $stmt->bind_param("sssss", $username, $jenis, $keterangan, $fotoName, $department);
    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(["status" => 500, "error" => "Gagal menyimpan data perizinan"]);
        exit;
    }

    $requestId = $conn->insert_id;

    // Tambahkan approval pertama ke tabel approvals
    $role = 'atasan'; // default, atau bisa disesuaikan logikanya
    $stmt2 = $conn->prepare("
        INSERT INTO approvals (request_id, step_order, role, status)
        VALUES (?, 1, ?, 'pending')
    ");
    $stmt2->bind_param("is", $requestId, $role);
    $stmt2->execute();

    echo json_encode(["status" => 200, "message" => "Perizinan berhasil diajukan"]);
    exit;
}

// === GET: Ambil data perizinan ===
elseif ($method === 'GET') {
    $role = isset($_GET['role']) ? $conn->real_escape_string($_GET['role']) : null;
    $username = isset($_GET['username']) ? $conn->real_escape_string($_GET['username']) : null;

    if (!$role && !$username) {
        http_response_code(400);
        echo json_encode(["status" => 400, "error" => "Role atau Username harus disertakan"]);
        exit;
    }

    $data = [];

    if ($role) {
        // Ambil perizinan untuk approval berdasarkan role
        $sql = "
            SELECT 
                p.id, p.username, u.name, p.jenis, p.keterangan, p.department, p.foto, 
                p.createdAt, p.status, p.feedback, p.current_step, 
                a.step_order, a.role, a.status AS approval_status
            FROM hr_perizinan p
            JOIN approvals a ON a.request_id = p.id AND a.step_order = p.current_step
            JOIN user_profiles u ON u.username = p.username
            WHERE p.status = 'pending' 
              AND a.status = 'pending' 
              AND a.role = ?
            ORDER BY p.createdAt DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $role);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
    } elseif ($username) {
        // Ambil semua perizinan milik user
        $sql = "
            SELECT 
                p.id, p.username, u.name, p.jenis, p.keterangan, p.department, p.foto, 
                p.createdAt, p.status, p.feedback
            FROM hr_perizinan p
            JOIN user_profiles u ON u.username = p.username
            WHERE p.username = ?
            ORDER BY p.createdAt DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
    }

    echo json_encode([
        "status" => 200,
        "count" => count($data),
        "data" => $data
    ]);
    exit;
}

// === Method tidak didukung ===
else {
    http_response_code(405);
    echo json_encode(["status" => 405, "error" => "Method not allowed"]);
    exit;
}

ob_end_flush();
$conn->close();
