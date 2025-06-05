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

function handleGet() {
    $uploadDir = "/var/www/html/uploads/perizinan/";
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    echo json_encode("handleGet");
};

function handlePost() {
    echo json_encode("handlePost");
};

function handlePut() {
    echo json_encode("handleput");
};

function handleDelete(){
    echo json_encode("handledelete");
};

