<?php
require_once __DIR__ . '/../helpers/auth.php';
require_role('admin');
// C:/laragon/www/on-shop/api/admin/get_sidebar_badges.php
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// Sesuaikan path ke database.php
require_once __DIR__ . '/../../config/database.php';

$db = isset($conn) ? $conn : (isset($pdo) ? $pdo : null);
if (!$db) {
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Database error"]);
    exit;
}

try {
    // 1. Toko Menunggu Verifikasi
    $stmtShop = $db->query("SELECT COUNT(*) FROM shops WHERE status = 'PENDING'");
    $pendingShops = (int)$stmtShop->fetchColumn();

    // 2. Payout Menunggu Persetujuan
    $stmtPayout = $db->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'PENDING'");
    $pendingPayouts = (int)$stmtPayout->fetchColumn();

    // 3. Kasus Bantuan/Sengketa Menunggu Tindakan
    $pendingDisputes = 0;
    try {
        $stmtDispute = $db->query("SELECT COUNT(*) FROM disputes WHERE status = 'PENDING'");
        $pendingDisputes = (int)$stmtDispute->fetchColumn();
    } catch (Exception $e) {
        $pendingDisputes = 0;
    }

    echo json_encode([
        "status" => true,
        "data"   => [
            "pending_shops"    => $pendingShops,
            "pending_payouts"  => $pendingPayouts,
            "pending_disputes" => $pendingDisputes
        ]
    ]);
    exit;

} catch (PDOException $e) {
    error_log("Admin API Error in " . basename(__FILE__) . ": " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Terjadi kesalahan pada sistem."]);
    exit;
}