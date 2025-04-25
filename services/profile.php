<?php
header("Content-Type: application/json");
require 'db.php';
require 'utils/compressResize.php';

$method = $_SERVER['REQUEST_METHOD'];

$uploadDir = "/var/www/html/uploads/profile/";
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$response = [
    "status" => "error",
    "message" => "Unknown error"
];

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
        $data = $_POST;
        if (!$data || !isset($data['username'])) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Invalid input."]);
            break;
        }
        $username = $data['username'];
        $isMoved = false;
        $fileName = "";

        // File upload handling
        if (isset($_FILES['profilePicture'])) {
            $profilePicture = $_FILES['profilePicture'];
            
            // Check if the file is a valid image
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
            if (!in_array($profilePicture['type'], $allowedTypes)) {
                http_response_code(400);
                echo json_encode(["status" => 400, "error" => "Invalid file type."]);
                break;
            }

            // File compression and resizing
            $ext = pathinfo($profilePicture["name"], PATHINFO_EXTENSION);
            $cleanUsername = preg_replace("/[^a-zA-Z0-9_-]/", "", $username);
            $fileName = $cleanUsername . "_" . time() . "." . $ext;

            $uploadPath = $uploadDir . $fileName;

            // Compress and resize the image
            $compressResizePath = '/var/www/html/yourpath/compressResize.php'; // Update the path as needed
            $cmd = "php $compressResizePath --source " . escapeshellarg($profilePicture['tmp_name']) . " --destination " . escapeshellarg($uploadPath) . " --max-width 500 --max-height 500";
            exec($cmd, $output, $return_var);

            if ($return_var !== 0) {
                http_response_code(500);
                echo json_encode(["status" => 500, "error" => "File resize and compress failed.", "output" => $output]);
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

        if ($isMoved) {
            $sql = "CALL user_profile_update(?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssssss", $username, $name, $department, $position, $fileName, $email, $phone);
        } else {
            $sql = "CALL user_profile_update_no_photo(?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssss", $username, $name, $department, $position, $email, $phone);
        }

        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => "Database error: " . $stmt->error]);
            break;
        }
        $stmt->close();

        // Conditionally update password if provided
        if (!empty($data['password'])) {
            $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);

            $sql = "CALL user_passwd_update(?, ?)";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                http_response_code(500);
                echo json_encode(["status" => 500, "error" => "Password update failed: " . $conn->error]);
                break;
            }

            $stmt->bind_param("ss", $username, $hashedPassword);
            $stmt->execute();
            $stmt->close();
        }

        // Final response
        echo json_encode(["status" => 200, "message" => "Success", "foto" => $fileName]);
        break;

    default:
        http_response_code(405);
        echo json_encode(["status" => 405, "error" => "Invalid request method"]);
        break;
}

$conn->close();
?>
