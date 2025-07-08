<?php
require_once 'db.php';
require_once 'auth.php';
require_once 'utils/mapRowWithCasts.php';
require_once 'utils/dynamicQuery.php';

header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];

$table = 'test';
$idColumn = 'id';

try {
    switch ($method) {
        case 'GET':
            handleGet();
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

function handleGet() {
    $response = [
        "timezone" => date_default_timezone_get(),
        "now" => date("Y-m-d H:i:s")
    ];
    header("Content-Type: application/json");
    echo json_encode($response);
}


function handlePost() {
    global $table;

    $data = json_decode(file_get_contents('php://input'), true);
    unset($data['request']);
    $insertedId = dynamicInsert($table, $data);
    echo json_encode(['success' => true, 'id' => $insertedId]);
}

function handlePut() {
    global $table, $idColumn;

    $data = json_decode(file_get_contents('php://input'), true);
    unset($data['request']);

    if (!isset($data[$idColumn])) {
        http_response_code(400);
        echo json_encode(['error' => "Missing required field: $idColumn"]);
        return;
    }

    $id = $data[$idColumn];
    unset($data[$idColumn]);

    $affected = dynamicUpdate($table, $data, $id, $idColumn);
    echo json_encode(['success' => true, 'updated_rows' => $affected]);
}

function handleDelete() {
    global $conn, $table, $idColumn;

    $data = json_decode(file_get_contents('php://input'), true);
    unset($data['request']);

    if (!isset($data[$idColumn])) {
        http_response_code(400);
        echo json_encode(['error' => "Missing required field: $idColumn"]);
        return;
    }

    $id = $data[$idColumn];
    $sql = "DELETE FROM `$table` WHERE `$idColumn` = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);

    if (!$stmt->execute()) {
        throw new Exception("Delete failed: " . $stmt->error);
    }

    echo json_encode(['success' => true, 'deleted_rows' => $stmt->affected_rows]);
}
