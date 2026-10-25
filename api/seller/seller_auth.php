<?php
// api/seller/seller_auth.php

require_once __DIR__ . '/../helpers/auth.php';

// Menghapus Access-Control-Allow-Origin: *
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$db = get_db_connection();
if (!$db) {
    json_response(false, "Koneksi database gagal.", null, 500);
}

// 1. Wajib terautentikasi melalui Sesi PHP murni
$user_id = require_auth();

// 2. Wajib memiliki toko yang valid terdaftar atas user_id ini
$shop_id = require_seller_shop();

// Ambil info detail toko jika diperlukan
$stmtShopInfo = $db->prepare("SELECT shop_id, user_id, shop_name, status, is_official FROM shops WHERE shop_id = ? AND user_id = ? LIMIT 1");
$stmtShopInfo->execute([$shop_id, $user_id]);
$currentShop = $stmtShopInfo->fetch(PDO::FETCH_ASSOC);

if (!$currentShop) {
    json_response(false, "Akses toko ditolak. Toko tidak ditemukan atau bukan milik akun Anda.", null, 403);
}

if (isset($currentShop['status']) && strtolower($currentShop['status']) === 'banned') {
    json_response(false, "Toko Anda sedang ditangguhkan oleh Admin.", null, 403);
}
?>