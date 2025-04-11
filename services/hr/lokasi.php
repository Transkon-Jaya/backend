<?php
header("Content-Type: application/json");
require 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET': // Fetch customers
        $sql = "SELECT * FROM hr_lokasi";
        $result = $conn->query($sql);

        if (!$result) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => $conn->error]);
            break;
        }

        $lokasi = [];
        while ($row = $result->fetch_assoc()) {
            $lokasi[] = $row;
        }
        echo json_encode($lokasi);
        break;

    default:
        http_response_code(405);
        echo json_encode(["status" => 405, "error" => "Invalid request method"]);
        break;
}

$conn->close();
?>

