<?php
require_once __DIR__ . '/../helpers/auth.php';
require_role('admin');
// C:/laragon/www/on-shop/api/admin/get_disputes.php
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

require_once __DIR__ . '/../../config/database.php';

$db = isset($conn) ? $conn : (isset($pdo) ? $pdo : null);
if (!$db) {
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Koneksi database terputus."]);
    exit;
}

try {
    // 1. Hitung Ringkasan KPI Kasus
    $stmtKpi = $db->query("
        SELECT 
            COUNT(CASE WHEN status = 'PENDING' THEN 1 END) AS pending_count,
            COUNT(CASE WHEN status = 'IN_REVIEW' THEN 1 END) AS review_count,
            COUNT(CASE WHEN status = 'REFUNDED' THEN 1 END) AS refunded_count,
            COUNT(CASE WHEN status = 'RELEASED_TO_SELLER' THEN 1 END) AS released_count
        FROM disputes
    ");
    $kpi = $stmtKpi->fetch(PDO::FETCH_ASSOC);

    // 2. Ambil Seluruh Data Sengketa dengan Relasi Toko, Invoice, dan Pembeli
    $query = "
        SELECT 
            d.dispute_id,
            d.ticket_code,
            d.sub_order_id,
            d.amount,
            d.reason,
            d.buyer_complaint,
            d.evidence_image,
            d.status,
            d.admin_verdict_notes,
            DATE_FORMAT(d.created_at, '%Y-%m-%d %H:%i:%s') AS created_at,
            so.sub_order_number,
            mo.invoice_number,
            u.name AS buyer_name,
            u.user_code AS buyer_code,
            s.shop_name
        FROM disputes d
        JOIN sub_orders so ON d.sub_order_id = so.sub_order_id
        JOIN master_orders mo ON so.master_order_id = mo.master_order_id
        JOIN users u ON d.buyer_id = u.user_id
        JOIN shops s ON d.shop_id = s.shop_id
        ORDER BY d.dispute_id DESC
    ";
    $stmtList = $db->query($query);
    $disputes = $stmtList->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => true,
        "data"   => [
            "kpi"      => [
                "pending"  => (int)$kpi['pending_count'],
                "review"   => (int)$kpi['review_count'],
                "refunded" => (int)$kpi['refunded_count'],
                "released" => (int)$kpi['released_count']
            ],
            "disputes" => $disputes
        ]
    ]);
    exit;

} catch (PDOException $e) {
    error_log("Admin API Error in " . basename(__FILE__) . ": " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Terjadi kesalahan pada sistem."]);
    exit;
}