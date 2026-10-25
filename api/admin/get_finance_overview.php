<?php
require_once __DIR__ . '/../helpers/auth.php';
require_role('admin');
// C:/laragon/www/on-shop/api/admin/get_finance_overview.php
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

require_once __DIR__ . '/../../config/database.php';

$db = isset($conn) ? $conn : (isset($pdo) ? $pdo : null);
if (!$db) {
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Koneksi database gagal."]);
    exit;
}

$db->exec("SET SESSION sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");

try {
    // 1. Saldo Escrow Platform (status pesanan DIKEMAS & DIKIRIM)
    $stmtEscrow = $db->query("
        SELECT COALESCE(SUM(gross_product_amount), 0) AS escrow_total 
        FROM sub_orders 
        WHERE order_status IN ('DIKEMAS', 'DIKIRIM')
    ");
    $escrowTotal = (float)$stmtEscrow->fetchColumn();

    // 2. Komisi Bersih Sah Platform 5% (status pesanan SELESAI)
    $stmtComm = $db->query("
        SELECT COALESCE(SUM(gross_product_amount * 0.05), 0) AS platform_comm 
        FROM sub_orders 
        WHERE order_status = 'SELESAI'
    ");
    $commissionTotal = (float)$stmtComm->fetchColumn();

    // 3. Total Nominal Payout Menunggu Persetujuan & Jumlah Antreannya
    $stmtPendingWD = $db->query("
        SELECT 
            COALESCE(SUM(amount), 0) AS total_pending,
            COUNT(withdrawal_id) AS count_pending
        FROM withdrawals 
        WHERE status = 'PENDING'
    ");
    $pendingWD = $stmtPendingWD->fetch(PDO::FETCH_ASSOC);
    $pendingAmount = (float)$pendingWD['total_pending'];
    $pendingCount  = (int)$pendingWD['count_pending'];

    // 4. Total Payout Berhasil Ditransfer
    $stmtSuccessWD = $db->query("
        SELECT COALESCE(SUM(amount), 0) AS total_success 
        FROM withdrawals 
        WHERE status = 'SUCCESS'
    ");
    $successAmount = (float)$stmtSuccessWD->fetchColumn();

    // 5. Daftar Seluruh Antrean Penarikan (Tabel Withdrawals + Shops)
    $stmtList = $db->query("
        SELECT 
            w.withdrawal_id,
            w.shop_id,
            w.amount,
            w.bank_name,
            w.bank_account_number,
            w.status,
            DATE_FORMAT(w.created_at, '%Y-%m-%d %H:%i:%s') AS created_at,
            s.shop_name,
            u.name AS owner_name
        FROM withdrawals w
        JOIN shops s ON w.shop_id = s.shop_id
        JOIN users u ON s.user_id = u.user_id
        ORDER BY w.withdrawal_id DESC
    ");
    $withdrawals = $stmtList->fetchAll(PDO::FETCH_ASSOC);

    // 6. Catatan Mutasi Kas Global Terkini (wallet_transactions + wallets + users + shops)
    $stmtMutations = $db->query("
        SELECT 
            wt.transaction_id,
            wt.type,
            wt.amount,
            wt.description,
            DATE_FORMAT(wt.created_at, '%Y-%m-%d %H:%i:%s') AS created_at,
            COALESCE(s.shop_name, u.name, 'Akun Pengguna') AS entity_name,
            u.role AS user_role
        FROM wallet_transactions wt
        JOIN wallets w ON wt.wallet_id = w.wallet_id
        JOIN users u ON w.user_id = u.user_id
        LEFT JOIN shops s ON u.user_id = s.user_id
        ORDER BY wt.transaction_id DESC
        LIMIT 30
    ");
    $mutations = $stmtMutations->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => true,
        "data"   => [
            "kpi" => [
                "escrow_total"     => $escrowTotal,
                "commission_total" => $commissionTotal,
                "pending_amount"   => $pendingAmount,
                "pending_count"    => $pendingCount,
                "success_amount"   => $successAmount
            ],
            "withdrawals" => $withdrawals,
            "mutations"   => $mutations
        ]
    ]);
    exit;

} catch (PDOException $e) {
    error_log("Admin API Error in " . basename(__FILE__) . ": " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Terjadi kesalahan pada sistem."]);
    exit;
}