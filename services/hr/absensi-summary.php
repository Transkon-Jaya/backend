<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $username = $_GET['username'] ?? null;
        authorize(8, ['admin_absensi'], [], $username);
        // Optional: get month/year from query string
        $month = $_GET['month'] ?? 5;
        $year = $_GET['year'] ?? 2025;

        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);

        $dayColumns = [];
        $presentSumParts = [];

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $col = "MAX(CASE WHEN DAY(a.tanggal) = $day THEN 1 ELSE NULL END)";
            $dayColumns[] = "$col AS `$day`";
            $presentSumParts[] = $col;
        }

        $dayColumnsSql = implode(",\n", $dayColumns);
        $presentSumSql = implode(" + ", $presentSumParts);

        $sql = "
            SELECT 
                u.username,
                u.name,
                u.department,
                $dayColumnsSql,
                COUNT(DISTINCT a.tanggal) AS `Tot Hadir`,
                SUM(CASE WHEN TIME(a.hour_in) > '08:00:00' THEN 1 ELSE 0 END) AS Telat,
                CASE WHEN COUNT(DISTINCT a.tanggal) = 0 THEN 0
                    ELSE ROUND((SUM(CASE WHEN TIME(a.hour_in) > '08:00:00' THEN 1 ELSE 0 END) / COUNT(DISTINCT a.tanggal)) * 100, 2)
                END AS `Telat(%)`
            FROM user_profiles u
            LEFT JOIN hr_absensi a 
                ON u.username = a.username
                AND MONTH(a.tanggal) = $month 
                AND YEAR(a.tanggal) = $year
            GROUP BY u.username, u.name, u.department
            ORDER BY u.name
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
