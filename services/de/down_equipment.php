<?php
header("Content-Type: application/json");
require 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

//$logfile = '/var/www/html/debug.log';
//$logmsg = 'datetime : ' . date("Y-m-d H:i:s") . " : ";

switch ($method) {
    case 'GET':
        $sql = "SELECT de.tk_no, de.vehicle_type, de.plate_no, de.customer, de.alt_location
                , ds.id, ds.username, ds.comment, ds.down_since, ds.estimated_return
                , ds.spare_exists, ds.tk_no_spare, ds.done, ds.createdAt, ds.lastUpdated
                FROM down_equipment de 
                LEFT JOIN de_site ds 
                ON de.tk_no = ds.tk_no
                WHERE status_unit_3 = 'Rental' AND (ds.done is null OR ds.done = 0)";
        $result = $conn->query($sql);

        if (!$result) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => $conn->error]);
            break;
        }

        $outputs = [];
        while ($row = $result->fetch_assoc()) {
            $outputs[] = $row;
        }
        echo json_encode($outputs);
        break;

    case 'POST': // Insert
        $data = json_decode(file_get_contents("php://input"), true);
        if (!$data) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Invalid JSON data"]);
            break;
        }
    
        // Extract columns and prepare placeholders
        $columns = array_keys($data);
        $placeholders = [];
        $values = [];
        $types = "";
    
        foreach ($data as $column => $value) {
            if ($value === null || $value === "") {
                $placeholders[] = "NULL"; // Use NULL in SQL directly
            } else {
                $placeholders[] = "?";
                $values[] = $value;
                $types .= "s"; // Adjust based on the actual data type if needed
            }
        }
    
        // Build SQL dynamically
        $sql = "INSERT INTO down_equipment (" . implode(", ", $columns) . ") VALUES (" . implode(", ", $placeholders) . ")";
        
        // Prepare statement
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => "SQL Error: " . $conn->error]);
            break;
        }
    
        // Bind parameters dynamically if there are values to bind
        if (!empty($values)) {
            $stmt->bind_param($types, ...$values);
        }
    
        if ($stmt->execute()) {
            echo json_encode(["status" => 200, "message" => "DE data added successfully"]);
        } else {
            http_response_code(409);
            echo json_encode(["status" => 409, "error" => $stmt->error]);
        }
        
        $stmt->close();
        break;
        
    
    case 'PUT':
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!isset($data['tk_no'])) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Missing required 'tk_no'"]);
            break;
        }
    
        $id = $conn->real_escape_string($data['tk_no']);
        unset($data['tk_no']); // Remove ID from update fields
    
        if (empty($data)) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "No fields provided for update"]);
            break;
        }
    
        $updateFields = [];
        $params = [];
        $types = "";
    
        foreach ($data as $column => $value) {
            $safeColumn = $conn->real_escape_string($column);
            
            // Ensure NULL is passed correctly
            if ($value === null || $value === "") {
                $updateFields[] = "`$safeColumn` = NULL";
            } else {
                $updateFields[] = "`$safeColumn` = ?";
                $params[] = $value;
                $types .= "s"; // Adjust based on data type if needed
            }
        }
    
        $updateQuery = "UPDATE down_equipment SET " . implode(", ", $updateFields) . " WHERE tk_no = ?";
        $params[] = $id;
        $types .= "s"; // Assuming ID is a string, change to "i" if it's an integer
    
        $stmt = $conn->prepare($updateQuery);
        
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
    
        if ($stmt->execute()) {
            echo json_encode(["status" => 200, "message" => "DE data updated successfully"]);
        } else {
            http_response_code(409);
            echo json_encode(["status" => 409, "error" => $stmt->error]);
        }
    
        $stmt->close();
        break;
             
    default:
        http_response_code(405);
        echo json_encode(["status" => 405, "error" => "Invalid request method"]);
        break;
}

//$logmsg .= $conn->errno . " => " . $conn->error . "\n"; // Fixed concatenation
//file_put_contents($logfile, $logmsg, FILE_APPEND);
$conn->close();
?>
