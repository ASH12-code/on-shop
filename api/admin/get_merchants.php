<?php
require_once __DIR__ . '/../helpers/auth.php';
require_role('admin');
// C:/laragon/www/on-shop/api/admin/get_merchants.php
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

require_once __DIR__ . '/../../config/database.php';

$db = isset($conn) ? $conn : (isset($pdo) ? $pdo : null);
if (!$db) {
    echo json_encode(["status" => false, "message" => "Koneksi database gagal."]);
    exit;
}

// Menonaktifkan ONLY_FULL_GROUP_BY untuk kompatibilitas MySQL 8.4
$db->exec("SET SESSION sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");

try {
    // Ambil data toko beserta nama pemilik, no hp, total produk aktif, dan rating rata-rata
    $query = "
        SELECT 
            s.shop_id,
            s.shop_name,
            s.pickup_city AS city,
            s.pickup_address,
            s.bank_name,
            s.bank_account_number,
            COALESCE(s.status, 'verified') AS status,
            COALESCE(s.is_official, 0) AS is_official,
            COALESCE(s.ktp_image_url, 'https://placehold.co/400x250/2e8b57/ffffff?text=KTP+Terverifikasi') AS ktp_image,
            s.suspended_until,
            s.suspension_reason,
            u.user_id,
            u.name AS owner_name,
            COALESCE(u.phone, '-') AS phone,
            (SELECT COUNT(p.product_id) FROM products p WHERE p.shop_id = s.shop_id AND p.is_archived = 0) AS active_products,
            COALESCE(
                (SELECT ROUND(AVG(r.rating), 1) 
                 FROM reviews r 
                 JOIN products prod ON r.product_id = prod.product_id 
                 WHERE prod.shop_id = s.shop_id), 
                0.0
            ) AS rating
        FROM shops s
        JOIN users u ON s.user_id = u.user_id
        ORDER BY s.shop_id DESC
    ";

    $stmt = $db->query($query);
    $merchants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format output
    $formatted = [];
    foreach ($merchants as $m) {
        $formatted[] = [
            'shop_id'         => (int)$m['shop_id'],
            'shop_name'       => $m['shop_name'],
            'owner_name'      => $m['owner_name'],
            'phone'           => $m['phone'],
            'city'            => $m['city'],
            'pickup_address'  => $m['pickup_address'],
            'bank_account'    => $m['bank_name'] . ' - ' . $m['bank_account_number'] . ' a.n ' . $m['owner_name'],
            'ktp_image'       => $m['ktp_image'],
            'active_products' => (int)$m['active_products'],
            'rating'          => (float)$m['rating'],
            'status'          => $m['status'],
            'is_official'     => (bool)$m['is_official'],
            'suspended_until' => $m['suspended_until'],
            'suspension_reason' => $m['suspension_reason']
        ];
    }

    echo json_encode([
        "status" => true,
        "data"   => $formatted
    ]);
    exit;

} catch (PDOException $e) {
    error_log("Admin API Error in " . basename(__FILE__) . ": " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Terjadi kesalahan pada sistem."]);
    exit;
}