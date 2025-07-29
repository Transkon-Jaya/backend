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
                // 1. Cek apakah user sudah punya score sebelumnya
                $stmtCheck = $conn->prepare("SELECT wpm FROM scores WHERE username = ? ORDER BY wpm DESC LIMIT 1");
                if (!$stmtCheck) {
                    throw new Exception("Prepare failed for SELECT score: " . $conn->error);
                }
                $stmtCheck->bind_param("s", $username);
                
                if (!$stmtCheck->execute()) {
                    throw new Exception("Execute failed for SELECT score: " . $stmtCheck->error);
                }
                
                $result = $stmtCheck->get_result();
                $existingScore = $result->fetch_assoc();
                $stmtCheck->close();

                // 2. Jika sudah ada dan score baru lebih tinggi, update
                if ($existingScore && $wpm > $existingScore['wpm']) {
                    $stmtUpdate = $conn->prepare("UPDATE scores SET wpm = ?, created_at = NOW() WHERE username = ? AND wpm = ?");
                    if (!$stmtUpdate) {
                        throw new Exception("Prepare failed for UPDATE score: " . $conn->error);
                    }
                    $stmtUpdate->bind_param("isi", $wpm, $username, $existingScore['wpm']);
                    
                    if (!$stmtUpdate->execute()) {
                        throw new Exception("Execute failed for UPDATE score: " . $stmtUpdate->error);
                    }
                    $stmtUpdate->close();
                    
                    $message = "Higher score updated successfully";
                } 
                // 3. Jika belum ada score, insert baru
                elseif (!$existingScore) {
                    $stmtInsert = $conn->prepare("INSERT INTO scores (username, wpm, created_at) VALUES (?, ?, NOW())");
                    if (!$stmtInsert) {
                        throw new Exception("Prepare failed for INSERT score: " . $conn->error);
                    }
                    $stmtInsert->bind_param("si", $username, $wpm);
                    
                    if (!$stmtInsert->execute()) {
                        throw new Exception("Execute failed for INSERT score: " . $stmtInsert->error);
                    }
                    $stmtInsert->close();
                    
                    $message = "Score saved successfully";
                }
                // 4. Jika score baru tidak lebih tinggi, tidak lakukan apa-apa
                else {
                    $message = "Existing score is higher, no update needed";
                }

                // Jika berhasil sampai sini, commit transaksi
                $conn->commit();

                http_response_code(200);
                echo json_encode([
                    "status" => 200,
                    "message" => $message,
                    "current_score" => $wpm,
                    "highest_score" => $existingScore ? max($existingScore['wpm'], $wpm) : $wpm
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
            // Ambil parameter limit dari query string (default 10, max 100)
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            if ($limit <= 0 || $limit > 100) {
                $limit = 10;
            }

            // Query untuk mendapatkan leaderboard dengan skor tertinggi per user
            $sql_leaderboard = "
                SELECT s.username, u.name, MAX(s.wpm) as wpm, MAX(s.created_at) as created_at
                FROM scores s
                JOIN user_profiles u ON s.username = u.username
                GROUP BY s.username, u.name
                ORDER BY wpm DESC, created_at ASC
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

            // Tambahkan info user yang sedang login jika ada
            $user = verifyToken();
            if ($user && isset($user['username'])) {
                $currentUsername = $user['username'];
                
                // Cari apakah user ada di leaderboard
                $userInLeaderboard = false;
                foreach($leaderboard as $entry) {
                    if ($entry['username'] === $currentUsername) {
                        $userInLeaderboard = true;
                        break;
                    }
                }
                
                // Jika tidak ada di leaderboard, ambil score tertinggi user
                if (!$userInLeaderboard) {
                    $stmtUser = $conn->prepare("SELECT MAX(wpm) as wpm FROM scores WHERE username = ?");
                    $stmtUser->bind_param("s", $currentUsername);
                    if ($stmtUser->execute()) {
                        $result = $stmtUser->get_result();
                        $userScore = $result->fetch_assoc();
                        $stmtUser->close();
                        
                        if ($userScore['wpm']) {
                            // Tambahkan info user di luar leaderboard
                            $response['user_info'] = [
                                'username' => $currentUsername,
                                'wpm' => $userScore['wpm'],
                                'message' => 'Your highest score is not in top ' . $limit
                            ];
                        }
                    }
                }
            }

            http_response_code(200);
            echo json_encode([
                "status" => 200,
                "data" => $leaderboard,
                "user_info" => $response['user_info'] ?? null
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
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>