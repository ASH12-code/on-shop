<?php
// api/buyer/get_shipping_rates.php
// Endpoint penyedia opsi kurir dan kalkulasi ongkir dinamis untuk halaman checkout pembeli

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/shipping.php';
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

try {
    $input = json_decode(file_get_contents("php://input"), true) ?: [];

    $addressId = intval($input['address_id'] ?? ($_GET['address_id'] ?? 0));
    $destProv  = trim($input['destination_province'] ?? ($_GET['destination_province'] ?? ''));

    // 1. Tentukan Provinsi Tujuan Pembeli
    if ($addressId > 0) {
        $stmtAddr = $conn->prepare("SELECT province, city FROM addresses WHERE address_id = :aid LIMIT 1");
        $stmtAddr->execute(['aid' => $addressId]);
        $addr = $stmtAddr->fetch(PDO::FETCH_ASSOC);
        if ($addr && !empty($addr['province'])) {
            $destProv = $addr['province'];
        }
    }

    if (empty($destProv)) {
        $destProv = 'Kepulauan Bangka Belitung';
    }

    // 2. Cek Mode Multi-Shop (Array Toko dari Checkout)
    $shopsInput = $input['shops'] ?? null;

    if (is_array($shopsInput) && count($shopsInput) > 0) {
        $results = [];

        foreach ($shopsInput as $sh) {
            $shopId = intval($sh['shop_id'] ?? 0);
            if ($shopId <= 0) continue;

            // Hitung berat produk secara sah dari database
            $totalWeight = 0;
            if (!empty($sh['items']) && is_array($sh['items'])) {
                foreach ($sh['items'] as $it) {
                    $pid = intval($it['product_id'] ?? 0);
                    $qty = max(1, intval($it['qty'] ?? 1));

                    if ($pid > 0) {
                        $stmtW = $conn->prepare("SELECT weight_grams FROM products WHERE product_id = :pid LIMIT 1");
                        $stmtW->execute(['pid' => $pid]);
                        $w = $stmtW->fetchColumn();
                        $totalWeight += (intval($w) ?: 500) * $qty;
                    }
                }
            } else {
                $totalWeight = max(500, intval($sh['total_weight'] ?? 500));
            }

            $shippingData = get_shop_shipping_options($conn, $shopId, $destProv, $totalWeight);
            $results[] = $shippingData;
        }

        json_response(true, "Tarif ongkos kirim berhasil dimuat.", [
            'destination_province' => $destProv,
            'shops_shipping'       => $results
        ], 200);
    }

    // 3. Mode Single Shop (GET atau Single Param)
    $singleShopId = intval($input['shop_id'] ?? ($_GET['shop_id'] ?? 1));
    $singleWeight = intval($input['total_weight'] ?? ($_GET['total_weight'] ?? 500));

    $shippingData = get_shop_shipping_options($conn, $singleShopId, $destProv, $singleWeight);

    json_response(true, "Tarif ongkos kirim toko berhasil dimuat.", $shippingData, 200);

} catch (Exception $e) {
    error_log("Get Shipping Rates Error: " . $e->getMessage());
    json_response(false, "Terjadi gangguan saat mengambil opsi pengiriman.", null, 500);
}
?>
