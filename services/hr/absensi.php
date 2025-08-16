<?php
require 'db.php';
require 'utils/compressResize.php';

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

// File info
$file = $_FILES["foto"];
$ext = pathinfo($file["name"], PATHINFO_EXTENSION);
$status = $_POST["status"] ?? null;
$cleanUsername = preg_replace("/[^a-zA-Z0-9_-]/", "", $username);
$cleanStatus = preg_replace("/[^a-zA-Z0-9_-]/", "", $status);
$uniqueName = $cleanUsername . '_' . $cleanStatus . '_' . time() . '.' . $ext;
$uploadPath = $uploadDir . $uniqueName;

// Compress & resize
$tempPath = $file["tmp_name"];
if (!compressAndResizeImage($tempPath, $uploadPath)) {
    $response["message"] = "Image compression failed.";
    echo json_encode($response);
    exit();
}

// Form data
$id = $_POST["id"] ?? null;
$date = $_POST["date"] ?? date("Y-m-d");
$long = $_POST["long"] ?? null;
$lang = $_POST["lang"] ?? null;
$ip = $_POST["ip"] ?? null;
$dim = $_POST["dim"] ?? null;
$device = $_POST["device"] ?? null;
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

// Insert or update
if ($status === "IN") {
    $stmt = $conn->prepare("
        INSERT INTO hr_absensi (
            username, tanggal, foto_in, lokasi_in, longitude_in, latitude_in, ip_in, jarak_in, dim_in, device_in, hour_in
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP())
    ");
    $stmt->bind_param("ssssssssss", $username, $date, $uniqueName, $lokasi, $long, $lang, $ip, $jarak, $dim, $device);
} else {
    $stmt = $conn->prepare("
        UPDATE hr_absensi SET
            foto_out = ?, lokasi_out = ?, longitude_out = ?, latitude_out = ?, ip_out = ?, jarak_out = ?, dim_out = ?, device_out = ?, hour_out = CURRENT_TIMESTAMP()
        WHERE id = ? AND hour_out IS NULL
    ");
    $stmt->bind_param("sssssssss", $uniqueName, $lokasi, $long, $lang, $ip, $jarak, $dim, $device, $id);
}

if ($stmt->execute()) {
    $response["status"] = "success";
    $response["message"] = "Attendance recorded successfully.";
} else {
    $response["message"] = "Database error: " . $stmt->error;
}

$stmt->close();
$conn->close();
echo json_encode($response);
