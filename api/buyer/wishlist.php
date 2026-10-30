<?php
// api/buyer/wishlist.php
// Endpoint Pengelolaan Daftar Keinginan (Wishlist) Pembeli

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// 1. Validasi Autentikasi Pembeli
$userId = require_auth();
$db = $conn ?? $pdo;

if (!$db) {
    json_response(false, "Koneksi basis data terputus.", null, 500);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? trim($_GET['action']) : ($method === 'POST' ? 'toggle' : 'list');

try {
    // =========================================================================
    // 1. AMBIL DAFTAR WISHLIST PENGGUNA
    // =========================================================================
    if ($action === 'list') {
        $query = "
            SELECT 
                w.wishlist_id,
                w.product_id,
                w.created_at,
                p.name AS product_name,
                p.is_archived,
                s.shop_id,
                s.shop_name,
                s.pickup_city AS shop_city,
                c.name AS category_name,
                COALESCE(
                    (SELECT pi.image_url FROM product_images pi WHERE pi.product_id = p.product_id AND pi.is_primary = 1 LIMIT 1),
                    (SELECT pi.image_url FROM product_images pi WHERE pi.product_id = p.product_id ORDER BY pi.sort_order ASC, pi.image_id ASC LIMIT 1),
                    (SELECT pv.image_url FROM product_variants pv WHERE pv.product_id = p.product_id AND pv.image_url IS NOT NULL AND pv.image_url != '' LIMIT 1),
                    'https://placehold.co/300x300/e2e8f0/475569?text=No+Image'
                ) AS image_url,
                COALESCE((SELECT MIN(pv.price) FROM product_variants pv WHERE pv.product_id = p.product_id), 0) AS display_price,
                COALESCE((SELECT SUM(pv.stock) FROM product_variants pv WHERE pv.product_id = p.product_id), 0) AS total_stock,
                COALESCE(ROUND((SELECT AVG(r.rating) FROM reviews r WHERE r.product_id = p.product_id), 1), 5.0) AS rating_avg,
                COALESCE((SELECT COUNT(*) FROM reviews r WHERE r.product_id = p.product_id), 0) AS total_reviews
            FROM wishlists w
            JOIN products p ON w.product_id = p.product_id
            JOIN shops s ON p.shop_id = s.shop_id
            LEFT JOIN categories c ON p.category_id = c.category_id
            WHERE w.user_id = :uid AND p.is_archived = 0
            ORDER BY w.created_at DESC
        ";

        $stmt = $db->prepare($query);
        $stmt->execute([':uid' => $userId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as &$item) {
            $item['wishlist_id']   = (int)$item['wishlist_id'];
            $item['product_id']    = (int)$item['product_id'];
            $item['shop_id']       = (int)$item['shop_id'];
            $item['display_price'] = (float)$item['display_price'];
            $item['total_stock']   = (int)$item['total_stock'];
            $item['total_reviews'] = (int)$item['total_reviews'];
            $item['rating_avg']    = (float)$item['rating_avg'];
        }

        json_response(true, "Daftar wishlist berhasil diambil.", [
            'total' => count($items),
            'items' => $items
        ]);
    }

    // =========================================================================
    // 2. CEK STATUS WISHLIST UNTUK PRODUK TERTENTU
    // =========================================================================
    if ($action === 'check') {
        $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
        if ($productId <= 0) {
            json_response(false, "ID produk tidak valid.", null, 400);
        }

        $stmt = $db->prepare("SELECT wishlist_id FROM wishlists WHERE user_id = :uid AND product_id = :pid LIMIT 1");
        $stmt->execute([':uid' => $userId, ':pid' => $productId]);
        $exists = (bool)$stmt->fetch();

        json_response(true, "Status wishlist berhasil dicek.", [
            'product_id'  => $productId,
            'in_wishlist' => $exists
        ]);
    }

    // Tangkap body JSON untuk aksi modifikasi
    $input = json_decode(file_get_contents("php://input"), true) ?: [];
    $productId = (int)($input['product_id'] ?? $_POST['product_id'] ?? $_GET['product_id'] ?? 0);

    // =========================================================================
    // 3. TOGGLE WISHLIST (TAMBAH JIKA BELUM ADA, HAPUS JIKA SUDAH ADA)
    // =========================================================================
    if ($action === 'toggle') {
        if ($productId <= 0) {
            json_response(false, "ID produk wajib diisi.", null, 400);
        }

        // Cek apakah produk valid
        $pCheck = $db->prepare("SELECT product_id, name FROM products WHERE product_id = :pid AND is_archived = 0 LIMIT 1");
        $pCheck->execute([':pid' => $productId]);
        $prod = $pCheck->fetch(PDO::FETCH_ASSOC);

        if (!$prod) {
            json_response(false, "Produk tidak ditemukan atau sudah tidak aktif.", null, 404);
        }

        // Cek keberadaan di wishlist
        $stmt = $db->prepare("SELECT wishlist_id FROM wishlists WHERE user_id = :uid AND product_id = :pid LIMIT 1");
        $stmt->execute([':uid' => $userId, ':pid' => $productId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Hapus dari wishlist
            $del = $db->prepare("DELETE FROM wishlists WHERE wishlist_id = :wid");
            $del->execute([':wid' => $existing['wishlist_id']]);

            json_response(true, "Produk dihapus dari Wishlist.", [
                'product_id'  => $productId,
                'in_wishlist' => false
            ]);
        } else {
            // Tambahkan ke wishlist
            $ins = $db->prepare("INSERT INTO wishlists (user_id, product_id) VALUES (:uid, :pid)");
            $ins->execute([':uid' => $userId, ':pid' => $productId]);

            json_response(true, "Produk berhasil ditambahkan ke Wishlist!", [
                'product_id'  => $productId,
                'in_wishlist' => true
            ]);
        }
    }

    // =========================================================================
    // 4. HAPUS SPESIFIK DARI WISHLIST
    // =========================================================================
    if ($action === 'remove') {
        $wishlistId = (int)($input['wishlist_id'] ?? $_POST['wishlist_id'] ?? $_GET['wishlist_id'] ?? 0);

        if ($wishlistId > 0) {
            $del = $db->prepare("DELETE FROM wishlists WHERE wishlist_id = :wid AND user_id = :uid");
            $del->execute([':wid' => $wishlistId, ':uid' => $userId]);
        } elseif ($productId > 0) {
            $del = $db->prepare("DELETE FROM wishlists WHERE product_id = :pid AND user_id = :uid");
            $del->execute([':pid' => $productId, ':uid' => $userId]);
        } else {
            json_response(false, "ID wishlist atau ID produk wajib disertakan.", null, 400);
        }

        json_response(true, "Item berhasil dihapus dari Wishlist.", [
            'product_id'  => $productId,
            'in_wishlist' => false
        ]);
    }

    json_response(false, "Aksi tidak dikenali.", null, 400);

} catch (Exception $e) {
    error_log("Wishlist API Error: " . $e->getMessage());
    json_response(false, "Terjadi kesalahan internal pada server.", null, 500);
}
