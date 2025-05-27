<?php
require 'db.php'; // Should define $conn as MySQLi connection

header('Content-Type: application/json');

if (!isset($_GET['code']) || empty($_GET['code'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing short link code.']);
    exit;
}

$code = $_GET['code'];

try {
    $stmt = $conn->prepare("CALL short_link_get(?)");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
        exit;
    }

    $stmt->bind_param("s", $code);

    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(['error' => 'Execute failed: ' . $stmt->error]);
        exit;
    }

    $result = $stmt->get_result();
    if (!$result) {
        // fallback if get_result() unsupported
        $stmt->bind_result($original_link);
        if ($stmt->fetch()) {
            echo json_encode(['original_link' => $original_link]);
            exit;
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Link not found or expired.']);
            exit;
        }
    } else {
        $row = $result->fetch_assoc();
        if ($row && !empty($row['original_link'])) {
            echo json_encode(['original_link' => $row['original_link']]);
            exit;
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Link not found or expired.']);
            exit;
        }
    }

    $stmt->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error.']);
}
