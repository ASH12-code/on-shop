<?php
require_once __DIR__ . '/../helpers/auth.php';
require_role('admin');
// C:/laragon/www/on-shop/api/admin/process_payout.php
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

if (!$input || empty($input['withdrawal_id']) || empty($input['action'])) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "Data permintaan tidak lengkap."]);
    exit;
}

$withdrawalId = (int)$input['withdrawal_id'];
$action       = $input['action']; // 'approve' | 'reject'

try {
    $db->beginTransaction();

    // 1. Ambil detail penarikan
    $stmt = $db->prepare("
        SELECT w.*, s.user_id, s.shop_name 
        FROM withdrawals w
        JOIN shops s ON w.shop_id = s.shop_id
        WHERE w.withdrawal_id = ? FOR UPDATE
    ");
    $stmt->execute([$withdrawalId]);
    $wd = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$wd) {
        $db->rollBack();
        echo json_encode(["status" => false, "message" => "Data penarikan tidak ditemukan."]);
        exit;
    }

    if ($wd['status'] !== 'PENDING') {
        $db->rollBack();
        echo json_encode(["status" => false, "message" => "Penarikan ini sudah diproses sebelumnya."]);
        exit;
    }

    // 2. Proses Approval (Setujui dan tandai sukses)
    if ($action === 'approve') {
        $updateWd = $db->prepare("UPDATE withdrawals SET status = 'SUCCESS' WHERE withdrawal_id = ?");
        $updateWd->execute([$withdrawalId]);

        // Catat mutasi kas penarikan berhasil
        $stmtWallet = $db->prepare("SELECT wallet_id FROM wallets WHERE user_id = ?");
        $stmtWallet->execute([$wd['user_id']]);
        $walletId = $stmtWallet->fetchColumn();

        if ($walletId) {
            $logStmt = $db->prepare("
                INSERT INTO wallet_transactions (wallet_id, type, amount, description) 
                VALUES (?, 'WITHDRAW', ?, ?)
            ");
            $desc = "Pencairan saldo ke rekening " . $wd['bank_name'] . " - " . $wd['bank_account_number'];
            $logStmt->execute([$walletId, $wd['amount'], $desc]);
        }

        $db->commit();
        echo json_encode(["status" => true, "message" => "Payout untuk toko " . $wd['shop_name'] . " berhasil disetujui & ditandai ditransfer!"]);
        exit;
    }

    // 3. Proses Penolakan (Reject & kembalikan saldo ke available_balance dompet toko)
    elseif ($action === 'reject') {
        $reason = trim($input['reason'] ?? 'Penolakan pencairan oleh admin.');

        $updateWd = $db->prepare("UPDATE withdrawals SET status = 'REJECTED' WHERE withdrawal_id = ?");
        $updateWd->execute([$withdrawalId]);

        // Kembalikan dana ke available balance toko
        $returnStmt = $db->prepare("
            UPDATE wallets 
            SET available_balance = available_balance + ? 
            WHERE user_id = ?
        ");
        $returnStmt->execute([$wd['amount'], $wd['user_id']]);

        // Catat pengembalian ke mutasi dompet
        $stmtWallet = $db->prepare("SELECT wallet_id FROM wallets WHERE user_id = ?");
        $stmtWallet->execute([$wd['user_id']]);
        $walletId = $stmtWallet->fetchColumn();

        if ($walletId) {
            $logStmt = $db->prepare("
                INSERT INTO wallet_transactions (wallet_id, type, amount, description) 
                VALUES (?, 'REFUND', ?, ?)
            ");
            $desc = "Pengembalian dana penarikan ditolak: " . $reason;
            $logStmt->execute([$walletId, $wd['amount'], $desc]);
        }

        $db->commit();
        echo json_encode(["status" => true, "message" => "Permintaan penarikan ditolak dan saldo telah dikembalikan ke dompet toko."]);
        exit;
    }

    $db->rollBack();
    echo json_encode(["status" => false, "message" => "Aksi tidak dikenali."]);
    exit;

} catch (PDOException $e) {
    error_log("Admin API Error in " . basename(__FILE__) . ": " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Terjadi kesalahan pada sistem."]);
    exit;
}
