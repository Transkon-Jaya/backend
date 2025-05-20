<?php
header("Content-Type: application/json");
require 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET': // Fetch user profiles
    $username = isset($_GET['username']) ? $_GET['username'] : '';

    // Jika parameter diberikan, filter berdasarkan username
    if (!empty($username)) {
        $stmt = $conn->prepare("SELECT username, name, department, placement, gender, lokasi 
                                FROM user_profiles 
                                WHERE username LIKE CONCAT(?, '%')");
        $stmt->bind_param("s", $username);
    } else {
        // Jika tidak ada filter
        $stmt = $conn->prepare("SELECT username, name, department, placement, gender, lokasi 
                                FROM user_profiles");
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