<?php
// C:/laragon/www/on-shop/api/buyer/search_products.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

require_once '../../config/database.php';

$keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
$sort    = isset($_GET['sort']) ? trim($_GET['sort']) : 'terkait';

try {
    // Bangun dasar query SQL agregasi
    $sql = "
        SELECT 
            p.product_id,
            p.name AS product_name,
            p.created_at,
            c.name AS category_name,
            s.shop_name,
            s.pickup_city AS shop_city,
            COALESCE(MIN(pv.price), 0) AS display_price,
            COALESCE(SUM(pv.stock), 0) AS total_stock,
            COALESCE(MAX(pi.image_url), 'https://placehold.co/300x300/cccccc/333333?text=No+Image') AS image_url,
            COALESCE(ROUND(AVG(r.rating), 1), 5.0) AS rating_avg,
            COUNT(DISTINCT r.review_id) AS total_reviews
        FROM products p
        JOIN shops s ON p.shop_id = s.shop_id
        JOIN categories c ON p.category_id = c.category_id
        LEFT JOIN product_variants pv ON p.product_id = pv.product_id
        LEFT JOIN product_images pi ON p.product_id = pi.product_id AND pi.is_primary = 1
        LEFT JOIN reviews r ON p.product_id = r.product_id
    ";

    $params = [];
    if (!empty($keyword)) {
        $sql .= " WHERE (p.name LIKE :kw_name OR c.name LIKE :kw_cat OR p.description LIKE :kw_desc) ";
        $params['kw_name'] = "%{$keyword}%";
        $params['kw_cat']  = "%{$keyword}%";
        $params['kw_desc'] = "%{$keyword}%";
    }

    $sql .= " GROUP BY p.product_id, p.name, p.created_at, c.name, s.shop_name, s.pickup_city ";

    // Klausa Pengurutan (Sorting)
    switch ($sort) {
        case 'terbaru':
            $sql .= " ORDER BY p.created_at DESC ";
            break;
        case 'price_asc':
            $sql .= " ORDER BY display_price ASC ";
            break;
        case 'price_desc':
            $sql .= " ORDER BY display_price DESC ";
            break;
        case 'terlaris':
            // Terlaris diurutkan berdasarkan banyak review / penjualan
            $sql .= " ORDER BY total_reviews DESC, p.product_id DESC ";
            break;
        case 'terkait':
        default:
            $sql .= " ORDER BY p.product_id DESC ";
            break;
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status"  => true,
        "keyword" => $keyword,
        "total"   => count($products),
        "data"    => $products
    ]);

} catch (PDOException $e) {
    error_log("Buyer search_products error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status"  => false,
        "message" => "Terjadi kesalahan pada pencarian produk."
    ]);
}
?>