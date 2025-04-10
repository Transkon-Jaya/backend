<?php
include 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header("Content-Type: application/json");

$uploadDir = "../uploads/absensi/";
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

// Upload file
$file = $_FILES["foto"];
$ext = pathinfo($file["name"], PATHINFO_EXTENSION);
$uniqueName = uniqid('absen_', true) . '.' . $ext;
$uploadPath = $uploadDir . $uniqueName;

// Get form data
$username = $_POST["username"] ?? null;
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
$calculation_overtime = $_POST["calculation_overtime"] ?? null;
$total = $_POST["total"] ?? null;

if (!$username) {
    $response["message"] = "Username is required.";
    echo json_encode($response);
    exit();
}

if (move_uploaded_file($file["tmp_name"], $uploadPath)) {
    $stmt = $conn->prepare("
        INSERT INTO hr_absensi (
            username, tanggal, foto, longitude, langitude, ip, jarak, lokasi, start, finish,
            break, hour_worked, ph, normal_hours, ovt, calculation_overtime_1x1_5, calculation_overtime_1x2, 
            calculation_overtime_1x3, calculation_overtime_1x4, total
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "sssssssssssssssss",
        $username, $date, $uploadPath, $long, $lang, $ip, $jarak, $lokasi,
        $start, $finish, $break, $hour_worked, $ph, $normal_hours,
        $ovt, $calculation_overtime, $total
    );

    if ($stmt->execute()) {
        $response["status"] = "success";
        $response["message"] = "Attendance recorded successfully.";
        $response["foto"] = $uploadPath;
    } else {
        $response["message"] = "Database error: " . $stmt->error;
    }

    $stmt->close();
} else {
    $response["message"] = "Failed to move uploaded file.";
}

$conn->close();
echo json_encode($response);
