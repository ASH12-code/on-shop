<?php
// C:/laragon/www/on-shop/api/buyer/checkout.php
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/shipping.php';
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// 1. Ambil identitas pembeli terpercaya dari sesi server
$userId = require_auth();

$action = isset($_GET['action']) ? $_GET['action'] : '';

try {
    // =============================================================
    // 1. AMBIL DAFTAR ALAMAT PENGGUNA UNTUK CHECKOUT
    // =============================================================
    if ($action === 'get_addresses') {
        $stmt = $conn->prepare("
            SELECT address_id, label, recipient_name, phone, street_address, city, province, postal_code, is_default
            FROM addresses
            WHERE user_id = :uid
            ORDER BY is_default DESC, address_id DESC
        ");
        $stmt->execute(['uid' => $userId]);
        $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(["status" => true, "data" => $addresses]);
        exit;
    }

    // =============================================================
    // 2. PROSES TRANSAKSI BUAT PESANAN (SECURE SERVER-SIDE PRICING & VOUCHERS)
    // =============================================================
    if ($action === 'create_order') {
        $input = json_decode(file_get_contents("php://input"), true) ?: [];

        $addressId = isset($input['address_id']) ? intval($input['address_id']) : 0;
        $paymentMethod = isset($input['payment_method']) ? trim($input['payment_method']) : 'COD';
        $shops = isset($input['shops']) ? $input['shops'] : [];
        $serviceFee = 1000.00;

        if (empty($shops)) {
            echo json_encode(["status" => false, "message" => "Tidak ada barang belanjaan yang dikirim."]);
            exit;
        }

        // 1. Validasi Alamat
        $stmtAddr = $conn->prepare("SELECT * FROM addresses WHERE address_id = :aid AND user_id = :uid LIMIT 1");
        $stmtAddr->execute(['aid' => $addressId, 'uid' => $userId]);
        $addr = $stmtAddr->fetch(PDO::FETCH_ASSOC);

        if (!$addr) {
            echo json_encode(["status" => false, "message" => "Alamat pengiriman wajib dipilih!"]);
            exit;
        }

        // 2. Validasi Metode Pembayaran
        $validPaymentMethods = ['COD', 'QRIS', 'VA_BCA', 'VA_MANDIRI', 'VA_BRI'];
        if (!in_array($paymentMethod, $validPaymentMethods)) {
            $paymentMethod = 'COD';
        }

        $initialOrderStatus = ($paymentMethod === 'COD') ? 'DIKEMAS' : 'MENUNGGU_BAYAR';

        $conn->beginTransaction();

        // 3. Kunci dan Muat Voucher Aktif Pengguna (Anti-Race Condition)
        $stmtVouch = $conn->prepare("
            SELECT uv.user_voucher_id, v.* 
            FROM user_vouchers uv
            JOIN vouchers v ON uv.voucher_id = v.voucher_id
            WHERE uv.user_id = :uid 
              AND uv.status = 'UNUSED'
              AND v.is_active = 1
              AND NOW() BETWEEN v.start_date AND v.end_date
            FOR UPDATE
        ");
        $stmtVouch->execute(['uid' => $userId]);
        $userVouchers = $stmtVouch->fetchAll(PDO::FETCH_ASSOC);

        $vouchersKeyed = [];
        foreach ($userVouchers as $uv) {
            $vouchersKeyed[$uv['user_voucher_id']] = $uv;
        }

        $totalProductGross = 0.00;
        $totalShippingCost = 0.00;
        $totalVoucherDiscount = 0.00;
        $totalShippingDiscount = 0.00;

        $cartIdsToDelete = [];
        $verifiedShops = [];
        $usedUserVoucherIds = [];

        // 4. Verifikasi Produk, Ongkir Mandiri, dan Pemotongan Voucher Per Toko
        foreach ($shops as $shop) {
            $shopId = intval($shop['shop_id'] ?? 0);

            // Ambil data toko untuk lokasi asal gudang/pickup
            $stmtShopInfo = $conn->prepare("SELECT pickup_province, pickup_city, shop_name FROM shops WHERE shop_id = :sid LIMIT 1");
            $stmtShopInfo->execute(['sid' => $shopId]);
            $shopDb = $stmtShopInfo->fetch(PDO::FETCH_ASSOC);
            $shopOriginProv = !empty($shopDb['pickup_province']) ? $shopDb['pickup_province'] : 'Kepulauan Bangka Belitung';

            $verifiedItems = [];
            $shopGross = 0.00;
            $shopTotalWeightGrams = 0;

            foreach ($shop['items'] as $item) {
                $pid = intval($item['product_id'] ?? 0);
                $vid = !empty($item['variant_id']) ? intval($item['variant_id']) : null;
                $qty = max(1, intval($item['qty'] ?? 1));

                if ($vid) {
                    $stmtPrice = $conn->prepare("
                        SELECT pv.price, pv.stock, pv.variant_combination, p.name AS product_name,
                               COALESCE(p.weight_grams, 500) AS weight_grams,
                               COALESCE(
                                   pv.image_url,
                                   (SELECT image_url FROM product_images WHERE product_id = p.product_id AND is_primary = 1 LIMIT 1),
                                   'https://placehold.co/100x100/2e8b57/ffffff?text=Produk'
                               ) AS final_img
                        FROM product_variants pv
                        JOIN products p ON pv.product_id = p.product_id
                        WHERE pv.variant_id = :vid AND pv.product_id = :pid
                        LIMIT 1
                    ");
                    $stmtPrice->execute(['vid' => $vid, 'pid' => $pid]);
                    $prodMeta = $stmtPrice->fetch(PDO::FETCH_ASSOC);
                } else {
                    $stmtPrice = $conn->prepare("
                        SELECT COALESCE(MIN(pv.price), 0) AS price, COALESCE(SUM(pv.stock), 10) AS stock,
                               'Standar' AS variant_combination, p.name AS product_name,
                               COALESCE(p.weight_grams, 500) AS weight_grams,
                               COALESCE(
                                   (SELECT image_url FROM product_images WHERE product_id = p.product_id AND is_primary = 1 LIMIT 1),
                                   'https://placehold.co/100x100/2e8b57/ffffff?text=Produk'
                               ) AS final_img
                        FROM products p
                        LEFT JOIN product_variants pv ON p.product_id = pv.product_id
                        WHERE p.product_id = :pid
                        GROUP BY p.product_id, p.name, p.weight_grams
                        LIMIT 1
                    ");
                    $stmtPrice->execute(['pid' => $pid]);
                    $prodMeta = $stmtPrice->fetch(PDO::FETCH_ASSOC);
                }

                if (!$prodMeta) {
                    $conn->rollBack();
                    echo json_encode(["status" => false, "message" => "Produk tidak ditemukan di database."]);
                    exit;
                }

                if ((int)$prodMeta['stock'] < $qty) {
                    $conn->rollBack();
                    echo json_encode([
                        "status" => false, 
                        "message" => "Stok untuk produk '{$prodMeta['product_name']}' tidak mencukupi (Tersisa: {$prodMeta['stock']} pcs)."
                    ]);
                    exit;
                }

                $officialPrice = floatval($prodMeta['price']);
                $itemSubtotal = $officialPrice * $qty;
                $shopGross += $itemSubtotal;

                $weightPerUnit = max(100, intval($prodMeta['weight_grams'] ?? 500));
                $shopTotalWeightGrams += $weightPerUnit * $qty;

                $verifiedItems[] = [
                    'product_id'   => $pid,
                    'variant_id'   => $vid,
                    'product_name' => $prodMeta['product_name'],
                    'variant_name' => $prodMeta['variant_combination'] ?? 'Standar',
                    'image'        => $prodMeta['final_img'],
                    'price'        => $officialPrice,
                    'qty'          => $qty,
                    'total_price'  => $itemSubtotal
                ];

                if (!empty($item['cart_id'])) {
                    $cartIdsToDelete[] = intval($item['cart_id']);
                }
            }

            // Hitung Ongkos Kirim Resmi di Server (Anti-Tampering)
            $courierCode = trim($shop['courier_code'] ?? '');
            $serviceCode = trim($shop['service_code'] ?? '');
            if (empty($courierCode)) {
                $rawCourier = trim($shop['courier_name'] ?? 'JNE');
                if (stripos($rawCourier, 'J&T') !== false || stripos($rawCourier, 'JNT') !== false) {
                    $courierCode = 'JNT';
                    $serviceCode = 'EZ';
                } elseif (stripos($rawCourier, 'SiCepat') !== false) {
                    $courierCode = 'SICEPAT';
                    $serviceCode = 'SIUNT';
                } else {
                    $courierCode = 'JNE';
                    $serviceCode = 'REG';
                }
            }
            if (empty($serviceCode)) {
                $serviceCode = ($courierCode === 'JNT') ? 'EZ' : (($courierCode === 'SICEPAT') ? 'SIUNT' : 'REG');
            }

            $shippingCalc = calculate_shop_shipping(
                $conn,
                $shopOriginProv,
                $addr['province'],
                $shopTotalWeightGrams,
                $courierCode,
                $serviceCode
            );
            $shippingCost   = floatval($shippingCalc['shipping_cost']);
            $courierName    = $shippingCalc['courier_name'];
            $courierService = $shippingCalc['service_name'];

            // A. Diskon Voucher Toko (Hanya Produk, Maksimal 1 Voucher per Toko)
            $shopVoucherDiscount = 0.00;
            $shopUVId = !empty($shop['shop_voucher_id']) ? intval($shop['shop_voucher_id']) : null;
            if ($shopUVId && isset($vouchersKeyed[$shopUVId]) && !in_array($shopUVId, $usedUserVoucherIds)) {
                $sv = $vouchersKeyed[$shopUVId];
                if ($sv['shop_id'] == $shopId && $sv['voucher_category'] === 'PRODUCT_DISCOUNT' && $shopGross >= floatval($sv['min_purchase'])) {
                    if ($sv['discount_type'] === 'FIXED') {
                        $shopVoucherDiscount = floatval($sv['discount_value']);
                    } else {
                        $shopVoucherDiscount = ($shopGross * floatval($sv['discount_value'])) / 100.0;
                        if (!empty($sv['max_discount']) && floatval($sv['max_discount']) > 0) {
                            $shopVoucherDiscount = min($shopVoucherDiscount, floatval($sv['max_discount']));
                        }
                    }
                    $usedUserVoucherIds[] = $shopUVId;
                }
            }

            // B. Diskon Voucher Produk Platform (Toko Ini)
            $platformDiscount = 0.00;
            $pltProdUVId = !empty($shop['platform_prod_voucher_id']) ? intval($shop['platform_prod_voucher_id']) : null;
            if ($pltProdUVId && isset($vouchersKeyed[$pltProdUVId]) && !in_array($pltProdUVId, $usedUserVoucherIds)) {
                $pv = $vouchersKeyed[$pltProdUVId];
                if ($pv['shop_id'] === null && $pv['voucher_category'] === 'PRODUCT_DISCOUNT' && $shopGross >= floatval($pv['min_purchase'])) {
                    if ($pv['discount_type'] === 'FIXED') {
                        $platformDiscount = floatval($pv['discount_value']);
                    } else {
                        $platformDiscount = ($shopGross * floatval($pv['discount_value'])) / 100.0;
                        if (!empty($pv['max_discount']) && floatval($pv['max_discount']) > 0) {
                            $platformDiscount = min($platformDiscount, floatval($pv['max_discount']));
                        }
                    }
                    $usedUserVoucherIds[] = $pltProdUVId;
                }
            }

            // C. Diskon Voucher Gratis Ongkir (Toko Ini - Dihitung Terhadap Ongkir Server Sah)
            $shippingDiscount = 0.00;
            $shipUVId = !empty($shop['shipping_voucher_id']) ? intval($shop['shipping_voucher_id']) : null;
            if ($shipUVId && isset($vouchersKeyed[$shipUVId]) && !in_array($shipUVId, $usedUserVoucherIds)) {
                $shipV = $vouchersKeyed[$shipUVId];
                if ($shipV['shop_id'] === null && $shipV['voucher_category'] === 'FREE_SHIPPING' && $shippingCost >= floatval($shipV['min_purchase'])) {
                    $shippingDiscount = min($shippingCost, floatval($shipV['discount_value']));
                    $usedUserVoucherIds[] = $shipUVId;
                }
            }

            // D. Aturan Batas Bawah Produk (Floor Price Rp 1.000 per Toko)
            $totalProdDiscountThisShop = $shopVoucherDiscount + $platformDiscount;
            if (($shopGross - $totalProdDiscountThisShop) < 1000.00) {
                $maxAllowedDiscount = max(0.00, $shopGross - 1000.00);
                if ($shopVoucherDiscount > $maxAllowedDiscount) {
                    $shopVoucherDiscount = $maxAllowedDiscount;
                    $platformDiscount = 0.00;
                } else {
                    $platformDiscount = $maxAllowedDiscount - $shopVoucherDiscount;
                }
            }

            $totalProductGross += $shopGross;
            $totalShippingCost += $shippingCost;
            $totalVoucherDiscount += ($shopVoucherDiscount + $platformDiscount);
            $totalShippingDiscount += $shippingDiscount;

            $verifiedShops[] = [
                'shop_id'               => $shopId,
                'shipping_cost'         => $shippingCost,
                'shop_voucher_discount' => $shopVoucherDiscount,
                'platform_discount'     => $platformDiscount,
                'shipping_discount'     => $shippingDiscount,
                'courier_name'          => $courierName,
                'courier_service'       => $courierService,
                'shop_gross'            => $shopGross,
                'items'                 => $verifiedItems
            ];
        }

        $finalNetProduct = max(1000.00, $totalProductGross - $totalVoucherDiscount);
        $finalNetShipping = max(0.00, $totalShippingCost - $totalShippingDiscount);
        $grandTotal = $finalNetProduct + $finalNetShipping + $serviceFee;

        $invoiceNumber = "INV-" . date("Ymd") . "-" . strtoupper(substr(uniqid(), -5));

        // 5. Simpan ke master_orders
        $stmtMaster = $conn->prepare("
            INSERT INTO master_orders (
                buyer_id, invoice_number, total_product_amount, total_shipping_fee,
                total_voucher_discount, total_shipping_discount, service_fee, grand_total,
                shipping_recipient_name, shipping_phone, shipping_street_address, shipping_city,
                shipping_province, shipping_postal_code, created_at
            ) VALUES (
                :buyer_id, :inv, :prod_amount, :ship_amount,
                :v_disc, :s_disc, :service_fee, :grand_total,
                :r_name, :r_phone, :r_street, :r_city, :r_prov, :r_postal, NOW()
            )
        ");
        $stmtMaster->execute([
            'buyer_id'    => $userId,
            'inv'         => $invoiceNumber,
            'prod_amount' => $totalProductGross,
            'ship_amount' => $totalShippingCost,
            'v_disc'      => $totalVoucherDiscount,
            's_disc'      => $totalShippingDiscount,
            'service_fee' => $serviceFee,
            'grand_total' => $grandTotal,
            'r_name'      => $addr['recipient_name'],
            'r_phone'     => $addr['phone'],
            'r_street'    => $addr['street_address'],
            'r_city'      => $addr['city'],
            'r_prov'      => $addr['province'],
            'r_postal'    => $addr['postal_code']
        ]);
        $masterOrderId = (int)$conn->lastInsertId();

        // 6. Simpan ke sub_orders & order_items
        $subOrderCount = 1;
        foreach ($verifiedShops as $vShop) {
            $subOrderNumber = "ORD-" . date("Ymd") . "-" . str_pad($subOrderCount, 3, '0', STR_PAD_LEFT) . "-" . strtoupper(substr(uniqid(), -3));
            $subOrderCount++;

            $stmtSub = $conn->prepare("
                INSERT INTO sub_orders (
                    master_order_id, shop_id, sub_order_number, courier_name, courier_service,
                    shipping_cost, shop_voucher_discount, platform_discount, shipping_discount,
                    order_status, gross_product_amount, created_at
                ) VALUES (
                    :mid, :sid, :sub_num, :c_name, :c_serv,
                    :ship_cost, :sv_disc, :plt_disc, :shp_disc,
                    :order_status, :gross, NOW()
                )
            ");
            $stmtSub->execute([
                'mid'          => $masterOrderId,
                'sid'          => $vShop['shop_id'],
                'sub_num'      => $subOrderNumber,
                'c_name'       => $vShop['courier_name'],
                'c_serv'       => $vShop['courier_service'],
                'ship_cost'    => $vShop['shipping_cost'],
                'sv_disc'      => $vShop['shop_voucher_discount'],
                'plt_disc'     => $vShop['platform_discount'],
                'shp_disc'     => $vShop['shipping_discount'],
                'order_status' => $initialOrderStatus,
                'gross'        => $vShop['shop_gross']
            ]);
            $subOrderId = (int)$conn->lastInsertId();

            $stmtItem = $conn->prepare("
                INSERT INTO order_items (
                    sub_order_id, product_id, variant_id, product_name, variant_name,
                    product_image_url, price_at_purchase, quantity, total_price
                ) VALUES (
                    :sub_id, :pid, :vid, :pname, :vname, :img, :price, :qty, :total
                )
            ");

            $stmtDeductStock = $conn->prepare("
                UPDATE product_variants 
                SET stock = stock - :qty 
                WHERE variant_id = :vid AND stock >= :qty
            ");

            foreach ($vShop['items'] as $it) {
                $stmtItem->execute([
                    'sub_id' => $subOrderId,
                    'pid'    => $it['product_id'],
                    'vid'    => $it['variant_id'],
                    'pname'  => $it['product_name'],
                    'vname'  => $it['variant_name'],
                    'img'    => $it['image'],
                    'price'  => $it['price'],
                    'qty'    => $it['qty'],
                    'total'  => $it['total_price']
                ]);

                if ($it['variant_id']) {
                    $stmtDeductStock->execute([
                        'qty' => $it['qty'],
                        'vid' => $it['variant_id']
                    ]);
                }
            }
        }

        // 7. Tandai Voucher Pengguna Sebagai USED
        if (!empty($usedUserVoucherIds)) {
            $usedUnique = array_unique($usedUserVoucherIds);
            $stmtMarkVoucher = $conn->prepare("
                UPDATE user_vouchers 
                SET status = 'USED', used_at = NOW(), order_id = :mid 
                WHERE user_voucher_id = :uvid
            ");
            $stmtUpdQuota = $conn->prepare("
                UPDATE vouchers v
                JOIN user_vouchers uv ON v.voucher_id = uv.voucher_id
                SET v.used_quota = v.used_quota + 1
                WHERE uv.user_voucher_id = :uvid
            ");

            foreach ($usedUnique as $uvId) {
                $stmtMarkVoucher->execute(['mid' => $masterOrderId, 'uvid' => $uvId]);
                $stmtUpdQuota->execute(['uvid' => $uvId]);
            }
        }

        // 8. Masukkan ke Payments
        $vaNumber = null;
        if ($paymentMethod === 'QRIS') {
            $vaNumber = "QRIS" . date("YmdHis") . rand(100, 999);
        } elseif ($paymentMethod !== 'COD') {
            $prefix = ($paymentMethod === 'VA_BCA') ? '8800' : (($paymentMethod === 'VA_MANDIRI') ? '8900' : '8801');
            $vaNumber = $prefix . date("His") . rand(100, 999);
        }

        $expiredAt = date("Y-m-d H:i:s", strtotime("+24 hours"));
        $stmtPay = $conn->prepare("
            INSERT INTO payments (
                master_order_id, payment_method, va_number, payment_status, expired_at
            ) VALUES (
                :mid, :pm, :va, 'PENDING', :exp
            )
        ");
        $stmtPay->execute([
            'mid' => $masterOrderId,
            'pm'  => $paymentMethod,
            'va'  => $vaNumber,
            'exp' => $expiredAt
        ]);

        // 9. Bersihkan Item dari Keranjang
        if (!empty($cartIdsToDelete)) {
            $placeholders = implode(',', array_fill(0, count($cartIdsToDelete), '?'));
            $stmtDelCart = $conn->prepare("DELETE FROM cart WHERE user_id = ? AND cart_id IN ($placeholders)");
            $stmtDelCart->execute(array_merge([$userId], $cartIdsToDelete));
        }

        $conn->commit();

        echo json_encode([
            "status"          => true,
            "message"         => "Pesanan berhasil dibuat!",
            "master_order_id" => $masterOrderId,
            "invoice_number"  => $invoiceNumber,
            "grand_total"     => $grandTotal,
            "payment_method"  => $paymentMethod,
            "va_number"       => $vaNumber,
            "order_status"    => $initialOrderStatus
        ]);
        exit;
    }

    echo json_encode(["status" => false, "message" => "Aksi tidak dikenali."]);

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Checkout error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Terjadi kesalahan saat memproses pesanan."]);
    exit;
}