<?php
require 'db.php';

header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (isset($_GET['ta_id'])) {
            // Filter by TA ID
            $stmt = $pdo->prepare("SELECT * FROM transmittal_detail WHERE ta_id = ?");
            $stmt->execute([$_GET['ta_id']]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);

        } elseif (isset($_GET['start_date']) && isset($_GET['end_date'])) {
            // Filter by date range
            $stmt = $pdo->prepare("SELECT * FROM transmittal_detail WHERE date BETWEEN ? AND ?");
            $stmt->execute([$_GET['start_date'], $_GET['end_date']]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        } else {
            // Tampilkan semua data
            $stmt = $pdo->query("SELECT * FROM transmittal_detail");
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode($data);
        break;

    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);
        $stmt = $pdo->prepare("INSERT INTO transmittal_detail (
            ta_id, customer_name, date, from_origin, document_type,
            attention, awb_reg, expeditur, ras_status, receiver_name,
            receive_date, lastUpdated
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $success = $stmt->execute([
            $input['ta_id'],
            $input['customer_name'],
            $input['date'],
            $input['from_origin'],
            $input['document_type'],
            $input['attention'],
            $input['awb_reg'],
            $input['expeditur'],
            $input['ras_status'],
            $input['receiver_name'],
            $input['receive_date'],
            date('Y-m-d H:i:s')
        ]);
        echo json_encode(['success' => $success]);
        break;

    case 'PUT':
        $input = json_decode(file_get_contents('php://input'), true);
        $stmt = $pdo->prepare("UPDATE transmittal_detail SET
            customer_name = ?, date = ?, from_origin = ?, document_type = ?,
            attention = ?, awb_reg = ?, expeditur = ?, ras_status = ?,
            receiver_name = ?, receive_date = ?, lastUpdated = ?
            WHERE ta_id = ?");
        $success = $stmt->execute([
            $input['customer_name'],
            $input['date'],
            $input['from_origin'],
            $input['document_type'],
            $input['attention'],
            $input['awb_reg'],
            $input['expeditur'],
            $input['ras_status'],
            $input['receiver_name'],
            $input['receive_date'],
            date('Y-m-d H:i:s'),
            $input['ta_id']
        ]);
        echo json_encode(['success' => $success]);
        break;

    case 'DELETE':
        parse_str(file_get_contents('php://input'), $input);
        $stmt = $pdo->prepare("DELETE FROM transmittal_detail WHERE ta_id = ?");
        $success = $stmt->execute([$input['ta_id']]);
        echo json_encode(['success' => $success]);
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method Not Allowed']);
}
?>
