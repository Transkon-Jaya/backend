<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'POST':
            // --- Simpan Skor ---
            $user = verifyToken();

            if (!$user || !isset($user['username'])) {
                http_response_code(401);
                echo json_encode(["status" => 401, "error" => "Unauthorized: Invalid or missing token"]);
                exit();
            }

            $username = trim($user['username']); // Sanitasi dasar

            // Ambil dan validasi data dari body request
            $inputJSON = file_get_contents('php://input');
            $inputData = json_decode($inputJSON, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                http_response_code(400);
                echo json_encode(["status" => 400, "error" => "Invalid JSON payload"]);
                exit();
            }

            $wpm = isset($inputData['wpm']) ? (int)$inputData['wpm'] : 0;

            if ($wpm <= 0) {
                http_response_code(400);
                echo json_encode(["status" => 400, "error" => "Invalid WPM value"]);
                exit();
            }

            // Mulai transaksi
            $conn->begin_transaction();

            try {
                // Simpan skor ke database
                $stmt = $conn->prepare("INSERT INTO scores (username, wpm, created_at) VALUES (?, ?, NOW())");
                if (!$stmt) {
                    throw new Exception("Prepare failed for INSERT score: " . $conn->error);
                }
                $stmt->bind_param("si", $username, $wpm);

                if (!$stmt->execute()) {
                    throw new Exception("Execute failed for INSERT score: " . $stmt->error);
                }
                $stmt->close();

                // Jika berhasil sampai sini, commit transaksi
                $conn->commit();

                http_response_code(200); // Atau 201 Created
                echo json_encode([
                    "status" => 200, // Atau 201
                    "message" => "Score saved successfully"
                    // Anda bisa menambahkan data lain jika perlu, misalnya ID skor yang baru saja disimpan
                ]);
                exit();

            } catch (Exception $e) {
                // Jika ada error dalam transaksi, rollback
                $conn->rollback();
                error_log("Transaction failed in score.php POST: " . $e->getMessage());
                http_response_code(500);
                echo json_encode(["status" => 500, "error" => "Failed to save score. Please check server logs."]);
                exit();
            }

            break;

        case 'GET':
            // --- Ambil Leaderboard ---
            // Optional: Highlight user yang login
            // $user = verifyToken();
            // $currentUsername = ($user && isset($user['username'])) ? $user['username'] : null;

            // Ambil parameter limit dari query string (default 10, max 100)
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            if ($limit <= 0 || $limit > 100) {
                $limit = 10;
            }

            // Query untuk mendapatkan leaderboard berdasarkan WPM tertinggi
            // Ini menampilkan semua skor, termasuk beberapa skor dari user yang sama
            // Jika ingin satu skor terbaik per user, gunakan query dengan subquery atau view
            $sql_leaderboard = "
                SELECT s.username, u.name, s.wpm, s.created_at
                FROM scores s
                JOIN user_profiles u ON s.username = u.username
                ORDER BY s.wpm DESC, s.created_at ASC
                LIMIT ?
            ";

            $stmt_leaderboard = $conn->prepare($sql_leaderboard);
            if (!$stmt_leaderboard) {
                http_response_code(500);
                echo json_encode(["status" => 500, "error" => "Database prepare failed for leaderboard: " . $conn->error]);
                exit();
            }
            $stmt_leaderboard->bind_param("i", $limit);

            if (!$stmt_leaderboard->execute()) {
                $stmt_leaderboard->close();
                http_response_code(500);
                echo json_encode(["status" => 500, "error" => "Database execute failed for leaderboard"]);
                exit();
            }

            $result_leaderboard = $stmt_leaderboard->get_result();
            $raw_leaderboard = $result_leaderboard->fetch_all(MYSQLI_ASSOC);
            $stmt_leaderboard->close();

            // Format leaderboard dengan ranking
            $leaderboard = [];
            $current_rank = 1;
            $previous_wpm = null;
            $index = 0; // Untuk menghitung peringkat dasar

            foreach($raw_leaderboard as $player) {
                $index++;
                // Jika WPM sama dengan sebelumnya, beri peringkat sama (tied ranking)
                if ($previous_wpm !== null && $player['wpm'] == $previous_wpm) {
                    // $current_rank tetap sama
                } else {
                    $current_rank = $index;
                }
                $leaderboard[] = array_merge($player, ['rank' => $current_rank]);
                $previous_wpm = $player['wpm'];
            }

            http_response_code(200);
            echo json_encode([
                "status" => 200,
                "data" => $leaderboard
            ]);
            exit();

            break;

        default:
            http_response_code(405);
            echo json_encode(["status" => 405, "error" => "Method not allowed"]);
            exit();
            break;
    }

} catch (Exception $e) {
    // Tangkap error yang tidak terduga di luar blok spesifik
    error_log("Unexpected error in score.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => 500, "error" => "An internal server error occurred"]);
    exit();
} finally {
    // Tutup koneksi database jika sudah tidak digunakan
    // Perhatikan: Jika $conn digunakan di tempat lain setelah script ini,
    // mungkin tidak perlu ditutup di sini.
    // Namun, jika ini adalah endpoint API mandiri, menutupnya adalah praktik yang baik.
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>