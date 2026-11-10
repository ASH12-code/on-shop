<?php
// C:/laragon/www/on-shop/api/seller/manage_catalog.php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/seller_auth.php';
require_once __DIR__ . '/../helpers/upload.php';

$action = $_GET['action'] ?? 'list';

try {
    // -------------------------------------------------------------
    // 1. AMBIL SINGLE PRODUK UNTUK PREFILL EDIT
    // -------------------------------------------------------------
    if ($action === 'get_single' || $action === 'get_detail' || $action === 'detail') {
        $productId = (int)($_GET['id'] ?? ($_GET['product_id'] ?? 0));

        if (!$productId) {
            echo json_encode(["status" => false, "message" => "ID Produk tidak valid."]);
            exit;
        }

        $stmtProd = $db->prepare("
            SELECT product_id, shop_id, category_id, name, description, weight_grams, is_pinned, is_archived
            FROM products 
            WHERE product_id = ? AND shop_id = ?
        ");
        $stmtProd->execute([$productId, $shop_id]);
        $product = $stmtProd->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            echo json_encode(["status" => false, "message" => "Produk tidak ditemukan atau bukan milik toko Anda."]);
            exit;
        }

        $stmtImages = $db->prepare("
            SELECT image_id, image_url, sort_order, is_primary 
            FROM product_images 
            WHERE product_id = ? 
            ORDER BY sort_order ASC
        ");
        $stmtImages->execute([$productId]);
        $images = $stmtImages->fetchAll(PDO::FETCH_ASSOC);

        $stmtVariants = $db->prepare("
            SELECT variant_id, variant_combination, price, stock, sku, image_url 
            FROM product_variants 
            WHERE product_id = ? 
            ORDER BY variant_id ASC
        ");
        $stmtVariants->execute([$productId]);
        $variants = $stmtVariants->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "status"   => true,
            "data"     => $product,
            "images"   => $images,
            "variants" => $variants
        ]);
        exit;
    }

    // -------------------------------------------------------------
    // 2. SIMPAN / UPDATE PRODUK
    // -------------------------------------------------------------
    if ($action === 'save_product') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            echo json_encode(["status" => false, "message" => "Format data permintaan tidak valid."]);
            exit;
        }

        $productId   = (int)($input['product_id'] ?? 0);
        $name        = trim($input['name'] ?? '');
        $categoryId  = (int)($input['category_id'] ?? 0);
        $description = trim($input['description'] ?? '');
        $isArchived  = !empty($input['is_archived']) ? 1 : 0;
        $images      = $input['images'] ?? [];
        $variants    = $input['variants'] ?? [];

        if (empty($name) || !$categoryId || empty($variants)) {
            echo json_encode(["status" => false, "message" => "Nama produk, kategori, dan minimal 1 varian wajib diisi."]);
            exit;
        }

        $primaryWeight = isset($variants[0]['weight_grams']) ? (int)$variants[0]['weight_grams'] : 250;

        $db->beginTransaction();

        if ($productId > 0) {
            $checkStmt = $db->prepare("SELECT product_id FROM products WHERE product_id = ? AND shop_id = ?");
            $checkStmt->execute([$productId, $shop_id]);
            if (!$checkStmt->fetch()) {
                $db->rollBack();
                echo json_encode(["status" => false, "message" => "Produk tidak ditemukan atau bukan milik toko Anda."]);
                exit;
            }

            $updateProd = $db->prepare("
                UPDATE products 
                SET category_id = ?, name = ?, description = ?, weight_grams = ?, is_archived = ?
                WHERE product_id = ? AND shop_id = ?
            ");
            $updateProd->execute([$categoryId, $name, $description, $primaryWeight, $isArchived, $productId, $shop_id]);
        } else {
            $insertProd = $db->prepare("
                INSERT INTO products (shop_id, category_id, name, description, weight_grams, is_pinned, is_archived, created_at)
                VALUES (?, ?, ?, ?, ?, 0, ?, NOW())
            ");
            $insertProd->execute([$shop_id, $categoryId, $name, $description, $primaryWeight, $isArchived]);
            $productId = (int)$db->lastInsertId();
        }

        $uploadDir = __DIR__ . '/../../assets/images/products/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        // SIMPAN FOTO UTAMA
        if (!empty($images)) {
            $delImages = $db->prepare("DELETE FROM product_images WHERE product_id = ?");
            $delImages->execute([$productId]);

            $sortOrder = 1;
            foreach ($images as $imgStr) {
                if (empty($imgStr)) continue;

                $finalUrl = $imgStr;
                if (strpos($imgStr, 'data:image') === 0) {
                    $savedFile = validate_and_save_base64_image($imgStr, $uploadDir, 'prod_' . $productId . '_');
                    if (!$savedFile) {
                        continue;
                    }
                    $finalUrl = '/on-shop/assets/images/products/' . $savedFile;
                }

                $insImg = $db->prepare("
                    INSERT INTO product_images (product_id, image_url, sort_order, is_primary)
                    VALUES (?, ?, ?, ?)
                ");
                $isPrimary = ($sortOrder === 1) ? 1 : 0;
                $insImg->execute([$productId, $finalUrl, $sortOrder, $isPrimary]);
                $sortOrder++;
            }
        }

        // SIMPAN VARIAN BESERTA IMAGE_URL
        $delVariants = $db->prepare("DELETE FROM product_variants WHERE product_id = ?");
        $delVariants->execute([$productId]);

        $insVar = $db->prepare("
            INSERT INTO product_variants (product_id, variant_combination, price, stock, sku, image_url)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        foreach ($variants as $v) {
            $vName  = trim($v['name'] ?? 'Standar');
            $vPrice = (float)($v['price'] ?? 0);
            $vStock = (int)($v['stock'] ?? 0);
            $vSku   = trim($v['sku'] ?? 'RAK-01');
            $vImg   = !empty($v['image_url']) ? trim($v['image_url']) : null;

            if (!empty($vImg) && strpos($vImg, 'data:image') === 0) {
                $savedVarFile = validate_and_save_base64_image($vImg, $uploadDir, 'var_' . $productId . '_');
                $vImg = $savedVarFile ? ('/on-shop/assets/images/products/' . $savedVarFile) : null;
            }

            $insVar->execute([$productId, $vName, $vPrice, $vStock, $vSku, $vImg]);
        }

        $db->commit();

        echo json_encode([
            "status"     => true,
            "message"    => ($productId > 0) ? "Perubahan produk berhasil disimpan!" : "Produk baru berhasil ditambahkan!",
            "product_id" => $productId
        ]);
        exit;
    }

    // -------------------------------------------------------------
    // 3. DAFTAR KATALOG SELLER
    // -------------------------------------------------------------
    if ($action === 'list') {
        $sql = "
            SELECT 
                p.product_id,
                p.name AS product_name,
                p.is_pinned,
                p.is_archived,
                COALESCE(
                    (SELECT pi.image_url FROM product_images pi WHERE pi.product_id = p.product_id AND pi.is_primary = 1 LIMIT 1),
                    (SELECT pi.image_url FROM product_images pi WHERE pi.product_id = p.product_id ORDER BY pi.sort_order ASC LIMIT 1),
                    ''
                ) AS image_url,
                COALESCE((SELECT MIN(pv.price) FROM product_variants pv WHERE pv.product_id = p.product_id), 0) AS display_price,
                COALESCE((SELECT SUM(pv.stock) FROM product_variants pv WHERE pv.product_id = p.product_id), 0) AS total_stock
            FROM products p
            WHERE p.shop_id = :shop_id
            ORDER BY p.is_pinned DESC, p.product_id DESC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([':shop_id' => $shop_id]);
        $allProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $catalog = [];
        $pinned  = [];
        $cntAll = count($allProducts);
        $cntActive = 0;
        $cntOutOfStock = 0;
        $cntArchived = 0;

        foreach ($allProducts as $p) {
            $p['product_id']    = (int)$p['product_id'];
            $p['is_pinned']     = (int)$p['is_pinned'];
            $p['is_archived']   = (int)$p['is_archived'];
            $p['display_price'] = (float)$p['display_price'];
            $p['total_stock']   = (int)$p['total_stock'];

            if ($p['is_archived'] === 1) {
                $cntArchived++;
            } else {
                if ($p['total_stock'] === 0) {
                    $cntOutOfStock++;
                } else {
                    $cntActive++;
                }
            }

            if ($p['is_pinned'] === 1 && $p['is_archived'] === 0) {
                $pinned[] = $p;
            }
            $catalog[] = $p;
        }

        echo json_encode([
            "status"  => true,
            "counts"  => [
                "all"          => $cntAll,
                "active"       => $cntActive,
                "out_of_stock" => $cntOutOfStock,
                "archived"     => $cntArchived
            ],
            "pinned"  => $pinned,
            "catalog" => $catalog
        ]);
        exit;
    }

    // -------------------------------------------------------------
    // 4. TOGGLE PIN
    // -------------------------------------------------------------
    if ($action === 'toggle_pin') {
        $input = json_decode(file_get_contents('php://input'), true);
        $productId = (int)($input['product_id'] ?? 0);

        $stmt = $db->prepare("SELECT is_pinned FROM products WHERE product_id = ? AND shop_id = ?");
        $stmt->execute([$productId, $shop_id]);
        $current = $stmt->fetchColumn();

        if ($current === false) {
            echo json_encode(["status" => false, "message" => "Produk tidak ditemukan atau bukan milik toko Anda."]);
            exit;
        }

        $newPin = ($current == 1) ? 0 : 1;
        $upd = $db->prepare("UPDATE products SET is_pinned = ? WHERE product_id = ? AND shop_id = ?");
        $upd->execute([$newPin, $productId, $shop_id]);

        echo json_encode(["status" => true, "message" => $newPin ? "Produk disematkan di baris atas!" : "Pin dilepas."]);
        exit;
    }

    // -------------------------------------------------------------
    // 5. TOGGLE ARSIP
    // -------------------------------------------------------------
    if ($action === 'toggle_archive') {
        $input = json_decode(file_get_contents('php://input'), true);
        $productId = (int)($input['product_id'] ?? 0);

        $stmt = $db->prepare("SELECT is_archived FROM products WHERE product_id = ? AND shop_id = ?");
        $stmt->execute([$productId, $shop_id]);
        $current = $stmt->fetchColumn();

        if ($current === false) {
            echo json_encode(["status" => false, "message" => "Produk tidak ditemukan atau bukan milik toko Anda."]);
            exit;
        }

        $newArchive = ($current == 1) ? 0 : 1;
        $upd = $db->prepare("UPDATE products SET is_archived = ?, is_pinned = 0 WHERE product_id = ? AND shop_id = ?");
        $upd->execute([$newArchive, $productId, $shop_id]);

        echo json_encode(["status" => true, "message" => $newArchive ? "Produk diarsipkan." : "Produk kembali aktif di etalase!"]);
        exit;
    }

    // -------------------------------------------------------------
    // 6. HAPUS PRODUK (DENGAN PENANGANAN FOREIGN KEY CERDAS)
    // -------------------------------------------------------------
    if ($action === 'delete') {
        $input = json_decode(file_get_contents('php://input'), true);
        $productId = (int)($input['product_id'] ?? ($_GET['id'] ?? ($_GET['product_id'] ?? 0)));

        if ($productId <= 0) {
            echo json_encode(["status" => false, "message" => "ID Produk tidak valid."]);
            exit;
        }

        // Pastikan produk milik toko yang sedang login
        $check = $db->prepare("SELECT product_id FROM products WHERE product_id = ? AND shop_id = ?");
        $check->execute([$productId, $shop_id]);
        if (!$check->fetch()) {
            echo json_encode(["status" => false, "message" => "Produk tidak ditemukan atau bukan milik toko Anda."]);
            exit;
        }

        $db->beginTransaction();

        // 1. Bersihkan referensi di keranjang & wishlist
        $db->prepare("DELETE FROM cart WHERE product_id = ?")->execute([$productId]);
        $db->prepare("DELETE FROM wishlists WHERE product_id = ?")->execute([$productId]);

        // 2. Cek apakah ada riwayat pesanan (order_items) yang pernah membeli produk ini
        $chkOrder = $db->prepare("SELECT COUNT(*) FROM order_items WHERE product_id = ?");
        $chkOrder->execute([$productId]);
        $hasOrders = (int)$chkOrder->fetchColumn();

        if ($hasOrders > 0) {
            // Jika pernah dibeli, jangan hapus baris fisik agar data transaksi tidak rusak; arsipkan dan kosongkan stoknya
            $db->prepare("UPDATE products SET is_archived = 1, is_pinned = 0 WHERE product_id = ?")->execute([$productId]);
            $db->prepare("UPDATE product_variants SET stock = 0 WHERE product_id = ?")->execute([$productId]);
            $db->commit();

            echo json_encode([
                "status"  => true,
                "message" => "Produk memiliki riwayat pesanan, maka statusnya dialihkan ke 'Diarsipkan' agar data transaksi tetap aman."
            ]);
            exit;
        }

        // 3. Jika belum pernah ada transaksi, hapus total beserta gambarnya
        $db->prepare("DELETE FROM product_images WHERE product_id = ?")->execute([$productId]);
        $db->prepare("DELETE FROM product_variants WHERE product_id = ?")->execute([$productId]);
        $db->prepare("DELETE FROM reviews WHERE product_id = ?")->execute([$productId]);

        // Putuskan chat yang menyematkan product_id ini
        $db->prepare("UPDATE chats SET product_id = NULL WHERE product_id = ?")->execute([$productId]);

        $del = $db->prepare("DELETE FROM products WHERE product_id = ? AND shop_id = ?");
        $del->execute([$productId, $shop_id]);

        $db->commit();

        echo json_encode([
            "status"  => true,
            "message" => "Produk berhasil dihapus dari etalase!"
        ]);
        exit;
    }

    echo json_encode(["status" => false, "message" => "Aksi tidak dikenali."]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Seller manage_catalog error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Terjadi kesalahan sistem saat mengelola katalog."]);
    exit;
}