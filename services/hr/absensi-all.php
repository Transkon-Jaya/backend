<?php
header("Content-Type: application/json");
require 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // if (!isset($_GET['username'])) {
        //     http_response_code(400);
        //     echo json_encode(["status" => 400, "error" => "No Username!"]);
        //     break;
        // }

        // $username = $conn->real_escape_string($_GET['username']);
        $sql = "CALL hr_absensi_latest();";
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
