<?php
// C:/laragon/www/on-shop/api/seller/manage_orders.php
require_once __DIR__ . '/seller_auth.php';
header('Content-Type: application/json; charset=UTF-8');

$action = $_GET['action'] ?? 'get_orders';

try {
    // -------------------------------------------------------------
    // A. MENGAMBIL DAFTAR PESANAN LENGKAP DENGAN ITEM
    // -------------------------------------------------------------
    if ($action === 'get_orders') {
        // Ambil data sub_orders dan master_orders
        $query = "
            SELECT 
                so.sub_order_id,
                so.master_order_id,
                so.sub_order_number,
                so.courier_name,
                so.courier_service,
                so.tracking_number,
                so.shipping_cost,
                so.order_status,
                so.gross_product_amount,
                so.net_seller_amount,
                DATE_FORMAT(so.created_at, '%d %b %Y, %H:%i WIB') AS created_at_formatted,
                mo.shipping_recipient_name,
                mo.shipping_phone,
                CONCAT(mo.shipping_street_address, ', ', mo.shipping_city, ', ', mo.shipping_province, ' ', mo.shipping_postal_code) AS full_shipping_address,
                COALESCE(u.name, mo.shipping_recipient_name) AS buyer_name
            FROM sub_orders so
            JOIN master_orders mo ON so.master_order_id = mo.master_order_id
            JOIN users u ON mo.buyer_id = u.user_id
            WHERE so.shop_id = ?
            ORDER BY so.sub_order_id DESC
        ";
        $stmt = $db->prepare($query);
        $stmt->execute([$shop_id]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Ambil rincian produk untuk masing-masing pesanan
        foreach ($orders as &$order) {
            $itemStmt = $db->prepare("
                SELECT 
                    oi.order_item_id,
                    oi.product_name,
                    oi.variant_name,
                    oi.product_image_url,
                    oi.price_at_purchase,
                    oi.quantity,
                    oi.total_price,
                    COALESCE(pv.sku, 'RAK-01') AS sku
                FROM order_items oi
                LEFT JOIN product_variants pv ON oi.variant_id = pv.variant_id
                WHERE oi.sub_order_id = ?
            ");
            $itemStmt->execute([$order['sub_order_id']]);
            $order['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode(["status" => true, "data" => $orders]);
        exit;
    }

    // -------------------------------------------------------------
    // B. SERAHKAN KE KURIR (UPDATE STATUS JADI DIKIRIM & SET RESI)
    // -------------------------------------------------------------
    if ($action === 'handover_courier') {
        $input = json_decode(file_get_contents('php://input'), true);
        $sub_order_id = (int)($input['sub_order_id'] ?? 0);
        $tracking_number = trim($input['tracking_number'] ?? '');

        if ($sub_order_id <= 0) {
            echo json_encode(["status" => false, "message" => "ID Pesanan tidak valid."]);
            exit;
        }

        if (empty($tracking_number)) {
            $tracking_number = 'ON-TRK-' . strtoupper(substr(md5(uniqid()), 0, 10));
        }

        $stmt = $db->prepare("
            UPDATE sub_orders 
            SET order_status = 'DIKIRIM', 
                tracking_number = ?, 
                shipped_at = NOW() 
            WHERE sub_order_id = ? AND shop_id = ?
        ");
        $stmt->execute([$tracking_number, $sub_order_id, $shop_id]);

        echo json_encode([
            "status" => true, 
            "message" => "Pesanan berhasil diserahkan ke kurir!",
            "tracking_number" => $tracking_number
        ]);
        exit;
    }

    // -------------------------------------------------------------
    // C. BATALKAN PESANAN TOKO
    // -------------------------------------------------------------
    if ($action === 'cancel_order') {
        $input = json_decode(file_get_contents('php://input'), true);
        $sub_order_id = (int)($input['sub_order_id'] ?? 0);

        if ($sub_order_id <= 0) {
            echo json_encode(["status" => false, "message" => "ID Pesanan tidak valid."]);
            exit;
        }

        $stmt = $db->prepare("UPDATE sub_orders SET order_status = 'BATAL' WHERE sub_order_id = ? AND shop_id = ?");
        $stmt->execute([$sub_order_id, $shop_id]);

        echo json_encode(["status" => true, "message" => "Pesanan berhasil dibatalkan."]);
        exit;
    }

} catch (Exception $e) {
    error_log("Seller manage_orders error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Terjadi kesalahan pada sistem."]);
    exit;
}
?>