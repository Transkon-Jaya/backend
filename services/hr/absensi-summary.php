<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $username = $_GET['username'] ?? null;
        authorize(8, ['admin_absensi'], [], $username);

        // Baca dan proses parameter month (format: YYYY-MM)
        $monthInput = $_GET['month'] ?? null;
        if ($monthInput && preg_match('/^(\d{4})-(\d{2})$/', $monthInput, $matches)) {
            $year = (int)$matches[1];
            $month = (int)$matches[2];
        } else {
            $year = (int)date('Y');
            $month = (int)date('n');
        }

        // Validasi
        if ($month < 1 || $month > 12) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Bulan tidak valid"]);
            exit;
        }
        if ($year < 2000 || $year > 2100) {
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Tahun tidak valid"]);
            exit;
        }

        // Hitung rentang tanggal
        $start_date = "$year-$month-01";
        $end_date = date("Y-m-t", strtotime($start_date)); // akhir bulan

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
    u.username AS NIK,
    u.name,
    u.department,
    u.lokasi,
    u.jabatan,
    NULL AS Kepeg,
    $dayColumnsSql,
    agg.total_hadir AS `Tot Hadir`,
    NULL AS PD,
    NULL AS `Adj PD`,
    NULL AS OFF,
    NULL AS Absen,
    NULL AS Izin,
    NULL AS Sakit,
    NULL AS `Cuti Lokasi`,
    NULL AS `Cuti Tahunan`,
    agg.total_telat AS Telat,
    CASE 
        WHEN agg.total_hadir = 0 THEN 0
        ELSE ROUND((agg.total_telat / agg.total_hadir) * 100, 2)
    END AS `Telat(%)`,
    CASE 
        WHEN agg.total_hadir = 0 THEN ''
        WHEN (agg.total_telat / agg.total_hadir) * 100 >= 70 THEN 'SP1'
        WHEN (agg.total_telat / agg.total_hadir) * 100 > 10 THEN 'Coaching'
        ELSE ''
    END AS Action,
    agg.count_ovt AS Overhour,
    CASE 
        WHEN agg.total_hadir = 0 THEN 0
        ELSE ROUND((agg.count_ovt / agg.total_hadir) * 100, 2)
    END AS `Overhour(%)`,
    agg.total_ovt_hour AS `Overhour(H)`,
    agg.total_ovt AS `Tot Overhour`,
    agg.avg_hour_in AS 'AVG HOUR IN',
    agg.total_hour_worked AS 'TOT HOUR WORKED'
FROM user_profiles u
LEFT JOIN (
    SELECT 
        a.username,
        COUNT(DISTINCT a.tanggal) AS total_hadir,
        SUM(CASE WHEN TIME(a.hour_in) > '08:00:00' THEN 1 ELSE 0 END) AS total_telat,
        SUM(CASE WHEN a.ovt > 0 THEN 1 ELSE 0 END) AS count_ovt,
        SUM(a.ovt) AS total_ovt_hour,
        SUM(a.total) AS total_ovt,
        TIME_FORMAT(SEC_TO_TIME(AVG(TIME_TO_SEC(TIME(a.hour_in)))), '%H:%i:%s') AS avg_hour_in,
        TIME_FORMAT(SEC_TO_TIME(AVG(TIME_TO_SEC(TIME(a.hour_out)))), '%H:%i:%s') AS avg_hour_out,
        ROUND(SUM(a.hour_worked), 2) as total_hour_worked
    FROM hr_absensi a
    WHERE a.tanggal >= '$start_date' AND a.tanggal <= '$end_date'
    GROUP BY a.username
) agg ON u.username = agg.username
LEFT JOIN hr_absensi a 
    ON u.username = a.username
    AND a.tanggal >= '$start_date' AND a.tanggal <= '$end_date'
WHERE u.username LIKE 'tj%'
  AND (0 = $id_company OR u.id_company = $id_company)
GROUP BY u.username, u.name, u.department
ORDER BY u.username";


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