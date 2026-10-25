<?php
require_once __DIR__ . '/../helpers/auth.php';
require_role('admin');
// C:/laragon/www/on-shop/api/admin/process_dispute_verdict.php
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
    echo json_encode(["status" => false, "message" => "Koneksi database terputus."]);
    exit;
}

$raw = file_get_contents("php://input");
$input = json_decode($raw, true);

if (!$input || empty($input['dispute_id']) || empty($input['verdict'])) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "Parameter sengketa tidak lengkap."]);
    exit;
}

$disputeId = (int)$input['dispute_id'];
$verdict   = $input['verdict']; // 'refund' | 'release'
$notes     = trim($input['notes'] ?? 'Keputusan vonis admin marketplace.');

try {
    $db->beginTransaction();

    // 1. Ambil detail tiket sengketa
    $stmt = $db->prepare("
        SELECT d.*, s.user_id AS seller_user_id, s.shop_name, u.name AS buyer_name, u.user_code AS buyer_code
        FROM disputes d
        JOIN shops s ON d.shop_id = s.shop_id
        JOIN users u ON d.buyer_id = u.user_id
        WHERE d.dispute_id = ? FOR UPDATE
    ");
    $stmt->execute([$disputeId]);
    $dispute = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dispute) {
        $db->rollBack();
        echo json_encode(["status" => false, "message" => "Data sengketa tidak ditemukan."]);
        exit;
    }

    if (in_array($dispute['status'], ['REFUNDED', 'RELEASED_TO_SELLER'])) {
        $db->rollBack();
        echo json_encode(["status" => false, "message" => "Kasus ini telah diputuskan sebelumnya."]);
        exit;
    }

    // 2. VONIS A: REFUND DANA KE PEMBELI (Hak Pembeli Dimenangkan)
    if ($verdict === 'refund') {
        // Update status sengketa
        $updDispute = $db->prepare("
            UPDATE disputes 
            SET status = 'REFUNDED', admin_verdict_notes = ?, resolved_at = NOW() 
            WHERE dispute_id = ?
        ");
        $updDispute->execute([$notes, $disputeId]);

        // Ubah sub_order menjadi BATAL/DIKOMPLAIN
        $db->prepare("UPDATE sub_orders SET order_status = 'BATAL' WHERE sub_order_id = ?")
           ->execute([$dispute['sub_order_id']]);

        // Buat atau tambahkan dana ke wallet pembeli
        $db->prepare("
            INSERT INTO wallets (user_id, available_balance, pending_balance) 
            VALUES (?, ?, 0) 
            ON DUPLICATE KEY UPDATE available_balance = available_balance + ?
        ")->execute([$dispute['buyer_id'], $dispute['amount'], $dispute['amount']]);

        // Catat mutasi kas dompet pembeli
        $stmtW = $db->prepare("SELECT wallet_id FROM wallets WHERE user_id = ?");
        $stmtW->execute([$dispute['buyer_id']]);
        $buyerWalletId = $stmtW->fetchColumn();

        if ($buyerWalletId) {
            $db->prepare("
                INSERT INTO wallet_transactions (wallet_id, type, amount, description) 
                VALUES (?, 'REFUND', ?, ?)
            ")->execute([$buyerWalletId, $dispute['amount'], "Pengembalian dana sengketa " . $dispute['ticket_code'] . ": " . $notes]);
        }

        $db->commit();
        echo json_encode([
            "status"  => true,
            "message" => "Vonis Selesai: Dana Rp " . number_format($dispute['amount'], 0, ',', '.') . " telah dikembalikan ke saldo pembeli " . $dispute['buyer_name'] . " (" . $dispute['buyer_code'] . ")!"
        ]);
        exit;
    }

    // 3. VONIS B: TERUSKAN DANA KE TOKO (Komplain Ditolak, Toko Menang)
    elseif ($verdict === 'release') {
        // Update status sengketa
        $updDispute = $db->prepare("
            UPDATE disputes 
            SET status = 'RELEASED_TO_SELLER', admin_verdict_notes = ?, resolved_at = NOW() 
            WHERE dispute_id = ?
        ");
        $updDispute->execute([$notes, $disputeId]);

        // Ubah status sub_order menjadi SELESAI
        $db->prepare("UPDATE sub_orders SET order_status = 'SELESAI', delivered_at = NOW() WHERE sub_order_id = ?")
           ->execute([$dispute['sub_order_id']]);

        // Ambil nominal bersih penjual (setelah komisi 5%)
        $stmtSub = $db->prepare("SELECT net_seller_amount FROM sub_orders WHERE sub_order_id = ?");
        $stmtSub->execute([$dispute['sub_order_id']]);
        $netSeller = (float)$stmtSub->fetchColumn();
        if ($netSeller <= 0) {
            $netSeller = (float)$dispute['amount'] * 0.95;
        }

        // Teruskan dana ke wallet toko
        $db->prepare("
            INSERT INTO wallets (user_id, available_balance, pending_balance) 
            VALUES (?, ?, 0) 
            ON DUPLICATE KEY UPDATE available_balance = available_balance + ?
        ")->execute([$dispute['seller_user_id'], $netSeller, $netSeller]);

        // Catat mutasi kas toko
        $stmtW = $db->prepare("SELECT wallet_id FROM wallets WHERE user_id = ?");
        $stmtW->execute([$dispute['seller_user_id']]);
        $sellerWalletId = $stmtW->fetchColumn();

        if ($sellerWalletId) {
            $db->prepare("
                INSERT INTO wallet_transactions (wallet_id, type, amount, description) 
                VALUES (?, 'INCOME_SALES', ?, ?)
            ")->execute([$sellerWalletId, $netSeller, "Pelepasan dana mediasi tiket " . $dispute['ticket_code'] . " ke toko " . $dispute['shop_name']]);
        }

        $db->commit();
        echo json_encode([
            "status"  => true,
            "message" => "Vonis Selesai: Komplain ditolak, dana bersih Rp " . number_format($netSeller, 0, ',', '.') . " diteruskan ke dompet toko " . $dispute['shop_name'] . "!"
        ]);
        exit;
    }

    $db->rollBack();
    echo json_encode(["status" => false, "message" => "Jenis vonis tidak dikenali."]);
    exit;

} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Admin API Error in " . basename(__FILE__) . ": " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Terjadi kesalahan pada sistem."]);
    exit;
}
