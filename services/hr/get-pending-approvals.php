<?php
ob_start(); // Start buffer
header("Content-Type: application/json");
error_reporting(E_ALL);
ini_set('display_errors', 0); // Jangan tampilkan error

include 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Validasi input
        if (!isset($_GET['approver_role'])) {
            ob_clean();
            http_response_code(400);
            echo json_encode(["status" => 400, "error" => "Parameter approver_role diperlukan"]);
            ob_end_flush();
            exit;
        }

        $approverRole = $conn->real_escape_string($_GET['approver_role']);
        
        // Query untuk mendapatkan pending approvals berdasarkan role approver
        $sql = "SELECT 
                    p.id, 
                    p.username, 
                    p.keterangan, 
                    p.jenis, 
                    p.department,
                    p.createdAt,
                    p.foto,
                    a.step_order as current_step,
                    p.total_steps
                FROM hr_perizinan p
                JOIN approvals a ON p.id = a.request_id
                WHERE a.role = ? 
                AND a.status = 'pending'
                AND p.approval_status = 'pending'
                ORDER BY p.createdAt DESC";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $approverRole);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $data = [];
        while ($row = $result->fetch_assoc()) {
            // Tambahkan URL lengkap untuk foto jika ada
            if (!empty($row['foto'])) {
                $row['foto_url'] = '/uploads/perizinan/' . $row['foto'];
            }
            $data[] = $row;
        }

        ob_clean();
        echo json_encode($data);
        ob_end_flush();
        exit;

    default:
        ob_clean();
        http_response_code(405);
        echo json_encode(["status" => 405, "error" => "Invalid request method"]);
        ob_end_flush();
        exit;
}

$conn->close();
ob_end_flush();
?>