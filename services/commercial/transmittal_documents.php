<?php
require 'db.php';
header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (!isset($_GET['ta_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'ta_id is required']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT * FROM transmittal_documents WHERE ta_id = ?");
        $stmt->execute([$_GET['ta_id']]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;

    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);
        $stmt = $pdo->prepare("INSERT INTO transmittal_documents (ta_id, no_urut, doc_desc, remarks) VALUES (?, ?, ?, ?)");
        $success = $stmt->execute([
            $input['ta_id'],
            $input['no_urut'],
            $input['doc_desc'],
            $input['remarks']
        ]);
        echo json_encode(['success' => $success]);
        break;

    case 'PUT':
        $input = json_decode(file_get_contents('php://input'), true);
        $stmt = $pdo->prepare("UPDATE transmittal_documents SET doc_desc = ?, remarks = ? WHERE ta_id = ? AND no_urut = ?");
        $success = $stmt->execute([
            $input['doc_desc'],
            $input['remarks'],
            $input['ta_id'],
            $input['no_urut']
        ]);
        echo json_encode(['success' => $success]);
        break;

    case 'DELETE':
        parse_str(file_get_contents('php://input'), $input);
        $stmt = $pdo->prepare("DELETE FROM transmittal_documents WHERE ta_id = ? AND no_urut = ?");
        $success = $stmt->execute([$input['ta_id'], $input['no_urut']]);
        echo json_encode(['success' => $success]);
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method Not Allowed']);
}
?>