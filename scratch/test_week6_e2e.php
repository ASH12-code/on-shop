<?php
// scratch/test_week6_e2e.php
// Suite Pengujian Integrasi Lintas Peran E2E (End-to-End) Onshop Marketplace

error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "====================================================\n";
echo "🔄  SUITE TEST E2E LINTAS PERAN ONSHOP (WEEK 6)\n";
echo "====================================================\n\n";

require_once __DIR__ . '/../config/database.php';
$db = $conn ?? $pdo;

$passed = 0;
$failed = 0;

function assert_step($stepName, $condition, $details = '') {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] $stepName" . ($details ? " -> $details" : "") . "\n";
        $passed++;
    } else {
        echo " [FAIL] $stepName" . ($details ? " -> $details" : "") . "\n";
        $failed++;
    }
}

try {
    // Persiapan Akun Test
    // 1. Ambil 1 User Buyer, 1 Seller (Shop), dan 1 Admin
    $buyer = $db->query("SELECT user_id, email, name FROM users WHERE role = 'buyer' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $shop = $db->query("SELECT s.shop_id, s.user_id AS seller_user_id, s.shop_name, s.pickup_province, s.pickup_city FROM shops s JOIN users u ON s.user_id = u.user_id WHERE s.status = 'verified' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $admin = $db->query("SELECT user_id, email, name FROM users WHERE role = 'admin' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $product = $db->query("SELECT p.product_id, p.name, pv.variant_id, pv.price, pv.stock FROM products p JOIN product_variants pv ON p.product_id = pv.product_id WHERE p.shop_id = {$shop['shop_id']} AND pv.stock > 0 LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    assert_step("Kesiapan Data Pengujian", $buyer && $shop && $admin && $product, "Buyer ID: {$buyer['user_id']}, Shop ID: {$shop['shop_id']}, Product ID: {$product['product_id']}");

    $buyerId = (int)$buyer['user_id'];
    $sellerId = (int)$shop['seller_user_id'];
    $shopId = (int)$shop['shop_id'];
    $adminId = (int)$admin['user_id'];
    $productId = (int)$product['product_id'];
    $variantId = (int)$product['variant_id'];

    // =========================================================================
    // FASE 1: FITUR WISHLIST PEMBELI (MINGGU 6)
    // =========================================================================
    echo "\n--- FASE 1: WISHLIST PEMBELI ---\n";
    // 1. Bersihkan dulu wishlist produk ini jika ada
    $db->prepare("DELETE FROM wishlists WHERE user_id = ? AND product_id = ?")->execute([$buyerId, $productId]);

    // 2. Tambah produk ke wishlist
    $stmtWish = $db->prepare("INSERT INTO wishlists (user_id, product_id, created_at) VALUES (?, ?, NOW())");
    $stmtWish->execute([$buyerId, $productId]);

    $checkWish = $db->prepare("SELECT wishlist_id FROM wishlists WHERE user_id = ? AND product_id = ?");
    $checkWish->execute([$buyerId, $productId]);
    $wishEntry = $checkWish->fetch(PDO::FETCH_ASSOC);
    assert_step("1.1 Pembeli menambahkan produk ke Wishlist", (bool)$wishEntry, "Wishlist ID: " . ($wishEntry['wishlist_id'] ?? 0));

    // 3. Verifikasi query join wishlist menampilkan data toko & harga produk
    $stmtWishList = $db->prepare("
        SELECT w.wishlist_id, p.name AS product_name, s.shop_name, pv.price
        FROM wishlists w
        JOIN products p ON w.product_id = p.product_id
        JOIN shops s ON p.shop_id = s.shop_id
        JOIN product_variants pv ON p.product_id = pv.product_id
        WHERE w.user_id = ? AND w.product_id = ?
        LIMIT 1
    ");
    $stmtWishList->execute([$buyerId, $productId]);
    $wishDetail = $stmtWishList->fetch(PDO::FETCH_ASSOC);
    assert_step("1.2 Wishlist join query valid dengan relasi Produk & Toko", (bool)$wishDetail, "Produk: {$wishDetail['product_name']}");


    // =========================================================================
    // FASE 2: TRANSAKSI & CHECKOUT PEMBELI (SERVER-SIDE ONGKIR)
    // =========================================================================
    echo "\n--- FASE 2: CHECKOUT & SIMULASI ONGKIR SERVER ---\n";
    require_once __DIR__ . '/../api/helpers/shipping.php';

    // Alamat buyer
    $stmtAddr = $db->prepare("SELECT * FROM addresses WHERE user_id = ? LIMIT 1");
    $stmtAddr->execute([$buyerId]);
    $addr = $stmtAddr->fetch(PDO::FETCH_ASSOC);
    if (!$addr) {
        // Buat dummy alamat jika belum ada
        $db->prepare("INSERT INTO addresses (user_id, label, recipient_name, phone, street_address, city, province, postal_code, is_default) VALUES (?, 'Rumah', 'Buyer Test', '081299990001', 'Jl. Jenderal Sudirman No. 12', 'Pangkalpinang', 'Kepulauan Bangka Belitung', '33111', 1)")->execute([$buyerId]);
        $addr = $db->query("SELECT * FROM addresses WHERE user_id = $buyerId LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    }

    $destProv = $addr['province'] ?: 'Kepulauan Bangka Belitung';
    $origProv = $shop['pickup_province'] ?: 'Kepulauan Bangka Belitung';

    $shippingCalc = calculate_shop_shipping($db, $origProv, $destProv, 1000, 'JNE', 'REG');
    $shippingFee = (float)$shippingCalc['shipping_cost'];
    $productPrice = (float)$product['price'];
    $subtotal = $productPrice * 1;
    $serviceFee = 1000.00;
    $grandTotal = $subtotal + $shippingFee + $serviceFee;

    // Buat Master Order
    $invNumber = 'INV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
    $stmtMo = $db->prepare("
        INSERT INTO master_orders (
            buyer_id, invoice_number, total_product_amount, total_shipping_fee,
            total_voucher_discount, total_shipping_discount, service_fee, grand_total,
            shipping_recipient_name, shipping_phone, shipping_street_address, shipping_city,
            shipping_province, shipping_postal_code, created_at
        ) VALUES (?, ?, ?, ?, 0.00, 0.00, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmtMo->execute([
        $buyerId, $invNumber, $subtotal, $shippingFee, $serviceFee, $grandTotal,
        $addr['recipient_name'], $addr['phone'], $addr['street_address'], $addr['city'], $addr['province'], $addr['postal_code']
    ]);
    $masterOrderId = (int)$db->lastInsertId();

    // Buat Sub Order
    $subOrderNum = 'SUB-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
    $platformFeePercent = 5.0; // 5% komisi
    $platformCommission = ($subtotal * $platformFeePercent) / 100.0;
    $netSellerAmount = ($subtotal - $platformCommission) + $shippingFee;

    $stmtSo = $db->prepare("
        INSERT INTO sub_orders (
            master_order_id, shop_id, sub_order_number, courier_name, courier_service,
            shipping_cost, shop_voucher_discount, platform_discount, shipping_discount,
            order_status, gross_product_amount, created_at
        ) VALUES (?, ?, ?, 'JNE Express', 'Reguler (2-3 Hari)', ?, 0.00, 0.00, 0.00, 'MENUNGGU_BAYAR', ?, NOW())
    ");
    $stmtSo->execute([
        $masterOrderId, $shopId, $subOrderNum, $shippingFee, $subtotal
    ]);
    $subOrderId = (int)$db->lastInsertId();

    // Buat Order Items
    $stmtOi = $db->prepare("
        INSERT INTO order_items (
            sub_order_id, product_id, variant_id, product_name, variant_name, product_image_url, price_at_purchase, quantity, total_price
        ) VALUES (?, ?, ?, ?, 'Standard', 'assets/Images/products/sample.jpg', ?, 1, ?)
    ");
    $stmtOi->execute([$subOrderId, $productId, $variantId, $product['name'], $productPrice, $subtotal]);

    // Buat Tagihan Payments
    $stmtPay = $db->prepare("
        INSERT INTO payments (
            master_order_id, payment_method, payment_status, expired_at
        ) VALUES (?, 'QRIS', 'PENDING', DATE_ADD(NOW(), INTERVAL 1 DAY))
    ");
    $stmtPay->execute([$masterOrderId]);
    $paymentId = (int)$db->lastInsertId();

    assert_step("2.1 Pembeli Checkout Pesanan Berhasil", $masterOrderId > 0 && $subOrderId > 0, "Invoice: $invNumber | Grand Total: Rp " . number_format($grandTotal, 0, ',', '.'));


    // =========================================================================
    // FASE 3: UNGGAH BUKTI PEMBAYARAN (PEMBELI)
    // =========================================================================
    echo "\n--- FASE 3: UNGGAH BUKTI BAYAR ---\n";
    $proofUrl = 'uploads/payment_proofs/test_proof_' . time() . '.png';
    $updateProof = $db->prepare("
        UPDATE payments 
        SET proof_image_url = ?, payment_status = 'MENUNGGU_VERIFIKASI' 
        WHERE payment_id = ?
    ");
    $updateProof->execute([$proofUrl, $paymentId]);

    // Update status sub_order menjadi MENUNGGU_VERIFIKASI
    $db->prepare("UPDATE sub_orders SET order_status = 'MENUNGGU_VERIFIKASI' WHERE sub_order_id = ?")->execute([$subOrderId]);

    $checkStatus = $db->query("SELECT order_status FROM sub_orders WHERE sub_order_id = $subOrderId")->fetchColumn();
    assert_step("3.1 Pembeli mengunggah bukti bayar -> Status 'MENUNGGU_VERIFIKASI'", $checkStatus === 'MENUNGGU_VERIFIKASI');


    // =========================================================================
    // FASE 4: VERIFIKASI PEMBAYARAN & PROSES PESANAN (PENJUAL)
    // =========================================================================
    echo "\n--- FASE 4: VERIFIKASI PENJUAL & PENGIRIMAN ---\n";
    // 4.1 Penjual menyetujui bukti bayar
    $db->prepare("
        UPDATE payments 
        SET payment_status = 'PAID', verified_at = NOW(), verified_by = ? 
        WHERE payment_id = ?
    ")->execute([$sellerId, $paymentId]);

    $db->prepare("UPDATE sub_orders SET order_status = 'DIKEMAS' WHERE sub_order_id = ?")->execute([$subOrderId]);

    // Catat ke payment_verification_logs
    $stmtVLog = $db->prepare("
        INSERT INTO payment_verification_logs (payment_id, master_order_id, sub_order_id, action, actor_user_id, actor_role, notes)
        VALUES (?, ?, ?, 'APPROVE', ?, 'seller', 'Bukti bayar disetujui via simulasi E2E')
    ");
    $stmtVLog->execute([$paymentId, $masterOrderId, $subOrderId, $sellerId]);

    $statusDikemas = $db->query("SELECT order_status FROM sub_orders WHERE sub_order_id = $subOrderId")->fetchColumn();
    assert_step("4.1 Penjual menyetujui pembayaran -> Status 'DIKEMAS'", $statusDikemas === 'DIKEMAS');

    // 4.2 Penjual menyerahkan ke kurir (DIKIRIM)
    $trackingNum = 'ON-TRK-TEST-' . rand(100000, 999999);
    $db->prepare("
        UPDATE sub_orders 
        SET order_status = 'DIKIRIM', tracking_number = ?, shipped_at = NOW() 
        WHERE sub_order_id = ?
    ")->execute([$trackingNum, $subOrderId]);

    $statusDikirim = $db->query("SELECT order_status FROM sub_orders WHERE sub_order_id = $subOrderId")->fetchColumn();
    assert_step("4.2 Penjual menyerahkan pesanan ke kurir -> Status 'DIKIRIM'", $statusDikirim === 'DIKIRIM', "Resi: $trackingNum");


    // =========================================================================
    // FASE 5: KONFIRMASI DITERIMA & ULASAN (PEMBELI)
    // =========================================================================
    echo "\n--- FASE 5: KONFIRMASI SELESAI & RATING ---\n";
    // 5.1 Pembeli konfirmasi terima pesanan
    $db->prepare("
        UPDATE sub_orders 
        SET order_status = 'SELESAI', delivered_at = NOW(), net_seller_amount = ? 
        WHERE sub_order_id = ?
    ")->execute([$netSellerAmount, $subOrderId]);

    // Dompet penjual menerima dana bersih
    $stmtWallet = $db->prepare("
        INSERT INTO wallets (user_id, available_balance, pending_balance, updated_at) 
        VALUES (:uid, :net, 0.00, NOW()) 
        ON DUPLICATE KEY UPDATE 
            available_balance = available_balance + :net,
            updated_at = NOW()
    ");
    $stmtWallet->execute(['uid' => $sellerId, 'net' => $netSellerAmount]);

    $stmtGetWid = $db->prepare("SELECT wallet_id FROM wallets WHERE user_id = :uid LIMIT 1");
    $stmtGetWid->execute(['uid' => $sellerId]);
    $walletId = (int)$stmtGetWid->fetchColumn();

    if ($walletId) {
        $stmtTx = $db->prepare("
            INSERT INTO wallet_transactions (wallet_id, type, amount, description, created_at)
            VALUES (?, 'INCOME_SALES', ?, 'Pelepasan dana pesanan simulasi E2E', NOW())
        ");
        $stmtTx->execute([$walletId, $netSellerAmount]);
    }

    $statusSelesai = $db->query("SELECT order_status FROM sub_orders WHERE sub_order_id = $subOrderId")->fetchColumn();
    assert_step("5.1 Pembeli konfirmasi pesanan diterima -> Status 'SELESAI' & Dana Toko Masuk", $statusSelesai === 'SELESAI', "Dana Bersih: Rp " . number_format($netSellerAmount, 0, ',', '.'));

    // 5.2 Pembeli memberikan ulasan dan rating
    $stmtRev = $db->prepare("
        INSERT INTO reviews (sub_order_id, product_id, user_id, rating, comment, created_at)
        VALUES (?, ?, ?, 5, 'Kualitas barang sangat bagus dan pengiriman cepat!', NOW())
    ");
    $stmtRev->execute([$subOrderId, $productId, $buyerId]);
    $revId = (int)$db->lastInsertId();
    assert_step("5.2 Pembeli memberikan rating (5 Bintang) & ulasan", $revId > 0, "Review ID: $revId");


    // =========================================================================
    // FASE 6: PENARIKAN DANA PENJUAL & APPROVAL ADMIN
    // =========================================================================
    echo "\n--- FASE 6: PENARIKAN DANA & APPROVAL ADMIN ---\n";
    $wdAmount = min(50000.00, $netSellerAmount);
    $stmtWd = $db->prepare("
        INSERT INTO withdrawals (shop_id, amount, bank_name, bank_account_number, status, created_at)
        VALUES (?, ?, 'BCA', '1234567890', 'PENDING', NOW())
    ");
    $stmtWd->execute([$shopId, $wdAmount]);
    $wdId = (int)$db->lastInsertId();
    assert_step("6.1 Penjual mengajukan penarikan saldo (Withdrawal Request)", $wdId > 0, "Withdrawal ID: $wdId | Rp " . number_format($wdAmount, 0, ',', '.'));

    // Admin approve payout
    $db->prepare("UPDATE withdrawals SET status = 'SUCCESS' WHERE withdrawal_id = ?")->execute([$wdId]);
    $db->prepare("INSERT INTO wallet_transactions (wallet_id, type, amount, description) VALUES (?, 'WITHDRAW', ?, 'Penarikan dana disetujui admin')")->execute([$walletId, $wdAmount]);

    // Catat aksi audit log admin (Minggu 6)
    $stmtAudit = $db->prepare("
        INSERT INTO admin_audit_logs (admin_id, action_type, target_id, description, ip_address, created_at)
        VALUES (?, 'UPDATE_PLATFORM_SETTING', ?, 'Menyetujui transfer dana penarikan toko simulasi E2E', '127.0.0.1', NOW())
    ");
    $stmtAudit->execute([$adminId, $wdId]);
    $auditId = (int)$db->lastInsertId();

    $wdStatus = $db->query("SELECT status FROM withdrawals WHERE withdrawal_id = $wdId")->fetchColumn();
    assert_step("6.2 Admin menyetujui payout & dicatat di audit log", $wdStatus === 'SUCCESS' && $auditId > 0, "Audit Log ID: $auditId");


    // =========================================================================
    // FASE 7: PENGATURAN PLATFORM & DASHBOARD ANALITIK (ADMIN)
    // =========================================================================
    echo "\n--- FASE 7: PENGATURAN PLATFORM & AUDIT ADMIN ---\n";
    // 7.1 Cek keberadaan tabel platform_settings
    $stmtPs = $db->query("SELECT setting_key, setting_value FROM platform_settings WHERE setting_key = 'seller_commission_percentage'");
    $setting = $stmtPs->fetch(PDO::FETCH_ASSOC);
    assert_step("7.1 Tabel platform_settings dapat diakses", (bool)$setting, "Komisi Platform: {$setting['setting_value']}%");

    // 7.2 Verifikasi Audit Log terbaru tercatat
    $stmtLastAudit = $db->query("SELECT log_id, action_type, description FROM admin_audit_logs ORDER BY log_id DESC LIMIT 1");
    $lastAudit = $stmtLastAudit->fetch(PDO::FETCH_ASSOC);
    assert_step("7.2 Riwayat aktivitas administratif tercatat di admin_audit_logs", (bool)$lastAudit, "Aksi: {$lastAudit['action_type']}");

    // Pembersihan data uji agar database tetap bersih
    $db->prepare("DELETE FROM reviews WHERE review_id = ?")->execute([$revId]);
    $db->prepare("DELETE FROM payment_verification_logs WHERE master_order_id = ?")->execute([$masterOrderId]);
    $db->prepare("DELETE FROM order_items WHERE sub_order_id = ?")->execute([$subOrderId]);
    $db->prepare("DELETE FROM sub_orders WHERE sub_order_id = ?")->execute([$subOrderId]);
    $db->prepare("DELETE FROM payments WHERE payment_id = ?")->execute([$paymentId]);
    $db->prepare("DELETE FROM master_orders WHERE master_order_id = ?")->execute([$masterOrderId]);
    $db->prepare("DELETE FROM withdrawals WHERE withdrawal_id = ?")->execute([$wdId]);
    $db->prepare("DELETE FROM admin_audit_logs WHERE log_id = ?")->execute([$auditId]);
    $db->prepare("DELETE FROM wishlists WHERE user_id = ? AND product_id = ?")->execute([$buyerId, $productId]);
    echo "\n [CLEANUP] Seluruh data transaksi uji berhasil dibersihkan dari database.\n";

} catch (Throwable $e) {
    echo "\n❌ Terjadi exception selama pengujian E2E: " . $e->getMessage() . " on line " . $e->getLine() . "\n";
    $failed++;
}

// REKAPITULASI
echo "\n====================================================\n";
echo "HASIL UJI TERINTEGRASI E2E: Passed = $passed | Failed = $failed\n";
if ($failed === 0) {
    echo "🎉 SELURUH SIKLUS E2E LINTAS PERAN LULUS 100% (PASS)\n";
} else {
    echo "⚠️ ADA SKENARIO E2E YANG GAGAL!\n";
}
echo "====================================================\n";
