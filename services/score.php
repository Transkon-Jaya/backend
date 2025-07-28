<?php
// api/score.php

header("Content-Type: application/json");
require 'db.php'; // Sesuaikan path jika perlu
require 'auth.php'; // Sesuaikan path jika perlu

// Fungsi pembantu untuk mengirim respons JSON dan keluar
function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit();
}

// Fungsi untuk memvalidasi dan membersihkan input
function validateAndSanitizeInput($input, $type = 'string') {
    $input = trim($input);
    if ($type === 'int') {
        return filter_var($input, FILTER_VALIDATE_INT);
    } elseif ($type === 'float') {
        return filter_var($input, FILTER_VALIDATE_FLOAT);
    } elseif ($type === 'string') {
        // Batasi panjang string untuk keamanan
        return substr(htmlspecialchars($input, ENT_QUOTES, 'UTF-8'), 0, 255);
    }
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8'); // Default sanitasi
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'POST':
            // --- Simpan Skor ---
            $user = verifyToken();

            if (!$user || !isset($user['username'])) {
                sendJsonResponse(["status" => 401, "error" => "Unauthorized: Invalid or missing token"], 401);
            }

            $username = validateAndSanitizeInput($user['username'], 'string');

            // Ambil dan validasi data dari body request
            $inputJSON = file_get_contents('php://input');
            $inputData = json_decode($inputJSON, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                sendJsonResponse(["status" => 400, "error" => "Invalid JSON payload"], 400);
            }

            $wpm = validateAndSanitizeInput($inputData['wpm'] ?? 0, 'int');
            // accuracy, language, difficulty bisa diterima tapi tidak disimpan ke tabel ini
            // Sesuaikan jika Anda ingin menyimpannya.

            if ($wpm === false || $wpm < 0) {
                 sendJsonResponse(["status" => 400, "error" => "Invalid WPM value"], 400);
            }

            // Periksa skor terakhir pengguna dari tabel scores
            $isNewHighScore = false;
            $lastScoreStmt = $conn->prepare("SELECT wpm FROM scores WHERE username = ? ORDER BY created_at DESC LIMIT 1");
            if (!$lastScoreStmt) {
                sendJsonResponse(["status" => 500, "error" => "Database prepare failed (check last score): " . $conn->error], 500);
            }
            $lastScoreStmt->bind_param("s", $username);
            $lastScoreStmt->execute();
            $lastScoreResult = $lastScoreStmt->get_result();
            $lastScoreData = $lastScoreResult->fetch_assoc();
            $lastScoreStmt->close();

            if (!$lastScoreData || $wpm > $lastScoreData['wpm']) {
                $isNewHighScore = true;
            }


            // Simpan skor ke database
            $stmt = $conn->prepare("INSERT INTO scores (username, wpm, created_at) VALUES (?, ?, NOW())");
            if (!$stmt) {
                sendJsonResponse(["status" => 500, "error" => "Database prepare failed (insert): " . $conn->error], 500);
            }
            $stmt->bind_param("si", $username, $wpm);

            if ($stmt->execute()) {
                $stmt->close();
                sendJsonResponse([
                    "status" => 200,
                    "message" => "Score saved successfully",
                    "data" => ["isNewHighScore" => $isNewHighScore] // Kirim info apakah ini high score
                ]);
            } else {
                $stmt->close();
                sendJsonResponse(["status" => 500, "error" => "Failed to save score"], 500);
            }

            break;

        case 'GET':
            // --- Ambil Leaderboard ---
            // Optional: Jika ingin highlight user yang login
            // $user = verifyToken();
            // $currentUsername = $user && isset($user['username']) ? $user['username'] : null;

            // Ambil parameter limit dari query string (default 10, max 100)
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            if ($limit <= 0 || $limit > 100) { $limit = 10; }

            // Query untuk mendapatkan leaderboard
            // Ini mengambil semua skor dan mengurutkannya. Jika pengguna bisa punya banyak entri,
            // Anda mungkin ingin query yang lebih kompleks untuk mendapatkan skor terbaik per pengguna.

            // Contoh 1: Leaderboard berdasarkan skor tertinggi per pengguna (menggunakan subquery)
            // Ini akan menampilkan satu entri per pengguna dengan skor WPM tertinggi mereka.
            /*
            $sql_leaderboard = "
                SELECT s.username, u.name, s.wpm, s.created_at
                FROM scores s
                INNER JOIN user_profiles u ON s.username = u.username
                INNER JOIN (
                    SELECT username, MAX(wpm) AS max_wpm
                    FROM scores
                    GROUP BY username
                ) latest_scores ON s.username = latest_scores.username AND s.wpm = latest_scores.max_wpm
                ORDER BY s.wpm DESC, s.created_at ASC
                LIMIT ?
            ";
            */

            // Contoh 2: Leaderboard sederhana, menampilkan semua skor tinggi
            // Ini bisa membuat pengguna muncul berkali-kali jika mereka punya banyak skor tinggi.
            $sql_leaderboard = "
                SELECT s.username, u.name, s.wpm, s.created_at
                FROM scores s
                JOIN user_profiles u ON s.username = u.username
                ORDER BY s.wpm DESC, s.created_at ASC
                LIMIT ?
            ";

            $stmt_leaderboard = $conn->prepare($sql_leaderboard);
            if (!$stmt_leaderboard) {
                sendJsonResponse(["status" => 500, "error" => "Database prepare failed for leaderboard: " . $conn->error], 500);
            }
            $stmt_leaderboard->bind_param("i", $limit);
            
            if (!$stmt_leaderboard->execute()) {
                 $stmt_leaderboard->close();
                 sendJsonResponse(["status" => 500, "error" => "Database execute failed for leaderboard"], 500);
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
                    $current_rank = $index; // Atau gunakan $index + 1 jika ingin dimulai dari 1
                }
                $leaderboard[] = array_merge($player, ['rank' => $current_rank]);
                $previous_wpm = $player['wpm'];
            }

            sendJsonResponse([
                "status" => 200,
                "data" => $leaderboard
            ]);

            break;

        default:
            sendJsonResponse(["status" => 405, "error" => "Method not allowed"], 405);
            break;
    }

} catch (Exception $e) {
    error_log("Unexpected error in score.php: " . $e->getMessage());
    sendJsonResponse(["status" => 500, "error" => "An internal server error occurred"], 500);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>