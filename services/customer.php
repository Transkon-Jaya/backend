<?php
header("Content-Type: application/json");
require 'db.php';

require 'auth.php';

$method = $_SERVER['REQUEST_METHOD'];

$user = verifyToken();

switch ($method) {
    case 'GET': // Fetch users
        $sql = "CALL customer_get_all()";
        $result = $conn->query($sql);

        if (!$result) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => $conn->error]);
            break;
        }

        $customers = [];
        while ($row = $result->fetch_assoc()) {
            $customers[] = $row;
        }
        echo json_encode($customers);
        break;

    case 'POST': // Insert new users
        $data = json_decode(file_get_contents("php://input"), true);
        if (!isset($data['name'])) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Missing required fields"]);
            break;
        }

        $name = $conn->real_escape_string($data['name']);
        $sql = "CALL customer_insert('$name')";
        $conn->query($sql);
        if ($conn->errno == 0) {
            $logMesssage .= "error isnt called\n";
            echo json_encode(["status" => 200, "message" => "Customer added successfully"]);
        } else {
            http_response_code(409);
            echo json_encode(["status" => 409, "error" => $conn->error]);
        }
        break;

    case 'PUT': // Update users
        $data = json_decode(file_get_contents("php://input"), true);
        if (!isset($data['oldname']) || !isset($data['newname'])) {
        http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Missing required fields"]);
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

    case 'DELETE': // Delete users
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

