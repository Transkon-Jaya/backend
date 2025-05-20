<?php
header("Content-Type: application/json");
require 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET': // Fetch user profiles
        $username = isset($_GET['username']) ? $_GET['username'] : '';

        if (!empty($username)) {
            $stmt = $conn->prepare("SELECT username, name, department, placement, gender, lokasi 
                                    FROM user_profiles 
                                    WHERE username LIKE CONCAT(?, '%')
                                    ORDER BY username ASC");
            $stmt->bind_param("s", $username);
        } else {
            $stmt = $conn->prepare("SELECT username, name, department, placement, gender, lokasi 
                                    FROM user_profiles 
                                    ORDER BY username ASC");
        }

        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => $stmt->error]);
            break;
        }

        $result = $stmt->get_result();
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

    case 'PUT':
    parse_str(file_get_contents("php://input"), $_PUT);
    $name = $_PUT['name'];
    $department = $_PUT['department'];
    $placement = $_PUT['placement'];
    $gender = $_PUT['gender'];
    $lokasi = $_PUT['lokasi'];

    $stmt = $conn->prepare("UPDATE user_profiles SET name=?, department=?, placement=?, gender=?, lokasi=? WHERE username=?");
    $stmt->bind_param("ssssss", $name, $department, $placement, $gender, $lokasi, $username);

    if ($stmt->execute()) {
        echo json_encode(["status" => 200, "message" => "Updated"]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => 500, "error" => $stmt->error]);
    }
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
