<?php
// C:/laragon/www/on-shop/api/seller/get_dashboard_data.php
require_once __DIR__ . '/seller_auth.php';
header('Content-Type: application/json; charset=UTF-8');

$sales_period    = $_GET['sales_period'] ?? 'daily';
$products_period = $_GET['products_period'] ?? 'all_time';

try {
    // 1. Data Profil Toko
    $shopStmt = $db->prepare("SELECT shop_id, shop_name, pickup_city, user_id FROM shops WHERE shop_id = ?");
    $shopStmt->execute([$shop_id]);
    $shopInfo = $shopStmt->fetch(PDO::FETCH_ASSOC);

    $sellerUserId = $shopInfo['user_id'] ?? null;

    // 2. Metrik Saldo & Omzet Bersih yang Disinkronkan
    // A. Saldo Siap Tarik (Ambil kas nyata dari tabel wallets)
    $availableBalance = 0.0;
    if ($sellerUserId) {
        $stmtWallet = $db->prepare("SELECT available_balance FROM wallets WHERE user_id = ? LIMIT 1");
        $stmtWallet->execute([$sellerUserId]);
        $walletVal = $stmtWallet->fetchColumn();
        if ($walletVal !== false && $walletVal !== null) {
            $availableBalance = (float)$walletVal;
        }
    }

    // B. Saldo Escrow, Pesanan Perlu Diproses, dan Total Omzet Bersih Historis
    $statStmt = $db->prepare("SELECT order_status, net_seller_amount FROM sub_orders WHERE shop_id = ?");
    $statStmt->execute([$shop_id]);
    $orderRows = $statStmt->fetchAll(PDO::FETCH_ASSOC);

    $escrowBalance    = 0.0;
    $ordersToProcess  = 0;
    $totalRevenue     = 0.0;

    foreach ($orderRows as $row) {
        $net = (float)$row['net_seller_amount'];
        $st  = strtoupper($row['order_status']);

        if ($st === 'SELESAI') {
            // Omzet historis toko dari pesanan yang sukses selesai
            $totalRevenue += $net;
        } else if ($st === 'DIKEMAS' || $st === 'DIKIRIM' || $st === 'MENUNGGU_VERIFIKASI') {
            // Saldo tertahan / pesanan yang sedang berjalan
            $escrowBalance += $net;
            if ($st === 'DIKEMAS' || $st === 'MENUNGGU_VERIFIKASI') {
                $ordersToProcess++;
            }
        }
    }

    // Jika tabel wallets belum ada data untuk seller ini, sinkronkan ke availableBalance
    if ($availableBalance == 0.0 && $sellerUserId) {
        $stmtCheckW = $db->prepare("SELECT COUNT(*) FROM wallets WHERE user_id = ?");
        $stmtCheckW->execute([$sellerUserId]);
        if ((int)$stmtCheckW->fetchColumn() === 0) {
            $availableBalance = $totalRevenue;
        }
    }

    // 3. 5 Pesanan Terbaru Toko
    $ordersQuery = "
        SELECT 
            so.sub_order_id,
            so.sub_order_number,
            so.courier_name,
            so.courier_service,
            so.order_status,
            so.gross_product_amount,
            so.created_at,
            COALESCE(u.name, 'Pembeli') AS buyer_name
        FROM sub_orders so
        JOIN master_orders mo ON so.master_order_id = mo.master_order_id
        JOIN users u ON mo.buyer_id = u.user_id
        WHERE so.shop_id = ?
        ORDER BY so.sub_order_id DESC
        LIMIT 5
    ";
    $ordStmt = $db->prepare($ordersQuery);
    $ordStmt->execute([$shop_id]);
    $recentOrders = $ordStmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Data Tren Pendapatan Bersih Berdasarkan Filter Periode (REAL DATABASE QUERY)
    $salesLabels = [];
    $salesData   = [];

    if ($sales_period === 'weekly') {
        // 4 Minggu Terakhir (Interval per 7 hari ke belakang dari hari ini)
        $salesLabels = ["Minggu 1", "Minggu 2", "Minggu 3", "Minggu 4"];
        $weeks = [
            [28, 22],
            [21, 15],
            [14, 8],
            [7, 0]
        ];
        foreach ($weeks as [$startDay, $endDay]) {
            $stmtW = $db->prepare("
                SELECT COALESCE(SUM(net_seller_amount), 0) 
                FROM sub_orders 
                WHERE shop_id = ? 
                  AND order_status != 'BATAL'
                  AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                  AND created_at <= DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmtW->execute([$shop_id, $startDay, $endDay]);
            $salesData[] = (float)$stmtW->fetchColumn();
        }
    } else if ($sales_period === 'semester1') {
        // Semester 1: Jan - Jun (Tahun Berjalan)
        $salesLabels = ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun"];
        $stmtM = $db->prepare("
            SELECT MONTH(created_at) AS bln, COALESCE(SUM(net_seller_amount), 0) AS omzet
            FROM sub_orders
            WHERE shop_id = ? 
              AND order_status != 'BATAL'
              AND YEAR(created_at) = YEAR(CURDATE())
              AND MONTH(created_at) BETWEEN 1 AND 6
            GROUP BY MONTH(created_at)
        ");
        $stmtM->execute([$shop_id]);
        $monthRows = $stmtM->fetchAll(PDO::FETCH_KEY_PAIR);
        for ($m = 1; $m <= 6; $m++) {
            $salesData[] = isset($monthRows[$m]) ? (float)$monthRows[$m] : 0.0;
        }
    } else if ($sales_period === 'semester2') {
        // Semester 2: Jul - Des (Tahun Berjalan)
        $salesLabels = ["Jul", "Agu", "Sep", "Okt", "Nov", "Des"];
        $stmtM = $db->prepare("
            SELECT MONTH(created_at) AS bln, COALESCE(SUM(net_seller_amount), 0) AS omzet
            FROM sub_orders
            WHERE shop_id = ? 
              AND order_status != 'BATAL'
              AND YEAR(created_at) = YEAR(CURDATE())
              AND MONTH(created_at) BETWEEN 7 AND 12
            GROUP BY MONTH(created_at)
        ");
        $stmtM->execute([$shop_id]);
        $monthRows = $stmtM->fetchAll(PDO::FETCH_KEY_PAIR);
        for ($m = 7; $m <= 12; $m++) {
            $salesData[] = isset($monthRows[$m]) ? (float)$monthRows[$m] : 0.0;
        }
    } else {
        // Daily: 7 Hari Terakhir (Harian)
        $daysIndo = ['Sun' => 'Min', 'Mon' => 'Sen', 'Tue' => 'Sel', 'Wed' => 'Rab', 'Thu' => 'Kam', 'Fri' => 'Jum', 'Sat' => 'Sab'];
        $stmtD = $db->prepare("
            SELECT DATE(created_at) AS tgl, COALESCE(SUM(net_seller_amount), 0) AS omzet
            FROM sub_orders
            WHERE shop_id = ? 
              AND order_status != 'BATAL'
              AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
            GROUP BY DATE(created_at)
        ");
        $stmtD->execute([$shop_id]);
        $dateRows = $stmtD->fetchAll(PDO::FETCH_KEY_PAIR);

        for ($i = 6; $i >= 0; $i--) {
            $timestamp = strtotime("-$i days");
            $dateKey   = date('Y-m-d', $timestamp);
            $dayShort  = date('D', $timestamp);
            $label     = $daysIndo[$dayShort] ?? $dayShort;

            $salesLabels[] = $label;
            $salesData[]   = isset($dateRows[$dateKey]) ? (float)$dateRows[$dateKey] : 0.0;
        }
    }

    $salesChart = [
        'labels' => $salesLabels,
        'data'   => $salesData
    ];

    // 5. Top 5 Produk Terlaris Toko (REAL DATABASE QUERY DENGAN FILTER PERIODE)
    $dateCondition = "";
    if ($products_period === 'last_7_days') {
        $dateCondition = "AND so.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    } else if ($products_period === 'last_30_days') {
        $dateCondition = "AND so.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    } else if ($products_period === 'month_jan') {
        $dateCondition = "AND MONTH(so.created_at) = 1 AND YEAR(so.created_at) = YEAR(CURDATE())";
    } else if ($products_period === 'month_feb') {
        $dateCondition = "AND MONTH(so.created_at) = 2 AND YEAR(so.created_at) = YEAR(CURDATE())";
    }

    $topQuery = "
        SELECT 
            oi.product_name,
            COALESCE(SUM(oi.quantity), 0) AS total_sold
        FROM order_items oi
        JOIN sub_orders so ON oi.sub_order_id = so.sub_order_id
        WHERE so.shop_id = ?
          AND so.order_status != 'BATAL'
          $dateCondition
        GROUP BY oi.product_id, oi.product_name
        ORDER BY total_sold DESC
        LIMIT 5
    ";
    $topStmt = $db->prepare($topQuery);
    $topStmt->execute([$shop_id]);
    $dbTopProducts = $topStmt->fetchAll(PDO::FETCH_ASSOC);

    $topLabels = [];
    $topData   = [];

    foreach ($dbTopProducts as $item) {
        $topLabels[] = $item['product_name'];
        $topData[]   = (int)$item['total_sold'];
    }

    echo json_encode([
        "status" => true,
        "shop"   => $shopInfo,
        "metrics" => [
            "available_balance" => (int)$availableBalance,
            "escrow_balance"    => (int)$escrowBalance,
            "orders_to_process" => (int)$ordersToProcess,
            "total_revenue"     => (int)$totalRevenue
        ],
        "recent_orders" => $recentOrders,
        "sales_chart"   => $salesChart,
        "top_products"  => [
            "labels" => $topLabels,
            "data"   => $topData
        ]
    ]);
    exit;

} catch (Exception $e) {
    error_log("Seller get_dashboard_data error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Terjadi kesalahan pada sistem."]);
    exit;
}
