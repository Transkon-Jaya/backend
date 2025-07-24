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

        $selectFields = "username, name, department, placement, hub_placement, gender, lokasi, dob, status, jabatan, kepegawaian, klasifikasi, klasifikasi_jabatan, email, phone, gaji_pokok, divisi, section, salary_code, site,id_company";

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
            echo json_encode(["status" => 500, "error" => $stmt->error]);
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
        $data = json_decode(file_get_contents('php://input'), true);

        // Set default id_company jika tidak disediakan
        $data['id_company'] = $data['id_company'] ?? 1;

        if (empty($data['username']) || empty($data['name'])) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Username and name are required"]);
            break;
        }

        $password = password_hash("password", PASSWORD_BCRYPT);
        $user_level = 9;

        $stmt = $conn->prepare("INSERT INTO users (username, passwd, user_level) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $data['username'], $password, $user_level);
        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => $stmt->error]);
            break;
        }

        $stmt = $conn->prepare("INSERT INTO user_profiles (
            username, name, dob, placement, gender, lokasi, hub_placement, status,
            jabatan, department, klasifikasi_jabatan, klasifikasi, kepegawaian,
            email, phone, gaji_pokok, divisi, section, salary_code, site, id_company
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->bind_param("ssssssssssssssssssssi",
            $data['username'], $data['name'], $data['dob'], $data['placement'], $data['gender'], $data['lokasi'],
            $data['hub_placement'], $data['status'], $data['jabatan'], $data['department'],
            $data['klasifikasi_jabatan'], $data['klasifikasi'], $data['kepegawaian'],
            $data['email'], $data['phone'], $data['gaji_pokok'], $data['divisi'], $data['section'], $data['salary_code'], $data['site'], $data['id_company']
        );

        if ($stmt->execute()) {
            http_response_code(201);
            echo json_encode(["status" => 201, "message" => "User created successfully"]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => $stmt->error]);
        }
        break;

    case 'PUT':
    $data = json_decode(file_get_contents('php://input'), true);
    $username = $data['username'];

    // Default value untuk mencegah error null
    $data['hub_placement'] = $data['hub_placement'] ?? '';
    $data['gaji_pokok'] = $data['gaji_pokok'] ?? 0;
    $data['id_company'] = $data['id_company'] ?? 0;

    $stmt = $conn->prepare("UPDATE user_profiles SET 
        name=?, dob=?, placement=?, gender=?, lokasi=?, hub_placement=?, status=?,
        jabatan=?, department=?, klasifikasi_jabatan=?, klasifikasi=?, kepegawaian=?,
        email=?, phone=?, gaji_pokok=?, divisi=?, section=?, salary_code=?, site=?, id_company=?
        WHERE username=?");

    $stmt->bind_param("ssssssssssssssdssssis",
        $data['name'], $data['dob'], $data['placement'], $data['gender'], $data['lokasi'], $data['hub_placement'],
        $data['status'], $data['jabatan'], $data['department'], $data['klasifikasi_jabatan'],
        $data['klasifikasi'], $data['kepegawaian'], $data['email'], $data['phone'],
        $data['gaji_pokok'], // This is the double (d) field
        $data['divisi'], $data['section'], $data['salary_code'], $data['site'], $data['id_company'], $username
    );

    if ($stmt->execute()) {
        echo json_encode(["status" => 200, "message" => "Updated successfully"]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => 500, "error" => $stmt->error]);
    }
    break;

    case 'DELETE':
        $username = $_GET['username'] ?? '';
        if (empty($username)) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Username is required"]);
            break;
        }

        $stmt = $conn->prepare("DELETE FROM user_profiles WHERE username = ?");
        $stmt->bind_param("s", $username);

        if ($stmt->execute()) {
            echo json_encode(["status" => 200, "message" => "User deleted successfully"]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => $stmt->error]);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(["status" => 405, "error" => "Method not allowed"]);
        break;
}

$conn->close();
?>
