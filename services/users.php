<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        authorize(8, ["admin_absensi"], [], null);
        $user = verifyToken();
        $id_company = $user['id_company'] ?? null;
        $username = $_GET['username'] ?? '';

        // --- Tambahkan photo ke selectFields ---
        $selectFields = "username, name, department, placement, hub_placement, gender, lokasi, dob, status, jabatan, kepegawaian, klasifikasi, klasifikasi_jabatan, email, phone, gaji_pokok, divisi, section, salary_code, site, id_company, photo"; // <-- Tambahkan photo

        if (!empty($username)) {
            if ($id_company === 0) {
                $stmt = $conn->prepare("SELECT $selectFields FROM user_profiles WHERE username LIKE CONCAT(?, '%') ORDER BY username ASC");
                $stmt->bind_param("s", $username);
            } else {
                $stmt = $conn->prepare("SELECT $selectFields FROM user_profiles WHERE username LIKE CONCAT(?, '%') AND (id_company = ? OR id_company IS NULL) AND placement != 'Admin' ORDER BY username ASC");
                $stmt->bind_param("si", $username, $id_company);
            }
        } else {
            if ($id_company === 0) {
                $stmt = $conn->prepare("SELECT $selectFields FROM user_profiles ORDER BY username ASC");
            } else {
                $stmt = $conn->prepare("SELECT $selectFields FROM user_profiles WHERE id_company = ? AND placement != 'Admin' ORDER BY username ASC");
                $stmt->bind_param("i", $id_company);
            }
        }

        if (!$stmt->execute()) {
            http_response_code(500);
            error_log("DB Error (GET user_profiles): " . $stmt->error);
            echo json_encode(["status" => 500, "error" => "Failed to fetch users."]);
            break;
        }

        $result = $stmt->get_result();
        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }

        echo json_encode($users);
        break;

        case 'POST':
        // --- Tambahkan Authorization di sini ---
        authorize(8, ["admin_absensi"], [], null); // Hanya level 8 atau admin_absensi
        $user = verifyToken(); // Dapatkan info user dari token
        $requester_id_company = $user['id_company'] ?? null; // Ambil id_company dari requester

        if ($requester_id_company === null) {
             http_response_code(403);
             echo json_encode(["status" => 403, "error" => "Access denied. User company information missing."]);
             break;
        }
        // --- Akhir Penambahan Authorization ---

        $data = json_decode(file_get_contents('php://input'), true);

        // Validasi awal
        if (empty($data['username']) || empty($data['name'])) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Username and name are required"]);
            break;
        }

        // --- Logika Penentuan id_company untuk User Baru ---
        // Asumsi: Admin hanya bisa membuat user untuk company mereka sendiri.
        $user_id_company = $requester_id_company;
        // --- Akhir Logika id_company ---

        // Hash password default
        $password = password_hash("password", PASSWORD_BCRYPT);
        $user_level = 9;

        // --- Cek Duplikasi Username ---
        $checkStmt = $conn->prepare("SELECT username FROM users WHERE username = ?");
        $checkStmt->bind_param("s", $data['username']);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
             http_response_code(400);
             echo json_encode(["status" => 400, "error" => "Username already exists"]);
             $checkStmt->close();
             break;
        }
        $checkStmt->close();
        // --- Akhir Cek Duplikasi ---

        // Mulai transaksi untuk memastikan konsistensi data
        $conn->begin_transaction();

        try {
            // Insert ke tabel `users`
            $stmt1 = $conn->prepare("INSERT INTO users (username, passwd, user_level) VALUES (?, ?, ?)");
            if (!$stmt1) {
                 throw new Exception("Prepare failed for users table: " . $conn->error);
            }
            $stmt1->bind_param("sss", $data['username'], $password, $user_level);
            if (!$stmt1->execute()) {
                 throw new Exception("Execute failed for users table: " . $stmt1->error);
            }
            $stmt1->close();

            // --- Modifikasi Query INSERT untuk menyertakan photo dengan nilai 'default.jpeg' ---
            $stmt2 = $conn->prepare("INSERT INTO user_profiles (
                username, name, dob, placement, gender, lokasi, hub_placement, status,
                jabatan, department, klasifikasi_jabatan, klasifikasi, kepegawaian,
                email, phone, gaji_pokok, divisi, section, salary_code, site, id_company, photo -- Tambahkan photo
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"); // Tambahkan placeholder ?

            if (!$stmt2) {
                 throw new Exception("Prepare failed for user_profiles table: " . $conn->error);
            }

           $stmt2->bind_param(
                "sssssssssssssssdsssiss", // <-- Perbaiki menjadi 22 karakter yang benar
                $data['username'], $data['name'], $data['dob'], $data['placement'],
                $data['gender'], $data['lokasi'], $data['hub_placement'], $data['status'],
                $data['jabatan'], $data['department'], $data['klasifikasi_jabatan'],
                $data['klasifikasi'], $data['kepegawaian'], $data['email'], $data['phone'], // 15 's'
                $data['gaji_pokok'], // 1 'd'
                $data['divisi'], $data['section'], $data['salary_code'], // 3 's'
                $data['site'], // 1 's' 
                $user_id_company, // 1 'i'
                'default.jpeg' // 1 's'
            );

            if (!$stmt2->execute()) {
                 throw new Exception("Execute failed for user_profiles table: " . $stmt2->error);
            }
            $stmt2->close();

            // Jika semua berhasil, commit transaksi
            $conn->commit();

            http_response_code(201);
            echo json_encode([
                "status" => 201,
                "message" => "User created successfully",
                "created_user_company_id" => $user_id_company
            ]);

        } catch (Exception $e) {
            // Jika ada error, rollback transaksi
            $conn->rollback();

            // Log error untuk debugging (jangan tampilkan ke user di production)
            error_log("DB Transaction Error (user creation): " . $e->getMessage());

            http_response_code(500);
            // Kembalikan pesan umum ke frontend
            echo json_encode(["status" => 500, "error" => "Failed to create user. Please check server logs."]);
        }

        break; // Akhir case 'POST'

    case 'PUT':
        // Anda mungkin juga ingin memperbarui PUT jika nanti mendukung upload foto
        // Untuk sekarang, kita biarkan seperti apa adanya, hanya memastikan photo bisa diupdate jika dikirim
        $data = json_decode(file_get_contents('php://input'), true);
        $username = $data['username'] ?? '';

        if (empty($username)) {
             http_response_code(400);
             echo json_encode(["status" => 400, "error" => "Username is required for update"]);
             break;
        }

        $data['hub_placement'] = $data['hub_placement'] ?? '';
        $data['gaji_pokok'] = $data['gaji_pokok'] ?? 0.0;
        // Jika photo tidak dikirim, jangan ubah. Jika dikirim, gunakan nilai yang dikirim.
        // Jika ingin memungkinkan reset ke default, frontend bisa mengirim 'default.jpeg'
        $data['id_company'] = $data['id_company'] ?? 0;

        // Modifikasi query untuk memungkinkan update photo jika ada di data
        $stmt = $conn->prepare("UPDATE user_profiles SET 
            name=?, dob=?, placement=?, gender=?, lokasi=?, hub_placement=?, status=?,
            jabatan=?, department=?, klasifikasi_jabatan=?, klasifikasi=?, kepegawaian=?,
            email=?, phone=?, gaji_pokok=?, divisi=?, section=?, salary_code=?, site=?, id_company=?
            WHERE username=?"); // photo tidak diupdate kecuali secara eksplisit diatur

        if (!$stmt) {
             http_response_code(500);
             error_log("DB Prepare Error (PUT user_profiles): " . $conn->error);
             echo json_encode(["status" => 500, "error" => "Failed to prepare update query."]);
             break;
        }

        $stmt->bind_param("ssssssssssssssdssssis",
            $data['name'], $data['dob'], $data['placement'], $data['gender'], $data['lokasi'], $data['hub_placement'],
            $data['status'], $data['jabatan'], $data['department'], $data['klasifikasi_jabatan'],
            $data['klasifikasi'], $data['kepegawaian'], $data['email'], $data['phone'],
            $data['gaji_pokok'],
            $data['divisi'], $data['section'], $data['salary_code'], $data['site'], $data['id_company'], $username
        );

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                 echo json_encode(["status" => 200, "message" => "Updated successfully"]);
            } else {
                 echo json_encode(["status" => 200, "message" => "No changes made or user not found."]);
            }
        } else {
            http_response_code(500);
            error_log("DB Execute Error (PUT user_profiles): " . $stmt->error);
            echo json_encode(["status" => 500, "error" => "Failed to update user."]);
        }
        $stmt->close();
        break;

    case 'DELETE':
        $username = $_GET['username'] ?? '';
        if (empty($username)) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Username is required"]);
            break;
        }

        $conn->begin_transaction();
        try {
            $stmt1 = $conn->prepare("DELETE FROM user_profiles WHERE username = ?");
            if (!$stmt1) {
                 throw new Exception("Prepare failed for user_profiles delete: " . $conn->error);
            }
            $stmt1->bind_param("s", $username);
            if (!$stmt1->execute()) {
                 throw new Exception("Execute failed for user_profiles delete: " . $stmt1->error);
            }
            $rows_affected_profile = $stmt1->affected_rows;
            $stmt1->close();

            $stmt2 = $conn->prepare("DELETE FROM users WHERE username = ?");
            if (!$stmt2) {
                 throw new Exception("Prepare failed for users delete: " . $conn->error);
            }
            $stmt2->bind_param("s", $username);
            if (!$stmt2->execute()) {
                 throw new Exception("Execute failed for users delete: " . $stmt2->error);
            }
            $rows_affected_user = $stmt2->affected_rows;
            $stmt2->close();

            $conn->commit();

            if ($rows_affected_profile > 0 || $rows_affected_user > 0) {
                 echo json_encode(["status" => 200, "message" => "User deleted successfully"]);
            } else {
                 echo json_encode(["status" => 404, "message" => "User not found"]);
            }

        } catch (Exception $e) {
            $conn->rollback();
            error_log("DB Transaction Error (user deletion): " . $e->getMessage());
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => "Failed to delete user. Please check server logs."]);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(["status" => 405, "error" => "Method not allowed"]);
        break;
}

$conn->close();
?>