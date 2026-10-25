<?php
// C:/laragon/www/on-shop/api/buyer/manage_chats.php
require_once __DIR__ . '/../helpers/auth.php';
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// 1. Ambil identitas terpercaya dari sesi server
$authUserId = require_auth();

$rawInput = file_get_contents("php://input");
$postData = json_decode($rawInput, true) ?: [];

$action = isset($_GET['action']) ? trim($_GET['action']) : '';

try {
    // 1. Inisialisasi Obrolan dari Toko/Produk
    if ($action === 'init_chat') {
        $sellerId  = isset($_GET['seller_id']) ? (int)$_GET['seller_id'] : 0;
        $shopId    = isset($_GET['shop_id']) ? (int)$_GET['shop_id'] : 0;
        $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;

        $sellerData = null;
        if ($shopId > 0) {
            $stmt = $conn->prepare("SELECT s.shop_id, s.shop_name, s.user_id AS seller_id FROM shops s WHERE s.shop_id = :sid LIMIT 1");
            $stmt->execute(['sid' => $shopId]);
            $sellerData = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$sellerData && $productId > 0) {
            $stmt = $conn->prepare("SELECT s.shop_id, s.shop_name, s.user_id AS seller_id FROM products p JOIN shops s ON s.shop_id = p.shop_id WHERE p.product_id = :pid LIMIT 1");
            $stmt->execute(['pid' => $productId]);
            $sellerData = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$sellerData && $sellerId > 0) {
            $stmt = $conn->prepare("SELECT s.shop_id, COALESCE(s.shop_name, u.name) AS shop_name, u.user_id AS seller_id FROM users u LEFT JOIN shops s ON s.user_id = u.user_id WHERE u.user_id = :uid LIMIT 1");
            $stmt->execute(['uid' => $sellerId]);
            $sellerData = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$sellerData || empty($sellerData['seller_id'])) {
            echo json_encode(["status" => false, "message" => "Toko penjual tidak ditemukan."]);
            exit;
        }

        $relatedProduct = null;
        if ($productId > 0) {
            $stmtP = $conn->prepare("
                SELECT p.product_id, p.name AS product_name,
                       COALESCE((SELECT price FROM product_variants WHERE product_id = p.product_id ORDER BY price ASC LIMIT 1), 0) AS display_price,
                       COALESCE((SELECT image_url FROM product_images WHERE product_id = p.product_id ORDER BY is_primary DESC, sort_order ASC LIMIT 1), 'https://placehold.co/100x100?text=Produk') AS image_url
                FROM products p
                WHERE p.product_id = :pid
                LIMIT 1
            ");
            $stmtP->execute(['pid' => $productId]);
            $relatedProduct = $stmtP->fetch(PDO::FETCH_ASSOC);
        }

        echo json_encode([
            "status" => true,
            "seller" => [
                "seller_id" => (int)$sellerData['seller_id'],
                "shop_id"   => (int)$sellerData['shop_id'],
                "shop_name" => $sellerData['shop_name']
            ],
            "related_product" => $relatedProduct
        ]);
        exit;
    }

    // 2. Daftar Toko yang Pernah Dichat oleh Pembeli (Hanya jika Auth User Bertindak Sebagai Buyer)
    if ($action === 'get_conversations') {
        $sql = "
            SELECT 
                s.user_id AS seller_id,
                s.shop_name,
                s.shop_id,
                c.message AS last_message,
                c.created_at AS last_time,
                (SELECT COUNT(*) FROM chats WHERE sender_id = s.user_id AND receiver_id = :uid AND is_read = 0) AS unread_count
            FROM chats c
            JOIN users u ON (u.user_id = IF(c.sender_id = :uid, c.receiver_id, c.sender_id))
            JOIN shops s ON s.user_id = u.user_id
            WHERE (c.sender_id = :uid OR c.receiver_id = :uid)
              AND s.user_id != :uid
              AND c.chat_id IN (
                  SELECT MAX(chat_id) FROM chats 
                  WHERE sender_id = :uid OR receiver_id = :uid 
                  GROUP BY IF(sender_id = :uid, receiver_id, sender_id)
              )
              -- Memastikan percakapan ini bukan pelanggan yang sedang membeli ke toko milik auth user
              AND NOT EXISTS (
                  SELECT 1 FROM shops my_s 
                  WHERE my_s.user_id = :uid 
                    AND c.product_id IN (SELECT product_id FROM products WHERE shop_id = my_s.shop_id)
              )
            ORDER BY c.created_at DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute(['uid' => $authUserId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['last_time_formatted'] = date("H:i", strtotime($r['last_time']));
        }

        echo json_encode(["status" => true, "data" => $rows]);
        exit;
    }

    // 3. Ambil Pesan antara Pembeli dan Penjual
    if ($action === 'get_messages') {
        $sellerId = isset($_GET['seller_id']) ? (int)$_GET['seller_id'] : 0;
        if ($sellerId <= 0) {
            echo json_encode(["status" => false, "message" => "ID Penjual tidak valid."]);
            exit;
        }

        $conn->prepare("UPDATE chats SET is_read = 1 WHERE sender_id = :sid AND receiver_id = :uid AND is_read = 0")
             ->execute(['sid' => $sellerId, 'uid' => $authUserId]);

        $stmt = $conn->prepare("
            SELECT chat_id, sender_id, receiver_id, message, product_id, is_read, created_at,
                   DATE_FORMAT(created_at, '%H:%i') AS created_at_formatted
            FROM chats
            WHERE (sender_id = :uid AND receiver_id = :sid)
               OR (sender_id = :sid AND receiver_id = :uid)
            ORDER BY created_at ASC
        ");
        $stmt->execute(['uid' => $authUserId, 'sid' => $sellerId]);

        echo json_encode([
            "status"   => true,
            "buyer_id" => $authUserId,
            "messages" => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);
        exit;
    }

    // 4. Kirim Pesan dari Pembeli ke Penjual
    if ($action === 'send_message') {
        $receiverId = isset($postData['receiver_id']) ? (int)$postData['receiver_id'] : 0;
        $message    = isset($postData['message']) ? trim($postData['message']) : '';
        $productId  = isset($postData['product_id']) ? (int)$postData['product_id'] : null;

        if ($receiverId <= 0 || empty($message)) {
            echo json_encode(["status" => false, "message" => "Penerima toko atau isi pesan tidak boleh kosong."]);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO chats (sender_id, receiver_id, message, product_id, is_read, created_at) VALUES (:sid, :rid, :msg, :pid, 0, NOW())");
        $stmt->execute([
            'sid' => $authUserId,
            'rid' => $receiverId,
            'msg' => $message,
            'pid' => $productId
        ]);

        echo json_encode(["status" => true, "message" => "Pesan terkirim."]);
        exit;
    }

} catch (PDOException $e) {
    error_log("Buyer manage_chats error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Terjadi kesalahan pada sistem."]);
    exit;
}
?>