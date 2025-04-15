<?php
header("Content-Type: application/json");
require 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

$uploadDir = "/var/www/html/uploads/profile/";
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

switch ($method) {
    case 'GET':
        if (!isset($_GET['username'])) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "No Username!"]);
            break;
        }
        $username = $conn->real_escape_string($_GET['username']);
        $sql = "CALL user_profile_get(?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        if (!$result) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => $conn->error]);
            break;
        }

        $profile = [];
        while ($row = $result->fetch_assoc()) {
            $profile[] = $row;
        }
        echo json_encode($profile);
        break;

    case 'POST':
        // Get JSON data (non-file fields)
        $data = json_decode(file_get_contents("php://input"), true);
    
        if (!$data || !isset($data['username'])) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Invalid input."]);
            break;
        }
        $username = $data['username'];
        // File upload handling
        $isMoved = true;
        if (isset($_FILES['profilePicture'])) {
            $profilePicture = $_FILES['profilePicture'];
            
            // Check if the file is a valid image
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
            if (!in_array($profilePicture['type'], $allowedTypes)) {
                http_response_code(400);
                echo json_encode(["status" => 400, "error" => "Invalid file type."]);
                break;
            }
    
            // Move the uploaded file to the server's directory
            $ext = pathinfo($file["name"], PATHINFO_EXTENSION);
            $cleanUsername = preg_replace("/[^a-zA-Z0-9_-]/", "", $username);
            $fileName = $cleanUsername . "_" . time() . "." . $ext;
            $uploadPath = $uploadDir . $fileName;
            if (!move_uploaded_file($profilePicture['tmp_name'], $uploadPath)) {
                http_response_code(500);
                echo json_encode(["status" => 500, "error" => "File upload failed."]);
                break;
            }
            $isMoved = true;
        }
    
        // Update other fields and handle file path if needed
        $name = $data['name'];
        $email = $data['email'] ?? null;
        $phone = $data['phone'] ?? null;
        $department = $data['department'];
        $position = $data['position'];
        $password = isset($data['password']) ? password_hash($data['password'], PASSWORD_DEFAULT) : null;
        
        // If the file is uploaded, save the file path to the database
        $profilePicturePath = isset($uploadFile) ? $uploadFile : null;
    
        if ($isMoved) {
            $sql = "CALL user_profile_update(?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssssss", $username, $name, $department, $position, $fileName, $email, $phone);
        } else {
            $sql = "CALL user_profile_update_no_photo(?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssss", $username, $name, $department, $position, $email, $phone);
        }
        $stmt->execute();
        $stmt->close();
        
        // 2. Conditionally update password if provided
        if (!empty($data['password'])) {
            $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
            
            $sql = "CALL user_passwd_update(?, ?)";
            $stmt = $conn->prepare($sql);
            
            if (!$stmt) {
                http_response_code(500);
                echo json_encode(["status" => 500, "error" => $conn->error]);
                break;
            }
            
            $stmt->bind_param("ss", $username, $hashedPassword);
            $stmt->execute();
            $stmt->close();
        }
        
        // 3. Final response
        echo json_encode(["status" => 200, "message" => "Profile updated successfully."]);
        break;
        

    case 'DELETE': // Delete customer
        $data = json_decode(file_get_contents("php://input"), true);
        if (!isset($data['name'])) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Missing required fields"]);
            break;
        }

        $name = $conn->real_escape_string($data['name']);
        $sql = "CALL customer_delete('$name')";

        if ($conn->query($sql)) {
            echo json_encode(["status" => 200, "message" => "Customer deleted successfully"]);
        } else {
            http_response_code(409);
            echo json_encode(["status" => 409, "error" => $conn->error]);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(["status" => 405, "error" => "Invalid request method"]);
        break;
}

$conn->close();
?>

