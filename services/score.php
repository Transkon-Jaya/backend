<?php
header("Content-Type: application/json");
require 'db.php';
require 'auth.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        authorize(9, [""], [], null);
        $user = verifyToken();
        $username = $user['username'] ?? null;

        if (!$username) {
            http_response_code(403);
            echo json_encode(["status" => 403, "error" => "Unauthorized: username not found"]);
            break;
        }

        // Ambil 10 besar
        $topStmt = $conn->prepare("SELECT s.username, u.name, s.wpm, s.accuracy, s.test_time, s.created_at
                                  FROM scores s
                                  JOIN user_profiles u ON s.username = u.username
                                  ORDER BY s.wpm DESC, s.accuracy DESC, s.created_at ASC
                                  LIMIT 10");
        $topStmt->execute();
        $topResult = $topStmt->get_result();
        $leaderboard = $topResult->fetch_all(MYSQLI_ASSOC);
        $topStmt->close();

        // Ambil ranking user
        $rankStmt = $conn->prepare("SELECT username, wpm, accuracy, created_at
                                    FROM scores
                                    ORDER BY wpm DESC, accuracy DESC, created_at ASC");
        $rankStmt->execute();
        $rankResult = $rankStmt->get_result();

        $rank = 0;
        $userRank = null;
        while ($row = $rankResult->fetch_assoc()) {
            $rank++;
            if ($row['username'] === $username) {
                $userRank = [
                    "rank" => $rank,
                    "wpm" => $row['wpm'],
                    "accuracy" => $row['accuracy'],
                    "created_at" => $row['created_at']
                ];
                break;
            }
        }
        $rankStmt->close();

        echo json_encode([
            "status" => 200,
            "leaderboard" => $leaderboard,
            "user" => ["username" => $username, "rank" => $userRank]
        ]);
        break;

    default:
        http_response_code(405);
        echo json_encode(["status" => 405, "error" => "Method not allowed"]);
        break;
}

$conn->close();
?>
