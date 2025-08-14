<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $username = $_GET['username'] ?? null;
        // Authorize dan dapatkan id_company dari user yang login
        $user_data = authorize(8, ['admin_absensi'], [], $username);
        $logged_in_company_id = $user_data['id_company']; // Asumsi fungsi authorize mengembalikan data user termasuk id_company

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
        $end_date = date("Y-m-t", strtotime($start_date));

        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);

        $dayColumns = [];
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $col = "MAX(CASE WHEN DAY(a.tanggal) = $day THEN 1 ELSE NULL END)";
            $dayColumns[] = "$col AS `$day`";
        }
        $dayColumnsSql = implode(",\n", $dayColumns);

        // Query dengan filter berdasarkan perusahaan yang login
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
                /* kolom-kolom lainnya... */
                agg.total_hour_worked AS 'TOT HOUR WORKED'
            FROM user_profiles u
            LEFT JOIN (
                SELECT 
                    a.username,
                    COUNT(DISTINCT a.tanggal) AS total_hadir,
                    /* subquery lainnya... */
                FROM hr_absensi a
                WHERE a.tanggal >= ? AND a.tanggal <= ?
                GROUP BY a.username
            ) agg ON u.username = agg.username
            LEFT JOIN hr_absensi a 
                ON u.username = a.username
                AND a.tanggal >= ? AND a.tanggal <= ?
            WHERE " . ($logged_in_company_id == 0 ? "1=1" : "u.id_company = ?") . "
            GROUP BY u.username, u.name, u.department
            ORDER BY u.username
        ";

        // Gunakan prepared statement
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => $conn->error]);
            break;
        }

        // Bind parameter berdasarkan kondisi
        if ($logged_in_company_id == 0) {
            $stmt->bind_param("ssss", $start_date, $end_date, $start_date, $end_date);
        } else {
            $stmt->bind_param("ssssi", $start_date, $end_date, $start_date, $end_date, $logged_in_company_id);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        if (!$result) {
            http_response_code(500);
            echo json_encode(["status" => 500, "error" => $stmt->error]);
            break;
        }

        $status = [];
        while ($row = $result->fetch_assoc()) {
            $status[] = $row;
        }

        echo json_encode($status);
        $stmt->close();
        break;

    default:
        http_response_code(405);
        echo json_encode(["status" => 405, "error" => "Invalid request method"]);
        break;
}

$conn->close();
?>