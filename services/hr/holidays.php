
<?php
require 'db.php';
require 'auth.php';
require 'utils/mapRowWithCasts.php';

header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGet($conn);
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
    // $query = "SELECT id, holiday_date, name, type, is_recurring, day_of_week FROM holiday";
    $query = "SELECT * FROM view_calendar_with_holidays";
    $result = $conn->query($query);
    
    if (!$result) {
        throw new Exception("Failed to fetch holidays: " . $conn->error);
    }
    $casts = [
        'id' => 'int',
        'is_recurring' => 'bool',
        // 'day_of_week' => 'nullable_int'
    ];
    $holidays = [];
    while ($row = $result->fetch_assoc()) {
        $holidays[] = mapRowWithCasts($row, $casts);
    }
    
    echo json_encode($holidays);
}
