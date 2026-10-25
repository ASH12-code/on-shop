<?php
// api/helpers/shipping.php
// Modul kalkulator dan simulasi ongkos kirim server-side untuk toko dan pembeli Onshop

/**
 * Menormalisasi nama provinsi agar cocok dengan skema rute shipping_rates
 */
function normalize_shipping_province(?string $province): string
{
    $p = trim((string)$province);
    if (empty($p)) {
        return 'Kepulauan Bangka Belitung';
    }

    $lower = strtolower($p);
    if (strpos($lower, 'bangka') !== false || strpos($lower, 'belitung') !== false) {
        return 'Kepulauan Bangka Belitung';
    }
    if (strpos($lower, 'jakarta') !== false || strpos($lower, 'dki') !== false) {
        return 'DKI Jakarta';
    }
    if (strpos($lower, 'jawa barat') !== false || strpos($lower, 'jabar') !== false || strpos($lower, 'bandung') !== false) {
        return 'Jawa Barat';
    }
    if (strpos($lower, 'jawa timur') !== false || strpos($lower, 'jatim') !== false || strpos($lower, 'surabaya') !== false) {
        return 'Jawa Timur';
    }
    if (strpos($lower, 'jawa tengah') !== false || strpos($lower, 'jateng') !== false || strpos($lower, 'semarang') !== false) {
        return 'Jawa Tengah';
    }
    if (strpos($lower, 'sumatera selatan') !== false || strpos($lower, 'sumsel') !== false || strpos($lower, 'palembang') !== false) {
        return 'Sumatera Selatan';
    }
    if (strpos($lower, 'banten') !== false || strpos($lower, 'tangerang') !== false) {
        return 'Banten';
    }
    if (strpos($lower, 'bali') !== false || strpos($lower, 'denpasar') !== false) {
        return 'Bali';
    }

    return $p;
}

/**
 * Menghitung ongkir resmi server untuk satu kombinasi kurir, rute, dan berat
 *
 * @param PDO $conn Koneksi database PDO
 * @param string $originProv Provinsi asal toko
 * @param string $destProv Provinsi tujuan pembeli
 * @param int $weightGrams Total berat produk (gram)
 * @param string $courierCode Kode kurir (misal: 'JNE', 'JNT', 'SICEPAT')
 * @param string $serviceCode Kode layanan (misal: 'REG', 'EZ', 'SIUNT')
 * @return array Detail tarif dan total biaya ongkir
 */
