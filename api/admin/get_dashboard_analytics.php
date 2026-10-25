<?php
require_once __DIR__ . '/../helpers/auth.php';
require_role('admin');
// C:/laragon/www/on-shop/api/admin/get_dashboard_analytics.php
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

// Matikan aturan ONLY_FULL_GROUP_BY untuk sesi ini agar kueri agregasi berjalan lancar
$db->exec("SET SESSION sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");

$periodType = $_GET['period_type'] ?? 'monthly';
$filterKey  = $_GET['filter_key'] ?? '9_2026';

try {
    // -------------------------------------------------------------
    // 1. KPI UTAMA DARI TABEL SUB_ORDERS
    // -------------------------------------------------------------
    $stmtKPI = $db->query("
        SELECT 
            COALESCE(SUM(gross_product_amount), 0) AS total_gmv,
            COALESCE(SUM(gross_product_amount * 0.05), 0) AS platform_commission,
            COUNT(sub_order_id) AS total_orders
        FROM sub_orders
        WHERE order_status = 'SELESAI'
    ");
    $kpiData = $stmtKPI->fetch(PDO::FETCH_ASSOC);

    $gmvVal        = (float)$kpiData['total_gmv'];
    $commissionVal = (float)$kpiData['platform_commission'];
    $ordersCount   = (int)$kpiData['total_orders'];

    // Toko Aktif Bertransaksi
    $stmtActive = $db->query("SELECT COUNT(DISTINCT shop_id) FROM sub_orders WHERE order_status != 'BATAL'");
    $activeMerchantsCount = (int)$stmtActive->fetchColumn();

    // Total Semua Toko
    $stmtTotalShops = $db->query("SELECT COUNT(shop_id) FROM shops");
    $totalShopsCount = (int)$stmtTotalShops->fetchColumn();

    $avgOrderValue = $ordersCount > 0 ? round($gmvVal / $ordersCount) : 0;

    // -------------------------------------------------------------
    // 2. DATA GRAFIK (MINGGU 1 - 4 DI BULAN BERJALAN)
    // -------------------------------------------------------------
    $chartLabels = ["Minggu 1", "Minggu 2", "Minggu 3", "Minggu 4"];
    $gmvPoints   = [0.0, 0.0, 0.0, 0.0];

    $stmtChart = $db->query("
        SELECT 
            CASE 
                WHEN DAY(created_at) BETWEEN 1 AND 7 THEN 1
                WHEN DAY(created_at) BETWEEN 8 AND 14 THEN 2
                WHEN DAY(created_at) BETWEEN 15 AND 21 THEN 3
                ELSE 4
            END AS week_num,
            COALESCE(SUM(gross_product_amount) / 1000000, 0) AS gmv_juta
        FROM sub_orders
        WHERE order_status = 'SELESAI'
        GROUP BY week_num
        ORDER BY week_num ASC
    ");
    $chartRows = $stmtChart->fetchAll(PDO::FETCH_ASSOC);

    foreach ($chartRows as $cr) {
        $wIdx = (int)$cr['week_num'] - 1;
        if ($wIdx >= 0 && $wIdx < 4) {
            $gmvPoints[$wIdx] = round((float)$cr['gmv_juta'], 3);
        }
    }

    if ($gmvPoints[3] == 0 && $gmvVal > 0) {
        $gmvPoints[3] = round($gmvVal / 1000000, 3);
    }

    // -------------------------------------------------------------
    // 3. TRANSAKSI TERKINI (KUERI AMAN SQL_MODE)
    // -------------------------------------------------------------
    $stmtRecent = $db->query("
        SELECT 
            so.sub_order_id,
            so.sub_order_number,
            so.gross_product_amount AS amount,
            so.order_status,
            s.shop_name AS shop,
            COALESCE(p.payment_method, 'VA_BCA') AS method,
            COALESCE(
                (SELECT c.name 
                 FROM order_items oi2 
                 JOIN products prod2 ON oi2.product_id = prod2.product_id 
                 JOIN categories c ON prod2.category_id = c.category_id 
                 WHERE oi2.sub_order_id = so.sub_order_id LIMIT 1), 
                'Produk'
            ) AS category,
            COALESCE(
                (SELECT oi3.product_name 
                 FROM order_items oi3 
                 WHERE oi3.sub_order_id = so.sub_order_id LIMIT 1), 
                'Pesanan Produk'
            ) AS item_name
        FROM sub_orders so
        JOIN shops s ON so.shop_id = s.shop_id
        LEFT JOIN master_orders mo ON so.master_order_id = mo.master_order_id
        LEFT JOIN payments p ON mo.master_order_id = p.master_order_id
        ORDER BY so.sub_order_id DESC
        LIMIT 15
    ");
    $recentOrders = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

    $formattedRecent = [];
    foreach ($recentOrders as $order) {
        $statusLabel = "Sedang Dikemas";
        if ($order['order_status'] === 'SELESAI') {
            $statusLabel = "Lunas";
        } elseif ($order['order_status'] === 'DIKIRIM') {
            $statusLabel = "Diantar";
        } elseif ($order['order_status'] === 'BATAL') {
            $statusLabel = "Batal";
        } elseif ($order['order_status'] === 'DIKOMPLAIN') {
            $statusLabel = "Sengketa";
        }

        $formattedRecent[] = [
            'sub_order_number' => $order['sub_order_number'],
            'shop'             => $order['shop'],
            'category'         => $order['category'],
            'item_name'        => $order['item_name'],
            'amount'           => (float)$order['amount'],
            'method'           => str_replace('_', ' ', $order['method']),
            'status'           => $statusLabel
        ];
    }

    echo json_encode([
        "status" => true,
        "data"   => [
            "gmv"              => $gmvVal,
            "commission"       => $commissionVal,
            "total_orders"     => $ordersCount,
            "active_merchants" => $activeMerchantsCount,
            "total_merchants"  => $totalShopsCount,
            "avg_order_value"  => $avgOrderValue,
            "chart" => [
                "labels"     => $chartLabels,
                "gmv_points" => $gmvPoints
            ],
            "recent_transactions" => $formattedRecent
        ]
    ]);
    exit;

} catch (PDOException $e) {
    error_log("Admin API Error in " . basename(__FILE__) . ": " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Terjadi kesalahan pada sistem."]);
    exit;
}