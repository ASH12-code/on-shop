<?php
require_once __DIR__ . '/../helpers/auth.php';
require_role('admin');
// C:/laragon/www/on-shop/api/admin/get_sidebar_counters.php
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

require_once __DIR__ . '/../../config/database.php';
$db = isset($conn) ? $conn : (isset($pdo) ? $pdo : null);

if (!$db) {
    echo json_encode(["status" => false, "message" => "Database offline."]);
    exit;
}

try {
    // 1. Sengketa aktif (PENDING_REVIEW)
    $stmtDisputes = $db->query("SELECT COUNT(dispute_id) FROM disputes WHERE dispute_status = 'PENDING_REVIEW'");
    $openDisputes = (int)$stmtDisputes->fetchColumn();

    // 2. Payout Pending
    $pendingPayouts = 0;
    try {
        $stmtPayout = $db->query("SELECT COUNT(withdrawal_id) FROM withdrawals WHERE status = 'PENDING'");
        $pendingPayouts = (int)$stmtPayout->fetchColumn();
    } catch (Exception $e) {
        $pendingPayouts = 0;
    }

    echo json_encode([
        "status" => true,
        "data"   => [
            "open_disputes"   => $openDisputes,
            "pending_payouts" => $pendingPayouts,
            "pending_shops"   => 0
        ]
    ]);
    exit;

} catch (PDOException $e) {
    error_log("Admin API Error in " . basename(__FILE__) . ": " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Terjadi kesalahan pada sistem."]);
    exit;
}