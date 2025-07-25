<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

authorize(8, ["admin_absensi"], [], null);
$user = verifyToken();
$id_company = $user['id_company'] ?? null;

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(["status" => 405, "error" => "Invalid request method"]);
    exit;
}

$dept = $_GET['department'] ?? "";
$name = $_GET["name"] ?? "";
$start_date = $_GET["start_date"] ?? "";
$end_date = $_GET["end_date"] ?? "";

$where = [];
$params = [];
$types = "";

// Determine the type
if ($name !== "") {
    $type = "name";
} elseif ($dept !== "") {
    $type = "dept";
} else {
    $type = "all";
}

// Base filters
if ($dept !== "") {
    $where[] = "e.department = ?";
    $params[] = $dept;
    $types .= "s";
}
if ($name !== "") {
    $where[] = "e.name = ?";
    $params[] = $name;
    $types .= "s";
}

// Date logic
if ($start_date && !$end_date) {
    if ($type === "all") {
        $where[] = "a.tanggal = ?";
        $params[] = $start_date;
        $types .= "s";
    } elseif ($type === "dept") {
        $where[] = "a.tanggal BETWEEN ? AND DATE_ADD(?, INTERVAL 1 MONTH)";
        $params[] = $start_date;
        $params[] = $start_date;
        $types .= "ss";
    } elseif ($type === "name") {
        $where[] = "a.tanggal >= ?";
        $params[] = $start_date;
        $types .= "s";
    }
} elseif (!$start_date && $end_date) {
    if ($type === "all") {
        $where[] = "a.tanggal = ?";
        $params[] = $end_date;
        $types .= "s";
    } elseif ($type === "dept") {
        $where[] = "a.tanggal BETWEEN DATE_SUB(?, INTERVAL 1 MONTH) AND ?";
        $params[] = $end_date;
        $params[] = $end_date;
        $types .= "ss";
    } elseif ($type === "name") {
        $where[] = "a.tanggal <= ?";
        $params[] = $end_date;
        $types .= "s";
    }
} elseif ($start_date && $end_date) {
    $where[] = "a.tanggal BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= "ss";
} elseif ($type === "all"){
    $where[] = "a.tanggal = ?";
    $params[] = date("Y-m-d");
    $types .= "s";
}

$whereClause = count($where) > 0 ? " AND " . implode(" AND ", $where) : "";

$companyClause = "";
if ($id_company !== 0) {
    $companyClause = " AND (e.id_company = ?)";
    $params[] = $id_company;
    $types .= "i";
}
// Construct SQL with placeholders
$sql = "
    (
        SELECT a.username, e.name, a.tanggal, a.hour_in AS hour, a.foto_in AS foto, 'IN' AS status
        FROM hr_absensi a
        JOIN user_profiles e ON a.username = e.username
        WHERE a.foto_in IS NOT NULL $whereClause $companyClause
    )
    UNION
    (
        SELECT a.username, e.name, a.tanggal,a.hour_out AS hour, a.foto_out AS foto, 'OUT' AS status
        FROM hr_absensi a
        JOIN user_profiles e ON a.username = e.username
        WHERE a.foto_out IS NOT NULL $whereClause $companyClause
    )
    ORDER BY hour DESC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(["status" => 500, "error" => "Prepare failed: " . $conn->error]);
    exit;
}
if (!empty($params)) {
    $stmt->bind_param(str_repeat($types, 2), ...array_merge($params, $params)); // Bind twice for UNION
}

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(["status" => 500, "error" => "Execute failed: " . $stmt->error]);
    exit;
}

$result = $stmt->get_result();
$data = [];

while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

echo json_encode($data);

$stmt->close();
$conn->close();
?>
