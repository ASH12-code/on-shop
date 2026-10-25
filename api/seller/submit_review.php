<?php
// api/seller/submit_review.php (Review submission oleh buyer)
require_once __DIR__ . '/../helpers/auth.php';
header('Content-Type: application/json; charset=UTF-8');

$db = get_db_connection();
if (!$db) {
    json_response(false, "Koneksi database gagal.", null, 500);
}

// Ambil buyer yang login murni dari Sesi
$buyer_id = require_auth();

$input = json_decode(file_get_contents('php://input'), true);
$sub_order_id = (int)($input['sub_order_id'] ?? 0);
$product_id   = (int)($input['product_id'] ?? 0);
$rating       = (int)($input['rating'] ?? 5);
$comment      = trim($input['comment'] ?? '');

if ($sub_order_id <= 0 || $product_id <= 0) {
    echo json_encode(["status" => false, "message" => "Data pesanan atau produk tidak valid."]);
    exit;
}

if ($rating < 1 || $rating > 5) {
    echo json_encode(["status" => false, "message" => "Rating harus berada di antara 1 sampai 5 bintang."]);
    exit;
}

try {
    // 1. VALIDASI: Pastikan pesanan adalah milik pembeli dan berstatus SELESAI
    $stmtCheck = $db->prepare("
        SELECT so.sub_order_id, so.order_status
        FROM sub_orders so
        JOIN master_orders mo ON so.master_order_id = mo.master_order_id
        WHERE so.sub_order_id = ? AND mo.buyer_id = ?
        LIMIT 1
    ");
    $stmtCheck->execute([$sub_order_id, $buyer_id]);
    $order = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo json_encode(["status" => false, "message" => "Pesanan tidak ditemukan atau bukan milik Anda."]);
        exit;
    }

    if ($order['order_status'] !== 'SELESAI') {
        echo json_encode([
            "status" => false, 
            "message" => "Ulasan hanya dapat diberikan setelah pesanan berstatus SELESAI (Paket sudah Anda terima)."
        ]);
        exit;
    }

    // 2. CEK DUPLIKASI: Apakah produk dalam pesanan ini sudah pernah diulas?
    $stmtDup = $db->prepare("
        SELECT review_id FROM reviews 
        WHERE sub_order_id = ? AND product_id = ? AND user_id = ?
    ");
    $stmtDup->execute([$sub_order_id, $product_id, $buyer_id]);
    if ($stmtDup->fetch()) {
        echo json_encode(["status" => false, "message" => "Anda sudah memberikan ulasan untuk produk ini pada transaksi ini."]);
        exit;
    }

    // 3. SIMPAN KE TABEL REVIEWS
    $stmtInsert = $db->prepare("
        INSERT INTO reviews (product_id, user_id, sub_order_id, rating, comment, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmtInsert->execute([$product_id, $buyer_id, $sub_order_id, $rating, $comment]);

    echo json_encode([
        "status"    => true,
        "message"   => "Terima kasih! Ulasan Anda berhasil diterbitkan.",
        "review_id" => $db->lastInsertId()
    ]);
    exit;

} catch (Exception $e) {
    error_log("Submit review error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Terjadi kesalahan pada sistem."]);
    exit;
}