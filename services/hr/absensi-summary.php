<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        try {
            // Authorization
            authorize(8, ["admin_absensi"], [], null);
            $user = verifyToken();
            $logged_in_company_id = $user['id_company'] ?? -1;

            if ($logged_in_company_id == -1) {
                throw new Exception("Missing company ID");
            }

            // Get month parameter
            $monthInput = $_GET['month'] ?? null;
            if ($monthInput && preg_match('/^(\d{4})-(\d{2})$/', $monthInput, $matches)) {
                $year = (int)$matches[1];
                $month = (int)$matches[2];
            } else {
                $year = (int)date('Y');
                $month = (int)date('n');
            }

            // Validate
            if ($month < 1 || $month > 12) {
                throw new Exception("Invalid month");
            }
            if ($year < 2000 || $year > 2100) {
                throw new Exception("Invalid year");
            }

            // Get requested company filter (only applicable for superadmin)
            $requested_company_id = isset($_GET['company_id']) ? (int)$_GET['company_id'] : null;
            
            // Apply company filter
            $company_filter = "";
            if ($logged_in_company_id !== 0) {
                // Regular admin can only see their own company
                $company_filter = "AND u.id_company = $logged_in_company_id";
            } else if ($requested_company_id !== null && $requested_company_id > 0) {
                // Superadmin can filter by specific company if requested
                $company_filter = "AND u.id_company = $requested_company_id";
            }

            // Calculate date range
            $start_date = "$year-$month-01";
            $end_date = date("Y-m-t", strtotime($start_date));

            // Days in month
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);

            // Build dynamic day columns
            $dayColumns = [];
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $col = "MAX(CASE WHEN DAY(a.tanggal) = $day THEN 1 ELSE NULL END)";
                $dayColumns[] = "$col AS `$day`";
            }
            $dayColumnsSql = implode(",\n", $dayColumns);

            // Main query
            $sql = "
                SELECT 
                    u.username AS NIK,
                    u.name,
                    u.department,
                    u.lokasi,
                    u.jabatan,
                    NULL AS Kepeg,
                    $dayColumnsSql,
                    COUNT(DISTINCT a.tanggal) AS `Tot Hadir`,
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
                LEFT JOIN hr_absensi a ON u.username = a.username
                    AND a.tanggal BETWEEN '$start_date' AND '$end_date'
                WHERE 1=1
                    $company_filter
                GROUP BY u.username, u.name, u.department, u.lokasi, u.jabatan
                ORDER BY u.username
            ";

            $result = $conn->query($sql);
            if (!$result) {
                throw new Exception("Query failed: " . $conn->error);
            }

            $data = [];
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }

            echo json_encode([
                'status' => 200,
                'data' => $data,
                'meta' => [
                    'company_id' => $logged_in_company_id,
                    'date_range' => "$start_date to $end_date"
                ]
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'status' => 500,
                'error' => $e->getMessage()
            ]);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(["status" => 405, "error" => "Method not allowed"]);
        break;
}

$conn->close();
?>