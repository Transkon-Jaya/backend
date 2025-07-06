<?php
header("Content-Type: application/json");
require 'db.php';

$method = $_SERVER['REQUEST_METHOD'];
$uploadDir = "/var/www/html/uploads/memo/";

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

switch ($method) {
    case 'GET':
        $sql = "SELECT * FROM memo ORDER BY createdAt DESC LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => "Prepare failed: " . $conn->error]);
            exit;
        }

        $stmt->execute();
        $result = $stmt->get_result();
        if (!$result) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => $conn->error]);
            exit;
        }

        $profile = [];
        while ($row = $result->fetch_assoc()) {
            $profile[] = $row;
        }

        $result->free(); // ✅ cleanup
        $stmt->close();

        echo json_encode($profile);
        break;

    case 'POST':
        if (!isset($_POST['username'])) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Username is required."]);
            exit;
        }

        $username = $_POST['username'];
        $no_document = $_POST['no_document'] ?? "";
        $description = $_POST['description'] ?? "";
        $fileName = null;

        if (isset($_FILES['memo']) && $_FILES['memo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['memo'];
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];

            if (!in_array($file['type'], $allowedTypes)) {
                http_response_code(400);
                echo json_encode(["status" => 400, "error" => "Invalid file type."]);
                exit;
            }

            $cleanUsername = preg_replace("/[^a-zA-Z0-9_-]/", "", $username);
            $ext = pathinfo($file["name"], PATHINFO_EXTENSION);
            $fileName = $cleanUsername . "_" . time() . "." . $ext;
            $uploadPath = $uploadDir . $fileName;

            if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
                http_response_code(500);
                echo json_encode(["status" => 500, "error" => "Failed to move uploaded file."]);
                exit;
            }
        }

        $sql = "INSERT INTO memo (username, no_document, photo, description) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => "Prepare failed: " . $conn->error]);
            exit;
        }

        $stmt->bind_param("ssss", $username, $no_document, $fileName, $description);

        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => "Database error: " . $stmt->error]);
            $stmt->close();
            exit;
        }

        $stmt->close();

        echo json_encode([
            "status" => 200,
            "message" => "Memo inserted successfully",
            "foto" => $fileName
        ]);
        break;

    default:
        http_response_code(405);
        echo json_encode(["status" => 405, "error" => "Invalid request method"]);
        break;
}

$conn->close();
