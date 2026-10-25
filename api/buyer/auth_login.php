<?php
// api/buyer/auth_login.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/captcha.php';
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);

$username       = isset($input['username']) ? trim($input['username']) : '';
$password       = isset($input['password']) ? trim($input['password']) : '';
$turnstileToken = isset($input['turnstile_token']) ? trim($input['turnstile_token']) : '';

// 1. Validasi Kehadiran Input
if (empty($username) || empty($password)) {
    json_response(false, "Kontak/Email dan Password wajib diisi!", null, 400);
}

// 2. Validasi Proteksi Bot Turnstile
if (!verify_turnstile($turnstileToken, $_SERVER['REMOTE_ADDR'] ?? null)) {
    json_response(false, "Verifikasi keamanan captcha gagal atau kedaluwarsa. Silakan coba lagi.", null, 400);
}

try {
    // Cari pengguna berdasarkan email, nomor hp, atau kode user
    $sql = "SELECT user_id, user_code, name, email, phone, password, role, has_shop, is_banned, banned_reason 
            FROM users 
            WHERE email = :u OR phone = :u OR user_code = :u 
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute(['u' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        json_response(false, "Kredensial login tidak valid!", null, 401);
    }

    // Cek apakah akun sedang diblokir oleh Admin
    if (!empty($user['is_banned']) && (int)$user['is_banned'] === 1) {
        $reason = !empty($user['banned_reason']) ? $user['banned_reason'] : "Pelanggaran pedoman layanan.";
        json_response(false, "Akun Anda ditangguhkan: " . $reason, null, 403);
    }

    // Verifikasi password HANYA menggunakan password_verify (bcrypt)
    if (!password_verify($password, $user['password'])) {
        json_response(false, "Kredensial login tidak valid!", null, 401);
    }

    // 1. Kunci Identitas Pengguna di Sesi Server PHP
    session_regenerate_id(true);
    $_SESSION['user_id']   = (int)$user['user_id'];
    $_SESSION['role']      = $user['role'];
    $_SESSION['has_shop']  = (int)$user['has_shop'];

    // 2. Kunci Data Toko jika akun sudah memiliki toko aktif
    $stmtShop = $conn->prepare("SELECT shop_id, shop_name FROM shops WHERE user_id = :uid LIMIT 1");
    $stmtShop->execute(['uid' => $user['user_id']]);
    $shop = $stmtShop->fetch(PDO::FETCH_ASSOC);

    if ($shop) {
        $_SESSION['shop_id']   = (int)$shop['shop_id'];
        $_SESSION['shop_name'] = $shop['shop_name'];
        $user['shop_id']       = (int)$shop['shop_id'];
        $user['shop_name']     = $shop['shop_name'];
    } else {
        unset($_SESSION['shop_id']);
        unset($_SESSION['shop_name']);
        $user['shop_id']       = null;
        $user['shop_name']     = null;
    }

    unset($user['password']); // Hapus password dari objek sebelum dikirim ke frontend

    // 3. Tentukan Arah Redirect
    $redirectUrl = "../buyer/index.html";
    if (strtolower($user['role'] ?? '') === 'admin') {
        $redirectUrl = "../admin/index.html";
    }

    json_response(true, "Login berhasil!", [
        "redirect" => $redirectUrl,
        "user"     => $user
    ], 200);

} catch (PDOException $e) {
    error_log("Login error: " . $e->getMessage());
    json_response(false, "Terjadi gangguan saat memproses login. Silakan coba lagi.", null, 500);
}
?>