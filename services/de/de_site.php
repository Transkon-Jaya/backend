<?php
header("Content-Type: application/json");
require 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Check if username is passed as a GET parameter
        $username = isset($_GET['username']) ? $conn->real_escape_string($_GET['username']) : null;

        // Base SQL query
        $sql = "SELECT ds.*, de.customer, de.alt_location, de.vehicle_type , de.plate_no
                FROM de_site ds 
                INNER JOIN down_equipment de ON ds.tk_no = de.tk_no
                WHERE ds.done = false";

        // Add WHERE clause if username is provided
        if ($username !== null) {
            $sql .= " AND ds.username = '$username'";
        }
        $sql .= " ORDER BY ds.createdAt DESC";

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

    case 'POST':
        // Get JSON input
        $input = json_decode(file_get_contents('php://input'), true);

        // Validate required fields
        $required = ['tk_no', 'username'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                http_response_code(400);
                echo json_encode(["status" => 400, "error" => "$field is required"]);
                exit;
            }
        }

        // Escape values
        $tk_no = $conn->real_escape_string($input['tk_no']);
        $username = $conn->real_escape_string($input['username']);

        // Check for optional fields, set to NULL if not present
        $comment = isset($input['comment']) ? $conn->real_escape_string($input['comment']) : null;
        $down_since = isset($input['down_since']) ? $conn->real_escape_string($input['down_since']) : null;
        $estimated_return = isset($input['estimated_return']) ? $conn->real_escape_string($input['estimated_return']) : null;
        $spare_exists = isset($input['spare_exists']) ? $conn->real_escape_string($input['spare_exists']) : null;

        $sql = "INSERT INTO de_site (tk_no, username, comment, down_since, estimated_return, spare_exists)
                VALUES ('$tk_no', '$username', " . ($comment ? "'$comment'" : 'NULL') . ", " . ($down_since ? "'$down_since'" : 'NULL') . ", " . ($estimated_return ? "'$estimated_return'" : 'NULL') . ($spare_exists ? "'$spare_exists'" : 'NULL') . ")";

        if ($conn->query($sql)) {
            echo json_encode(["status" => 200, "message" => "Data inserted successfully"]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => $conn->error]);
        }

        break;

    case 'PUT':
        $input = json_decode(file_get_contents('php://input'), true);

        // Validate required fields
        $required = ['tk_no', 'username'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                http_response_code(400);
                echo json_encode(["status" => 400, "error" => "$field is required"]);
                exit;
            }
        }

        $tk_no = $conn->real_escape_string($input['tk_no']);
        $username = $conn->real_escape_string($input['username']);

        // Check for optional fields, set to NULL if not present
        $comment = isset($input['comment']) ? $conn->real_escape_string($input['comment']) : null;
        $down_since = isset($input['down_since']) ? $conn->real_escape_string($input['down_since']) : null;
        $estimated_return = isset($input['estimated_return']) ? $conn->real_escape_string($input['estimated_return']) : null;
        $spare_exists = isset($input['spare_exists']) ? $conn->real_escape_string($input['spare_exists']) : null;
        
        // Prepare SQL with conditional null values
        $sql = "UPDATE de_site 
                SET 
                    comment = " . ($comment ? "'$comment'" : 'NULL') . ",
                    down_since = " . ($down_since ? "'$down_since'" : 'NULL') . ",
                    estimated_return = " . ($estimated_return ? "'$estimated_return'" : 'NULL') . "
                    spare_exists = " . ($spare_exists ? "'$spare_exists'" : 'NULL') . "
                WHERE tk_no = '$tk_no' AND username = '$username'";

        if ($conn->query($sql)) {
            echo json_encode(["status" => 200, "message" => "Data updated successfully"]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => $conn->error]);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(["status" => 405, "error" => "Invalid request method"]);
        break;
}

$conn->close();
?>
