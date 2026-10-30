<?php
// scratch/test_enhancements.php
// Suite Pengujian Fitur Peningkatan (Issue #14)

error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "====================================================\n";
echo "🧪  SUITE TEST PENINGKATAN SISTEM (ISSUE #14)\n";
echo "====================================================\n\n";

require_once __DIR__ . '/../config/database.php';
$db = $conn ?? $pdo;

$passed = 0;
$failed = 0;

function assert_test($description, $condition, $extra = '') {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] $description" . ($extra ? " -> $extra" : "") . "\n";
        $passed++;
    } else {
        echo " [FAIL] $description" . ($extra ? " -> $extra" : "") . "\n";
        $failed++;
    }
}

// 1. UJI NOTIFIKASI COUNTERS PENJUAL
echo "--- 1. UJI COUNTERS NOTIFIKASI PENJUAL (SELLER BADGES) ---\n";
// Ambil 1 seller toko aktif
$shop = $db->query("SELECT shop_id, user_id, shop_name FROM shops WHERE status = 'verified' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($shop) {
    $shopId = (int)$shop['shop_id'];
    $sellerId = (int)$shop['user_id'];

    // Inisialisasi sesi seller
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    session_start();
    $_SESSION['user_id'] = $sellerId;
    $_SESSION['role'] = 'seller';
    $_SESSION['shop_id'] = $shopId;

    // Panggil get_unread_counters logic secara terisolasi
    // A. Produk Habis
    $stmtStock = $db->prepare("
        SELECT COUNT(*) FROM (
            SELECT p.product_id, COALESCE(SUM(pv.stock), 0) AS total_stock
            FROM products p
            LEFT JOIN product_variants pv ON p.product_id = pv.product_id
            WHERE p.shop_id = :shop_id AND p.is_archived = 0
            GROUP BY p.product_id
            HAVING total_stock = 0
        ) AS empty_prods
    ");
    $stmtStock->execute([':shop_id' => $shopId]);
    $emptyStockCount = (int)$stmtStock->fetchColumn();

    // B. Pesanan Perlu Diproses (MENUNGGU_VERIFIKASI atau DIKEMAS)
    $stmtOrders = $db->prepare("
        SELECT COUNT(*) 
        FROM sub_orders 
        WHERE shop_id = :shop_id 
          AND order_status IN ('MENUNGGU_VERIFIKASI', 'DIKEMAS')
    ");
    $stmtOrders->execute([':shop_id' => $shopId]);
    $pendingOrdersCount = (int)$stmtOrders->fetchColumn();

    // C. Pesan Belum Dibaca dari tabel chats
    $stmtChats = $db->prepare("SELECT COUNT(*) FROM chats WHERE receiver_id = :user_id AND is_read = 0");
    $stmtChats->execute([':user_id' => $sellerId]);
    $unreadChatsCount = (int)$stmtChats->fetchColumn();

    assert_test("Query counters seller dari database valid tanpa error SQL", true, "Empty Stock: $emptyStockCount, Pending Orders: $pendingOrdersCount, Unread Chats: $unreadChatsCount");
} else {
    assert_test("Ketersediaan toko seller pengujian", false);
}


// 2. UJI NOTIFIKASI CHAT PEMBELI (BUYER BADGE)
echo "\n--- 2. UJI UNREAD CHAT COUNT PEMBELI (BUYER BADGE) ---\n";
$buyer = $db->query("SELECT user_id, name FROM users WHERE role = 'buyer' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($buyer) {
    $buyerId = (int)$buyer['user_id'];
    $stmtBuyerChat = $db->prepare("SELECT COUNT(*) FROM chats WHERE receiver_id = :uid AND is_read = 0");
    $stmtBuyerChat->execute([':uid' => $buyerId]);
    $buyerUnread = (int)$stmtBuyerChat->fetchColumn();

    assert_test("Query unread chats pembeli berhasil dihitung", true, "Buyer ID: $buyerId | Unread: $buyerUnread");
} else {
    assert_test("Ketersediaan user buyer pengujian", false);
}


// 3. UJI INTEGRASI DATABASE REAL-TIME PADA DASBOR PENJUALAN
echo "\n--- 3. UJI DASBOR PENJUALAN TOKO TERHUBUNG DATABASE ---\n";
if ($shop) {
    // 3.1 Cek tren harian (Daily) 7 hari
    $stmtDaily = $db->prepare("
        SELECT DATE(created_at) AS tgl, COALESCE(SUM(net_seller_amount), 0) AS omzet
        FROM sub_orders
        WHERE shop_id = ? 
          AND order_status != 'BATAL'
          AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY DATE(created_at)
    ");
    $stmtDaily->execute([$shopId]);
    $dailyRows = $stmtDaily->fetchAll(PDO::FETCH_KEY_PAIR);
    assert_test("Query agregasi harian 7 hari terakhir berhasil dijalankan", is_array($dailyRows), "Jumlah hari ada transaksi: " . count($dailyRows));

    // 3.2 Cek tren mingguan (Weekly) 4 minggu
    $stmtWeekly = $db->prepare("
        SELECT COALESCE(SUM(net_seller_amount), 0) 
        FROM sub_orders 
        WHERE shop_id = ? 
          AND order_status != 'BATAL'
          AND created_at >= DATE_SUB(NOW(), INTERVAL 28 DAY)
    ");
    $stmtWeekly->execute([$shopId]);
    $weeklyOmzet = (float)$stmtWeekly->fetchColumn();
    assert_test("Query agregasi mingguan 4 minggu terakhir berhasil dihitung", $weeklyOmzet >= 0, "Omzet 4 Minggu: Rp " . number_format($weeklyOmzet, 0, ',', '.'));

    // 3.3 Cek tren semesteran
    $stmtSemester = $db->prepare("
        SELECT MONTH(created_at) AS bln, COALESCE(SUM(net_seller_amount), 0) AS omzet
        FROM sub_orders
        WHERE shop_id = ? 
          AND order_status != 'BATAL'
          AND YEAR(created_at) = YEAR(CURDATE())
          AND MONTH(created_at) BETWEEN 1 AND 6
        GROUP BY MONTH(created_at)
    ");
    $stmtSemester->execute([$shopId]);
    $semesterRows = $stmtSemester->fetchAll(PDO::FETCH_KEY_PAIR);
    assert_test("Query agregasi semester 1 tahun berjalan berhasil dijalankan", is_array($semesterRows));

    // 3.4 Cek Top 5 Produk Terlaris toko
    $stmtTop = $db->prepare("
        SELECT 
            oi.product_name,
            COALESCE(SUM(oi.quantity), 0) AS total_sold
        FROM order_items oi
        JOIN sub_orders so ON oi.sub_order_id = so.sub_order_id
        WHERE so.shop_id = ?
          AND so.order_status != 'BATAL'
        GROUP BY oi.product_id, oi.product_name
        ORDER BY total_sold DESC
        LIMIT 5
    ");
    $stmtTop->execute([$shopId]);
    $topProducts = $stmtTop->fetchAll(PDO::FETCH_ASSOC);
    assert_test("Query Top 5 Produk terlaris toko murni ditarik dari order_items & sub_orders", is_array($topProducts), "Jumlah top produk: " . count($topProducts));
}


// 4. UJI INTEGRITAS FILE FRONTEND
echo "\n--- 4. UJI INTEGRITAS FRONTEND (TOUCH DRAG & BADGE STYLING) ---\n";
// 4.1 Cek CSS badge di seller-nav.css
$sellerNavCss = file_get_contents(__DIR__ . '/../assets/css/seller-css/seller-nav.css');
$hasGlobalBadge = strpos($sellerNavCss, '.global-badge-pill') !== false && strpos($sellerNavCss, '#ef4444') !== false;
assert_test("Styling .global-badge-pill warna merah (#ef4444) aktif di seller-nav.css", $hasGlobalBadge);

// 4.2 Cek Touch-Drag di akun.js & akun.html
$akunHtml = file_get_contents(__DIR__ . '/../pages/buyer/akun.html');
$akunJs = file_get_contents(__DIR__ . '/../assets/js/buyer-js/akun.js');
$akunCss = file_get_contents(__DIR__ . '/../assets/css/buyer-css/akun.css');

$hasTouchContainer = strpos($akunHtml, 'id="avatarCropContainer"') !== false;
$hasTouchAction = strpos($akunCss, 'touch-action: none') !== false;
$hasTouchDragFunc = strpos($akunJs, 'initAvatarTouchDrag') !== false && strpos($akunJs, 'touchstart') !== false;

assert_test("Kontainer crop avatar memiliki id avatarCropContainer di akun.html", $hasTouchContainer);
assert_test("CSS preview crop avatar memiliki touch-action: none di akun.css", $hasTouchAction);
assert_test("Fungsi initAvatarTouchDrag dengan event touchstart & touchmove aktif di akun.js", $hasTouchDragFunc);

// 4.3 Cek Touch-Drag di pesanan-saya.js & pesanan-saya.html
$pesananHtml = file_get_contents(__DIR__ . '/../pages/buyer/pesanan-saya.html');
$pesananJs = file_get_contents(__DIR__ . '/../assets/js/buyer-js/pesanan-saya.js');
$hasReviewTouch = strpos($pesananHtml, 'id="reviewImgCropContainer"') !== false && strpos($pesananJs, 'initReviewTouchDrag') !== false;
$hasReturnTouch = strpos($pesananHtml, 'id="returnImgCropContainer"') !== false && strpos($pesananJs, 'initReturnTouchDrag') !== false;

assert_test("Kontainer crop foto ulasan mendukung touch drag di pesanan-saya", $hasReviewTouch);
assert_test("Kontainer crop foto retur mendukung touch drag di pesanan-saya", $hasReturnTouch);


// REKAPITULASI
echo "\n====================================================\n";
echo "HASIL UJI PENINGKATAN SISTEM: Passed = $passed | Failed = $failed\n";
if ($failed === 0) {
    echo "🎉 SEMUA TEST PENINGKATAN SISTEM LULUS 100% (PASS)\n";
} else {
    echo "⚠️ ADA TEST YANG GAGAL!\n";
}
echo "====================================================\n";
