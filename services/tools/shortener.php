<?php
require 'db.php'; // Should define $conn as MySQLi connection

if (!isset($_GET['code']) || empty($_GET['code'])) {
    http_response_code(400);
    exit('Missing short link code.');
}

$code = $_GET['code'];

try {
    // Prepare the CALL statement
    $stmt = $conn->prepare("CALL short_link_get(?)");
    if (!$stmt) {
        http_response_code(500);
        exit("Prepare failed: " . $conn->error);
    }

    $stmt->bind_param("s", $code);

    if (!$stmt->execute()) {
        http_response_code(500);
        exit("Execute failed: " . $stmt->error);
    }

    // Stored procedures with MySQLi require using get_result or bind_result
    $result = $stmt->get_result();
    if (!$result) {
        // Some MySQLi setups don’t support get_result() with stored procedures
        // In that case, use bind_result:
        $stmt->bind_result($original_link);
        if ($stmt->fetch()) {
            header("Location: " . $original_link);
            exit;
        } else {
            http_response_code(404);
            exit("Link not found or expired.");
        }
    } else {
        // Using get_result()
        $row = $result->fetch_assoc();
        if ($row && !empty($row['original_link'])) {
            header("Location: " . $row['original_link']);
            exit;
        } else {
            http_response_code(404);
            exit("Link not found or expired.");
        }
    }

    $stmt->close();
} catch (Exception $e) {
    http_response_code(500);
    exit("Server error.");
}
