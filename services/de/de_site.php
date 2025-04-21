<?php
header("Content-Type: application/json");
require 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Check if username is passed as a GET parameter
        $username = isset($_GET['username']) ? $conn->real_escape_string($_GET['username']) : null;

        // Base SQL query
        $sql = "SELECT ds.*, de.vehicle_type 
                FROM de_site ds 
                INNER JOIN down_equipment de ON ds.tk_no = de.tk_no";

        // Add WHERE clause if username is provided
        if ($username !== null) {
            $sql .= " WHERE de.username = '$username'";
        }

        $result = $conn->query($sql);

        if (!$result) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => $conn->error]);
            break;
        }

        $outputs = [];
        while ($row = $result->fetch_assoc()) {
            $outputs[] = $row;
        }
        echo json_encode($outputs);
        break;

    default:
        http_response_code(405);
        echo json_encode(["status" => 405, "error" => "Invalid request method"]);
        break;
}

$conn->close();
?>
