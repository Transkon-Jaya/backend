<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
        case 'GET':
        authorize(8, ["admin_absensi"], [], null);
        $user = verifyToken();
        $requester_user_level = $user['user_level'] ?? null; // Dapatkan level pengguna yang sedang login
        $id_company = $user['id_company'] ?? null;
        $username_filter = $_GET['username'] ?? '';

        // Validasi tambahan: Pastikan user_level pengguna login diketahui
        if ($requester_user_level === null) {
             http_response_code(403);
             echo json_encode(["status" => 403, "error" => "Access denied. User level information missing."]);
             break;
        }

        $selectFields = "up.username, up.name, up.department, up.placement, up.hub_placement, up.gender, up.lokasi, up.dob, up.status, up.jabatan, up.kepegawaian, up.klasifikasi, up.klasifikasi_jabatan, up.email, up.phone, up.gaji_pokok, up.divisi, up.section, up.salary_code, up.site, up.id_company, up.photo";

        // Tentukan kondisi filter user_level berdasarkan level pengguna yang login
        $userLevelCondition = "";
        if ($requester_user_level != 0) {
            // Jika bukan level 0, jangan tampilkan user level 8
            $userLevelCondition = "AND u.user_level != 8";
        }
        // Jika level 0, $userLevelCondition tetap kosong, sehingga tidak ada filter tambahan

        if (!empty($username_filter)) {
            if ($id_company === 0) {
                // Super Admin
                $stmt = $conn->prepare("SELECT $selectFields
                                        FROM user_profiles up
                                        JOIN users u ON up.username = u.username
                                        WHERE up.username LIKE CONCAT(?, '%')
                                        $userLevelCondition
                                        ORDER BY up.username ASC");
                $stmt->bind_param("s", $username_filter);
            } else {
                // Admin Perusahaan
                $stmt = $conn->prepare("SELECT $selectFields
                                        FROM user_profiles up
                                        JOIN users u ON up.username = u.username
                                        WHERE up.username LIKE CONCAT(?, '%')
                                        AND (up.id_company = ? OR up.id_company IS NULL)
                                        AND up.placement != 'Admin'
                                        $userLevelCondition
                                        ORDER BY up.username ASC");
                $stmt->bind_param("si", $username_filter, $id_company);
            }
        } else {
            if ($id_company === 0) {
                // Super Admin
                $stmt = $conn->prepare("SELECT $selectFields
                                        FROM user_profiles up
                                        JOIN users u ON up.username = u.username
                                        WHERE 1=1
                                        $userLevelCondition
                                        ORDER BY up.username ASC");
            } else {
                // Admin Perusahaan
                $stmt = $conn->prepare("SELECT $selectFields
                                        FROM user_profiles up
                                        JOIN users u ON up.username = u.username
                                        WHERE up.id_company = ?
                                        AND up.placement != 'Admin'
                                        $userLevelCondition
                                        ORDER BY up.username ASC");
                $stmt->bind_param("i", $id_company);
            }
        }

        if (!$stmt) {
             http_response_code(500);
             error_log("DB Prepare Error (GET user_profiles): " . $conn->error);
             echo json_encode(["status" => 500, "error" => "Failed to prepare fetch query."]);
             break;
        }

        if (!$stmt->execute()) {
            http_response_code(500);
            error_log("DB Execute Error (GET user_profiles): " . $stmt->error);
            echo json_encode(["status" => 500, "error" => "Failed to fetch users."]);
            $stmt->close();
            break;
        }

        $result = $stmt->get_result();
        $users = [];
        while ($row = $result->fetch_assoc()) {
            $cleanedRow = [];
            foreach($row as $key => $value) {
                $cleanKey = preg_replace('/^up\./', '', $key);
                $cleanedRow[$cleanKey] = $value;
            }
            $users[] = $cleanedRow;
        }
        $stmt->close();

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
        // Jika ingin Super Admin (id_company=0) bisa membuat untuk siapa saja,
        // logikanya bisa disesuaikan (lihat jawaban sebelumnya untuk contoh).
        // Untuk saat ini, kita paksa id_company user baru sama dengan requester.
        $user_id_company = $requester_id_company; // <-- Ini adalah perubahan utama

        // Jika Anda ingin memungkinkan Super Admin menentukan id_company:
        // if ($requester_id_company == 0) {
        //     $user_id_company = $data['id_company'] ?? 0; // Atau validasi lebih lanjut
        // } else {
        //     $user_id_company = $requester_id_company;
        // }
        // --- Akhir Logika id_company ---

        // Hash password default
        $password = password_hash("password", PASSWORD_BCRYPT); // Pertimbangkan untuk mengubah ini
        $user_level = 9; // Pertimbangkan untuk membuat ini dinamis atau default yang lebih aman

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

            // Insert ke tabel `user_profiles` (tambahkan id_company)
            // --- Perbaikan bind_param: Gunakan 'd' untuk gaji_pokok dan 'i' untuk id_company ---
            $stmt2 = $conn->prepare("INSERT INTO user_profiles (
                username, name, dob, placement, gender, lokasi, hub_placement, status,
                jabatan, department, klasifikasi_jabatan, klasifikasi, kepegawaian,
                email, phone, gaji_pokok, divisi, section, salary_code, site, id_company
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            if (!$stmt2) {
                 throw new Exception("Prepare failed for user_profiles table: " . $conn->error);
            }

            // Perbarui bind_param: 'd' untuk gaji_pokok (posisi 16), 'i' untuk id_company (posisi 21)
            // Urutan tipe: s(15) d(1) s(4) i(1)
            $stmt2->bind_param("sssssssssssssssdssssi",
                $data['username'], $data['name'], $data['dob'], $data['placement'], $data['gender'], $data['lokasi'],
                $data['hub_placement'], $data['status'], $data['jabatan'], $data['department'],
                $data['klasifikasi_jabatan'], $data['klasifikasi'], $data['kepegawaian'],
                $data['email'], $data['phone'], $data['gaji_pokok'], $data['divisi'], $data['section'], $data['salary_code'], $data['site'], $user_id_company // Gunakan $user_id_company
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
                "created_user_company_id" => $user_id_company // Opsional: informasikan id_company yang digunakan
            ]);

        } catch (Exception $e) {
            // Jika ada error, rollback transaksi
            $conn->rollback();

            // Log error untuk debugging (jangan tampilkan ke user di production)
            error_log("DB Transaction Error (user creation): " . $e->getMessage());

            http_response_code(500);
            // Kembalikan pesan umum ke frontend
            echo json_encode(["status" => 500, "error" => "Failed to create user. Please check server logs."]);
            // echo json_encode(["status" => 500, "error" => $e->getMessage()]); // Hanya untuk debugging
        }

        break; // Akhir case 'POST'

    case 'PUT':
        // --- Tambahkan Authorization jika diperlukan ---
        // authorize(8, ["admin_absensi"], [], null);
        // $user = verifyToken();
        // $requester_id_company = $user['id_company'] ?? null;
        // ... (logika validasi id_company jika perlu membatasi update lintas company)
        // --- Akhir Authorization ---

        $data = json_decode(file_get_contents('php://input'), true);
        $username = $data['username'] ?? '';

        if (empty($username)) {
             http_response_code(400);
             echo json_encode(["status" => 400, "error" => "Username is required for update"]);
             break;
        }

        // Default value untuk mencegah error null
        $data['hub_placement'] = $data['hub_placement'] ?? '';
        $data['gaji_pokok'] = $data['gaji_pokok'] ?? 0.0; // Pastikan default adalah float/double
        $data['id_company'] = $data['id_company'] ?? 0; // Default id_company jika tidak ada

        // --- Perbaikan Query dan bind_param ---
        // Tambahkan id_company=? ke SET clause
        // Pastikan jumlah placeholder (?) dan karakter di bind_param sesuai
        $stmt = $conn->prepare("UPDATE user_profiles SET 
            name=?, dob=?, placement=?, gender=?, lokasi=?, hub_placement=?, status=?,
            jabatan=?, department=?, klasifikasi_jabatan=?, klasifikasi=?, kepegawaian=?,
            email=?, phone=?, gaji_pokok=?, divisi=?, section=?, salary_code=?, site=?, id_company=?
            WHERE username=?");

        if (!$stmt) {
             http_response_code(500);
             error_log("DB Prepare Error (PUT user_profiles): " . $conn->error);
             echo json_encode(["status" => 500, "error" => "Failed to prepare update query."]);
             break;
        }

        // Perbaiki bind_param: 'd' untuk gaji_pokok (posisi 15), 'i' untuk id_company (posisi 20)
        // Total 21 parameter: 14x's' + 1x'd' + 5x's' + 1x'i' + 1x's' (username)
        $stmt->bind_param("ssssssssssssssdssssis",
            $data['name'], $data['dob'], $data['placement'], $data['gender'], $data['lokasi'], $data['hub_placement'],
            $data['status'], $data['jabatan'], $data['department'], $data['klasifikasi_jabatan'],
            $data['klasifikasi'], $data['kepegawaian'], $data['email'], $data['phone'],
            $data['gaji_pokok'], // Ini adalah double (d)
            $data['divisi'], $data['section'], $data['salary_code'], $data['site'], $data['id_company'], $username // id_company adalah integer (i)
        );

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                 echo json_encode(["status" => 200, "message" => "Updated successfully"]);
            } else {
                 // Tidak ada baris yang terpengaruh, mungkin username tidak ditemukan
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
    
    // Validasi username
    if (empty($username)) {
        http_response_code(400);
        echo json_encode(["status" => 400, "error" => "Username is required"]);
        break;
    }

    if (!is_string($username)) {
        http_response_code(400);
        echo json_encode(["status" => 400, "error" => "Username must be a string"]);
        break;
    }

    // Cek apakah user ada
    $stmt_check = $conn->prepare("SELECT 1 FROM users WHERE username = ?");
    $stmt_check->bind_param("s", $username);
    $stmt_check->execute();
    if ($stmt_check->get_result()->num_rows === 0) {
        http_response_code(404);
        echo json_encode(["status" => 404, "message" => "User not found"]);
        $stmt_check->close();
        break;
    }
    $stmt_check->close();

    // Mulai transaksi
    $conn->begin_transaction();
    try {
        // 🔴 Hapus data terkait di tabel lain
        $related_tables = ['absensi', 'lembur', 'cuti', 'shift', 'log_login', 'user_profiles_history']; // Sesuaikan
        foreach ($related_tables as $table) {
            $sql = "DELETE FROM `$table` WHERE username = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $stmt->close();
            }
            // Jika tabel tidak ada, prepare gagal → abaikan (opsional log error)
        }

        // Hapus dari user_profiles
        $stmt1 = $conn->prepare("DELETE FROM user_profiles WHERE username = ?");
        $stmt1->bind_param("s", $username);
        $stmt1->execute();
        $rows_affected_profile = $stmt1->affected_rows;
        $stmt1->close();

        // Hapus dari users
        $stmt2 = $conn->prepare("DELETE FROM users WHERE username = ?");
        $stmt2->bind_param("s", $username);
        $stmt2->execute();
        $rows_affected_user = $stmt2->affected_rows;
        $stmt2->close();

        $conn->commit();

        // Log untuk debugging
        error_log("DELETE SUCCESS: $username | Profile: $rows_affected_profile | User: $rows_affected_user");

        if ($rows_affected_profile > 0 || $rows_affected_user > 0) {
            echo json_encode(["status" => 200, "message" => "User deleted successfully"]);
        } else {
            echo json_encode(["status" => 404, "message" => "User not found"]);
        }

    } catch (Exception $e) {
        $conn->rollback();
        error_log("DB Transaction Error (user deletion): " . $e->getMessage() . " | Username: " . $username);
        http_response_code(500);
        echo json_encode([
            "status" => 500,
            "error" => "Failed to delete user. Please check server logs."
            // Jangan tampilkan $e->getMessage() ke user di production
        ]);
    }
    break;


    default:
        http_response_code(405);
        echo json_encode(["status" => 405, "error" => "Method not allowed"]);
        break;
}

$conn->close();
?>