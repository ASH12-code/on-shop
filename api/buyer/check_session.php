<?php
// C:/laragon/www/on-shop/api/buyer/check_session.php
session_start();
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// Periksa apakah sesi PHP server aktif dan memiliki user_id
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    echo json_encode([
        "logged_in" => true,
        "user_id"   => intval($_SESSION['user_id']),
        "username"  => $_SESSION['username'] ?? '',
        "name"      => $_SESSION['name'] ?? '',
        "role"      => $_SESSION['role'] ?? 'buyer',
        "has_shop"  => isset($_SESSION['has_shop']) ? intval($_SESSION['has_shop']) : 0
    ]);
} else {
    echo json_encode([
        "logged_in" => false,
        "message"   => "Belum login atau sesi telah berakhir."
    ]);
}
exit;