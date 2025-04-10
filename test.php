<?php
header("Content-Type: application/json");
require 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $name = "I AM USER 10";
        $username = "user10";
        $password = "user10";
        $passwd_h = password_hash($password, PASSWORD_DEFAULT);
        $sql = "INSERT INTO users (name, username, passwd) VALUES ('$name', '$username', '$passwd_h')";
        $result = $conn->query($sql);

        if (!$result) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => $conn->error]);
            break;
        }
        echo json_encode(["status" => 200, "error" => "TEST DONE"]);
        break;

    default:
        http_response_code(405);
        echo json_encode(["status" => 405, "error" => "Invalid request method"]);
        break;
}

$conn->close();
?>

