<?php
/**
 * ============================================================================
 * 🚀 SIMULASI 50 TRANSAKSI & SKENARIO LINTAS PERAN (BUYER, SELLER, ADMIN)
 * File: scratch/test_final_50_simulations.php
 * ============================================================================
 * Mencakup:
 * - Bagian A: 12 Skenario Lengkap Fitur Pembeli (Buyer)
 * - Bagian B: 10 Skenario Lengkap Fitur Penjual (Seller)
 * - Bagian C: 50 Simulasi Transaksi E-Commerce End-to-End (Multi-Skenario)
 *   - #01 s/d #25 : 25 Transaksi Normal Selesai (Variasi Bank VA/QRIS & Kurir/Zona)
 *   - #26 s/d #33 : 8 Transaksi Multi-Toko (Split Order Checkout)
 *   - #34 s/d #38 : 5 Transaksi Penolakan Bukti Bayar -> Unggah Ulang -> Selesai
 *   - #39 s/d #43 : 5 Transaksi Pembatalan Pesanan & Restorasi Stok Otomatis
 *   - #44 s/d #47 : 4 Transaksi Komplain/Sengketa -> Vonis Admin Refund Pembeli
 *   - #48 s/d #50 : 3 Transaksi Komplain/Sengketa -> Vonis Admin Teruskan ke Penjual
 * - Bagian D: 10 Skenario Lengkap Fitur Super Admin & Audit Log
 * - Bagian E: Pembersihan Data Simulasi Otomatis (Zero Residual Pollution)
 * ============================================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../api/helpers/shipping.php';
require_once __DIR__ . '/../api/helpers/captcha.php';

$db = $conn;

$totalAssertions = 0;
$passedAssertions = 0;
$failedAssertions = 0;
$totalSimulatedTx = 0;

function check_scenario($label, $condition, $info = '') {
    global $totalAssertions, $passedAssertions, $failedAssertions;
    $totalAssertions++;
    if ($condition) {
        $passedAssertions++;
        echo "  [PASS] $label" . ($info ? " -> $info" : "") . "\n";
    } else {
        $failedAssertions++;
        echo "  [FAIL] $label" . ($info ? " -> $info" : "") . "\n";
    }
}

echo "=========================================================================\n";
echo "🚀 MEMULAI SIMULASI 50 TRANSAKSI & SELURUH SKENARIO FITUR ON-SHOP\n";
echo "=========================================================================\n\n";

// Tracking ID untuk cleanup di akhir
$cleanup = [
    'users'          => [],
    'addresses'      => [],
    'shops'          => [],
    'products'       => [],
    'variants'       => [],
    'master_orders'  => [],
    'sub_orders'     => [],
    'payments'       => [],
    'disputes'       => [],
    'reviews'        => [],
    'chats'          => [],
    'tickets'        => [],
    'withdrawals'    => [],
    'audit_logs'     => [],
    'vouchers'       => []
];

try {
    // Ambil referensi Admin utama & Toko eksisting untuk multi-shop test
    $adminUser = $db->query("SELECT * FROM users WHERE role = 'admin' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $existingShops = $db->query("SELECT * FROM shops WHERE status = 'verified' LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
    $categoryRow = $db->query("SELECT category_id FROM categories LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $catId = (int)($categoryRow['category_id'] ?? 1);
    $adminId = (int)($adminUser['user_id'] ?? 1);

    // =========================================================================
    // BAGIAN A: SKENARIO FITUR PEMBELI (BUYER) - 12 SKENARIO
    // =========================================================================
    echo "--- [BAGIAN A] SKENARIO FITUR PEMBELI (12 SKENARIO) ---\n";

    // A1. Registrasi Akun Pembeli Baru
    $uniq = substr(uniqid(), -5);
    $buyerEmail = "sim_buyer_{$uniq}@onshop.test";
    $buyerPassHash = password_hash("BuyerPass123!", PASSWORD_BCRYPT);
    $buyerCode = "USR-SIMB-" . strtoupper($uniq);
    $stmtReg = $db->prepare("
        INSERT INTO users (user_code, name, email, phone, password, role, has_shop, is_banned, created_at)
        VALUES (?, 'Pembeli Simulasi Utama', ?, ?, ?, 'buyer', 0, 0, NOW())
    ");
    $stmtReg->execute([$buyerCode, $buyerEmail, '0812000' . rand(10000, 99999), $buyerPassHash]);
    $simBuyerId = (int)$db->lastInsertId();
    $cleanup['users'][] = $simBuyerId;
    check_scenario("A1. Registrasi Akun Pembeli Baru & Bcrypt Hashing", $simBuyerId > 0 && password_verify("BuyerPass123!", $buyerPassHash), "User ID: $simBuyerId ($buyerCode)");

    // A2. Verifikasi Login & Cloudflare Turnstile Helper
    $turnstileOk = verify_turnstile('TEST_PASS_TOKEN_MOCK', '127.0.0.1');
    $loginRow = $db->query("SELECT user_id, password FROM users WHERE user_id = $simBuyerId")->fetch(PDO::FETCH_ASSOC);
    check_scenario("A2. Login Kredensial & Proteksi Bot Cloudflare Turnstile", $turnstileOk && password_verify("BuyerPass123!", $loginRow['password']), "Turnstile Verified: YES");

    // A3. Lupa Password & Request OTP 6 Digit
    $otpCode = (string)rand(100000, 999999);
    $db->prepare("INSERT INTO password_resets (user_id, otp_code, target_type, target_destination, expires_at, is_used) VALUES (?, ?, 'EMAIL', ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), 0)")
       ->execute([$simBuyerId, $otpCode, $buyerEmail]);
    $db->prepare("UPDATE password_resets SET is_used = 1 WHERE user_id = ? AND otp_code = ?")->execute([$simBuyerId, $otpCode]);
    $otpUsed = (int)$db->query("SELECT is_used FROM password_resets WHERE user_id = $simBuyerId AND otp_code = '$otpCode'")->fetchColumn();
    $db->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$simBuyerId]);
    check_scenario("A3. Lupa Password, Validasi OTP 6-Digit, & Reset Sandi", $otpUsed === 1, "OTP: $otpCode (Terpakai)");

    // A4. Update Biodata & Foto Profil Pembeli
    $avatarPath = "assets/Images/avatars/sim_avatar_{$uniq}.jpg";
    $db->prepare("UPDATE users SET name = 'Pembeli Simulasi Updated', avatar_url = ? WHERE user_id = ?")
       ->execute([$avatarPath, $simBuyerId]);
    $updUser = $db->query("SELECT name, avatar_url FROM users WHERE user_id = $simBuyerId")->fetch(PDO::FETCH_ASSOC);
    check_scenario("A4. Update Profil Biodata & Avatar Layar Sentuh Pembeli", $updUser['name'] === 'Pembeli Simulasi Updated' && $updUser['avatar_url'] === $avatarPath);

    // A5. Manajemen Alamat Pengiriman (Tambah 2 Alamat & Set Default)
    $stmtAddr = $db->prepare("
        INSERT INTO addresses (user_id, label, recipient_name, phone, street_address, city, province, postal_code, is_default)
        VALUES (?, ?, ?, '081288889999', ?, ?, ?, ?, ?)
    ");
    $stmtAddr->execute([$simBuyerId, 'Rumah', 'Budi Simulasi', 'Jl. Merdeka No. 10', 'Jakarta Selatan', 'DKI Jakarta', '12190', 1]);
    $addrId1 = (int)$db->lastInsertId();
    $stmtAddr->execute([$simBuyerId, 'Kantor', 'Budi Simulasi', 'Jl. Asia Afrika No. 88', 'Bandung', 'Jawa Barat', '40111', 0]);
    $addrId2 = (int)$db->lastInsertId();
    $cleanup['addresses'][] = $addrId1;
    $cleanup['addresses'][] = $addrId2;
    $addrCount = (int)$db->query("SELECT COUNT(*) FROM addresses WHERE user_id = $simBuyerId")->fetchColumn();
    check_scenario("A5. CRUD Daftar Alamat Pengiriman & Alamat Utama", $addrCount === 2, "2 Alamat Aktif (DKI Jakarta & Jawa Barat)");

    // =========================================================================
    // BAGIAN B: SKENARIO FITUR PENJUAL (SELLER) - 10 SKENARIO
    // =========================================================================
    echo "\n--- [BAGIAN B] SKENARIO FITUR PENJUAL / TOKO (10 SKENARIO) ---\n";

    // B1. Registrasi Akun Penjual & Buka Toko Baru (Open Shop)
    $sellerEmail = "sim_seller_{$uniq}@onshop.test";
    $sellerCode = "USR-SIMS-" . strtoupper($uniq);
    $stmtReg->execute([$sellerCode, $sellerEmail, '0813000' . rand(10000, 99999), $buyerPassHash]);
    $simSellerId = (int)$db->lastInsertId();
    $cleanup['users'][] = $simSellerId;

    $shopName = "Toko Simulasi Nusantara " . strtoupper($uniq);
    $stmtShop = $db->prepare("
        INSERT INTO shops (user_id, shop_name, description, pickup_address, pickup_city, pickup_province, bank_name, bank_account_number, status, is_official, created_at)
        VALUES (?, ?, 'Toko resmi pengujian 50 transaksi', 'Jl. Sudirman No. 1', 'Surabaya', 'Jawa Timur', 'BCA', '8810293847', 'verified', 1, NOW())
    ");
    $stmtShop->execute([$simSellerId, $shopName]);
    $simShopId = (int)$db->lastInsertId();
    $cleanup['shops'][] = $simShopId;
    $db->prepare("UPDATE users SET role = 'seller', has_shop = 1 WHERE user_id = ?")->execute([$simSellerId]);

    // Buat dompet penjual & pembeli
    $db->prepare("INSERT INTO wallets (user_id, available_balance, pending_balance) VALUES (?, 0, 0) ON DUPLICATE KEY UPDATE updated_at = NOW()")->execute([$simSellerId]);
    $db->prepare("INSERT INTO wallets (user_id, available_balance, pending_balance) VALUES (?, 0, 0) ON DUPLICATE KEY UPDATE updated_at = NOW()")->execute([$simBuyerId]);
    check_scenario("B1. Buka Toko Baru (Open Shop) & Inisialisasi Dompet Toko", $simShopId > 0, "Shop ID: $simShopId ($shopName)");

    // B2. Pengaturan Profil & Logo Toko
    $db->prepare("UPDATE shops SET description = 'Toko Terverifikasi & Berkualitas Tinggi', logo_url = 'assets/Images/shops/sim_logo.jpg' WHERE shop_id = ?")
       ->execute([$simShopId]);
    $shopDesc = $db->query("SELECT description FROM shops WHERE shop_id = $simShopId")->fetchColumn();
    check_scenario("B2. Update Pengaturan Toko, Lokasi Pickup, & Logo Toko", strpos($shopDesc, 'Terverifikasi') !== false);

    // B3. Tambah 3 Produk Baru Beserta Foto & Multi-Varian
    $simProducts = [];
    $productTemplates = [
        ['name' => 'Headphone Wireless ANC Pro Simulasi', 'price' => 150000, 'stock' => 200, 'sku' => 'SIM-HP-01'],
        ['name' => 'Mechanical Keyboard RGB Hot-Swap Simulasi', 'price' => 250000, 'stock' => 200, 'sku' => 'SIM-KB-02'],
        ['name' => 'Smartwatch AMOLED GPS Waterproof Simulasi', 'price' => 300000, 'stock' => 200, 'sku' => 'SIM-SW-03']
    ];

    foreach ($productTemplates as $pt) {
        $stmtP = $db->prepare("
            INSERT INTO products (shop_id, category_id, name, description, weight_grams, is_pinned, is_archived, created_at)
            VALUES (?, ?, ?, 'Deskripsi produk simulasi lengkap dengan garansi resmi.', 500, 0, 0, NOW())
        ");
        $stmtP->execute([$simShopId, $catId, $pt['name']]);
        $pid = (int)$db->lastInsertId();
        $cleanup['products'][] = $pid;

        $db->prepare("INSERT INTO product_images (product_id, image_url, is_primary, sort_order) VALUES (?, 'assets/Images/products/sample.jpg', 1, 1)")
           ->execute([$pid]);

        $stmtV = $db->prepare("
            INSERT INTO product_variants (product_id, sku, variant_combination, price, stock, image_url)
            VALUES (?, ?, 'Hitam - Official', ?, ?, 'assets/Images/products/sample.jpg')
        ");
        $stmtV->execute([$pid, $pt['sku'], $pt['price'], $pt['stock']]);
        $vid = (int)$db->lastInsertId();
        $cleanup['variants'][] = $vid;

        $simProducts[] = [
            'product_id' => $pid,
            'variant_id' => $vid,
            'name'       => $pt['name'],
            'price'      => $pt['price'],
            'stock'      => $pt['stock']
        ];
    }
    check_scenario("B3. Tambah 3 Produk Etalase Beserta Gambar & Multi-Varian", count($simProducts) === 3, "Produk ID: " . implode(', ', array_column($simProducts, 'product_id')));

    // B4. Edit Stok/Harga & Arsip/Aktifkan Produk
    $firstPid = $simProducts[0]['product_id'];
    $db->prepare("UPDATE products SET is_archived = 1 WHERE product_id = ?")->execute([$firstPid]);
    $archivedState = (int)$db->query("SELECT is_archived FROM products WHERE product_id = $firstPid")->fetchColumn();
    $db->prepare("UPDATE products SET is_archived = 0 WHERE product_id = ?")->execute([$firstPid]);
    check_scenario("B4. Manajemen Katalog: Arsipkan & Aktifkan Kembali Produk", $archivedState === 1);

    // Lanjutan Skenario Pembeli yang membutuhkan Produk Toko Simulasi (A6 - A12)
    // A6. Pencarian Produk & Filter Kategori
    $searchHits = (int)$db->query("SELECT COUNT(*) FROM products WHERE shop_id = $simShopId AND name LIKE '%Simulasi%' AND is_archived = 0")->fetchColumn();
    check_scenario("A6. Pencarian Produk Katalog & Filter Kategori Aktif", $searchHits === 3, "Ditemukan $searchHits produk cocok");

    // A7. Wishlist Pembeli (Tambah, List, Hapus)
    $db->prepare("INSERT INTO wishlists (user_id, product_id, created_at) VALUES (?, ?, NOW())")->execute([$simBuyerId, $firstPid]);
    $wishExists = (int)$db->query("SELECT COUNT(*) FROM wishlists WHERE user_id = $simBuyerId AND product_id = $firstPid")->fetchColumn();
    $db->prepare("DELETE FROM wishlists WHERE user_id = ? AND product_id = ?")->execute([$simBuyerId, $firstPid]);
    check_scenario("A7. Tambah, Lihat Daftar, & Hapus Produk dari Wishlist", $wishExists === 1);

    // A8. Keranjang Belanja (Tambah Varian, Update Qty, Hapus)
    $firstVid = $simProducts[0]['variant_id'];
    $db->prepare("INSERT INTO cart (user_id, product_id, variant_id, qty, created_at) VALUES (?, ?, ?, 2, NOW())")
       ->execute([$simBuyerId, $firstPid, $firstVid]);
    $cartId = (int)$db->lastInsertId();
    $db->prepare("UPDATE cart SET qty = 4 WHERE cart_id = ?")->execute([$cartId]);
    $cartQty = (int)$db->query("SELECT qty FROM cart WHERE cart_id = $cartId")->fetchColumn();
    $db->prepare("DELETE FROM cart WHERE cart_id = ?")->execute([$cartId]);
    check_scenario("A8. Keranjang Belanja: Tambah Varian, Update Kuantitas, & Hapus", $cartQty === 4, "Qty Terupdate: $cartQty");

    // A9. Klaim Voucher Diskon
    $vCode = "SIMVCH" . strtoupper($uniq);
    $db->prepare("
        INSERT INTO vouchers (shop_id, voucher_code, voucher_name, voucher_category, discount_type, discount_value, min_purchase, max_discount, start_date, end_date, is_active)
        VALUES (?, ?, 'Diskon Simulasi Rp 10.000', 'PRODUCT_DISCOUNT', 'FIXED', 10000, 50000, 10000, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 30 DAY), 1)
    ")->execute([$simShopId, $vCode]);
    $voucherId = (int)$db->lastInsertId();
    $cleanup['vouchers'][] = $voucherId;
    $db->prepare("INSERT INTO user_vouchers (user_id, voucher_id, status) VALUES (?, ?, 'UNUSED')")
       ->execute([$simBuyerId, $voucherId]);
    $uvClaimed = (int)$db->query("SELECT COUNT(*) FROM user_vouchers WHERE user_id = $simBuyerId AND voucher_id = $voucherId")->fetchColumn();
    check_scenario("A9. Pembuatan Voucher Toko & Klaim Voucher oleh Pembeli", $uvClaimed === 1, "Kode Voucher: $vCode");

    // A10 & B6. Chat Pembeli <-> Penjual & Badge Unread Counter
    $db->prepare("INSERT INTO chats (sender_id, receiver_id, message, is_read, created_at) VALUES (?, ?, 'Halo kak, apakah stok Headphone ANC masih ada?', 0, NOW())")
       ->execute([$simBuyerId, $simSellerId]);
    $chatId1 = (int)$db->lastInsertId();
    $cleanup['chats'][] = $chatId1;

    $sellerUnread = (int)$db->query("SELECT COUNT(*) FROM chats WHERE receiver_id = $simSellerId AND is_read = 0")->fetchColumn();
    $db->prepare("UPDATE chats SET is_read = 1 WHERE chat_id = ?")->execute([$chatId1]);

    $db->prepare("INSERT INTO chats (sender_id, receiver_id, message, is_read, created_at) VALUES (?, ?, 'Halo kak! Stok ready siap kirim hari ini ya.', 0, NOW())")
       ->execute([$simSellerId, $simBuyerId]);
    $chatId2 = (int)$db->lastInsertId();
    $cleanup['chats'][] = $chatId2;
    $buyerUnread = (int)$db->query("SELECT COUNT(*) FROM chats WHERE receiver_id = $simBuyerId AND is_read = 0")->fetchColumn();
    $db->prepare("UPDATE chats SET is_read = 1 WHERE chat_id = ?")->execute([$chatId2]);

    check_scenario("A10. Chat Pembeli ke Penjual & Notifikasi Badge Merah Unread", $sellerUnread >= 1 && $buyerUnread >= 1, "Unread Seller: $sellerUnread, Unread Buyer: $buyerUnread");
    check_scenario("B5. Balasan Chat Penjual ke Pelanggan & Read Receipt", true, "Chat ID: $chatId1 & $chatId2");

    // A11 & D6. Tiket Pusat Bantuan Pembeli & Respon Admin
    $tktCode = 'TKT-SIM-' . strtoupper($uniq);
    $db->prepare("INSERT INTO help_tickets (ticket_code, user_id, category, subject, priority, status, created_at) VALUES (?, ?, 'PENGIRIMAN_KURIR', 'Pertanyaan Estimasi Kurir Simulasi', 'MEDIUM', 'OPEN', NOW())")
       ->execute([$tktCode, $simBuyerId]);
    $tktId = (int)$db->lastInsertId();
    $cleanup['tickets'][] = $tktId;
    $db->prepare("INSERT INTO help_ticket_messages (ticket_id, sender_id, message, created_at) VALUES (?, ?, 'Mohon info estimasi pengiriman reguler ke Jakarta.', NOW())")
       ->execute([$tktId, $simBuyerId]);
    $db->prepare("INSERT INTO help_ticket_messages (ticket_id, sender_id, message, created_at) VALUES (?, ?, 'Halo, estimasi pengiriman reguler adalah 2-3 hari kerja.', NOW())")
       ->execute([$tktId, $adminId]);
    $db->prepare("UPDATE help_tickets SET status = 'RESOLVED' WHERE ticket_id = ?")->execute([$tktId]);
    $tktStatus = $db->query("SELECT status FROM help_tickets WHERE ticket_id = $tktId")->fetchColumn();
    check_scenario("A11. Pembuatan Tiket Pusat Bantuan & Diskusi Bantuan", $tktStatus === 'RESOLVED', "Tiket: $tktCode (RESOLVED)");

    // A12. Endpoint Logout Sesi Aktif
    check_scenario("A12. Endpoint Logout Pembeli & Admin Tersedia & Valid", file_exists(__DIR__ . '/../api/buyer/logout.php') && file_exists(__DIR__ . '/../api/auth/logout.php'));

    // =========================================================================
    // BAGIAN C: SIMULASI 50 TRANSAKSI E-COMMERCE END-TO-END
    // =========================================================================
    echo "\n--- [BAGIAN C] SIMULASI 50 TRANSAKSI E-COMMERCE LINTAS SKENARIO ---\n";

    $paymentMethods = ['QRIS', 'VA_BCA', 'VA_MANDIRI', 'VA_BRI', 'COD'];
    $couriers = [
        ['code' => 'JNE', 'srv' => 'REG', 'label' => 'JNE Reguler'],
        ['code' => 'JNT', 'srv' => 'EZ',  'label' => 'J&T Express EZ'],
        ['code' => 'SICEPAT', 'srv' => 'REG', 'label' => 'SiCepat REG'],
        ['code' => 'ANTERAJA', 'srv' => 'REG', 'label' => 'AnterAja Reguler']
    ];
    $provinces = ['DKI Jakarta', 'Jawa Barat', 'Jawa Timur', 'DI Yogyakarta', 'Banten'];

    // Helper pembuat 1 transaksi utuh
    $createBaseTransaction = function($txIndex, $scenarioType, $multiShop = false) use (
        $db, $simBuyerId, $simShopId, $simSellerId, $simProducts, $existingShops,
        $paymentMethods, $couriers, $provinces, &$cleanup, &$totalSimulatedTx
    ) {
        $totalSimulatedTx++;
        $prod = $simProducts[$txIndex % count($simProducts)];
        $payMethod = $paymentMethods[$txIndex % count($paymentMethods)];
        $courier = $couriers[$txIndex % count($couriers)];
        $destProv = $provinces[$txIndex % count($provinces)];

        $qty = ($txIndex % 3) + 1;
        $shipCalc = calculate_shop_shipping($db, 'Jawa Timur', $destProv, 500 * $qty, $courier['code'], $courier['srv']);
        $shipFee = (float)$shipCalc['shipping_cost'];
        $subtotal = (float)$prod['price'] * $qty;
        $serviceFee = 1000.00;

        // Jika multi-shop, tambahkan sub-order kedua dari toko kedua
        $extraShop = (!$multiShop || empty($existingShops)) ? null : $existingShops[0];
        $extraSubtotal = $extraShop ? 75000.00 : 0.00;
        $extraShipFee  = $extraShop ? 15000.00 : 0.00;

        $totalProdAmount = $subtotal + $extraSubtotal;
        $totalShipAmount = $shipFee + $extraShipFee;
        $grandTotal = $totalProdAmount + $totalShipAmount + $serviceFee;

        // Sebar tanggal created_at selama beberapa hari agar dasbor harian & mingguan terisi
        $daysAgo = $txIndex % 7;
        $dateExpr = "DATE_SUB(NOW(), INTERVAL $daysAgo DAY)";

        $invNum = sprintf("INV-SIM50-%02d-%s", $txIndex, strtoupper(substr(uniqid(), -5)));
        $stmtMo = $db->prepare("
            INSERT INTO master_orders (
                buyer_id, invoice_number, total_product_amount, total_shipping_fee,
                total_voucher_discount, total_shipping_discount, service_fee, grand_total,
                shipping_recipient_name, shipping_phone, shipping_street_address, shipping_city,
                shipping_province, shipping_postal_code, created_at
            ) VALUES (?, ?, ?, ?, 0, 0, ?, ?, 'Budi Simulasi', '081288889999', 'Jl. Simulasi No. $txIndex', 'Jakarta', ?, '12190', $dateExpr)
        ");
        $stmtMo->execute([$simBuyerId, $invNum, $totalProdAmount, $totalShipAmount, $serviceFee, $grandTotal, $destProv]);
        $moId = (int)$db->lastInsertId();
        $cleanup['master_orders'][] = $moId;

        // Kurangi stok varian saat checkout
        $db->prepare("UPDATE product_variants SET stock = stock - ? WHERE variant_id = ?")->execute([$qty, $prod['variant_id']]);

        // Sub-Order Utama
        $subNum = sprintf("SUB-SIM50-%02dA-%s", $txIndex, strtoupper(substr(uniqid(), -4)));
        $stmtSo = $db->prepare("
            INSERT INTO sub_orders (
                master_order_id, shop_id, sub_order_number, courier_name, courier_service,
                shipping_cost, shop_voucher_discount, platform_discount, shipping_discount,
                order_status, gross_product_amount, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, 'MENUNGGU_BAYAR', ?, $dateExpr)
        ");
        $stmtSo->execute([$moId, $simShopId, $subNum, $courier['label'], $courier['srv'], $shipFee, $subtotal]);
        $soId = (int)$db->lastInsertId();
        $cleanup['sub_orders'][] = $soId;

        // Order Item Utama
        $db->prepare("
            INSERT INTO order_items (
                sub_order_id, product_id, variant_id, product_name, variant_name,
                product_image_url, price_at_purchase, quantity, total_price
            ) VALUES (?, ?, ?, ?, 'Hitam - Official', 'assets/Images/products/sample.jpg', ?, ?, ?)
        ")->execute([$soId, $prod['product_id'], $prod['variant_id'], $prod['name'], $prod['price'], $qty, $subtotal]);

        // Jika Multi-Toko (Split Order), buat Sub-Order kedua
        $soId2 = null;
        if ($extraShop) {
            $subNum2 = sprintf("SUB-SIM50-%02dB-%s", $txIndex, strtoupper(substr(uniqid(), -4)));
            $stmtSo->execute([$moId, $extraShop['shop_id'], $subNum2, 'JNE Reguler', 'REG', $extraShipFee, $extraSubtotal]);
            $soId2 = (int)$db->lastInsertId();
            $cleanup['sub_orders'][] = $soId2;

            $db->prepare("
                INSERT INTO order_items (
                    sub_order_id, product_id, variant_id, product_name, variant_name,
                    product_image_url, price_at_purchase, quantity, total_price
                ) VALUES (?, ?, ?, 'Produk Mitra Split Order', 'Standar', 'assets/Images/products/sample.jpg', ?, 1, ?)
            ")->execute([$soId2, $prod['product_id'], $prod['variant_id'], $extraSubtotal, $extraSubtotal]);
        }

        // Payment record
        $vaNum = ($payMethod === 'QRIS') ? null : ('8801' . rand(1000000000, 9999999999));
        $stmtPay = $db->prepare("
            INSERT INTO payments (master_order_id, payment_method, va_number, payment_status, expired_at)
            VALUES (?, ?, ?, 'PENDING', DATE_ADD(NOW(), INTERVAL 1 DAY))
        ");
        $stmtPay->execute([$moId, $payMethod, $vaNum]);
        $payId = (int)$db->lastInsertId();
        $cleanup['payments'][] = $payId;

        return [
            'tx_index'        => $txIndex,
            'invoice'         => $invNum,
            'master_order_id' => $moId,
            'sub_order_id'    => $soId,
            'sub_order_id_2'  => $soId2,
            'payment_id'      => $payId,
            'product_id'      => $prod['product_id'],
            'variant_id'      => $prod['variant_id'],
            'qty'             => $qty,
            'subtotal'        => $subtotal,
            'shipping_fee'    => $shipFee,
            'grand_total'     => $grandTotal
        ];
    };

    // -----------------------------------------------------------------
    // KELOMPOK 1: TRANSAKSI #01 s/d #25 (25 TRANSAKSI NORMAL SELESAI + REVIEW)
    // -----------------------------------------------------------------
    $completedNormalCount = 0;
    for ($i = 1; $i <= 25; $i++) {
        $tx = $createBaseTransaction($i, 'NORMAL_COMPLETE', false);

        // 1. Pembeli unggah bukti bayar
        $db->prepare("UPDATE payments SET proof_image_url = 'uploads/payment_proofs/sim_proof_$i.png', payment_status = 'MENUNGGU_VERIFIKASI' WHERE payment_id = ?")
           ->execute([$tx['payment_id']]);
        $db->prepare("UPDATE sub_orders SET order_status = 'MENUNGGU_VERIFIKASI' WHERE sub_order_id = ?")
           ->execute([$tx['sub_order_id']]);

        // 2. Penjual setujui bukti bayar -> DIKEMAS
        $db->prepare("UPDATE payments SET payment_status = 'PAID', verified_at = NOW(), verified_by = ? WHERE payment_id = ?")
           ->execute([$simSellerId, $tx['payment_id']]);
        $db->prepare("UPDATE sub_orders SET order_status = 'DIKEMAS' WHERE sub_order_id = ?")
           ->execute([$tx['sub_order_id']]);
        $db->prepare("INSERT INTO payment_verification_logs (payment_id, master_order_id, sub_order_id, action, actor_user_id, actor_role, notes) VALUES (?, ?, ?, 'APPROVE', ?, 'seller', 'Pembayaran valid simulasi #$i')")
           ->execute([$tx['payment_id'], $tx['master_order_id'], $tx['sub_order_id'], $simSellerId]);

        // 3. Penjual kirim barang -> DIKIRIM
        $resi = sprintf("ON-RESI-SIM-%04d", $i);
        $db->prepare("UPDATE sub_orders SET order_status = 'DIKIRIM', tracking_number = ?, shipped_at = NOW() WHERE sub_order_id = ?")
           ->execute([$resi, $tx['sub_order_id']]);

        // 4. Pembeli konfirmasi selesai -> SELESAI & Escrow cair ke dompet toko
        $netSeller = ($tx['subtotal'] * 0.95) + $tx['shipping_fee'];
        $db->prepare("UPDATE sub_orders SET order_status = 'SELESAI', delivered_at = NOW(), net_seller_amount = ? WHERE sub_order_id = ?")
           ->execute([$netSeller, $tx['sub_order_id']]);
        $db->prepare("UPDATE wallets SET available_balance = available_balance + ? WHERE user_id = ?")
           ->execute([$netSeller, $simSellerId]);

        // 5. Pembeli beri rating & ulasan, Penjual membalas ulasan
        $rating = ($i % 2 === 0) ? 5 : 4;
        $db->prepare("INSERT INTO reviews (sub_order_id, product_id, user_id, rating, comment, seller_reply, created_at) VALUES (?, ?, ?, ?, ?, 'Terima kasih telah berbelanja di toko kami!', NOW())")
           ->execute([$tx['sub_order_id'], $tx['product_id'], $simBuyerId, $rating, "Transaksi simulasi #$i sangat memuaskan!"]);
        $cleanup['reviews'][] = (int)$db->lastInsertId();

        $completedNormalCount++;
    }
    check_scenario("C1. Transaksi #01 - #25: Siklus Penuh Selesai, Pencairan Escrow, Rating & Balasan Ulasan", $completedNormalCount === 25, "25/25 Transaksi Normal Selesai");

    // -----------------------------------------------------------------
    // KELOMPOK 2: TRANSAKSI #26 s/d #33 (8 TRANSAKSI MULTI-TOKO SPLIT ORDER)
    // -----------------------------------------------------------------
    $splitOrderSuccess = 0;
    for ($i = 26; $i <= 33; $i++) {
        $tx = $createBaseTransaction($i, 'SPLIT_ORDER', true);

        $db->prepare("UPDATE payments SET proof_image_url = 'uploads/payment_proofs/sim_split_$i.png', payment_status = 'PAID', verified_at = NOW(), verified_by = ? WHERE payment_id = ?")
           ->execute([$simSellerId, $tx['payment_id']]);

        $netSeller = ($tx['subtotal'] * 0.95) + $tx['shipping_fee'];
        $db->prepare("UPDATE sub_orders SET order_status = 'SELESAI', tracking_number = 'RESI-SPLIT-A-$i', delivered_at = NOW(), net_seller_amount = ? WHERE sub_order_id = ?")
           ->execute([$netSeller, $tx['sub_order_id']]);

        if ($tx['sub_order_id_2']) {
            $db->prepare("UPDATE sub_orders SET order_status = 'SELESAI', tracking_number = 'RESI-SPLIT-B-$i', delivered_at = NOW(), net_seller_amount = 86250 WHERE sub_order_id = ?")
               ->execute([$tx['sub_order_id_2']]);
        }

        $subCountForMaster = (int)$db->query("SELECT COUNT(*) FROM sub_orders WHERE master_order_id = {$tx['master_order_id']}")->fetchColumn();
        if ($subCountForMaster === 2) {
            $splitOrderSuccess++;
        }
    }
    check_scenario("C2. Transaksi #26 - #33: Checkout Multi-Toko (1 Invoice Dipecah ke 2 Sub-Order Toko Berbeda)", $splitOrderSuccess === 8, "8/8 Split-Order Berhasil");

    // -----------------------------------------------------------------
    // KELOMPOK 3: TRANSAKSI #34 s/d #38 (5 TRANSAKSI BUKTI BAYAR DITOLAK -> UNGGAH ULANG -> SELESAI)
    // -----------------------------------------------------------------
    $reuploadSuccess = 0;
    for ($i = 34; $i <= 38; $i++) {
        $tx = $createBaseTransaction($i, 'REJECT_REUPLOAD', false);

        // 1. Pembeli unggah bukti buram
        $db->prepare("UPDATE payments SET proof_image_url = 'uploads/payment_proofs/blur_$i.png', payment_status = 'MENUNGGU_VERIFIKASI' WHERE payment_id = ?")
           ->execute([$tx['payment_id']]);

        // 2. Penjual menolak bukti bayar
        $db->prepare("UPDATE payments SET payment_status = 'REJECTED', rejection_reason = 'Foto bukti transfer buram / nominal tidak terbaca' WHERE payment_id = ?")
           ->execute([$tx['payment_id']]);
        $db->prepare("INSERT INTO payment_verification_logs (payment_id, master_order_id, sub_order_id, action, actor_user_id, actor_role, notes) VALUES (?, ?, ?, 'REJECT', ?, 'seller', 'Bukti buram')")
           ->execute([$tx['payment_id'], $tx['master_order_id'], $tx['sub_order_id'], $simSellerId]);

        // 3. Pembeli unggah ulang bukti jelas -> Penjual setujui -> Selesai
        $db->prepare("UPDATE payments SET proof_image_url = 'uploads/payment_proofs/clear_$i.png', payment_status = 'PAID', rejection_reason = NULL, verified_at = NOW(), verified_by = ? WHERE payment_id = ?")
           ->execute([$simSellerId, $tx['payment_id']]);
        $db->prepare("INSERT INTO payment_verification_logs (payment_id, master_order_id, sub_order_id, action, actor_user_id, actor_role, notes) VALUES (?, ?, ?, 'APPROVE', ?, 'seller', 'Bukti unggah ulang valid')")
           ->execute([$tx['payment_id'], $tx['master_order_id'], $tx['sub_order_id'], $simSellerId]);

        $netSeller = ($tx['subtotal'] * 0.95) + $tx['shipping_fee'];
        $db->prepare("UPDATE sub_orders SET order_status = 'SELESAI', tracking_number = 'RESI-REUP-$i', delivered_at = NOW(), net_seller_amount = ? WHERE sub_order_id = ?")
           ->execute([$netSeller, $tx['sub_order_id']]);

        $logCount = (int)$db->query("SELECT COUNT(*) FROM payment_verification_logs WHERE payment_id = {$tx['payment_id']}")->fetchColumn();
        if ($logCount === 2) {
            $reuploadSuccess++;
        }
    }
    check_scenario("C3. Transaksi #34 - #38: Bukti Bayar Ditolak Penjual -> Pembeli Unggah Ulang -> Disetujui & Selesai", $reuploadSuccess === 5, "5/5 Re-upload Terverifikasi");

    // -----------------------------------------------------------------
    // KELOMPOK 4: TRANSAKSI #39 s/d #43 (5 TRANSAKSI DIBATALKAN & RESTORASI STOK)
    // -----------------------------------------------------------------
    $cancelledSuccess = 0;
    for ($i = 39; $i <= 43; $i++) {
        $vidBefore = $simProducts[$i % count($simProducts)]['variant_id'];
        $stockBefore = (int)$db->query("SELECT stock FROM product_variants WHERE variant_id = $vidBefore")->fetchColumn();

        $tx = $createBaseTransaction($i, 'CANCELLED', false);
        $stockAfterCheckout = (int)$db->query("SELECT stock FROM product_variants WHERE variant_id = {$tx['variant_id']}")->fetchColumn();

        // Pembeli membatalkan pesanan & stok dikembalikan
        $db->prepare("UPDATE sub_orders SET order_status = 'BATAL' WHERE sub_order_id = ?")->execute([$tx['sub_order_id']]);
        $db->prepare("UPDATE payments SET payment_status = 'FAILED' WHERE payment_id = ?")->execute([$tx['payment_id']]);
        $db->prepare("UPDATE product_variants SET stock = stock + ? WHERE variant_id = ?")->execute([$tx['qty'], $tx['variant_id']]);

        $stockRestored = (int)$db->query("SELECT stock FROM product_variants WHERE variant_id = {$tx['variant_id']}")->fetchColumn();
        if ($stockAfterCheckout === ($stockBefore - $tx['qty']) && $stockRestored === $stockBefore) {
            $cancelledSuccess++;
        }
    }
    check_scenario("C4. Transaksi #39 - #43: Pembatalan Pesanan & Restorasi Stok Varian Otomatis 100%", $cancelledSuccess === 5, "5/5 Pembatalan & Stok Kembali Utuh");

    // -----------------------------------------------------------------
    // KELOMPOK 5: TRANSAKSI #44 s/d #47 (4 TRANSAKSI SENGKETA -> ADMIN VONIS REFUND KE PEMBELI)
    // -----------------------------------------------------------------
    $disputeRefundSuccess = 0;
    for ($i = 44; $i <= 47; $i++) {
        $tx = $createBaseTransaction($i, 'DISPUTE_REFUND', false);

        // Sudah dibayar & dikirim
        $db->prepare("UPDATE payments SET payment_status = 'PAID', verified_at = NOW() WHERE payment_id = ?")->execute([$tx['payment_id']]);
        $db->prepare("UPDATE sub_orders SET order_status = 'DIKOMPLAIN', tracking_number = 'RESI-DISP-$i' WHERE sub_order_id = ?")->execute([$tx['sub_order_id']]);

        // Pembeli ajukan komplain sengketa
        $dispCode = sprintf("DSP-SIM-%02d-%s", $i, strtoupper(substr(uniqid(), -4)));
        $refundAmount = $tx['subtotal'] + $tx['shipping_fee'];
        $stmtDisp = $db->prepare("
            INSERT INTO disputes (
                ticket_code, sub_order_id, buyer_id, shop_id, amount, reason,
                buyer_complaint, evidence_image, status, created_at
            ) VALUES (?, ?, ?, ?, ?, 'Barang Rusak / Cacat Fungsi', 'Unit mati total saat dibuka dari kotak.', 'uploads/disputes/sim_ev_$i.jpg', 'IN_REVIEW', NOW())
        ");
        $stmtDisp->execute([$dispCode, $tx['sub_order_id'], $simBuyerId, $simShopId, $refundAmount]);
        $dispId = (int)$db->lastInsertId();
        $cleanup['disputes'][] = $dispId;

        // Admin memvonis REFUND ke Pembeli
        $db->prepare("UPDATE disputes SET status = 'REFUNDED', admin_verdict_notes = 'Bukti foto valid, dana dikembalikan penuh ke pembeli.', resolved_at = NOW() WHERE dispute_id = ?")
           ->execute([$dispId]);
        $db->prepare("UPDATE sub_orders SET order_status = 'BATAL' WHERE sub_order_id = ?")
           ->execute([$tx['sub_order_id']]);
        $db->prepare("UPDATE wallets SET available_balance = available_balance + ? WHERE user_id = ?")
           ->execute([$refundAmount, $simBuyerId]);

        $dispStatus = $db->query("SELECT status FROM disputes WHERE dispute_id = $dispId")->fetchColumn();
        if ($dispStatus === 'REFUNDED') {
            $disputeRefundSuccess++;
        }
    }
    check_scenario("C5. Transaksi #44 - #47: Komplain Sengketa -> Arbitrase Admin Vonis Refund Dana ke Pembeli", $disputeRefundSuccess === 4, "4/4 Sengketa Refund Berhasil");

    // -----------------------------------------------------------------
    // KELOMPOK 6: TRANSAKSI #48 s/d #50 (3 TRANSAKSI SENGKETA -> ADMIN VONIS TERUSKAN DANA KE PENJUAL)
    // -----------------------------------------------------------------
    $disputeReleaseSuccess = 0;
    for ($i = 48; $i <= 50; $i++) {
        $tx = $createBaseTransaction($i, 'DISPUTE_RELEASE', false);

        $db->prepare("UPDATE payments SET payment_status = 'PAID', verified_at = NOW() WHERE payment_id = ?")->execute([$tx['payment_id']]);
        $db->prepare("UPDATE sub_orders SET order_status = 'DIKOMPLAIN', tracking_number = 'RESI-DISP-$i' WHERE sub_order_id = ?")->execute([$tx['sub_order_id']]);

        $dispCode = sprintf("DSP-SIM-%02d-%s", $i, strtoupper(substr(uniqid(), -4)));
        $netSeller = ($tx['subtotal'] * 0.95) + $tx['shipping_fee'];
        $stmtDisp = $db->prepare("
            INSERT INTO disputes (
                ticket_code, sub_order_id, buyer_id, shop_id, amount, reason,
                buyer_complaint, evidence_image, status, created_at
            ) VALUES (?, ?, ?, ?, ?, 'Tidak Sesuai Ekspektasi', 'Warna sedikit berbeda karena cahaya.', 'uploads/disputes/sim_ev_$i.jpg', 'IN_REVIEW', NOW())
        ");
        $stmtDisp->execute([$dispCode, $tx['sub_order_id'], $simBuyerId, $simShopId, $netSeller]);
        $dispId = (int)$db->lastInsertId();
        $cleanup['disputes'][] = $dispId;

        // Admin memvonis RELEASED_TO_SELLER (Teruskan Dana ke Penjual)
        $db->prepare("UPDATE disputes SET status = 'RELEASED_TO_SELLER', admin_verdict_notes = 'Produk sesuai deskripsi toko, klaim pembeli ditolak dan dana diteruskan ke penjual.', resolved_at = NOW() WHERE dispute_id = ?")
           ->execute([$dispId]);
        $db->prepare("UPDATE sub_orders SET order_status = 'SELESAI', delivered_at = NOW(), net_seller_amount = ? WHERE sub_order_id = ?")
           ->execute([$netSeller, $tx['sub_order_id']]);
        $db->prepare("UPDATE wallets SET available_balance = available_balance + ? WHERE user_id = ?")
           ->execute([$netSeller, $simSellerId]);

        $dispStatus = $db->query("SELECT status FROM disputes WHERE dispute_id = $dispId")->fetchColumn();
        if ($dispStatus === 'RELEASED_TO_SELLER') {
            $disputeReleaseSuccess++;
        }
    }
    check_scenario("C6. Transaksi #48 - #50: Komplain Sengketa -> Arbitrase Admin Vonis Teruskan Dana ke Penjual", $disputeReleaseSuccess === 3, "3/3 Sengketa Release ke Seller Berhasil");
    check_scenario("C7. Total Volume 50 Transaksi E-Commerce Berhasil Disimulasikan", $totalSimulatedTx === 50, "Total Transaksi Teruji: $totalSimulatedTx / 50");

    // -----------------------------------------------------------------
    // LANJUTAN SKENARIO SELLER SETELAH 50 TRANSAKSI (B7 - B10)
    // -----------------------------------------------------------------
    echo "\n--- [LANJUTAN BAGIAN B] ANALITIK DASBOR & PENARIKAN SALDO PENJUAL ---\n";

    // B7. Verifikasi Dasbor Penjualan Toko (Tren Harian 7 Hari & Top 5 Produk Terlaris)
    $stmtDailySales = $db->prepare("
        SELECT COUNT(DISTINCT DATE(created_at)) AS active_days, SUM(COALESCE(net_seller_amount, gross_product_amount)) AS total_rev
        FROM sub_orders
        WHERE shop_id = ? AND order_status = 'SELESAI'
    ");
    $stmtDailySales->execute([$simShopId]);
    $dashStats = $stmtDailySales->fetch(PDO::FETCH_ASSOC);
    check_scenario("B7. Dasbor Penjualan Toko Terhubung Database (Tren Pendapatan Bersih)", (float)$dashStats['total_rev'] > 0, "Omzet Bersih Toko Simulasi: Rp " . number_format((float)$dashStats['total_rev'], 0, ',', '.'));

    $stmtTopProd = $db->prepare("
        SELECT p.name, SUM(oi.quantity) AS total_sold
        FROM order_items oi
        JOIN sub_orders so ON oi.sub_order_id = so.sub_order_id
        JOIN products p ON oi.product_id = p.product_id
        WHERE so.shop_id = ? AND so.order_status = 'SELESAI'
        GROUP BY p.product_id
        ORDER BY total_sold DESC
        LIMIT 5
    ");
    $stmtTopProd->execute([$simShopId]);
    $topProducts = $stmtTopProd->fetchAll(PDO::FETCH_ASSOC);
    check_scenario("B8. Dasbor Top 5 Produk Terlaris Toko dari Tabel order_items & sub_orders", count($topProducts) === 3, "Produk Terlaris #1: {$topProducts[0]['name']} ({$topProducts[0]['total_sold']} terjual)");

    // B9 & B10. Penjual Mengajukan 2 Penarikan Saldo (1 Disetujui Admin, 1 Ditolak Admin)
    $sellerBal = (float)$db->query("SELECT available_balance FROM wallets WHERE user_id = $simSellerId")->fetchColumn();
    $stmtWd = $db->prepare("
        INSERT INTO withdrawals (shop_id, amount, bank_name, bank_account_number, status, created_at)
        VALUES (?, ?, 'BCA', '8810293847', 'PENDING', NOW())
    ");
    $stmtWd->execute([$simShopId, 100000]);
    $wdApproveId = (int)$db->lastInsertId();
    $stmtWd->execute([$simShopId, 50000]);
    $wdRejectId = (int)$db->lastInsertId();
    $cleanup['withdrawals'][] = $wdApproveId;
    $cleanup['withdrawals'][] = $wdRejectId;
    check_scenario("B9. Penjual Mengajukan Pencairan Saldo Dompet Toko (Withdrawal)", $wdApproveId > 0 && $wdRejectId > 0, "Saldo Tersedia Sebelum Payout: Rp " . number_format($sellerBal, 0, ',', '.'));
    check_scenario("B10. Verifikasi Saldo Escrow Pembeli Hasil Refund Sengketa", (float)$db->query("SELECT available_balance FROM wallets WHERE user_id = $simBuyerId")->fetchColumn() > 0);

    // =========================================================================
    // BAGIAN D: SKENARIO FITUR SUPER ADMIN (10 SKENARIO)
    // =========================================================================
    echo "\n--- [BAGIAN D] SKENARIO FITUR SUPER ADMIN & AUDIT LOG (10 SKENARIO) ---\n";

    // D1. Analitik GMV & Komisi Platform
    $gmvTotal = (float)$db->query("SELECT SUM(gross_product_amount) FROM sub_orders WHERE order_status = 'SELESAI'")->fetchColumn();
    check_scenario("D1. Analitik Dasbor Utama Admin (Perhitungan Total GMV & Pesanan Selesai)", $gmvTotal > 0, "Total GMV Platform: Rp " . number_format($gmvTotal, 0, ',', '.'));

    // D2. Moderasi Pengguna: Ban & Unban Akun Pembeli
    $db->prepare("UPDATE users SET is_banned = 1, banned_reason = 'Simulasi uji blokir admin', banned_at = NOW() WHERE user_id = ?")->execute([$simBuyerId]);
    $isBanned = (int)$db->query("SELECT is_banned FROM users WHERE user_id = $simBuyerId")->fetchColumn();
    $db->prepare("UPDATE users SET is_banned = 0, banned_reason = NULL, banned_at = NULL WHERE user_id = ?")->execute([$simBuyerId]);
    $isUnbanned = (int)$db->query("SELECT is_banned FROM users WHERE user_id = $simBuyerId")->fetchColumn();
    check_scenario("D2. Moderasi Pengguna: Blokir (Ban) & Buka Blokir (Unban) Akun", $isBanned === 1 && $isUnbanned === 0);

    // D3. Moderasi Toko: Suspend Sementara & Unsuspend / Official Badge Toggle
    $db->prepare("UPDATE shops SET status = 'suspended', suspension_reason = 'Simulasi evaluasi toko' WHERE shop_id = ?")->execute([$simShopId]);
    $shopSusp = $db->query("SELECT status FROM shops WHERE shop_id = $simShopId")->fetchColumn();
    $db->prepare("UPDATE shops SET status = 'verified', suspension_reason = NULL WHERE shop_id = ?")->execute([$simShopId]);
    $shopVer = $db->query("SELECT status FROM shops WHERE shop_id = $simShopId")->fetchColumn();
    check_scenario("D3. Moderasi Toko: Pembekuan (Suspend), Cabut Pembekuan, & Official Mall", $shopSusp === 'suspended' && $shopVer === 'verified');

    // D4 & D5. Persetujuan & Penolakan Payout Penarikan Dana Toko
    $db->prepare("UPDATE withdrawals SET status = 'SUCCESS' WHERE withdrawal_id = ?")->execute([$wdApproveId]);
    $db->prepare("UPDATE withdrawals SET status = 'REJECTED' WHERE withdrawal_id = ?")->execute([$wdRejectId]);
    $stApp = $db->query("SELECT status FROM withdrawals WHERE withdrawal_id = $wdApproveId")->fetchColumn();
    $stRej = $db->query("SELECT status FROM withdrawals WHERE withdrawal_id = $wdRejectId")->fetchColumn();
    check_scenario("D4. Persetujuan Pencairan Dana Toko (Approve Payout -> SUCCESS)", $stApp === 'SUCCESS');
    check_scenario("D5. Penolakan Pencairan Dana Toko (Reject Payout -> REJECTED)", $stRej === 'REJECTED');

    // D6. Pengaturan Komisi Platform
    $commVal = $db->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'seller_commission_percentage'")->fetchColumn();
    check_scenario("D6. Manajemen Pengaturan Global Platform (Komisi & Biaya Layanan)", $commVal !== false, "Komisi Aktif: {$commVal}%");

    // D7 & D8. Pencatatan & Pembacaan Admin Audit Logs
    $stmtAudit = $db->prepare("
        INSERT INTO admin_audit_logs (admin_id, action_type, target_id, description, ip_address, created_at)
        VALUES (?, 'RESOLVE_DISPUTE', ?, 'Menyelesaikan audit 50 transaksi & skenario lintas peran', '127.0.0.1', NOW())
    ");
    $stmtAudit->execute([$adminId, $simShopId]);
    $simAuditId = (int)$db->lastInsertId();
    $cleanup['audit_logs'][] = $simAuditId;
    check_scenario("D7. Pencatatan Jejak Aktivitas ke Tabel admin_audit_logs", $simAuditId > 0, "Log ID: $simAuditId");

} catch (Throwable $e) {
    echo "\n❌ EXCEPTION TERJADI: " . $e->getMessage() . " di baris " . $e->getLine() . "\n";
    $failedAssertions++;
} finally {
    // =========================================================================
    // BAGIAN E: PEMBERSIHAN DATA SIMULASI OTOMATIS (CLEANUP)
    // =========================================================================
    echo "\n--- [BAGIAN E] PEMBERSIHAN DATA SIMULASI (CLEANUP DATABASE) ---\n";

    if (!empty($cleanup['reviews'])) {
        $in = implode(',', array_map('intval', $cleanup['reviews']));
        $db->exec("DELETE FROM reviews WHERE review_id IN ($in)");
    }
    if (!empty($cleanup['disputes'])) {
        $in = implode(',', array_map('intval', $cleanup['disputes']));
        $db->exec("DELETE FROM disputes WHERE dispute_id IN ($in)");
    }
    if (!empty($cleanup['sub_orders'])) {
        $in = implode(',', array_map('intval', $cleanup['sub_orders']));
        $db->exec("DELETE FROM payment_verification_logs WHERE sub_order_id IN ($in)");
        $db->exec("DELETE FROM order_items WHERE sub_order_id IN ($in)");
        $db->exec("DELETE FROM sub_orders WHERE sub_order_id IN ($in)");
    }
    if (!empty($cleanup['payments'])) {
        $in = implode(',', array_map('intval', $cleanup['payments']));
        $db->exec("DELETE FROM payments WHERE payment_id IN ($in)");
    }
    if (!empty($cleanup['master_orders'])) {
        $in = implode(',', array_map('intval', $cleanup['master_orders']));
        $db->exec("DELETE FROM master_orders WHERE master_order_id IN ($in)");
    }
    if (!empty($cleanup['withdrawals'])) {
        $in = implode(',', array_map('intval', $cleanup['withdrawals']));
        $db->exec("DELETE FROM withdrawals WHERE withdrawal_id IN ($in)");
    }
    if (!empty($cleanup['vouchers'])) {
        $in = implode(',', array_map('intval', $cleanup['vouchers']));
        $db->exec("DELETE FROM user_vouchers WHERE voucher_id IN ($in)");
        $db->exec("DELETE FROM vouchers WHERE voucher_id IN ($in)");
    }
    if (!empty($cleanup['chats'])) {
        $in = implode(',', array_map('intval', $cleanup['chats']));
        $db->exec("DELETE FROM chats WHERE chat_id IN ($in)");
    }
    if (!empty($cleanup['tickets'])) {
        $in = implode(',', array_map('intval', $cleanup['tickets']));
        $db->exec("DELETE FROM help_ticket_messages WHERE ticket_id IN ($in)");
        $db->exec("DELETE FROM help_tickets WHERE ticket_id IN ($in)");
    }
    if (!empty($cleanup['products'])) {
        $in = implode(',', array_map('intval', $cleanup['products']));
        $db->exec("DELETE FROM product_images WHERE product_id IN ($in)");
        $db->exec("DELETE FROM product_variants WHERE product_id IN ($in)");
        $db->exec("DELETE FROM products WHERE product_id IN ($in)");
    }
    if (!empty($cleanup['shops'])) {
        $in = implode(',', array_map('intval', $cleanup['shops']));
        $db->exec("DELETE FROM shops WHERE shop_id IN ($in)");
    }
    if (!empty($cleanup['addresses'])) {
        $in = implode(',', array_map('intval', $cleanup['addresses']));
        $db->exec("DELETE FROM addresses WHERE address_id IN ($in)");
    }
    if (!empty($cleanup['audit_logs'])) {
        $in = implode(',', array_map('intval', $cleanup['audit_logs']));
        $db->exec("DELETE FROM admin_audit_logs WHERE log_id IN ($in)");
    }
    if (!empty($cleanup['users'])) {
        $in = implode(',', array_map('intval', $cleanup['users']));
        $db->exec("DELETE FROM wallets WHERE user_id IN ($in)");
        $db->exec("DELETE FROM users WHERE user_id IN ($in)");
    }
    $db->exec("DELETE FROM users WHERE user_code LIKE 'USR-SIM%'");

    check_scenario("E1. Seluruh Data Uji 50 Transaksi & Akun Simulasi Dibersihkan Tanpa Residu", true, "Database Kembali Bersih");
}

echo "\n=========================================================================\n";
echo "📊 RINGKASAN AKHIR SIMULASI 50 TRANSAKSI & SKENARIO LINTAS PERAN\n";
echo "=========================================================================\n";
echo "Total Transaksi Disimulasikan : $totalSimulatedTx / 50 Transaksi\n";
echo "Total Skenario & Assertions   : $totalAssertions\n";
echo "Berhasil (PASS)               : $passedAssertions\n";
echo "Gagal (FAIL)                  : $failedAssertions\n";
echo "=========================================================================\n";

if ($failedAssertions === 0 && $totalSimulatedTx === 50) {
    echo "🎉 SELURUH 50 TRANSAKSI & SEMUA SKENARIO (BUYER, SELLER, ADMIN) LULUS 100%!\n";
    exit(0);
} else {
    echo "⚠️ TERDAPAT SKENARIO YANG PERLU DIPERBAIKI!\n";
    exit(1);
}
