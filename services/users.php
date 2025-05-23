<?php
header("Content-Type: application/json");
require 'db.php';

// Enable error logging for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$method = $_SERVER['REQUEST_METHOD'];

// Get request data based on method
$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(["status" => 400, "error" => "Invalid JSON data"]);
    exit;
}

switch ($method) {
    case 'GET':
        // ... (keep your existing GET code) ...
        break;

    case 'POST':
        // Validate required fields
        $required = ['username', 'name'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                http_response_code(400);
                echo json_encode(["status" => 400, "error" => "Missing required field: $field"]);
                exit;
            }
        }

        // Set default values for optional fields
        $defaults = [
            'department' => '',
            'placement' => '',
            'gender' => 'L',
            'lokasi' => ''
        ];
        $data = array_merge($defaults, $input);

        try {
            $stmt = $conn->prepare("INSERT INTO user_profiles 
                                  (username, name, department, placement, gender, lokasi) 
                                  VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssss", 
                $data['username'],
                $data['name'],
                $data['department'],
                $data['placement'],
                $data['gender'],
                $data['lokasi']
            );

            if ($stmt->execute()) {
                // Get the newly created user data
                $newUser = [
                    'username' => $data['username'],
                    'name' => $data['name'],
                    'department' => $data['department'],
                    'placement' => $data['placement'],
                    'gender' => $data['gender'],
                    'lokasi' => $data['lokasi']
                ];
                
                http_response_code(201);
                echo json_encode([
                    "status" => 201, 
                    "message" => "User created successfully",
                    "data" => $newUser
                ]);
            } else {
                throw new Exception($stmt->error);
            }
        } catch (Exception $e) {
            // Check for duplicate entry
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                http_response_code(409);
                echo json_encode(["status" => 409, "error" => "Username already exists"]);
            } else {
                http_response_code(500);
                echo json_encode(["status" => 500, "error" => $e->getMessage()]);
            }
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