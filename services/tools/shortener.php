<?php
require 'db.php';
require 'auth.php';
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGet($conn);
            break;
        case 'POST':
            handlePost();
            break;
        case 'PUT':
            handlePut();
            break;
        case 'DELETE':
            handleDelete();
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    error_log($e->getMessage());
} finally {
    $conn->close();
}

function handleGet($conn) {
    if (isset($_GET['code']) && $_GET['code'] !== '') {
        handleRedirect($conn);
    } elseif (isset($_GET['username'])) {
        handleUserLinks($_GET['username']);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required parameters.']);
    }
}


function handleRedirect($conn) {
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

function handleUserLinks($username) {
    if ($username === '') {
        authorize(8, [], [], null);

        $stmt = $conn->prepare("CALL short_links_all()");
    } else {
        authorize(9, [], [], $username);
        $stmt = $conn->prepare("SELECT * FROM short_links;");
    }

    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);

    if ($username !== '') {
        $stmt->bind_param("s", $username);
    }

    if (!$stmt->execute()) throw new Exception("Execute failed: " . $stmt->error);

    $result = $stmt->get_result();
    $links = [];

    while ($row = $result->fetch_assoc()) {
        $links[] = $row;
    }

    echo json_encode(['links' => $links]);
    $stmt->close();
}



// POST with JSON: { "code": "abc123", "original_link": "https://example.com" }
function handlePost() {
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
function handlePut() {
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
function handleDelete() {
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
