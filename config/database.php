<?php
// config/database.php

$host     = "localhost";
$db_name  = "db_on_shop";
$username = "root";
$password = "";

try {
    $conn = new PDO("mysql:host=" . $host . ";dbname=" . $db_name . ";charset=utf8", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    http_response_code(500);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode([
        "status"  => false,
        "message" => "Terjadi gangguan pada koneksi server database. Silakan coba beberapa saat lagi."
    ]);
    exit;
}
?>