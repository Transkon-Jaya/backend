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
    
    // 🔍 Debug: Cek input
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON input']);
        exit;
    }

    // Cek apakah ta_id ada
    if (empty($input['ta_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'ta_id is required']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO transmittal_detail (
        ta_id, customer_name, date, from_origin, document_type,
        attention, awb_reg, expeditur, ras_status, receiver_name,
        receive_date, lastUpdated
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $values = [
        $input['ta_id'],
        $input['customer_name'] ?? null,
        $input['date'] ?? null,
        $input['from_origin'] ?? null,
        $input['document_type'] ?? null,
        $input['attention'] ?? null,
        $input['awb_reg'] ?? null,
        $input['expeditur'] ?? null,
        $input['ras_status'] ?? null,
        $input['receiver_name'] ?? null,
        $input['receive_date'] ?? null,
        date('Y-m-d H:i:s')
    ];

    try {
        $success = $stmt->execute($values);
        echo json_encode(['success' => $success]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error', 'details' => $e->getMessage()]);
    }
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
