<?php
header("Content-Type: application/json");
require 'db.php';
require_once 'auth.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $username = $_GET['username'] ?? null;
        authorize(2, ['admin_absensi'], [], $username);

        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $dayColumns = [];
        $presentSumParts = [];
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $col = "MAX(CASE WHEN DAY(a.date) = $day THEN 1 ELSE NULL END)";
            $dayColumns[] = "$col AS '$day'";
            $presentSumParts[] = $col;
        }
        $dayColumnsSql = implode(",\n", $dayColumns);
        $presentSumSql = implode(" + ", $presentSumParts);
        $sql = "
            SELECT 
                u.id AS user_id,
                u.name,
                $dayColumnsSql,
                ($presentSumSql) AS total_present_days,
                ($daysInMonth - ($presentSumSql)) AS total_absent_days
            FROM users u
            LEFT JOIN attendance a 
                ON u.id = a.user_id 
                AND MONTH(a.date) = $month 
                AND YEAR(a.date) = $year
            GROUP BY u.id, u.name
            ORDER BY u.name;
        ";
        $result = $conn->query($sql);
        if (!$result) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => $conn->error]);
            break;
        }

        $status = [];
        while ($row = $result->fetch_assoc()) {
            $status[] = $row;
        }

        echo json_encode($status);
        break;

    default:
        http_response_code(405);
        echo json_encode(["status" => 405, "error" => "Invalid request method"]);
        break;
}

$conn->close();
?>
