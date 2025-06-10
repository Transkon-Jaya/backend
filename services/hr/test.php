
<?php
require 'db.php';
require 'auth.php';
require 'utils/mapRowWithCasts.php';
require 'utils/dynamicQuery.php';

header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];

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

$table = 'test';
$idColumn = 'id';

function handleGet() {
    global $table;

    $conditions = $_GET;
    unset($conditions['request']);
    echo json_encode($conditions);
    $data = dynamicSelect($table, $conditions);
    echo json_encode(['success' => true, 'data' => $data]);
}

function handlePost() {
    echo json_encode("handlePost");
}

function handlePut() {
    echo json_encode("handlePut");
}

function handleDelete() {
    echo json_encode("handleDelete");
}