function calculate_shop_shipping(PDO $conn, string $originProv, string $destProv, int $weightGrams, string $courierCode = 'JNE', string $serviceCode = 'REG'): array
{
    $weightGrams = max(100, $weightGrams);
    // Pembulatan ke atas per kilogram (contoh: 500g -> 1kg; 1200g -> 2kg)
    $billedKg = (int)ceil($weightGrams / 1000);

    $originNorm = normalize_shipping_province($originProv);
    $destNorm   = normalize_shipping_province($destProv);
    $courierCode = strtoupper(trim($courierCode));
    $serviceCode = strtoupper(trim($serviceCode));

    // 1. Cari rute spesifik yang cocok
    $stmt = $conn->prepare("
        SELECT * FROM shipping_rates 
        WHERE courier_code = :courier 
          AND service_code = :service 
          AND origin_province = :orig 
          AND destination_province = :dest 
          AND is_active = 1 
        LIMIT 1
    ");
    $stmt->execute([
        'courier' => $courierCode,
        'service' => $serviceCode,
        'orig'    => $originNorm,
        'dest'    => $destNorm
    ]);
    $rate = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2. Jika tidak ada, cari fallback default untuk kurir dan layanan yang sama
    if (!$rate) {
        $stmtDef = $conn->prepare("
            SELECT * FROM shipping_rates 
            WHERE courier_code = :courier 
              AND service_code = :service 
              AND origin_province = 'DEFAULT' 
              AND destination_province = 'DEFAULT' 
              AND is_active = 1 
            LIMIT 1
        ");
        $stmtDef->execute([
            'courier' => $courierCode,
            'service' => $serviceCode
        ]);
        $rate = $stmtDef->fetch(PDO::FETCH_ASSOC);
    }

    // 3. Fallback absolut jika kurir/layanan tidak terdaftar di database
    if (!$rate) {
        $ratePerKg = 18000.00;
        $courierName = ($courierCode === 'JNT') ? 'J&T Express' : (($courierCode === 'SICEPAT') ? 'SiCepat Ekspres' : 'JNE Express');
        $serviceName = 'Reguler (2-3 Hari)';
        $etd = '2-3 Hari';
    } else {
        $ratePerKg   = floatval($rate['rate_per_kg']);
        $courierName = $rate['courier_name'];
        $serviceName = $rate['service_name'];
        $etd         = $rate['etd_days'];
    }

    $totalShippingCost = $ratePerKg * $billedKg;

    return [
        'courier_code'    => $courierCode,
        'courier_name'    => $courierName,
        'service_code'    => $serviceCode,
        'service_name'    => $serviceName,
        'rate_per_kg'     => $ratePerKg,
        'billed_kg'       => $billedKg,
        'weight_grams'    => $weightGrams,
        'shipping_cost'   => $totalShippingCost,
        'etd_days'        => $etd
    ];
}

/**
 * Mengambil seluruh opsi kurir aktif untuk rute toko ke alamat tujuan
 *
 * @param PDO $conn Koneksi PDO
 * @param int $shopId ID Toko
 * @param string $destProv Provinsi tujuan pembeli
 * @param int $weightGrams Total berat belanjaan toko (gram)
 * @return array Daftar pilihan opsi kurir lengkap dengan nominal terhitung
 */
function get_shop_shipping_options(PDO $conn, int $shopId, string $destProv, int $weightGrams): array
{
    $weightGrams = max(100, $weightGrams);
    $billedKg = (int)ceil($weightGrams / 1000);

    // Ambil provinsi asal toko
    $stmtShop = $conn->prepare("SELECT shop_name, pickup_province, pickup_city FROM shops WHERE shop_id = :sid LIMIT 1");
    $stmtShop->execute(['sid' => $shopId]);
    $shop = $stmtShop->fetch(PDO::FETCH_ASSOC);

    $originProv = !empty($shop['pickup_province']) ? $shop['pickup_province'] : 'Kepulauan Bangka Belitung';
    $originNorm = normalize_shipping_province($originProv);
    $destNorm   = normalize_shipping_province($destProv);

    // Cari opsi kurir untuk rute asal -> tujuan
    $stmtRates = $conn->prepare("
        SELECT * FROM shipping_rates 
        WHERE origin_province = :orig 
          AND destination_province = :dest 
          AND is_active = 1
        ORDER BY rate_per_kg ASC
    ");
    $stmtRates->execute([
        'orig' => $originNorm,
        'dest' => $destNorm
    ]);
    $rates = $stmtRates->fetchAll(PDO::FETCH_ASSOC);

    // Jika belum ada rute spesifik, muat rute DEFAULT
    if (empty($rates)) {
        $stmtDef = $conn->query("
            SELECT * FROM shipping_rates 
            WHERE origin_province = 'DEFAULT' 
              AND destination_province = 'DEFAULT' 
              AND is_active = 1
            ORDER BY rate_per_kg ASC
        ");
        $rates = $stmtDef->fetchAll(PDO::FETCH_ASSOC);
    }

    $options = [];
    foreach ($rates as $r) {
        $ratePerKg = floatval($r['rate_per_kg']);
        $totalCost = $ratePerKg * $billedKg;

        $options[] = [
            'courier_code'    => $r['courier_code'],
            'courier_name'    => $r['courier_name'],
            'service_code'    => $r['service_code'],
            'service_name'    => $r['service_name'],
            'rate_per_kg'     => $ratePerKg,
            'billed_kg'       => $billedKg,
            'weight_grams'    => $weightGrams,
            'shipping_cost'   => $totalCost,
            'etd_days'        => $r['etd_days'],
            'label'           => "{$r['courier_name']} {$r['service_name']} (Rp " . number_format($totalCost, 0, ',', '.') . ")",
            'value_key'       => "{$r['courier_code']}|{$r['service_code']}"
        ];
    }

    return [
        'shop_id'         => $shopId,
        'shop_name'       => $shop['shop_name'] ?? 'Toko',
        'origin_province' => $originNorm,
        'dest_province'   => $destNorm,
        'total_weight'    => $weightGrams,
        'billed_kg'       => $billedKg,
        'options'         => $options
    ];
}
?>
