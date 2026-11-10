<?php
// C:/laragon/www/on-shop/api/seller/manage_reviews.php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/seller_auth.php'; // Proteksi multi-seller dinamis ($db, $user_id, $shop_id)

$action = $_GET['action'] ?? 'list';

try {
    // -------------------------------------------------------------
    // 1. MENGAMBIL DAFTAR ULASAN & AGREGAT SKOR RATING TOKO
    // -------------------------------------------------------------
    if ($action === 'list') {
        // Ambil semua ulasan untuk produk yang dimiliki oleh toko yang sedang aktif
        $sql = "
            SELECT 
                r.review_id,
                r.rating,
                r.comment,
                r.seller_reply,
                DATE_FORMAT(r.created_at, '%d %b %Y, %H:%i WIB') AS created_at_formatted,
                r.created_at,
                u.name AS buyer_name,
                p.product_id,
                p.name AS product_name,
                COALESCE(
                    (SELECT pi.image_url FROM product_images pi WHERE pi.product_id = p.product_id AND pi.is_primary = 1 LIMIT 1),
                    (SELECT pi.image_url FROM product_images pi WHERE pi.product_id = p.product_id ORDER BY pi.sort_order ASC LIMIT 1),
                    ''
                ) AS product_image,
                COALESCE(
                    (SELECT oi.variant_name FROM order_items oi WHERE oi.sub_order_id = r.sub_order_id AND oi.product_id = r.product_id LIMIT 1),
                    'Standar'
                ) AS variant_name
            FROM reviews r
            JOIN products p ON r.product_id = p.product_id
            JOIN users u ON r.user_id = u.user_id
            WHERE p.shop_id = :shop_id
            ORDER BY r.created_at DESC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([':shop_id' => $shop_id]);
        $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Agregasi Rating
        $totalReviews = count($reviews);
        $starCounts = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $sumRating = 0;
        $unrepliedCount = 0;
        $repliedCount = 0;

        foreach ($reviews as &$rev) {
            $rating = (int)$rev['rating'];
            if ($rating >= 1 && $rating <= 5) {
                $starCounts[$rating]++;
                $sumRating += $rating;
            }

            if (!empty($rev['seller_reply'])) {
                $repliedCount++;
            } else {
                $unrepliedCount++;
            }

            // Lampiran foto ulasan (diasosiasikan dengan array gambar jika ada)
            $rev['photos'] = [];
        }
        unset($rev);

        $avgScore = ($totalReviews > 0) ? round($sumRating / $totalReviews, 1) : 0.0;

        echo json_encode([
            "status"  => true,
            "summary" => [
                "total"        => $totalReviews,
                "average"      => number_format($avgScore, 1),
                "stars_breakdown" => $starCounts,
                "unreplied"    => $unrepliedCount,
                "replied"      => $repliedCount
            ],
            "reviews" => $reviews
        ]);
        exit;
    }

    // -------------------------------------------------------------
    // 2. SIMPAN / UBAH BALASAN ULASAN PENJUAL
    // -------------------------------------------------------------
    if ($action === 'reply_review') {
        $input = json_decode(file_get_contents('php://input'), true);
        $review_id = (int)($input['review_id'] ?? 0);
        $reply     = trim($input['reply'] ?? '');

        if ($review_id <= 0 || empty($reply)) {
            echo json_encode(["status" => false, "message" => "Isi balasan tidak boleh kosong!"]);
            exit;
        }

        // Verifikasi kepemilikan: ulasan harus milik produk di toko penjual yang aktif
        $checkStmt = $db->prepare("
            SELECT r.review_id 
            FROM reviews r
            JOIN products p ON r.product_id = p.product_id
            WHERE r.review_id = ? AND p.shop_id = ?
        ");
        $checkStmt->execute([$review_id, $shop_id]);
        if (!$checkStmt->fetch()) {
            echo json_encode(["status" => false, "message" => "Ulasan tidak ditemukan atau bukan milik toko Anda."]);
            exit;
        }

        $upd = $db->prepare("UPDATE reviews SET seller_reply = ? WHERE review_id = ?");
        $upd->execute([$reply, $review_id]);

        echo json_encode([
            "status"  => true,
            "message" => "Balasan ulasan berhasil dikirim!"
        ]);
        exit;
    }

} catch (Exception $e) {
    error_log("Seller manage_reviews error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status"  => false,
        "message" => "Terjadi kesalahan sistem saat memproses ulasan."
    ]);
    exit;
}