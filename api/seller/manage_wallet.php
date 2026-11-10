<?php
// C:/laragon/www/on-shop/api/seller/manage_wallet.php
header('Content-Type: application/json; charset=UTF-8');

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/seller_auth.php'; // Menyediakan ($db, $user_id, $shop_id)

$action = $_GET['action'] ?? 'get_wallet';
$ADMIN_FEE = 0; // Biaya admin penarikan diset 0 atau sesuaikan kebutuhan

// -------------------------------------------------------------
// 1. AMBIL SALDO DOMPET, ESCROW, REKENING BANK & RIWAYAT MUTASI
// -------------------------------------------------------------
if ($action === 'get_wallet') {
    $availableBalance = 0.0;
    $escrowBalance = 0.0;
    $walletId = null;

    // A. Ambil atau Buat Wallet Toko
    try {
        $stmtWallet = $db->prepare("SELECT wallet_id, available_balance FROM wallets WHERE user_id = ?");
        $stmtWallet->execute([$user_id]);
        $rowW = $stmtWallet->fetch(PDO::FETCH_ASSOC);

        if ($rowW) {
            $walletId = (int)$rowW['wallet_id'];
            $availableBalance = (float)$rowW['available_balance'];
        } else {
            // Hitung akumulasi pesanan selesai toko jika belum ada wallet
            $stmtSum = $db->prepare("
                SELECT COALESCE(SUM(net_seller_amount), 0) 
                FROM sub_orders 
                WHERE shop_id = ? AND order_status = 'SELESAI'
            ");
            $stmtSum->execute([$shop_id]);
            $availableBalance = (float)$stmtSum->fetchColumn();

            $db->prepare("INSERT INTO wallets (user_id, available_balance, pending_balance) VALUES (?, ?, 0)")
               ->execute([$user_id, $availableBalance]);
            $walletId = (int)$db->lastInsertId();
        }
    } catch (Exception $e) {
        $availableBalance = 0.0;
    }

    // B. Hitung Saldo Tertahan (Escrow) dari pesanan DIKEMAS & DIKIRIM
    try {
        $stmtEscrow = $db->prepare("
            SELECT COALESCE(SUM(gross_product_amount * 0.95), 0)
            FROM sub_orders
            WHERE shop_id = ? AND order_status IN ('DIKEMAS', 'DIKIRIM')
        ");
        $stmtEscrow->execute([$shop_id]);
        $escrowBalance = (float)$stmtEscrow->fetchColumn();
    } catch (Exception $e) {
        $escrowBalance = 0.0;
    }

    // C. Ambil Rekening Bank Asli Toko dari tabel shops & users
    $bankAccounts = [];
    try {
        $stmtShop = $db->prepare("
            SELECT s.bank_name, s.bank_account_number, s.shop_name, u.name AS owner_name 
            FROM shops s
            JOIN users u ON s.user_id = u.user_id
            WHERE s.shop_id = ?
        ");
        $stmtShop->execute([$shop_id]);
        $shopData = $stmtShop->fetch(PDO::FETCH_ASSOC);

        if ($shopData && !empty($shopData['bank_account_number'])) {
            $bankAccounts[] = [
                'id'        => 1,
                'bank'      => $shopData['bank_name'] ?: 'Bank BCA',
                'number'    => $shopData['bank_account_number'],
                'holder'    => $shopData['owner_name'] ?: $shopData['shop_name'],
                'isPrimary' => true
            ];
        } else {
            $bankAccounts[] = [
                'id'        => 1,
                'bank'      => 'Belum diatur',
                'number'    => '-',
                'holder'    => $shopData['shop_name'] ?? 'Pemilik Toko',
                'isPrimary' => true
            ];
        }
    } catch (Exception $e) {
        // Fallback default
    }

    // D. Ambil Riwayat Mutasi Dompet Sesuai Skema Asli MySQL
    $history = [];
    if ($walletId) {
        try {
            $stmtHist = $db->prepare("
                SELECT 
                    wt.transaction_id,
                    CONCAT('TRX-', wt.transaction_id) AS transaction_code,
                    wt.type AS transaction_type,
                    wt.amount,
                    wt.description AS bank_info,
                    'SUCCESS' AS status,
                    DATE_FORMAT(wt.created_at, '%Y-%m-%d %H:%i') AS date_formatted
                FROM wallet_transactions wt
                WHERE wt.wallet_id = ?
                ORDER BY wt.transaction_id DESC
                LIMIT 15
            ");
            $stmtHist->execute([$walletId]);
            $history = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $history = [];
        }
    }

    echo json_encode([
        "status"            => true,
        "available_balance" => $availableBalance,
        "escrow_balance"    => $escrowBalance,
        "admin_fee"         => $ADMIN_FEE,
        "bank_accounts"     => $bankAccounts,
        "history"           => $history
    ]);
    exit;
}

// -------------------------------------------------------------
// 2. AJUKAN PENARIKAN SALDO KE ADMIN (TABEL WITHDRAWALS)
// -------------------------------------------------------------
if ($action === 'submit_withdraw') {
    $input = json_decode(file_get_contents('php://input'), true);
    $amount = (float)($input['amount'] ?? 0);

    if ($amount < 50000) {
        echo json_encode(["status" => false, "message" => "Nominal penarikan minimal adalah Rp 50.000!"]);
        exit;
    }

    try {
        $db->beginTransaction();

        // 1. Ambil data rekening toko
        $stmtShop = $db->prepare("SELECT bank_name, bank_account_number, shop_name FROM shops WHERE shop_id = ?");
        $stmtShop->execute([$shop_id]);
        $shop = $stmtShop->fetch(PDO::FETCH_ASSOC);

        if (!$shop || empty($shop['bank_account_number'])) {
            $db->rollBack();
            echo json_encode(["status" => false, "message" => "Rekening bank toko belum diatur!"]);
            exit;
        }

        // 2. Cek Saldo Dompet
        $stmtW = $db->prepare("SELECT wallet_id, available_balance FROM wallets WHERE user_id = ? FOR UPDATE");
        $stmtW->execute([$user_id]);
        $wallet = $stmtW->fetch(PDO::FETCH_ASSOC);

        if (!$wallet || (float)$wallet['available_balance'] < $amount) {
            $db->rollBack();
            echo json_encode([
                "status"  => false,
                "message" => "Saldo tidak mencukupi! Saldo Anda: Rp " . number_format($wallet['available_balance'] ?? 0, 0, ',', '.')
            ]);
            exit;
        }

        // 3. Potong Saldo Toko
        $stmtDeduct = $db->prepare("UPDATE wallets SET available_balance = available_balance - ? WHERE wallet_id = ?");
        $stmtDeduct->execute([$amount, $wallet['wallet_id']]);

        // 4. Masukkan ke tabel withdrawals (INI KUNCI AGAR MASUK KE ADMIN)
        $stmtWd = $db->prepare("
            INSERT INTO withdrawals (shop_id, amount, bank_name, bank_account_number, status, created_at)
            VALUES (?, ?, ?, ?, 'PENDING', NOW())
        ");
        $stmtWd->execute([
            $shop_id,
            $amount,
            $shop['bank_name'],
            $shop['bank_account_number']
        ]);
        $wdId = $db->lastInsertId();

        $db->commit();

        echo json_encode([
            "status"           => true,
            "message"          => "Pengajuan penarikan dana Rp " . number_format($amount, 0, ',', '.') . " berhasil diajukan ke admin!",
            "transaction_code" => "WD-" . $wdId
        ]);
        exit;

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Seller manage_wallet withdraw error: " . $e->getMessage());
        echo json_encode(["status" => false, "message" => "Terjadi kesalahan sistem saat memproses penarikan dana."]);
        exit;
    }
}

// -------------------------------------------------------------
// 3. SIMPAN PERUBAHAN REKENING TOKO
// -------------------------------------------------------------
if ($action === 'save_bank_account') {
    $input = json_decode(file_get_contents('php://input'), true);
    $bankName = trim($input['bank_name'] ?? 'BCA');
    $accNum   = trim($input['account_number'] ?? '');

    if (empty($accNum)) {
        echo json_encode(["status" => false, "message" => "Nomor rekening wajib diisi!"]);
        exit;
    }

    try {
        $stmtUpd = $db->prepare("UPDATE shops SET bank_name = ?, bank_account_number = ? WHERE shop_id = ?");
        $stmtUpd->execute([$bankName, $accNum, $shop_id]);

        echo json_encode([
            "status"  => true,
            "message" => "Rekening bank toko berhasil diperbarui!"
        ]);
        exit;
    } catch (Exception $e) {
        error_log("Seller manage_wallet bank error: " . $e->getMessage());
        echo json_encode(["status" => false, "message" => "Terjadi kesalahan sistem saat menyimpan rekening."]);
        exit;
    }
}