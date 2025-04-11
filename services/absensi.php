<?php
include 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header("Content-Type: application/json");

$uploadDir = "/var/www/html/uploads/absensi/";
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$response = [
    "status" => "error",
    "message" => "Unknown error"
];

if (!isset($_FILES["foto"]) || $_FILES["foto"]["error"] !== UPLOAD_ERR_OK) {
    $response["message"] = "No photo uploaded or upload error.";
    echo json_encode($response);
    exit();
}

$username = $_POST["username"] ?? null;
if (!$username) {
    $response["message"] = "Username is required.";
    echo json_encode($response);
    exit();
}

// Upload file
$file = $_FILES["foto"];
$ext = pathinfo($file["name"], PATHINFO_EXTENSION);


$status = $_POST["status"] ?? null;
$cleanUsername = preg_replace("/[^a-zA-Z0-9_-]/", "", $username);
$cleanStatus = preg_replace("/[^a-zA-Z0-9_-]/", "", $status);

$uniqueName = $cleanUsername . '_' . $cleanStatus . '_' . time() . '.' . $ext;
$uploadPath = $uploadDir . $uniqueName;

// Get form data
$id = $_POST["id"] ?? null;
$date = $_POST["date"] ?? date("Y-m-d");
$long = $_POST["long"] ?? null;
$lang = $_POST["lang"] ?? null;
$ip = $_POST["ip"] ?? null;
$jarak = $_POST["jarak"] ?? null;
$lokasi = $_POST["lokasi"] ?? null;
$start = $_POST["start"] ?? null;
$finish = $_POST["finish"] ?? null;
$break = $_POST["break"] ?? null;
$hour_worked = $_POST["hour_worked"] ?? null;
$ph = $_POST["ph"] ?? null;
$normal_hours = $_POST["normal_hours"] ?? null;
$ovt = $_POST["ovt"] ?? null;
$calculation_overtime_1x1_5 = $_POST["calculation_overtime_1x1_5"] ?? null;
$calculation_overtime_1x2 = $_POST["calculation_overtime_1x2"] ?? null;
$calculation_overtime_1x3 = $_POST["calculation_overtime_1x3"] ?? null;
$calculation_overtime_1x4 = $_POST["calculation_overtime_1x4"] ?? null;
$total = $_POST["total"] ?? null;


if (move_uploaded_file($file["tmp_name"], $uploadPath)) {
    if ($status === "IN"){
        $stmt = $conn->prepare("
            INSERT INTO hr_absensi (
                username, tanggal, foto_in, lokasi_in, longitude_in, latitude_in, ip_in
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "sssssss",
            $username, $date, $uploadPath, $lokasi, $long, $lang, $ip
        );
    }
    else{
        $stmt = $conn->prepare("
            UPDATE hr_absensi SET
                foto_out = ?,
                lokasi_out = ?,
                longitude_out = ?,
                latitude_out = ?,
                ip_out = ?
            WHERE id = ?
        ");
        $stmt->bind_param(
            "ssssss",
            $uploadPath, $lokasi, $long, $lang, $ip, $id
        );
    }

    if ($stmt->execute()) {
        $response["status"] = "success";
        $response["message"] = "Attendance recorded successfully.";
        // $response["foto"] = $uploadPath;
    } else {
        $response["message"] = "Database error: " . $stmt->error;
    }

    $stmt->close();
} else {
    $response["message"] = "Failed to move uploaded file.";
}

$conn->close();
echo json_encode($response);
