<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        authorize(8, ["admin_absensi"], [], null);
        $user = verifyToken();
        $id_company = $user['id_company'] ?? null;

        if (!$id_company) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Missing company ID"]);
            break;
        }

        $stmt = $conn->prepare("CALL hr_absensi_latest(?)");
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => "Prepare failed: " . $conn->error]);
            break;
        }

        $stmt->bind_param("i", $id_company);
        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => "Execute failed: " . $stmt->error]);
            break;
        }

        $result = $stmt->get_result();
        $status = [];
        while ($row = $result->fetch_assoc()) {
            $status[] = $row;
        }

        echo json_encode($status);
        $stmt->close();
        break;

    default:
        http_response_code(405);
        echo json_encode(["status" => 405, "error" => "Invalid request method"]);
        break;
}

$conn->close();
?>
