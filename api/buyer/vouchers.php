<?php
// C:/laragon/www/on-shop/api/buyer/vouchers.php
require_once __DIR__ . '/../helpers/auth.php';
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// 1. Ambil user_id dari sesi server yang aman
$userId = require_auth();

$action = $_GET['action'] ?? '';

try {
    // AMBIL DAFTAR VOUCHER UNTUK CHECKOUT
    if ($action === 'get_applicable_vouchers') {
        $sql = "
            SELECT 
                uv.user_voucher_id,
                v.voucher_id,
                v.shop_id,
                v.voucher_code,
                v.voucher_name,
                v.voucher_category,
                v.discount_type,
                v.discount_value,
                v.min_purchase,
                v.max_discount,
                v.start_date,
                v.end_date
            FROM user_vouchers uv
            JOIN vouchers v ON uv.voucher_id = v.voucher_id
            WHERE uv.user_id = :uid 
              AND uv.status = 'UNUSED'
              AND v.is_active = 1
              AND NOW() BETWEEN v.start_date AND v.end_date
            ORDER BY v.voucher_category ASC, v.discount_value DESC
        ";

        $stmt = $conn->prepare($sql);
        $stmt->execute(['uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formatted = array_map(function($r) {
            return [
                'user_voucher_id'  => (int)$r['user_voucher_id'],
                'voucher_id'       => (int)$r['voucher_id'],
                'shop_id'          => $r['shop_id'] !== null ? (int)$r['shop_id'] : null,
                'voucher_code'     => $r['voucher_code'],
                'voucher_name'     => $r['voucher_name'],
                'voucher_category' => $r['voucher_category'], // 'PRODUCT_DISCOUNT' / 'FREE_SHIPPING'
                'discount_type'    => $r['discount_type'],    // 'FIXED' / 'PERCENTAGE'
                'discount_value'   => (float)$r['discount_value'],
                'min_purchase'     => (float)$r['min_purchase'],
                'max_discount'     => $r['max_discount'] !== null ? (float)$r['max_discount'] : null
            ];
        }, $rows);

        echo json_encode(["status" => true, "data" => $formatted]);
        exit;
    }

    echo json_encode(["status" => false, "message" => "Aksi tidak dikenali."]);

} catch (PDOException $e) {
    error_log("Vouchers error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Terjadi kesalahan pada sistem."]);
    exit;
}
?>