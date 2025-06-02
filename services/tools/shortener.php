<?php
require 'db.php'; // $conn should be a valid MySQLi connection

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGet($conn);
            break;
        case 'POST':
            handlePost($conn);
            break;
        case 'PUT':
            handlePut($conn);
            break;
        case 'DELETE':
            handleDelete($conn);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    error_log($e->getMessage());
} finally {
    $conn->close();
}

// GET /shortlink.php?code=abc123
function handleGet($conn) {
    if (empty($_GET['code'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing short link code.']);
        return;
    }

    $code = $_GET['code'];
    $stmt = $conn->prepare("CALL short_link_get(?)");
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);

    $stmt->bind_param("s", $code);
    if (!$stmt->execute()) throw new Exception("Execute failed: " . $stmt->error);

    $result = $stmt->get_result();
    if ($result && $row = $result->fetch_assoc()) {
        echo json_encode(['original_link' => $row['original_link']]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Link not found or expired.']);
    }

    $stmt->close();
}

// POST with JSON: { "code": "abc123", "original_link": "https://example.com" }
function handlePost($conn) {
    $input = json_decode(file_get_contents("php://input"), true);
    if (empty($input['code']) || empty($input['original_link'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields.']);
        return;
    }

    $code = $input['code'];
    $original_link = $input['original_link'];

    $stmt = $conn->prepare("CALL short_link_create(?, ?)");
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);

    $stmt->bind_param("ss", $code, $original_link);
    if (!$stmt->execute()) throw new Exception("Execute failed: " . $stmt->error);

    echo json_encode(['message' => 'Short link created.']);
    $stmt->close();
}

// PUT with JSON: { "code": "abc123", "original_link": "https://updated.com" }
function handlePut($conn) {
    $input = json_decode(file_get_contents("php://input"), true);
    if (empty($input['code']) || empty($input['original_link'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields.']);
        return;
    }

    $code = $input['code'];
    $original_link = $input['original_link'];

    $stmt = $conn->prepare("CALL short_link_update(?, ?)");
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);

    $stmt->bind_param("ss", $code, $original_link);
    if (!$stmt->execute()) throw new Exception("Execute failed: " . $stmt->error);

    echo json_encode(['message' => 'Short link updated.']);
    $stmt->close();
}

// DELETE with body: code=abc123
function handleDelete($conn) {
    parse_str(file_get_contents("php://input"), $input);
    if (empty($input['code'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing short link code.']);
        return;
    }

    $code = $input['code'];

    $stmt = $conn->prepare("CALL short_link_delete(?)");
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);

    $stmt->bind_param("s", $code);
    if (!$stmt->execute()) throw new Exception("Execute failed: " . $stmt->error);

    echo json_encode(['message' => 'Short link deleted.']);
    $stmt->close();
}
