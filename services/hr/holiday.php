
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
    $query = "SELECT id, holiday_date, name, type, is_recurring, day_of_week FROM holiday";
    // $query = "SELECT * FROM calendar_dates WHERE id_holiday >= 0";
    $result = $conn->query($query);
    
    if (!$result) {
        throw new Exception("Failed to fetch holidays: " . $conn->error);
    }
    $casts = [
        // 'id' => 'int',
        // 'is_recurring' => 'bool',
        // 'day_of_week' => 'nullable_int'
    ];
    $holidays = [];
    while ($row = $result->fetch_assoc()) {
        $holidays[] = mapRowWithCasts($row, $casts);
    }
    
    echo json_encode($holidays);
}

function handlePost($conn) {
    $input = json_decode(file_get_contents("php://input"), true);
    
    // Validate required fields
    if (empty($input['name']) || empty($input['holiday_date'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields']);
        return;
    }
    $type = $input["type"] ?? "";
    $is_recurring = $input["is_recurring"] ?? 0;
    
    // Prepare statement
    $stmt = $conn->prepare("INSERT INTO holiday (holiday_date, `name`, `type`, is_recurring) 
                           VALUES (?, ?, ?, ?)");
    $stmt->bind_param("sssi", 
        $input['holiday_date'],
        $input['name'],
        $type,
        $is_recurring
    );
    if (!$stmt->execute()) {
        throw new Exception("Failed to create holiday: " . $stmt->error);
    }
    // Return the created holiday
    $newId = $stmt->insert_id;
    $stmt->close();
    
    $result = $conn->query("SELECT * FROM holiday WHERE id = $newId");
    echo json_encode($result->fetch_assoc());
}

function handlePut($conn) {
    $input = json_decode(file_get_contents("php://input"), true);
    
    // Validate required fields
    if (empty($input['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Holiday ID is required']);
        return;
    }
    
    // Build dynamic update query
    $updates = [];
    $params = [];
    $types = '';
    
    $fields = ['holiday_date', 'name', 'type', 'is_recurring', 'day_of_week'];
    foreach ($fields as $field) {
        if (isset($input[$field])) {
            $updates[] = "$field = ?";
            $params[] = $input[$field];
            $types .= $field === 'day_of_week' ? 'i' : 's';
        }
    }
    
    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['error' => 'No fields to update']);
        return;
    }
    
    // Add ID to params
    $params[] = $input['id'];
    $types .= 'i';
    
    $query = "UPDATE holiday SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to update holiday: " . $stmt->error);
    }
    
    $stmt->close();
    
    // Return the updated holiday
    $result = $conn->query("SELECT * FROM holiday WHERE id = " . $input['id']);
    echo json_encode($result->fetch_assoc());
}

function handleDelete($conn) {
    $input = json_decode(file_get_contents("php://input"), true);
    
    if (empty($input['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Holiday ID is required']);
        return;
    }
    
    // First get the holiday being deleted to return it
    $result = $conn->query("SELECT * FROM holiday WHERE id = " . $input['id']);
    $holiday = $result->fetch_assoc();
    
    if (!$holiday) {
        http_response_code(404);
        echo json_encode(['error' => 'Holiday not found']);
        return;
    }
    
    // Perform deletion
    $stmt = $conn->prepare("DELETE FROM holiday WHERE id = ?");
    $stmt->bind_param("i", $input['id']);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to delete holiday: " . $stmt->error);
    }
    
    $stmt->close();
    echo json_encode($holiday);
}