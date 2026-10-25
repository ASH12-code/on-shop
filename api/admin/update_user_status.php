<?php
require_once __DIR__ . '/../helpers/auth.php';
require_role('admin');
// C:/laragon/www/on-shop/api/admin/update_user_status.php
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

require_once __DIR__ . '/../../config/database.php';

$db = isset($conn) ? $conn : (isset($pdo) ? $pdo : null);
if (!$db) {
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Koneksi ke database terputus."]);
    exit;
}

$raw = file_get_contents("php://input");
$input = json_decode($raw, true);

if (!$input || empty($input['user_id']) || empty($input['action'])) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "Data permintaan tidak lengkap."]);
    exit;
}

$userId = (int)$input['user_id'];
$action = $input['action']; // 'ban' | 'unban'

try {
    if ($action === 'ban') {
        $reason = trim($input['reason'] ?? 'Pelanggaran ketentuan penggunaan marketplace.');
        $stmt = $db->prepare("UPDATE users SET is_banned = 1, banned_reason = ?, banned_at = NOW() WHERE user_id = ?");
        $stmt->execute([$reason, $userId]);

        echo json_encode([
            "status"  => true,
            "message" => "Pengguna berhasil diblokir dari sistem."
        ]);
        exit;

    } elseif ($action === 'unban') {
        $stmt = $db->prepare("UPDATE users SET is_banned = 0, banned_reason = NULL, banned_at = NULL WHERE user_id = ?");
        $stmt->execute([$userId]);

        echo json_encode([
            "status"  => true,
            "message" => "Pemblokiran akun pengguna telah berhasil dibuka!"
        ]);
        exit;

    } else {
        echo json_encode(["status" => false, "message" => "Aksi tidak dikenali."]);
        exit;
    }

} catch (PDOException $e) {
    error_log("Admin API Error in " . basename(__FILE__) . ": " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Terjadi kesalahan pada sistem."]);
    exit;
}