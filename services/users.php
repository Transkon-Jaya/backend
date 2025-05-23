<?php
header("Content-Type: application/json");
require 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET': // Fetch user profiles
        $username = isset($_GET['username']) ? $_GET['username'] : '';

        if (!empty($username)) {
            $stmt = $conn->prepare("SELECT username, name, department, placement, hub_placement, gender, lokasi, dob, status, jabatan, kepegawaian, klasifikasi, klasifikasi_jabatan,email, phone, gaji_pokok,site
                                    FROM user_profiles 
                                    WHERE username LIKE CONCAT(?, '%')
                                    ORDER BY username ASC");
            $stmt->bind_param("s", $username);
        } else {
            $stmt = $conn->prepare("SELECT username, name, department, placement, gender, lokasi,site 
                                    FROM user_profiles 
                                    ORDER BY username ASC");
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

        $stmt = $conn->prepare("INSERT INTO users
                               (username, passwd, user_level) 
                               VALUES (?, `$2y$10$b3ERgZ7Yw3q3EO/QiYDsnetnslJsQg0pg.eXw1LGQKYPiHQAz3EcC`, 9)");
        $stmt->bind_param("s", 
            $data['username']
        );
        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => $stmt->error]);
        }
        $stmt = $conn->prepare("INSERT INTO user_profiles 
                               (username, name, department, placement, gender, lokasi,site)              
                               VALUES (?, ?, ?, ?, ?, ?, AAA)");
        $stmt->bind_param("ssssss", 
            $data['username'],
            $data['name'],
            $data['department'],
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
                               name=?, department=?, placement=?, gender=?, lokasi=? 
                               WHERE username=?");
        $stmt->bind_param("ssssss", 
            $data['name'],
            $data['department'],
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