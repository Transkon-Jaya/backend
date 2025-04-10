<?php
header("Content-Type: application/json");
require 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

//$logfile = '/var/www/html/debug.log';
//$logmsg = 'datetime : ' . date("Y-m-d H:i:s") . " : ";

switch ($method) {
    case 'GET':
        $sql = "CALL po_unit_get_all()";
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

    default:
        http_response_code(405);
        echo json_encode(["status" => 405, "error" => "Invalid request method"]);
        break;
}

//$logmsg .= $conn->errno . " => " . $conn->error . "\n"; // Fixed concatenation
//file_put_contents($logfile, $logmsg, FILE_APPEND);
$conn->close();
?>
