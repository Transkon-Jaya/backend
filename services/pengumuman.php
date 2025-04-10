<?php
include 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$target_dir = "../uploads/pengumuman/";
if (!file_exists($target_dir)) {
    mkdir($target_dir, 0777, true);
}

$response = array();

if (isset($_FILES["image"])) {
    $file_ext = pathinfo($_FILES["image"]["name"], PATHINFO_EXTENSION); // Get file extension
    $unique_filename = uniqid() . "." . $file_ext; // Generate unique filename
    $target_file = $target_dir . $unique_filename;

    $name = $_POST["name"] ?? "No Name";
    $description = $_POST["description"] ?? "";

    if (move_uploaded_file($_FILES["image"]["tmp_name"], $target_file)) {
        $stmt = $conn->prepare("INSERT INTO pengumuman (filename, filedesc, filepath) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $name, $description, $target_file);

        if ($stmt->execute()) {
            $response["status"] = "success";
            $response["message"] = "Image uploaded successfully";
            $response["path"] = $target_file;
        } else {
            $response["status"] = "error";
            $response["message"] = "Database error: " . $stmt->error;
        }

        $stmt->close();
    } else {
        $response["status"] = "error";
        $response["message"] = $_FILES["image"]["error"];
    }
} else {
    $response["status"] = "error";
    $response["message"] = "No image uploaded.";
}

$conn->close();
echo json_encode($response);
?>
