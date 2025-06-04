<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET': // Fetch user profiles
        authorize(8, ["admin_absensi"], [], null);
        $user = verifyToken();
        $id_company = $user['id_company'] ?? null;
        $username = isset($_GET['username']) ? $_GET['username'] : '';

        if (!empty($username)) {
            if ($id_company === 0) {
                // Superadmin: search username without company filter
                $stmt = $conn->prepare("SELECT username, name, department, placement, hub_placement, gender, lokasi, dob, status, jabatan, kepegawaian, klasifikasi, klasifikasi_jabatan, email, phone, gaji_pokok, site
                                        FROM user_profiles 
                                        WHERE username LIKE CONCAT(?, '%')
                                        ORDER BY username ASC");
                $stmt->bind_param("s", $username);
            } else {
                // Regular user: search username + filter by company
                $stmt = $conn->prepare("SELECT username, name, department, placement, hub_placement, gender, lokasi, dob, status, jabatan, kepegawaian, klasifikasi, klasifikasi_jabatan, email, phone, gaji_pokok, site
                                        FROM user_profiles 
                                        WHERE username LIKE CONCAT(?, '%') 
                                            AND (id_company = ? OR id_company IS NULL)
                                            AND placement != 'Admin'
                                        ORDER BY username ASC");
                $stmt->bind_param("si", $username, $id_company);
            }
        } else {
            if ($id_company === 0) {
                // Superadmin: get all users
                $stmt = $conn->prepare("SELECT username, name, department, jabatan, placement, gender, lokasi, site 
                                        FROM user_profiles
                                        ORDER BY username ASC");
            } else {
                // Regular user: filter by company + global
                $stmt = $conn->prepare("SELECT username, name, department, jabatan, placement, gender, lokasi, site 
                                        FROM user_profiles
                                        WHERE id_company = ?
                                            AND placement != 'Admin'
                                        ORDER BY username ASC");
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

    case 'POST': // Insert new user
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validasi data
        if (empty($data['username']) || empty($data['name'])) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Username and name are required"]);
            break;
        }
        $password = password_hash("password", PASSWORD_BCRYPT);
        $user_level = 9;

        $stmt = $conn->prepare("INSERT INTO users
                               (username, passwd, user_level) 
                               VALUES (?, ?, ?)");
        $stmt->bind_param("sss", 
            $data['username'],
            $password,
            $user_level
        );
        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => $stmt->error]);
        }
        $stmt = $conn->prepare("INSERT INTO user_profiles 
                               (username, name, department,jabatan, placement, gender, lokasi, site)              
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssss", 
            $data['username'],
            $data['name'],
            $data['department'],
            $data['jabatan'],
            $data['placement'],
            $data['gender'],
            $data['lokasi'],
            $data['site']
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
        
        $stmt = $conn->prepare("UPDATE user_profiles SET 
                               name=?, department=?,jabatan=?, placement=?, gender=?, lokasi=? 
                               WHERE username=?");
        $stmt->bind_param("sssssss", 
            $data['name'],
            $data['department'],
            $data['jabatan'],
            $data['placement'],
            $data['gender'],
            $data['lokasi'],
            $username
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