<?php
header("Content-Type: application/json");
require 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (!isset($_GET['username'])) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "No Username!"]);
            break;
        }

        $username = $conn->real_escape_string($_GET['username']);
        $sql = "SELECT id, username, tanggal, REPLACE(foto_in, '/var/www/html', '') AS foto_in, REPLACE(foto_out, '/var/www/html', '') AS foto_out, hour_in, hour_out FROM hr_absensi WHERE username = '$username' ORDER BY id DESC LIMIT 1";
        $result = $conn->query($sql);

        if (!$result) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => $conn->error]);
            break;
        }

        $status = [];
        while ($row = $result->fetch_assoc()) {
            $status[] = $row;
        }

        echo json_encode($status);
        break;

    default:
        http_response_code(405);
        echo json_encode(["status" => 405, "error" => "Invalid request method"]);
        break;
}

$conn->close();
?>
