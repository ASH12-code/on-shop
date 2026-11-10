<?php
// api/helpers/auth.php

if (session_status() === PHP_SESSION_NONE) {
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.cookie_samesite', 'Lax');
    @session_start();
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
 * Memastikan pengguna telah login dan akun tidak diblokir (is_banned = 0).
 * Jika belum login kirim 401, jika diblokir kirim 403.
 * 
 * @return int User ID yang terautentikasi
 */
function require_auth(): int {
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId || !is_numeric($userId) || (int)$userId <= 0) {
        json_response(false, "Sesi login telah berakhir atau belum login. Silakan login kembali.", null, 401);
    }

    $uid = (int)$userId;
    $db = get_db_connection();
    if ($db) {
        $stmt = $db->prepare("SELECT is_banned, banned_reason FROM users WHERE user_id = ? LIMIT 1");
        $stmt->execute([$uid]);
        $uRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($uRow && !empty($uRow['is_banned']) && (int)$uRow['is_banned'] === 1) {
            $_SESSION = [];
            $reason = !empty($uRow['banned_reason']) ? $uRow['banned_reason'] : "Pelanggaran ketentuan layanan.";
            json_response(false, "Akun Anda telah ditangguhkan oleh Admin: " . $reason, null, 403);
        }
    }

    return $uid;
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
 * Mendapatkan shop_id dari sesi aktif atau basis data jika user memiliki toko sah dan tidak dibekukan
 * 
 * @return int shop_id
 */
function require_seller_shop(): int {
    $userId = require_auth();
    
    $db = get_db_connection();
    
    // Selalu validasi ke database bahwa shop_id benar-benar milik user_id sesi dan berstatus aktif
    if ($db) {
        $stmt = $db->prepare("SELECT shop_id, shop_name, status, suspension_reason FROM shops WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $shop = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($shop && !empty($shop['shop_id'])) {
            $status = strtolower(trim($shop['status'] ?? ''));
            if (in_array($status, ['banned', 'suspended', 'rejected'], true)) {
                $reason = !empty($shop['suspension_reason']) ? $shop['suspension_reason'] : "Toko sedang dibekukan atau ditolak oleh Admin.";
                json_response(false, "Akses toko ditangguhkan: " . $reason, null, 403);
            }

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
