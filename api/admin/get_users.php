<?php
require_once __DIR__ . '/../helpers/auth.php';
require_role('admin');
// C:/laragon/www/on-shop/api/admin/get_users.php
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

$db->exec("SET SESSION sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");

try {
    // Ambil semua pengguna kecuali super admin (atau role buyer & seller)
    $query = "
        SELECT 
            u.user_id,
            u.user_code,
            u.name,
            u.email,
            u.phone,
            u.role,
            u.has_shop,
            u.avatar_url,
            DATE_FORMAT(u.created_at, '%Y-%m-%d') AS join_date,
            COALESCE(u.is_banned, 0) AS is_banned,
            u.banned_reason,
            (SELECT COUNT(mo.master_order_id) FROM master_orders mo WHERE mo.buyer_id = u.user_id) AS total_orders,
            COALESCE(
                (SELECT SUM(mo.grand_total) FROM master_orders mo WHERE mo.buyer_id = u.user_id), 
                0
            ) AS total_spent
        FROM users u
        WHERE u.role != 'admin'
        ORDER BY u.user_id DESC
    ";

    $stmt = $db->query($query);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ambil riwayat order untuk tiap user secara terstruktur
    $formatted = [];
    foreach ($users as $u) {
        $userId = (int)$u['user_id'];
        
        $orderStmt = $db->prepare("
            SELECT 
                invoice_number,
                CONCAT(shipping_recipient_name, ' (', shipping_city, ')') AS recipient_info,
                grand_total,
                DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS order_date
            FROM master_orders
            WHERE buyer_id = ?
            ORDER BY master_order_id DESC
        ");
        $orderStmt->execute([$userId]);
        $orders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);

        $formatted[] = [
            'user_id'       => $userId,
            'user_code'     => $u['user_code'],
            'name'          => $u['name'],
            'email'         => $u['email'] ?: '-',
            'phone'         => $u['phone'] ?: '-',
            'role'          => $u['role'],
            'has_shop'      => (int)$u['has_shop'],
            'avatar_url'    => $u['avatar_url'],
            'created_at'    => $u['join_date'],
            'total_orders'  => (int)$u['total_orders'],
            'total_spent'   => (float)$u['total_spent'],
            'is_banned'     => (int)$u['is_banned'],
            'banned_reason' => $u['banned_reason'],
            'orders'        => $orders
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