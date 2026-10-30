<?php
// C:/laragon/www/on-shop/api/seller/get_unread_counters.php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/seller_auth.php'; // Proteksi sesi multi-seller

try {
    // 1. Hitung Produk Habis (Stok 0 dan Tidak Diarsipkan)
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
    $stmtStock->execute([':shop_id' => $shop_id]);
    $emptyStockCount = (int)$stmtStock->fetchColumn();

    // 2. Hitung Pesanan yang Perlu Diproses (MENUNGGU_VERIFIKASI atau DIKEMAS)
    $stmtOrders = $db->prepare("
        SELECT COUNT(*) 
        FROM sub_orders 
        WHERE shop_id = :shop_id 
          AND order_status IN ('MENUNGGU_VERIFIKASI', 'DIKEMAS')
    ");
    $stmtOrders->execute([':shop_id' => $shop_id]);
    $pendingOrdersCount = (int)$stmtOrders->fetchColumn();

    // 3. Hitung Pesan Pelanggan Belum Dibaca (Tabel chats)
    $stmtChats = $db->prepare("
        SELECT COUNT(*) 
        FROM chats 
        WHERE receiver_id = :user_id 
          AND is_read = 0
    ");
    $stmtChats->execute([':user_id' => $user_id]);
    $unreadChatsCount = (int)$stmtChats->fetchColumn();

    // 4. Hitung Ulasan Terbaru Toko (7 Hari Terakhir)
    $stmtRev = $db->prepare("
        SELECT COUNT(*) 
        FROM reviews r
        JOIN sub_orders so ON r.sub_order_id = so.sub_order_id
        WHERE so.shop_id = :shop_id 
          AND r.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $stmtRev->execute([':shop_id' => $shop_id]);
    $newReviewsCount = (int)$stmtRev->fetchColumn();

    echo json_encode([
        "status"         => true,
        "empty_stock"    => $emptyStockCount,
        "pending_orders" => $pendingOrdersCount,
        "unread_chats"   => $unreadChatsCount,
        "new_reviews"    => $newReviewsCount
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status"  => false,
        "message" => "Gagal mengambil counters: " . $e->getMessage()
    ]);
    exit;
}
