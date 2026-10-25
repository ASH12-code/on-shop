<?php
// C:/laragon/www/on-shop/api/buyer/cart.php
require_once __DIR__ . '/../helpers/auth.php';
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// 1. Dapatkan user_id dari Sesi PHP (Wajib Login)
$userId = require_auth();

$action = isset($_GET['action']) ? $_GET['action'] : '';

try {
    // -------------------------------------------------------------
    // 1. DAFTAR BARANG KERANJANG (DIKELOMPOKKAN PER TOKO)
    // -------------------------------------------------------------
    if ($action === 'list') {
        // Kueri fleksibel: jika variant_id di tabel cart NULL, otomatis fallback ke harga terendah produk
        $sql = "
            SELECT 
                c.cart_id,
                c.user_id,
                c.product_id,
                c.variant_id,
                c.qty,
                p.name AS product_name,
                s.shop_id,
                s.shop_name,
                s.pickup_city AS shop_city,
                COALESCE(pv.price, (SELECT MIN(price) FROM product_variants WHERE product_id = p.product_id), 0) AS price,
                COALESCE(pv.stock, (SELECT SUM(stock) FROM product_variants WHERE product_id = p.product_id), 10) AS stock,
                COALESCE(pv.variant_combination, 'Standar') AS variant_name,
                COALESCE(
                    NULLIF(pv.image_url, ''),
                    (SELECT image_url FROM product_images WHERE product_id = p.product_id AND is_primary = 1 LIMIT 1),
                    (SELECT image_url FROM product_images WHERE product_id = p.product_id ORDER BY is_primary DESC, sort_order ASC LIMIT 1),
                    'https://placehold.co/100x100/2e8b57/ffffff?text=Produk'
                ) AS image_url
            FROM cart c
            JOIN products p ON c.product_id = p.product_id
            JOIN shops s ON p.shop_id = s.shop_id
            LEFT JOIN product_variants pv ON c.variant_id = pv.variant_id
            WHERE c.user_id = :uid
            ORDER BY s.shop_id ASC, c.cart_id DESC
        ";

        $stmt = $conn->prepare($sql);
        $stmt->execute(['uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $shopsMap = [];
        foreach ($rows as $row) {
            $sId = $row['shop_id'];
            if (!isset($shopsMap[$sId])) {
                $shopsMap[$sId] = [
                    'shop_id'   => (int)$row['shop_id'],
                    'shop_name' => $row['shop_name'],
                    'city'      => $row['shop_city'],
                    'items'     => []
                ];
            }

            $shopsMap[$sId]['items'][] = [
                'cart_id'      => (int)$row['cart_id'],
                'product_id'   => (int)$row['product_id'],
                'variant_id'   => $row['variant_id'] ? (int)$row['variant_id'] : null,
                'name'         => $row['product_name'],
                'variant_name' => $row['variant_name'],
                'price'        => (float)$row['price'],
                'stock'        => (int)$row['stock'],
                'qty'          => (int)$row['qty'],
                'image'        => $row['image_url'],
                'checked'      => true
            ];
        }

        echo json_encode([
            "status" => true,
            "data"   => array_values($shopsMap)
        ]);
        exit;
    }

    $input = json_decode(file_get_contents("php://input"), true);

    // -------------------------------------------------------------
    // 2. TAMBAH BARANG KE KERANJANG (DENGAN PENGECEKAN STOK REAL-TIME)
    // -------------------------------------------------------------
    if ($action === 'add') {
        $productId = isset($input['product_id']) ? intval($input['product_id']) : 0;
        $variantId = (!empty($input['variant_id'])) ? intval($input['variant_id']) : null;
        $qty       = isset($input['qty']) ? intval($input['qty']) : 1;

        if ($productId <= 0) {
            echo json_encode(["status" => false, "message" => "Produk tidak valid."]);
            exit;
        }

        if ($qty <= 0) {
            echo json_encode(["status" => false, "message" => "Jumlah kuantitas minimal 1 pcs."]);
            exit;
        }

        // 1. Jika varian belum dipilih, ambil varian default pertama
        if (!$variantId) {
            $stmtV = $conn->prepare("SELECT variant_id, stock FROM product_variants WHERE product_id = :pid ORDER BY variant_id ASC LIMIT 1");
            $stmtV->execute(['pid' => $productId]);
            $firstV = $stmtV->fetch(PDO::FETCH_ASSOC);
            if ($firstV) {
                $variantId = (int)$firstV['variant_id'];
                $availableStock = (int)$firstV['stock'];
            } else {
                echo json_encode(["status" => false, "message" => "Varian produk tidak ditemukan."]);
                exit;
            }
        } else {
            // Cek stok langsung dari database untuk varian yang dipilih
            $stmtStock = $conn->prepare("SELECT stock FROM product_variants WHERE variant_id = :vid AND product_id = :pid LIMIT 1");
            $stmtStock->execute(['vid' => $variantId, 'pid' => $productId]);
            $stockData = $stmtStock->fetch(PDO::FETCH_ASSOC);

            if (!$stockData) {
                echo json_encode(["status" => false, "message" => "Varian produk tidak ditemukan di sistem."]);
                exit;
            }
            $availableStock = (int)$stockData['stock'];
        }

        // 2. Validasi ketersediaan stok fisik
        if ($availableStock <= 0) {
            echo json_encode(["status" => false, "message" => "Maaf, stok produk untuk varian ini sudah habis!"]);
            exit;
        }

        // 3. Cek apakah barang sudah ada di keranjang untuk menghitung akumulasi
        $stmtCheck = $conn->prepare("SELECT cart_id, qty FROM cart WHERE user_id = :uid AND product_id = :pid AND variant_id = :vid LIMIT 1");
        $stmtCheck->execute(['uid' => $userId, 'pid' => $productId, 'vid' => $variantId]);
        $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        $currentInCart = $existing ? (int)$existing['qty'] : 0;
        $newQty = $currentInCart + $qty;

        if ($newQty > $availableStock) {
            $sisaBisaDibeli = $availableStock - $currentInCart;
            if ($sisaBisaDibeli <= 0) {
                echo json_encode([
                    "status" => false, 
                    "message" => "Seluruh stok varian ini sudah ada di keranjang Anda (Tersisa: {$availableStock} pcs)."
                ]);
            } else {
                echo json_encode([
                    "status" => false, 
                    "message" => "Stok tidak mencukupi! Anda hanya dapat menambah {$sisaBisaDibeli} pcs lagi."
                ]);
            }
            exit;
        }

        // 4. Simpan atau Update ke tabel cart
        if ($existing) {
            $stmtUp = $conn->prepare("UPDATE cart SET qty = :qty WHERE cart_id = :cid");
            $stmtUp->execute(['qty' => $newQty, 'cid' => $existing['cart_id']]);
        } else {
            $stmtIn = $conn->prepare("INSERT INTO cart (user_id, product_id, variant_id, qty) VALUES (:uid, :pid, :vid, :qty)");
            $stmtIn->execute([
                'uid' => $userId,
                'pid' => $productId,
                'vid' => $variantId,
                'qty' => $qty
            ]);
        }

        // 5. Hitung total baris item keranjang aktif
        $stmtCount = $conn->prepare("SELECT COUNT(*) FROM cart WHERE user_id = :uid");
        $stmtCount->execute(['uid' => $userId]);
        $totalItems = (int)$stmtCount->fetchColumn();

        echo json_encode([
            "status"      => true,
            "message"     => "Produk berhasil ditambahkan ke keranjang!",
            "total_items" => $totalItems
        ]);
        exit;
    }

    // -------------------------------------------------------------
    // 3. UBAH KUANTITAS (+ / -)
    // -------------------------------------------------------------
    if ($action === 'update_qty') {
        $cartId = isset($input['cart_id']) ? intval($input['cart_id']) : 0;
        $qty    = isset($input['qty']) ? intval($input['qty']) : 1;

        $stmt = $conn->prepare("UPDATE cart SET qty = :qty WHERE cart_id = :cid AND user_id = :uid");
        $stmt->execute(['qty' => $qty, 'cid' => $cartId, 'uid' => $userId]);

        echo json_encode(["status" => true, "message" => "Kuantitas berhasil diperbarui."]);
        exit;
    }

    // -------------------------------------------------------------
    // 4. HAPUS SATU ITEM
    // -------------------------------------------------------------
    if ($action === 'delete_item') {
        $cartId = isset($input['cart_id']) ? intval($input['cart_id']) : 0;

        $stmt = $conn->prepare("DELETE FROM cart WHERE cart_id = :cid AND user_id = :uid");
        $stmt->execute(['cid' => $cartId, 'uid' => $userId]);

        echo json_encode(["status" => true, "message" => "Barang berhasil dihapus dari keranjang."]);
        exit;
    }

    // -------------------------------------------------------------
    // 5. HAPUS BANYAK ITEM TERPILIH
    // -------------------------------------------------------------
    if ($action === 'delete_selected') {
        $cartIds = isset($input['cart_ids']) && is_array($input['cart_ids']) ? $input['cart_ids'] : [];

        if (!empty($cartIds)) {
            $placeholders = implode(',', array_fill(0, count($cartIds), '?'));
            $stmt = $conn->prepare("DELETE FROM cart WHERE user_id = ? AND cart_id IN ($placeholders)");
            $stmt->execute(array_merge([$userId], array_map('intval', $cartIds)));
        }

        echo json_encode(["status" => true, "message" => "Barang terpilih berhasil dihapus."]);
        exit;
    }

} catch (PDOException $e) {
    error_log("Cart error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Terjadi kesalahan pada sistem."]);
    exit;
}
?>