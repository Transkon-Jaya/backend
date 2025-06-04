<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(["status" => 405, "message" => "Method not allowed"]);
    exit;
}

try {
    authorize(8, ["admin_absensi"], [], null);
    $user = verifyToken();
    $id_company = $user['id_company'] ?? -1;

    if ($id_company == -1) {
        throw new Exception("Missing id_company in token.");
    }

    // Query with join to user_profiles to match company
    $sql = "
        SELECT COUNT(DISTINCT a.username) AS total_masuk 
        FROM hr_absensi a
        INNER JOIN user_profiles up ON a.username = up.username
        WHERE a.tanggal = CURDATE() 
        AND a.hour_in IS NOT NULL
        AND up.id_company = ?
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    $stmt->bind_param("i", $id_company);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }

    $result = $stmt->get_result();
    $data = $result->fetch_assoc();

    echo json_encode([
        "status" => 200,
        "data" => [
            "total_masuk" => (int)$data['total_masuk']
        ]
    ]);

    $stmt->close();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => 500,
        "message" => "Server error",
        "error" => $e->getMessage()
    ]);
} finally {
    $conn->close();
}
?>
