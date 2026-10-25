<?php
require_once __DIR__ . '/../helpers/auth.php';
require_role('admin');
// C:/laragon/www/on-shop/api/admin/update_merchant_status.php
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

if (!$input || empty($input['shop_id']) || empty($input['action'])) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "Data permintaan tidak lengkap."]);
    exit;
}

$shopId = (int)$input['shop_id'];
$action = $input['action'];

try {
    if ($action === 'toggle_official') {
        // Balikkan nilai boolean is_official (0 jadi 1, 1 jadi 0)
        $stmt = $db->prepare("UPDATE shops SET is_official = IF(is_official = 1, 0, 1) WHERE shop_id = ?");
        $stmt->execute([$shopId]);

        // Cek status terbarunya
        $checkStmt = $db->prepare("SELECT is_official, shop_name FROM shops WHERE shop_id = ?");
        $checkStmt->execute([$shopId]);
        $shop = $checkStmt->fetch(PDO::FETCH_ASSOC);

        $statusText = ($shop && $shop['is_official'] == 1) ? "dijadikan Official Mall ★" : "diturunkan dari Official Mall";
        echo json_encode([
            "status" => true,
            "message" => "Toko " . ($shop['shop_name'] ?? '') . " berhasil " . $statusText . "!"
        ]);
        exit;

    } elseif ($action === 'approve') {
        $stmt = $db->prepare("UPDATE shops SET status = 'verified', suspended_until = NULL, suspension_reason = NULL WHERE shop_id = ?");
        $stmt->execute([$shopId]);
        echo json_encode(["status" => true, "message" => "Toko berhasil diverifikasi dan aktif berjualan!"]);
        exit;

    } elseif ($action === 'reject') {
        $reason = trim($input['reason'] ?? 'Berkas pendaftaran tidak memenuhi syarat.');
        $stmt = $db->prepare("UPDATE shops SET status = 'rejected', suspension_reason = ? WHERE shop_id = ?");
        $stmt->execute([$reason, $shopId]);
        echo json_encode(["status" => true, "message" => "Pendaftaran toko ditolak."]);
        exit;

    } elseif ($action === 'suspend') {
        $type = $input['type'] ?? 'temporary';
        $days = (int)($input['days'] ?? 7);
        $reason = trim($input['reason'] ?? 'Pelanggaran ketentuan marketplace.');

        $until = null;
        if ($type === 'temporary') {
            $until = date('Y-m-d H:i:s', strtotime("+$days days"));
        }

        $stmt = $db->prepare("UPDATE shops SET status = 'suspended', suspended_until = ?, suspension_reason = ? WHERE shop_id = ?");
        $stmt->execute([$until, $reason, $shopId]);
        echo json_encode([
            "status" => true,
            "message" => "Toko berhasil dibekukan (" . ($type === 'temporary' ? "$days hari" : "Permanen") . ")."
        ]);
        exit;

    } elseif ($action === 'unsuspend') {
        $stmt = $db->prepare("UPDATE shops SET status = 'verified', suspended_until = NULL, suspension_reason = NULL WHERE shop_id = ?");
        $stmt->execute([$shopId]);
        echo json_encode(["status" => true, "message" => "Pembekuan toko berhasil dicabut!"]);
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