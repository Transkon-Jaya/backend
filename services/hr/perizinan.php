<?php
header("Content-Type: application/json");
require 'db.php';

// Include compressResize.php utility script
require 'utils/compressResize.php';

$method = $_SERVER['REQUEST_METHOD'];

$uploadDir = "/var/www/html/uploads/perizinan/";
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
        $sql = "SELECT * FROM hr_perizinan WHERE username = ?";
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
        if (isset($_FILES['picture'])) {
            $profilePicture = $_FILES['picture'];
            
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

            // Call the compressResize function from utils/compressResize.php
            $resizeResult = compressAndResizeImage($profilePicture['tmp_name'], $uploadPath, 500, 500);

            if (!$resizeResult) {
                http_response_code(500);
                echo json_encode(["status" => 500, "error" => "File resize and compress failed."]);
                break;
            }
            $isMoved = true;
        }
        if(!$isMoved){
            echo json_encode(["status" => 500, "error" => "Photo Move Error"]);
            break;
        }
        // Update other fields and handle file path if needed
        $keterangan = $data["keterangan"];
        $izin = $data["izin"];

        if ($isMoved) {
            $sql = "INSERT INTO hr_perizinan (username, keterangan, jenis, foto, status) VALUES (?, ?, ?, ?, 'pending')";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssss", $username, $keterangan, $izin, $fileName);
        }

        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => "Database error: " . $stmt->error]);
            break;
        }
        $stmt->close();

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
