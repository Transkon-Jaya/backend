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
        $sql = "CALL user_profile_get($username)";
        $result = $conn->query($sql);
        if (!$result) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => $conn->error]);
            break;
        }

        $profile = [];
        while ($row = $result->fetch_assoc()) {
            $profile[] = $row;
        }
        echo json_encode($profile);
        break;

    case 'PUT': // Update customer
        $data = json_decode(file_get_contents("php://input"), true);
        if (!isset($data['username'])) {
        http_response_code(400);
            echo json_encode(["status" => 400, "error" => "no username"]);
            break;
        }

        $oldname = $conn->real_escape_string($data['oldname']);
        $newname = $conn->real_escape_string($data['newname']);
        $sql = "CALL customer_update('$oldname', '$newname')";

        if ($conn->query($sql)) {
            echo json_encode(["status" => 200, "message" => "Customer updated successfully"]);
        } else {
            http_response_code(409);
            echo json_encode(["status" => 409, "error" => $conn->error]);
        }
        break;

    case 'DELETE': // Delete customer
        $data = json_decode(file_get_contents("php://input"), true);
        if (!isset($data['name'])) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Missing required fields"]);
            break;
        }

        $name = $conn->real_escape_string($data['name']);
        $sql = "CALL customer_delete('$name')";

        if ($conn->query($sql)) {
            echo json_encode(["status" => 200, "message" => "Customer deleted successfully"]);
        } else {
            http_response_code(409);
            echo json_encode(["status" => 409, "error" => $conn->error]);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(["status" => 405, "error" => "Invalid request method"]);
        break;
}

$conn->close();
?>

