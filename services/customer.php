<?php
header("Content-Type: application/json");
require 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET': // Fetch user profiles
        $sql = "SELECT username, name, department, placement, gender, lokasi FROM user_profiles";
        $result = $conn->query($sql);

        if (!$result) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => $conn->error]);
            break;
        }

        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
        echo json_encode($users);
        break;

    case 'POST': // Insert new user (optional)
        http_response_code(501);
        echo json_encode(["status" => 501, "error" => "Not implemented"]);
        break;

    case 'PUT': // Update user (optional)
        http_response_code(501);
        echo json_encode(["status" => 501, "error" => "Not implemented"]);
        break;

    case 'DELETE': // Delete user (optional)
        http_response_code(501);
        echo json_encode(["status" => 501, "error" => "Not implemented"]);
        break;

    default:
        http_response_code(405);
        echo json_encode(["status" => 405, "error" => "Invalid request method"]);
        break;
}

$conn->close();
?>