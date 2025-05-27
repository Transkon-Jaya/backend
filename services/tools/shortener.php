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
    $stmt = $conn->prepare("CALL short_link_get(?)");
    $stmt->bindParam('s', $code);
    $stmt->execute();

    // Fetch the original link
    $result = $stmt->get_result();

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
