<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        try {
            $username = $_GET['username'] ?? null;
            
            // Pastikan authorize() mengembalikan data user yang benar
            $user_data = authorize(8, ['admin_absensi'], [], $username);
            if (!$user_data || !isset($user_data['id_company'])) {
                throw new Exception("Unauthorized or missing company data");
            }
            
            $logged_in_company_id = (int)$user_data['id_company'];

            // Validasi bulan dan tahun
            $monthInput = $_GET['month'] ?? null;
            if ($monthInput && preg_match('/^(\d{4})-(\d{2})$/', $monthInput, $matches)) {
                $year = (int)$matches[1];
                $month = (int)$matches[2];
            } else {
                $year = (int)date('Y');
                $month = (int)date('n');
            }

            if ($month < 1 || $month > 12) {
                throw new Exception("Invalid month");
            }
            if ($year < 2000 || $year > 2100) {
                throw new Exception("Invalid year");
            }

            // Hitung rentang tanggal
            $start_date = "$year-$month-01";
            $end_date = date("Y-m-t", strtotime($start_date));

            // Bangun kolom hari dinamis
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
            $dayColumns = array_map(function($day) {
                return "MAX(CASE WHEN DAY(a.tanggal) = $day THEN 1 ELSE NULL END) AS `$day`";
            }, range(1, $daysInMonth));
            $dayColumnsSql = implode(",\n", $dayColumns);

            // Bangun query dengan filter perusahaan
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
                    /* ... kolom lainnya ... */
                    agg.total_hour_worked AS 'TOT HOUR WORKED'
                FROM user_profiles u
                LEFT JOIN (
                    SELECT 
                        a.username,
                        COUNT(DISTINCT a.tanggal) AS total_hadir,
                        /* ... subquery lainnya ... */
                    FROM hr_absensi a
                    WHERE a.tanggal BETWEEN ? AND ?
                    GROUP BY a.username
                ) agg ON u.username = agg.username
                LEFT JOIN hr_absensi a 
                    ON u.username = a.username
                    AND a.tanggal BETWEEN ? AND ?
                " . ($logged_in_company_id === 0 ? "" : "WHERE u.id_company = ?") . "
                GROUP BY u.username, u.name, u.department
                ORDER BY u.username
            ";

            // Debugging: Log query yang akan dijalankan
            error_log("Executing query: " . str_replace(["\n", "\r", "\t"], " ", $sql));

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }

            // Binding parameter dinamis
            if ($logged_in_company_id === 0) {
                $stmt->bind_param("ssss", $start_date, $end_date, $start_date, $end_date);
            } else {
                $stmt->bind_param("ssssi", $start_date, $end_date, $start_date, $end_date, $logged_in_company_id);
            }

            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }

            $result = $stmt->get_result();
            if (!$result) {
                throw new Exception("Get result failed: " . $stmt->error);
            }

            $status = [];
            while ($row = $result->fetch_assoc()) {
                $status[] = $row;
            }

            // Tambahkan informasi debug
            $response = [
                'success' => true,
                'data' => $status,
                'debug' => [
                    'company_id' => $logged_in_company_id,
                    'date_range' => "$start_date to $end_date",
                    'record_count' => count($status)
                ]
            ];

            echo json_encode($response);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(["success" => false, "error" => "Method not allowed"]);
        break;
}

$conn->close();
?>