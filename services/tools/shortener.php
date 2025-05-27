<?php
require 'db.php';  // This file should define and initialize $pdo (PDO instance)
require 'auth.php';

// Get the short code from the URL query param
if (!isset($_GET['code']) || empty($_GET['code'])) {
    http_response_code(400);
    exit('Missing short link code.');
}

$code = $_GET['code'];

try {
    // Call the stored procedure
    $stmt = $pdo->prepare("CALL short_link_get(?)");
    $stmt->bindParam('s', $code, PDO::PARAM_STR);
    $stmt->execute();

    // Fetch the original link
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result && !empty($result['original_link'])) {
        header("Location: " . $result['original_link']);
        exit;
    } else {
        http_response_code(404);
        echo "Link not found or expired.";
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo "Server error.";
}
