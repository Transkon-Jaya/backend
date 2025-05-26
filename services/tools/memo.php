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
        if (!isset($_GET['username'])) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "No Username!"]);
            exit;
        }
        $username = $conn->real_escape_string($_GET['username']);

        // Updated SQL: Include username in WHERE clause, no bind needed if not used
        // Or fix to use prepared statement properly:
        $sql = "SELECT * FROM memo WHERE username = ? ORDER BY createdAt DESC LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => "Prepare failed: " . $conn->error]);
            exit;
        }
        $stmt->bind_param("s", $username);
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
        echo json_encode($profile);
        break;

    case 'POST':
        // Validate username
        if (!isset($_POST['username'])) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Username is required."]);
            exit;
        }
        $username = $_POST['username'];

        // Optional: Get no_document and description from POST
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

            // Clean username for filename safety
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

        // Insert into DB
        $sql = "INSERT INTO memo (username, no_document, photo, description) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => "Prepare failed: " . $conn->error]);
            exit;
        }
        // Bind params (all strings) - photo can be null if no file uploaded
        $stmt->bind_param(
            "ssss",
            $username,
            $no_document,
            $fileName,
            $description
        );

        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => "Database error: " . $stmt->error]);
            exit;
        }
        $stmt->close();

        echo json_encode([
            "status" => 200,
            "message" => "Memo inserted successfully",
            "foto" => $fileName ?? null
        ]);
        break;

    default:
        http_response_code(405);
        echo json_encode(["status" => 405, "error" => "Invalid request method"]);
        break;
}

$conn->close();
?>
