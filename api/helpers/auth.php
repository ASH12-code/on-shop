<?php
// api/helpers/auth.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/response.php';
require_once __DIR__ . '/../../config/database.php';

/**
 * Mendapatkan koneksi database PDO
 */
function get_db_connection() {
    global $conn, $pdo;
    return isset($conn) ? $conn : (isset($pdo) ? $pdo : null);
}

/**
 * Memastikan pengguna telah login. Jika belum, kirim respon 401 Unauthorized.
 * 
 * @return int User ID yang terautentikasi
 */
function require_auth(): int {
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId || !is_numeric($userId) || (int)$userId <= 0) {
        json_response(false, "Sesi login telah berakhir atau belum login. Silakan login kembali.", null, 401);
    }
    return (int)$userId;
}

/**
 * Memeriksa apakah user yang sedang login memiliki role tertentu.
 * Jika tidak sesuai, kirim respon 403 Forbidden.
 * 
 * @param string|array $allowedRoles Role tunggal atau array role yang diizinkan (misal: 'admin' atau ['seller', 'admin'])
 * @return string Role pengguna saat ini
 */
function require_role($allowedRoles): string {
    require_auth();
    $currentRole = strtolower(trim($_SESSION['role'] ?? ''));
    
    if (is_array($allowedRoles)) {
        $allowed = array_map(function($r) { return strtolower(trim($r)); }, $allowedRoles);
        if (!in_array($currentRole, $allowed, true)) {
            json_response(false, "Akses ditolak. Anda tidak memiliki izin untuk mengakses resource ini.", null, 403);
        }
    } else {
        if ($currentRole !== strtolower(trim($allowedRoles))) {
            json_response(false, "Akses ditolak. Anda tidak memiliki izin untuk mengakses resource ini.", null, 403);
        }
    }
    
    return $currentRole;
}

/**
 * Mendapatkan user_id dari sesi aktif
 */
function get_current_user_id(): ?int {
    $uid = $_SESSION['user_id'] ?? null;
    return ($uid && is_numeric($uid)) ? (int)$uid : null;
}

/**
 * Mendapatkan role dari sesi aktif
 */
function get_current_role(): ?string {
    return isset($_SESSION['role']) ? (string)$_SESSION['role'] : null;
}

/**
 * Mendapatkan shop_id dari sesi aktif atau basis data jika user memiliki toko sah
 * 
 * @return int shop_id
 */
function require_seller_shop(): int {
    $userId = require_auth();
    
    $shopId = $_SESSION['shop_id'] ?? null;
    $db = get_db_connection();
    
    // Selalu validasi ke database bahwa shop_id benar-benar milik user_id sesi
    if ($db) {
        $stmt = $db->prepare("SELECT shop_id, shop_name, status FROM shops WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $shop = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($shop && !empty($shop['shop_id'])) {
            $shopId = (int)$shop['shop_id'];
            $_SESSION['shop_id'] = $shopId;
            $_SESSION['shop_name'] = $shop['shop_name'];
            return $shopId;
        }
    }
    
    json_response(false, "Akses ditolak. Akun Anda belum memiliki toko aktif atau toko tidak ditemukan.", null, 403);
    exit;
}

/**
 * Mendapatkan shop_id jika ada
 */
function get_current_shop_id(): ?int {
    return isset($_SESSION['shop_id']) ? (int)$_SESSION['shop_id'] : null;
}
?>
