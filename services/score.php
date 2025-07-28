<?php
header("Content-Type: application/json");
require 'db.php'; // koneksi $conn
require 'auth.php'; // validasi token dan ambil $username dari token

$method = $_SERVER['REQUEST_METHOD'];

// POST: Simpan skor baru
if ($method === 'POST') {
    $input = json_decode(file_get_contents("php://input"), true);
    $wpm = intval($input['wpm']);

    if ($wpm > 0 && !empty($username)) {
        $stmt = $conn->prepare("INSERT INTO scores (username, wpm) VALUES (?, ?)");
        $stmt->bind_param("si", $username, $wpm);
        $stmt->execute();
        echo json_encode(["message" => "Score saved successfully"]);
    } else {
        http_response_code(400);
        echo json_encode(["error" => "Invalid WPM or user"]);
    }
    exit();
}

// GET: Ambil ranking semua user
if ($method === 'GET') {
    $query = "
        SELECT 
            username, 
            MAX(wpm) AS high_score,
            RANK() OVER (ORDER BY MAX(wpm) DESC) AS ranking
        FROM scores
        GROUP BY username
        ORDER BY high_score DESC
    ";

    $result = $conn->query($query);
    $leaderboard = [];

    while ($row = $result->fetch_assoc()) {
        $leaderboard[] = $row;
    }

    echo json_encode($leaderboard);
    exit();
}

// Jika method tidak dikenali
http_response_code(405);
echo json_encode(["error" => "Method Not Allowed"]);
exit();
