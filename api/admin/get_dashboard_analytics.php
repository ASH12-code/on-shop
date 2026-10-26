<?php
// C:/laragon/www/on-shop/api/admin/get_dashboard_analytics.php
require_once __DIR__ . '/../helpers/auth.php';
require_role('admin');

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
    // 1. FILTER PERIODE WAKTU DINAMIS
    // -------------------------------------------------------------
    $subOrderDateWhere = "1=1";
    $userDateWhere     = "1=1";
    $dateParams        = [];

    if ($filterKey === 'all_time') {
        $subOrderDateWhere = "1=1";
        $userDateWhere     = "1=1";
    } elseif (preg_match('/^(\d{1,2})_(\d{4})$/', $filterKey, $matches)) {
        $month = (int)$matches[1];
        $year  = (int)$matches[2];
        $startDate = sprintf('%04d-%02d-01 00:00:00', $year, $month);
        $endDate   = date('Y-m-t 23:59:59', strtotime($startDate));

        $subOrderDateWhere = "so.created_at >= :start_date AND so.created_at <= :end_date";
        $userDateWhere     = "u.created_at >= :start_date AND u.created_at <= :end_date";
        $dateParams = [
            'start_date' => $startDate,
            'end_date'   => $endDate
        ];
    }

    // -------------------------------------------------------------
    // 2. METRIK KPI UTAMA (GMV, KOMISI, TOTAL ORDERS, AOV)
    // -------------------------------------------------------------
    $stmtKPI = $db->prepare("
        SELECT 
            COALESCE(SUM(so.gross_product_amount), 0) AS total_gmv,
            COALESCE(SUM(so.gross_product_amount * 0.05), 0) AS platform_commission,
            COUNT(so.sub_order_id) AS total_orders
        FROM sub_orders so
        WHERE so.order_status = 'SELESAI' AND $subOrderDateWhere
    ");
    $stmtKPI->execute($dateParams);
    $kpiData = $stmtKPI->fetch(PDO::FETCH_ASSOC);

    $gmvVal        = (float)$kpiData['total_gmv'];
    $commissionVal = (float)$kpiData['platform_commission'];
    $ordersCount   = (int)$kpiData['total_orders'];
    $avgOrderValue = $ordersCount > 0 ? round($gmvVal / $ordersCount) : 0;

    // Toko Aktif Bertransaksi pada periode terpilih
    $stmtActive = $db->prepare("
        SELECT COUNT(DISTINCT so.shop_id) 
        FROM sub_orders so 
        WHERE so.order_status != 'BATAL' AND $subOrderDateWhere
    ");
    $stmtActive->execute($dateParams);
    $activeMerchantsCount = (int)$stmtActive->fetchColumn();

    // Total Semua Toko Terdaftar di Platform
    $stmtTotalShops = $db->query("SELECT COUNT(shop_id) FROM shops");
    $totalShopsCount = (int)$stmtTotalShops->fetchColumn();

    // -------------------------------------------------------------
    // 3. METRIK TAMBAHAN: TOTAL SALES (UNITS) & PEMBELI (TOTAL & BARU)
    // -------------------------------------------------------------
    // A. Total unit produk yang berhasil terjual
    $stmtSalesUnits = $db->prepare("
        SELECT COALESCE(SUM(oi.quantity), 0) AS total_sales_units
        FROM order_items oi
        JOIN sub_orders so ON oi.sub_order_id = so.sub_order_id
        WHERE so.order_status = 'SELESAI' AND $subOrderDateWhere
    ");
    $stmtSalesUnits->execute($dateParams);
    $totalSalesUnits = (int)$stmtSalesUnits->fetchColumn();

    // B. Total pembeli terdaftar di platform
    $stmtTotalBuyers = $db->query("SELECT COUNT(user_id) FROM users WHERE role = 'buyer'");
    $totalBuyersCount = (int)$stmtTotalBuyers->fetchColumn();

    // C. Pembeli baru yang mendaftar pada periode terpilih
    $stmtNewBuyers = $db->prepare("
        SELECT COUNT(u.user_id) 
        FROM users u 
        WHERE u.role = 'buyer' AND $userDateWhere
    ");
    $stmtNewBuyers->execute($dateParams);
    $newBuyersCount = (int)$stmtNewBuyers->fetchColumn();

    // -------------------------------------------------------------
    // 4. DATA GRAFIK MINGGUAN DINAMIS
    // -------------------------------------------------------------
    $chartLabels = ["Minggu 1", "Minggu 2", "Minggu 3", "Minggu 4"];
    $gmvPoints   = [0.0, 0.0, 0.0, 0.0];

    $stmtChart = $db->prepare("
        SELECT 
            CASE 
                WHEN DAY(so.created_at) BETWEEN 1 AND 7 THEN 1
                WHEN DAY(so.created_at) BETWEEN 8 AND 14 THEN 2
                WHEN DAY(so.created_at) BETWEEN 15 AND 21 THEN 3
                ELSE 4
            END AS week_num,
            COALESCE(SUM(so.gross_product_amount) / 1000000, 0) AS gmv_juta
        FROM sub_orders so
        WHERE so.order_status = 'SELESAI' AND $subOrderDateWhere
        GROUP BY week_num
        ORDER BY week_num ASC
    ");
    $stmtChart->execute($dateParams);
    $chartRows = $stmtChart->fetchAll(PDO::FETCH_ASSOC);

    foreach ($chartRows as $cr) {
        $wIdx = (int)$cr['week_num'] - 1;
        if ($wIdx >= 0 && $wIdx < 4) {
            $gmvPoints[$wIdx] = round((float)$cr['gmv_juta'], 3);
        }
    }

    // Jika all-time atau ada GMV tapi belum terbagi di minggu
    if ($gmvPoints[3] == 0 && $gmvVal > 0 && array_sum($gmvPoints) == 0) {
        $gmvPoints[3] = round($gmvVal / 1000000, 3);
    }

    // -------------------------------------------------------------
    // 5. TOP 5 PRODUK TERLARIS (BERDASARKAN KUANTITAS & OMZET)
    // -------------------------------------------------------------
    $stmtTopProducts = $db->prepare("
        SELECT 
            oi.product_id,
            oi.product_name,
            COALESCE(MAX(oi.product_image_url), 'https://placehold.co/80x80?text=Produk') AS product_image_url,
            COALESCE(SUM(oi.quantity), 0) AS total_sold,
            COALESCE(SUM(oi.total_price), 0) AS total_revenue
        FROM order_items oi
        JOIN sub_orders so ON oi.sub_order_id = so.sub_order_id
        WHERE so.order_status = 'SELESAI' AND $subOrderDateWhere
        GROUP BY oi.product_id, oi.product_name
        ORDER BY total_sold DESC, total_revenue DESC
        LIMIT 5
    ");
    $stmtTopProducts->execute($dateParams);
    $topProducts = $stmtTopProducts->fetchAll(PDO::FETCH_ASSOC);

    // Fallback: Jika pada periode tertentu belum ada order selesai, ambil top produk all-time
    if (empty($topProducts) && $filterKey !== 'all_time') {
        $stmtTopProductsAll = $db->query("
            SELECT 
                oi.product_id,
                oi.product_name,
                COALESCE(MAX(oi.product_image_url), 'https://placehold.co/80x80?text=Produk') AS product_image_url,
                COALESCE(SUM(oi.quantity), 0) AS total_sold,
                COALESCE(SUM(oi.total_price), 0) AS total_revenue
            FROM order_items oi
            JOIN sub_orders so ON oi.sub_order_id = so.sub_order_id
            WHERE so.order_status = 'SELESAI'
            GROUP BY oi.product_id, oi.product_name
            ORDER BY total_sold DESC, total_revenue DESC
            LIMIT 5
        ");
        $topProducts = $stmtTopProductsAll->fetchAll(PDO::FETCH_ASSOC);
    }

    // -------------------------------------------------------------
    // 6. TOP 5 SELLER BERPRESTASI (OMZET & PESANAN TERTINGGI)
    // -------------------------------------------------------------
    $stmtTopSellers = $db->prepare("
        SELECT 
            s.shop_id,
            s.shop_name,
            COALESCE(s.pickup_city, 'Pangkalpinang') AS shop_city,
            COALESCE(s.logo_url, '') AS logo_url,
            COUNT(so.sub_order_id) AS total_orders,
            COALESCE(SUM(so.gross_product_amount), 0) AS total_revenue
        FROM shops s
        JOIN sub_orders so ON s.shop_id = so.shop_id
        WHERE so.order_status = 'SELESAI' AND $subOrderDateWhere
        GROUP BY s.shop_id, s.shop_name, s.pickup_city, s.logo_url
        ORDER BY total_revenue DESC, total_orders DESC
        LIMIT 5
    ");
    $stmtTopSellers->execute($dateParams);
    $topSellers = $stmtTopSellers->fetchAll(PDO::FETCH_ASSOC);

    // Fallback: Jika pada periode tertentu belum ada toko dengan order selesai, ambil all-time
    if (empty($topSellers) && $filterKey !== 'all_time') {
        $stmtTopSellersAll = $db->query("
            SELECT 
                s.shop_id,
                s.shop_name,
                COALESCE(s.pickup_city, 'Pangkalpinang') AS shop_city,
                COALESCE(s.logo_url, '') AS logo_url,
                COUNT(so.sub_order_id) AS total_orders,
                COALESCE(SUM(so.gross_product_amount), 0) AS total_revenue
            FROM shops s
            JOIN sub_orders so ON s.shop_id = so.shop_id
            WHERE so.order_status = 'SELESAI'
            GROUP BY s.shop_id, s.shop_name, s.pickup_city, s.logo_url
            ORDER BY total_revenue DESC, total_orders DESC
            LIMIT 5
        ");
        $topSellers = $stmtTopSellersAll->fetchAll(PDO::FETCH_ASSOC);
    }

    // -------------------------------------------------------------
    // 7. TRANSAKSI TERKINI REAL MARKETPLACE
    // -------------------------------------------------------------
    $stmtRecent = $db->prepare("
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
        WHERE $subOrderDateWhere
        ORDER BY so.sub_order_id DESC
        LIMIT 15
    ");
    $stmtRecent->execute($dateParams);
    $recentOrders = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

    // Jika pada periode terpilih tidak ada transaksi terkini, tampilkan riwayat 15 terbaru
    if (empty($recentOrders)) {
        $stmtRecentFallback = $db->query("
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
        $recentOrders = $stmtRecentFallback->fetchAll(PDO::FETCH_ASSOC);
    }

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
        } elseif ($order['order_status'] === 'MENUNGGU_VERIFIKASI') {
            $statusLabel = "Menunggu Verifikasi";
        } elseif ($order['order_status'] === 'MENUNGGU_BAYAR') {
            $statusLabel = "Menunggu Bayar";
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
            "gmv"                 => $gmvVal,
            "commission"          => $commissionVal,
            "total_orders"        => $ordersCount,
            "active_merchants"    => $activeMerchantsCount,
            "total_merchants"     => $totalShopsCount,
            "avg_order_value"     => $avgOrderValue,
            "total_sales_units"   => $totalSalesUnits,
            "total_buyers"        => $totalBuyersCount,
            "new_buyers"          => $newBuyersCount,
            "chart" => [
                "labels"     => $chartLabels,
                "gmv_points" => $gmvPoints
            ],
            "top_products"        => $topProducts,
            "top_sellers"         => $topSellers,
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
?>