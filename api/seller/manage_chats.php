<?php
// C:/laragon/www/on-shop/api/seller/manage_chats.php
require_once __DIR__ . '/seller_auth.php';
header("Content-Type: application/json; charset=UTF-8");

$rawInput = file_get_contents("php://input");
$postData = json_decode($rawInput, true) ?: [];

$sellerUserId = $user_id;
$action = isset($_GET['action']) ? trim($_GET['action']) : '';

try {
    // Pastikan user memiliki toko
    $stmtShop = $conn->prepare("SELECT shop_id FROM shops WHERE user_id = :uid LIMIT 1");
    $stmtShop->execute(['uid' => $sellerUserId]);
    $myShop = $stmtShop->fetch(PDO::FETCH_ASSOC);

    if (!$myShop) {
        echo json_encode(["status" => false, "message" => "Anda belum memiliki toko terdaftar.", "data" => []]);
        exit;
    }
    $myShopId = (int)$myShop['shop_id'];

    // 1. Ambil Kontak Pembeli di Kotak Masuk Penjual
    if ($action === 'get_conversations') {
        $sql = "
            SELECT 
                u.user_id AS buyer_id,
                COALESCE(u.name, 'Pembeli') AS buyer_name,
                c.message AS last_message,
                c.created_at AS last_time,
                (SELECT COUNT(*) FROM chats WHERE sender_id = u.user_id AND receiver_id = :sid AND is_read = 0) AS unread_count
            FROM chats c
            JOIN users u ON (u.user_id = IF(c.sender_id = :sid, c.receiver_id, c.sender_id))
            WHERE (c.sender_id = :sid OR c.receiver_id = :sid)
              AND u.user_id != :sid
              AND c.chat_id IN (
                  SELECT MAX(chat_id) FROM chats 
                  WHERE sender_id = :sid OR receiver_id = :sid 
                  GROUP BY IF(sender_id = :sid, receiver_id, sender_id)
              )
              -- Memastikan obrolan ini adalah pembeli yang masuk ke toko milik seller ini
              AND (
                  EXISTS (
                      SELECT 1 FROM chats c2 
                      WHERE ((c2.sender_id = u.user_id AND c2.receiver_id = :sid) OR (c2.sender_id = :sid AND c2.receiver_id = u.user_id))
                        AND c2.product_id IN (SELECT product_id FROM products WHERE shop_id = :shop_id)
                  )
                  OR NOT EXISTS (
                      SELECT 1 FROM chats c3
                      JOIN products p ON c3.product_id = p.product_id
                      JOIN shops s ON p.shop_id = s.shop_id
                      WHERE ((c3.sender_id = u.user_id AND c3.receiver_id = :sid) OR (c3.sender_id = :sid AND c3.receiver_id = u.user_id))
                        AND s.user_id = u.user_id
                  )
              )
            ORDER BY c.created_at DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            'sid'     => $sellerUserId,
            'shop_id' => $myShopId
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['last_time'] = date("H:i", strtotime($r['last_time']));
        }

        echo json_encode(["status" => true, "data" => $rows]);
        exit;
    }

    // 2. Ambil Riwayat Chat dengan Pembeli Tertentu
    if ($action === 'get_messages') {
        $buyerId = isset($_GET['buyer_id']) ? (int)$_GET['buyer_id'] : 0;
        if ($buyerId <= 0) {
            echo json_encode(["status" => false, "message" => "ID Pembeli tidak valid."]);
            exit;
        }

        $conn->prepare("UPDATE chats SET is_read = 1 WHERE sender_id = :bid AND receiver_id = :sid AND is_read = 0")
             ->execute(['bid' => $buyerId, 'sid' => $sellerUserId]);

        $stmt = $conn->prepare("
            SELECT chat_id, sender_id, receiver_id, message, product_id, is_read, created_at,
                   DATE_FORMAT(created_at, '%H:%i') AS created_at_formatted
            FROM chats
            WHERE (sender_id = :sid AND receiver_id = :bid)
               OR (sender_id = :bid AND receiver_id = :sid)
            ORDER BY created_at ASC
        ");
        $stmt->execute(['sid' => $sellerUserId, 'bid' => $buyerId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Ambil sematan produk dari toko ini
        $relatedProduct = null;
        $stmtP = $conn->prepare("
            SELECT p.product_id, p.name AS product_name,
                   COALESCE((SELECT price FROM product_variants WHERE product_id = p.product_id ORDER BY price ASC LIMIT 1), 0) AS price_at_purchase,
                   COALESCE((SELECT image_url FROM product_images WHERE product_id = p.product_id ORDER BY is_primary DESC, sort_order ASC LIMIT 1), 'https://placehold.co/100x100?text=Produk') AS product_image_url
            FROM chats c
            JOIN products p ON p.product_id = c.product_id
            WHERE ((c.sender_id = :sid AND c.receiver_id = :bid) OR (c.sender_id = :bid AND c.receiver_id = :sid))
              AND c.product_id IS NOT NULL
              AND p.shop_id = :shop_id
            ORDER BY c.chat_id DESC
            LIMIT 1
        ");
        $stmtP->execute(['sid' => $sellerUserId, 'bid' => $buyerId, 'shop_id' => $myShopId]);
        $pRow = $stmtP->fetch(PDO::FETCH_ASSOC);

        if ($pRow) {
            $relatedProduct = [
                'product_name'      => $pRow['product_name'],
                'price_at_purchase' => (int)$pRow['price_at_purchase'],
                'product_image_url' => $pRow['product_image_url'],
                'sub_order_number'  => 'INQUIRY'
            ];
        }

        echo json_encode([
            "status"          => true,
            "seller_id"       => $sellerUserId,
            "messages"        => $messages,
            "related_product" => $relatedProduct
        ]);
        exit;
    }

    // 3. Kirim Balasan dari Penjual ke Pembeli
    if ($action === 'send_message') {
        $receiverId = isset($postData['receiver_id']) ? (int)$postData['receiver_id'] : 0;
        $message    = isset($postData['message']) ? trim($postData['message']) : '';

        if ($receiverId <= 0 || empty($message)) {
            echo json_encode(["status" => false, "message" => "Penerima atau isi pesan tidak boleh kosong."]);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO chats (sender_id, receiver_id, message, is_read, created_at) VALUES (:sid, :rid, :msg, 0, NOW())");
        $stmt->execute([
            'sid' => $sellerUserId,
            'rid' => $receiverId,
            'msg' => $message
        ]);

        echo json_encode(["status" => true, "message" => "Pesan balasan terkirim."]);
        exit;
    }

} catch (PDOException $e) {
    error_log("Seller manage_chats error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Terjadi kesalahan pada sistem."]);
    exit;
}
?>