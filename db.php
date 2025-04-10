<?php
$host = "175.184.248.171";
$user = "it-sp";
$pass = "transkon25";
$dbname = "transkon_db";

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    die(json_encode(["error" => "Database connection failed"]));
}
