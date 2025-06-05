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
    echo json_encode(['error' => $e->getMessage()]);
    error_log($e->getMessage());
} finally {
    $conn->close();
}

function handleGet($conn) {
    if (isset($_GET['code']) && $_GET['code'] !== '') {
        handleRedirect($conn);
    } elseif (isset($_GET['username'])) {
        handleUserLinks($conn);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required parameters.']);
    }
}


function handleRedirect($conn) {
    $code = $_GET['code'];
    $stmt = $conn->prepare("SELECT original_link FROM short_links WHERE link = ? AND isDeleted = 0");
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

function handleUserLinks($conn) {
    $username = $_GET['username'];
    if ($username === '') {
        authorize(8, [], [], null);
        $stmt = $conn->prepare("SELECT * FROM short_links WHERE isDeleted = 0");
    } else {
        authorize(9, [], [], $username);
        $stmt = $conn->prepare("SELECT * FROM short_links WHERE created_by = ? AND isDeleted = 0");
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

    echo json_encode($links);
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

    authorize(9, [], [], null);
    $user = verifyToken();
    $username = $user['username'] ?? null;

    $code = $input['code'];
    $original_link = $input['original_link'];

    // Step 1: Check if code already exists (including soft-deleted)
    $checkStmt = $conn->prepare("SELECT * FROM short_links WHERE link = ?");
    if (!$checkStmt) throw new Exception("Prepare failed: " . $conn->error);

    $checkStmt->bind_param("s", $code);
    $checkStmt->execute();
    $result = $checkStmt->get_result();

    if ($result && $row = $result->fetch_assoc()) {
        $checkStmt->close();

        if ($row['isDeleted'] == 0) {
            // Code exists and is active
            echo json_encode([
                'status' => 0,
                'message' => 'Short link already exists.',
                'data' => $row
            ]);
            return;
        } else {
            // Restore soft-deleted link
            $restoreStmt = $conn->prepare("UPDATE short_links SET original_link = ?, created_by = ?, isDeleted = 0 WHERE link = ?");
            if (!$restoreStmt) throw new Exception("Prepare failed: " . $conn->error);

            $restoreStmt->bind_param("sss", $original_link, $username, $code);
            if (!$restoreStmt->execute()) throw new Exception("Execute failed: " . $restoreStmt->error);

            echo json_encode([
                'status' => 2,
                'message' => 'Soft-deleted link restored.',
                'data' => [
                    'code' => $code,
                    'original_link' => $original_link,
                ]
            ]);
            $restoreStmt->close();
            return;
        }
    }
    $checkStmt->close();

    // Step 2: Insert new short link
    $stmt = $conn->prepare("INSERT INTO short_links (link, original_link, created_by) VALUES (?, ?, ?)");
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);

    $stmt->bind_param("sss", $code, $original_link, $username);
    if (!$stmt->execute()) throw new Exception("Execute failed: " . $stmt->error);

    echo json_encode([
        'status' => 1,
        'message' => 'Short link created.',
        'data' => [
            'code' => $code,
            'original_link' => $original_link,
        ]
    ]);

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
    authorize(9, [], [], null);
    $user = verifyToken();
    $username = $user['username'] ?? null;

    $stmt = $conn->prepare("UPDATE short_links SET original_link = ? WHERE link = ? AND created_by = ?");
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);

    $stmt->bind_param("sss", $original_link, $code, $username);
    if (!$stmt->execute()) throw new Exception("Execute failed: " . $stmt->error);

    echo json_encode(['message' => 'Short link updated.']);
    $stmt->close();
}

// DELETE with body: code=abc123
function handleDelete($conn) {
    $input = json_decode(file_get_contents("php://input"), true);

    if (empty($input['code'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing short link code.']);
        return;
    }

    $code = $input['code'];

    // Ambil username dari token
    $user = verifyToken();
    $username = $user['username'] ?? null;

    // Ambil created_by dari DB
    $checkStmt = $conn->prepare("SELECT created_by FROM short_links WHERE link = ? AND isDeleted = 0");
    if (!$checkStmt) throw new Exception("Prepare failed: " . $conn->error);

    $checkStmt->bind_param("s", $code);
    $checkStmt->execute();
    $result = $checkStmt->get_result();

    if (!$result || !$row = $result->fetch_assoc()) {
        http_response_code(404);
        echo json_encode(['error' => 'Short link not found.']);
        $checkStmt->close();
        return;
    }

    $created_by = $row['created_by'];
    $checkStmt->close();

    // Authorisasi: pastikan user adalah pemilik atau admin
    authorize(9, [], [], $created_by);

    // DELETE hanya jika user pemilik
    $stmt = $conn->prepare("UPDATE short_links SET isDeleted = 1 WHERE link = ? AND created_by = ?");
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);

    $stmt->bind_param("ss", $code, $username); // bind 2 params
    if (!$stmt->execute()) throw new Exception("Execute failed: " . $stmt->error);

    if ($stmt->affected_rows === 0) {
        http_response_code(403);
        echo json_encode(['error' => 'You are not allowed to delete this link.']);
    } else {
        echo json_encode(['message' => 'Short link deleted.']);
    }

    $stmt->close();
}
