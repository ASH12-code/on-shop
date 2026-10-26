<?php
// C:/laragon/www/on-shop/api/buyer/orders.php
require_once __DIR__ . '/../helpers/auth.php';
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$db = get_db_connection();
if (!$db) {
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Koneksi database gagal."]);
    exit;
}

// 1. Amankan identitas pembeli dari sesi server
$sessionUserId = require_auth();

$action = $_GET['action'] ?? '';

try {
    // -------------------------------------------------------------
    // 1. DAFTAR PESANAN PEMBELI LENGKAP DENGAN SENGKETA
    // -------------------------------------------------------------
    if ($action === 'list') {
        $userId = $sessionUserId;

        $sql = "
            SELECT 
                so.sub_order_id,
                so.master_order_id,
                so.sub_order_number,
                so.order_status,
                so.courier_name,
                so.courier_service,
                so.tracking_number,
                so.shipping_cost,
                so.gross_product_amount,
                so.shipped_at,
                so.delivered_at,
                DATE_FORMAT(so.created_at, '%d %b %Y, %H:%i') AS created_at,
                mo.invoice_number,
                mo.grand_total,
                s.shop_id,
                s.shop_name,
                s.pickup_city AS shop_city,
                p.payment_id,
                p.payment_method,
                p.payment_status,
                p.proof_image_url,
                p.rejection_reason,
                p.verified_at,
                p.va_number,
                p.expired_at
            FROM sub_orders so
            JOIN master_orders mo ON so.master_order_id = mo.master_order_id
            JOIN shops s ON so.shop_id = s.shop_id
            LEFT JOIN payments p ON mo.master_order_id = p.master_order_id
            WHERE mo.buyer_id = :uid
            ORDER BY so.sub_order_id DESC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute(['uid' => $userId]);
        $subOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($subOrders as $order) {
            $stmtItems = $db->prepare("
                SELECT 
                    oi.order_item_id,
                    oi.product_id,
                    oi.product_name,
                    oi.variant_name,
                    oi.product_image_url,
                    oi.price_at_purchase,
                    oi.quantity,
                    oi.total_price,
                    r.review_id,
                    r.rating,
                    r.comment AS review_comment,
                    r.review_image_url,
                    r.seller_reply
                FROM order_items oi
                LEFT JOIN reviews r ON (
                    oi.product_id = r.product_id 
                    AND oi.sub_order_id = r.sub_order_id 
                    AND r.user_id = :uid
                )
                WHERE oi.sub_order_id = :sub_id
            ");
            $stmtItems->execute([
                'sub_id' => $order['sub_order_id'],
                'uid'    => $userId
            ]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            // Kolom asli: evidence_image (di-alias jadi dispute_image_url untuk UI frontend)
            $stmtDispute = $db->prepare("
                SELECT 
                    dispute_id, 
                    ticket_code, 
                    amount, 
                    reason, 
                    buyer_complaint, 
                    evidence_image AS dispute_image_url,
                    status AS dispute_status, 
                    admin_verdict_notes AS admin_notes,
                    COALESCE(resolved_at, created_at) AS last_updated_at
                FROM disputes 
                WHERE sub_order_id = :sub_id 
                ORDER BY dispute_id DESC 
                LIMIT 1
            ");
            $stmtDispute->execute(['sub_id' => $order['sub_order_id']]);
            $dispute = $stmtDispute->fetch(PDO::FETCH_ASSOC);

            $order['items'] = $items;
            $order['dispute'] = $dispute ?: null;
            $order['proof_view_url'] = !empty($order['proof_image_url']) ? '/on-shop/api/files/payment_proof.php?file=' . urlencode(basename($order['proof_image_url'])) : null;
            $results[] = $order;
        }

        echo json_encode(["status" => true, "data" => $results]);
        exit;
    }

    // -------------------------------------------------------------
    // 1b. RINGKASAN DASHBOARD PEMBELI (METRIK & PESANAN TERBARU)
    // -------------------------------------------------------------
    if ($action === 'dashboard_summary') {
        $userId = $sessionUserId;

        // Total seluruh pesanan
        $stmtTotal = $db->prepare("
            SELECT COUNT(so.sub_order_id)
            FROM sub_orders so
            JOIN master_orders mo ON so.master_order_id = mo.master_order_id
            WHERE mo.buyer_id = :uid
        ");
        $stmtTotal->execute(['uid' => $userId]);
        $totalOrders = (int)$stmtTotal->fetchColumn();

        // Pesanan sedang berjalan (aktif)
        $stmtActive = $db->prepare("
            SELECT COUNT(so.sub_order_id)
            FROM sub_orders so
            JOIN master_orders mo ON so.master_order_id = mo.master_order_id
            WHERE mo.buyer_id = :uid 
              AND so.order_status IN ('MENUNGGU_BAYAR', 'MENUNGGU_VERIFIKASI', 'DIKEMAS', 'DIKIRIM')
        ");
        $stmtActive->execute(['uid' => $userId]);
        $activeOrders = (int)$stmtActive->fetchColumn();

        // Pesanan selesai
        $stmtCompleted = $db->prepare("
            SELECT COUNT(so.sub_order_id)
            FROM sub_orders so
            JOIN master_orders mo ON so.master_order_id = mo.master_order_id
            WHERE mo.buyer_id = :uid AND so.order_status = 'SELESAI'
        ");
        $stmtCompleted->execute(['uid' => $userId]);
        $completedOrders = (int)$stmtCompleted->fetchColumn();

        // Total belanja selesai (Rp)
        $stmtSpent = $db->prepare("
            SELECT COALESCE(SUM(so.gross_product_amount + so.shipping_cost), 0)
            FROM sub_orders so
            JOIN master_orders mo ON so.master_order_id = mo.master_order_id
            WHERE mo.buyer_id = :uid AND so.order_status = 'SELESAI'
        ");
        $stmtSpent->execute(['uid' => $userId]);
        $totalSpent = (float)$stmtSpent->fetchColumn();

        // 4 Pesanan terbaru
        $stmtRecent = $db->prepare("
            SELECT 
                so.sub_order_id,
                so.master_order_id,
                so.sub_order_number,
                so.order_status,
                (so.gross_product_amount + so.shipping_cost) AS total_amount,
                DATE_FORMAT(so.created_at, '%d %b %Y, %H:%i') AS created_at,
                s.shop_name,
                p.payment_method,
                p.payment_status
            FROM sub_orders so
            JOIN master_orders mo ON so.master_order_id = mo.master_order_id
            JOIN shops s ON so.shop_id = s.shop_id
            LEFT JOIN payments p ON mo.master_order_id = p.master_order_id
            WHERE mo.buyer_id = :uid
            ORDER BY so.sub_order_id DESC
            LIMIT 4
        ");
        $stmtRecent->execute(['uid' => $userId]);
        $recentSubs = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

        $recentOrders = [];
        foreach ($recentSubs as $sub) {
            $stmtItems = $db->prepare("
                SELECT product_name, product_image_url, quantity, total_price
                FROM order_items
                WHERE sub_order_id = :soid
                ORDER BY order_item_id ASC
            ");
            $stmtItems->execute(['soid' => $sub['sub_order_id']]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            $itemCount = count($items);
            $firstItem = $itemCount > 0 ? $items[0] : null;
            $otherCount = $itemCount > 1 ? ($itemCount - 1) : 0;

            $sub['first_item'] = $firstItem;
            $sub['total_items_count'] = $itemCount;
            $sub['other_items_count'] = $otherCount;
            $recentOrders[] = $sub;
        }

        echo json_encode([
            "status" => true,
            "data" => [
                "total_orders"     => $totalOrders,
                "active_orders"    => $activeOrders,
                "completed_orders" => $completedOrders,
                "total_spent"      => $totalSpent,
                "recent_orders"    => $recentOrders
            ]
        ]);
        exit;
    }

    $input = json_decode(file_get_contents("php://input"), true) ?: [];

    // -------------------------------------------------------------
    // 2. SIMULASI BAYAR PESANAN
    // -------------------------------------------------------------
    if ($action === 'pay_order') {
        $masterOrderId = isset($input['master_order_id']) ? intval($input['master_order_id']) : 0;
        if ($masterOrderId <= 0) {
            echo json_encode(["status" => false, "message" => "ID Pesanan tidak valid."]);
            exit;
        }

        $db->beginTransaction();

        $stmtPay = $db->prepare("
            UPDATE payments 
            SET payment_status = 'PAID', paid_at = NOW() 
            WHERE master_order_id = :mid
        ");
        $stmtPay->execute(['mid' => $masterOrderId]);

        $stmtGetSubs = $db->prepare("
            SELECT so.sub_order_id, so.gross_product_amount, s.user_id AS seller_user_id
            FROM sub_orders so
            JOIN shops s ON so.shop_id = s.shop_id
            WHERE so.master_order_id = :mid AND so.order_status = 'MENUNGGU_BAYAR'
        ");
        $stmtGetSubs->execute(['mid' => $masterOrderId]);
        $pendingSubs = $stmtGetSubs->fetchAll(PDO::FETCH_ASSOC);

        $stmtSub = $db->prepare("
            UPDATE sub_orders 
            SET order_status = 'DIKEMAS' 
            WHERE master_order_id = :mid AND order_status = 'MENUNGGU_BAYAR'
        ");
        $stmtSub->execute(['mid' => $masterOrderId]);

        $stmtWalletPending = $db->prepare("
            INSERT INTO wallets (user_id, available_balance, pending_balance, updated_at)
            VALUES (:uid, 0.00, :amt, NOW())
            ON DUPLICATE KEY UPDATE pending_balance = pending_balance + :amt, updated_at = NOW()
        ");

        foreach ($pendingSubs as $sub) {
            $gross = floatval($sub['gross_product_amount']);
            $stmtWalletPending->execute([
                'uid' => $sub['seller_user_id'],
                'amt' => $gross
            ]);
        }

        $db->commit();
        echo json_encode(["status" => true, "message" => "Pembayaran berhasil diverifikasi!"]);
        exit;
    }

    // -------------------------------------------------------------
    // 3. SIMULASI SELLER MENGIRIM PESANAN
    // -------------------------------------------------------------
    if ($action === 'ship_order') {
        $subOrderId = isset($input['sub_order_id']) ? intval($input['sub_order_id']) : 0;
        $trackingNumber = isset($input['tracking_number']) ? trim($input['tracking_number']) : ("JNE" . date("Ymd") . rand(1000, 9999));

        if ($subOrderId <= 0) {
            echo json_encode(["status" => false, "message" => "ID Pesanan tidak valid."]);
            exit;
        }

        $stmt = $db->prepare("
            UPDATE sub_orders 
            SET order_status = 'DIKIRIM', 
                tracking_number = :resi, 
                shipped_at = NOW() 
            WHERE sub_order_id = :soid AND order_status = 'DIKEMAS'
        ");
        $stmt->execute(['resi' => $trackingNumber, 'soid' => $subOrderId]);

        echo json_encode(["status" => true, "message" => "Pesanan diproses kirim! Resi: {$trackingNumber}"]);
        exit;
    }

    // -------------------------------------------------------------
    // 4. SIMULASI KURIR MENGANTAR (BARANG SAMPAI)
    // -------------------------------------------------------------
    if ($action === 'deliver_order') {
        $subOrderId = isset($input['sub_order_id']) ? intval($input['sub_order_id']) : 0;

        if ($subOrderId <= 0) {
            echo json_encode(["status" => false, "message" => "ID Pesanan tidak valid."]);
            exit;
        }

        $stmt = $db->prepare("
            UPDATE sub_orders 
            SET delivered_at = NOW() 
            WHERE sub_order_id = :soid AND order_status = 'DIKIRIM'
        ");
        $stmt->execute(['soid' => $subOrderId]);

        echo json_encode(["status" => true, "message" => "Paket telah tiba di alamat tujuan."]);
        exit;
    }

    // -------------------------------------------------------------
    // 5. KONFIRMASI TERIMA BARANG (SELESAI & CAIRKAN DANA KE TOKO)
    // -------------------------------------------------------------
    if ($action === 'confirm_received') {
        $subOrderId = isset($input['sub_order_id']) ? intval($input['sub_order_id']) : 0;
        $userId     = $sessionUserId;

        $stmtCheck = $db->prepare("
            SELECT 
                so.sub_order_id, 
                so.sub_order_number, 
                so.gross_product_amount, 
                so.shipping_cost,
                so.order_status, 
                s.user_id AS seller_user_id
            FROM sub_orders so
            JOIN master_orders mo ON so.master_order_id = mo.master_order_id
            JOIN shops s ON so.shop_id = s.shop_id
            WHERE so.sub_order_id = :sub_id AND mo.buyer_id = :uid
        ");
        $stmtCheck->execute([
            'sub_id'   => $subOrderId, 
            'uid'      => $userId,
            'uid_alt'  => $userId
        ]);
        $sub = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$sub) {
            echo json_encode(["status" => false, "message" => "Pesanan tidak ditemukan."]);
            exit;
        }

        if ($sub['order_status'] === 'SELESAI') {
            echo json_encode(["status" => false, "message" => "Pesanan sudah selesai."]);
            exit;
        }

        $db->beginTransaction();

        $gross = floatval($sub['gross_product_amount']);
        $commissionRate = 5.00;
        $platformFee = round($gross * ($commissionRate / 100), 2);
        $amountToAdd = $gross - $platformFee;

        $stmtUpdate = $db->prepare("
            UPDATE sub_orders 
            SET order_status = 'SELESAI', 
                delivered_at = COALESCE(delivered_at, NOW()),
                platform_commission_rate = :crate,
                platform_fee = :pfee,
                net_seller_amount = :net
            WHERE sub_order_id = :sub_id
        ");
        $stmtUpdate->execute([
            'crate'  => $commissionRate,
            'pfee'   => $platformFee,
            'net'    => $amountToAdd,
            'sub_id' => $subOrderId
        ]);

        $sellerUserId = $sub['seller_user_id'];

        $stmtWallet = $db->prepare("
            INSERT INTO wallets (user_id, available_balance, pending_balance, updated_at) 
            VALUES (:uid, :net, 0.00, NOW()) 
            ON DUPLICATE KEY UPDATE 
                available_balance = available_balance + :net,
                pending_balance = GREATEST(0.00, pending_balance - :gross),
                updated_at = NOW()
        ");
        $stmtWallet->execute([
            'uid'   => $sellerUserId, 
            'net'   => $amountToAdd,
            'gross' => $gross
        ]);

        $stmtGetWid = $db->prepare("SELECT wallet_id FROM wallets WHERE user_id = :uid LIMIT 1");
        $stmtGetWid->execute(['uid' => $sellerUserId]);
        $walletId = $stmtGetWid->fetchColumn();

        if ($walletId) {
            $desc = "Pelepasan dana pesanan " . $sub['sub_order_number'] . " (Net komisi 5%)";
            $stmtTx = $db->prepare("
                INSERT INTO wallet_transactions (wallet_id, type, amount, description, created_at)
                VALUES (:wid, 'INCOME_SALES', :amt, :dsc, NOW())
            ");
            $stmtTx->execute([
                'wid' => $walletId,
                'amt' => $amountToAdd,
                'dsc' => $desc
            ]);
        }

        $db->commit();
        echo json_encode(["status" => true, "message" => "Pesanan selesai! Dana telah diteruskan ke toko."]);
        exit;
    }

    // -------------------------------------------------------------
    // 6. AJUKAN PENGEMBALIAN BARANG (MENGGUNAKAN KOLOM evidence_image)
    // -------------------------------------------------------------
    if ($action === 'submit_return') {
        $subOrderId  = isset($input['sub_order_id']) ? intval($input['sub_order_id']) : 0;
        $userId      = $sessionUserId;
        $reason      = isset($input['reason']) ? trim($input['reason']) : 'Barang rusak / cacat fisik saat diterima';
        $complaint   = isset($input['complaint']) ? trim($input['complaint']) : '';
        $imageBase64 = isset($input['dispute_image_base64']) ? $input['dispute_image_base64'] : null;

        if ($subOrderId <= 0) {
            echo json_encode(["status" => false, "message" => "Data pengajuan tidak valid."]);
            exit;
        }

        $db->beginTransaction();

        $stmtOrder = $db->prepare("
            SELECT so.sub_order_id, so.shop_id, so.gross_product_amount, so.shipping_cost, mo.buyer_id
            FROM sub_orders so
            JOIN master_orders mo ON so.master_order_id = mo.master_order_id
            WHERE so.sub_order_id = :soid AND mo.buyer_id = :uid
            LIMIT 1
        ");
        $stmtOrder->execute(['soid' => $subOrderId, 'uid' => $userId]);
        $ord = $stmtOrder->fetch(PDO::FETCH_ASSOC);

        if (!$ord) {
            $db->rollBack();
            echo json_encode(["status" => false, "message" => "Pesanan tidak ditemukan."]);
            exit;
        }

        // Proses penyimpanan gambar base64
        $finalImageUrl = null;
        if (!empty($imageBase64) && preg_match('/^data:image\/(\w+);base64,/', $imageBase64)) {
            $imgData = substr($imageBase64, strpos($imageBase64, ',') + 1);
            $decodedImg = base64_decode($imgData);

            if ($decodedImg !== false) {
                $dir = __DIR__ . '/../../assets/images/disputes/';
                if (!file_exists($dir)) {
                    mkdir($dir, 0777, true);
                }
                $fileName = 'disp_' . $subOrderId . '_' . time() . '.webp';
                file_put_contents($dir . $fileName, $decodedImg);
                $finalImageUrl = '/on-shop/assets/images/disputes/' . $fileName;
            }
        }

        $totalAmount = floatval($ord['gross_product_amount']) + floatval($ord['shipping_cost']);
        $ticketCode  = 'TKT-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));

        $stmtUpd = $db->prepare("UPDATE sub_orders SET order_status = 'DIKOMPLAIN' WHERE sub_order_id = :soid");
        $stmtUpd->execute(['soid' => $subOrderId]);

        // Menggunakan kolom asli: evidence_image
        $stmtDisp = $db->prepare("
            INSERT INTO disputes (
                ticket_code, sub_order_id, buyer_id, shop_id, amount, reason, buyer_complaint, evidence_image, status, created_at
            ) VALUES (
                :tcode, :soid, :buid, :sid, :amt, :rsn, :cmp, :img, 'PENDING', NOW()
            )
            ON DUPLICATE KEY UPDATE 
                reason = :rsn2, 
                buyer_complaint = :cmp2, 
                evidence_image = COALESCE(:img2, evidence_image), 
                status = 'PENDING', 
                created_at = NOW()
        ");
        $stmtDisp->execute([
            'tcode' => $ticketCode,
            'soid'  => $subOrderId,
            'buid'  => $userId,
            'sid'   => $ord['shop_id'],
            'amt'   => $totalAmount,
            'rsn'   => $reason,
            'cmp'   => $complaint,
            'img'   => $finalImageUrl,
            'rsn2'  => $reason,
            'cmp2'  => $complaint,
            'img2'  => $finalImageUrl
        ]);

        $db->commit();
        echo json_encode([
            "status"      => true,
            "message"     => "Pengajuan pengembalian berhasil dikirim! Menunggu keputusan admin.",
            "ticket_code" => $ticketCode
        ]);
        exit;
    }

    // -------------------------------------------------------------
    // 7. SIMULASI SERAHKAN KE JNE & REFUND DANA
    // -------------------------------------------------------------
    if ($action === 'confirm_return_shipping') {
        $subOrderId = isset($input['sub_order_id']) ? intval($input['sub_order_id']) : 0;
        $userId     = $sessionUserId;

        if ($subOrderId <= 0) {
            echo json_encode(["status" => false, "message" => "Data tidak lengkap."]);
            exit;
        }

        $db->beginTransaction();

        $stmtD = $db->prepare("
            SELECT d.*, s.user_id AS seller_user_id, s.shop_name
            FROM disputes d
            JOIN shops s ON d.shop_id = s.shop_id
            WHERE d.sub_order_id = :soid AND d.buyer_id = :uid
            ORDER BY d.dispute_id DESC
            LIMIT 1
            FOR UPDATE
        ");
        $stmtD->execute(['soid' => $subOrderId, 'uid' => $userId]);
        $dispute = $stmtD->fetch(PDO::FETCH_ASSOC);

        if (!$dispute) {
            $db->rollBack();
            echo json_encode(["status" => false, "message" => "Data pengembalian tidak ditemukan."]);
            exit;
        }

        $refundAmount = floatval($dispute['amount']);

        $notes = "Barang diserahkan ke JNE. Refund dana berhasil diselesaikan sistem.";
        $stmtUpdD = $db->prepare("
            UPDATE disputes 
            SET status = 'REFUNDED', admin_verdict_notes = :notes, resolved_at = NOW() 
            WHERE dispute_id = :did
        ");
        $stmtUpdD->execute(['notes' => $notes, 'did' => $dispute['dispute_id']]);

        $db->prepare("UPDATE sub_orders SET order_status = 'BATAL' WHERE sub_order_id = :soid")
           ->execute(['soid' => $subOrderId]);

        $db->prepare("
            INSERT INTO wallets (user_id, available_balance, pending_balance, updated_at) 
            VALUES (:uid, :amt, 0.00, NOW()) 
            ON DUPLICATE KEY UPDATE available_balance = available_balance + :amt, updated_at = NOW()
        ")->execute(['uid' => $userId, 'amt' => $refundAmount]);

        $stmtW = $db->prepare("SELECT wallet_id FROM wallets WHERE user_id = :uid LIMIT 1");
        $stmtW->execute(['uid' => $userId]);
        $buyerWalletId = $stmtW->fetchColumn();

        if ($buyerWalletId) {
            $db->prepare("
                INSERT INTO wallet_transactions (wallet_id, type, amount, description, created_at) 
                VALUES (:wid, 'REFUND', :amt, :desc, NOW())
            ")->execute([
                'wid'  => $buyerWalletId,
                'amt'  => $refundAmount,
                'desc' => "Pengembalian dana retur " . $dispute['ticket_code'] . " via JNE"
            ]);
        }

        $stmtDeductPending = $db->prepare("
            UPDATE wallets 
            SET pending_balance = GREATEST(0.00, pending_balance - :amt), updated_at = NOW() 
            WHERE user_id = :seller_uid
        ");
        $stmtDeductPending->execute([
            'amt'        => $refundAmount,
            'seller_uid' => $dispute['seller_user_id']
        ]);

        $db->commit();
        echo json_encode([
            "status"      => true,
            "dispute_id"  => (int)$dispute['dispute_id'],
            "message"     => "Paket telah diterima JNE! Dana Rp " . number_format($refundAmount, 0, ',', '.') . " berhasil dikembalikan ke saldo dompet Anda."
        ]);
        exit;
    }

    // -------------------------------------------------------------
    // 8. CEK NOTIFIKASI KEPUTUSAN ADMIN / REFUND SELESAI
    // -------------------------------------------------------------
    if ($action === 'get_buyer_alerts') {
        $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
        
        $stmtAlert = $db->prepare("
            SELECT 
                dispute_id, 
                sub_order_id,
                status, 
                COALESCE(resolved_at, created_at) AS last_update
            FROM disputes 
            WHERE buyer_id = :uid AND status IN ('IN_REVIEW', 'REFUNDED')
            ORDER BY dispute_id DESC
        ");
        $stmtAlert->execute(['uid' => $userId]);
        $activeDisputes = $stmtAlert->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "status"      => true,
            "disputes"    => $activeDisputes,
            "alert_count" => count($activeDisputes)
        ]);
        exit;
    }

    // -------------------------------------------------------------
    // 9. BATALKAN PESANAN
    // -------------------------------------------------------------
    if ($action === 'cancel_order') {
        $subOrderId = isset($input['sub_order_id']) ? intval($input['sub_order_id']) : 0;
        $userId     = isset($input['user_id']) ? intval($input['user_id']) : 0;

        $db->beginTransaction();

        $stmtInfo = $db->prepare("
            SELECT so.order_status, so.gross_product_amount, s.user_id AS seller_user_id
            FROM sub_orders so
            JOIN shops s ON so.shop_id = s.shop_id
            WHERE so.sub_order_id = :soid
        ");
        $stmtInfo->execute(['soid' => $subOrderId]);
        $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);

        if ($info && $info['order_status'] === 'DIKEMAS') {
            $stmtDeductPending = $db->prepare("
                UPDATE wallets 
                SET pending_balance = GREATEST(0.00, pending_balance - :gross), updated_at = NOW() 
                WHERE user_id = :uid
            ");
            $stmtDeductPending->execute([
                'gross' => floatval($info['gross_product_amount']),
                'uid'   => $info['seller_user_id']
            ]);
        }

        $stmt = $db->prepare("
            UPDATE sub_orders so
            JOIN master_orders mo ON so.master_order_id = mo.master_order_id
            SET so.order_status = 'BATAL'
            WHERE so.sub_order_id = :sub_id AND (mo.buyer_id = :uid OR :uid_alt = 0)
              AND so.order_status IN ('MENUNGGU_BAYAR', 'DIKEMAS')
        ");
        $stmt->execute([
            'sub_id'  => $subOrderId, 
            'uid'     => $userId,
            'uid_alt' => $userId
        ]);

        $db->commit();
        echo json_encode(["status" => true, "message" => "Pesanan berhasil dibatalkan."]);
        exit;
    }

    // -------------------------------------------------------------
    // 10. KIRIM ULASAN
    // -------------------------------------------------------------
    if ($action === 'submit_review') {
        $subOrderId  = isset($input['sub_order_id']) ? intval($input['sub_order_id']) : 0;
        $productId   = isset($input['product_id']) ? intval($input['product_id']) : 0;
        $userId      = $sessionUserId;
        $rating      = isset($input['rating']) ? intval($input['rating']) : 5;
        $comment     = isset($input['comment']) ? trim($input['comment']) : '';
        $imageBase64 = isset($input['review_image_base64']) ? $input['review_image_base64'] : null;

        $stmtStatusCheck = $db->prepare("SELECT order_status FROM sub_orders WHERE sub_order_id = :soid LIMIT 1");
        $stmtStatusCheck->execute(['soid' => $subOrderId]);
        $currentStat = $stmtStatusCheck->fetchColumn();

        if ($currentStat !== 'SELESAI') {
            echo json_encode(["status" => false, "message" => "Ulasan hanya dapat dikirim jika pesanan telah selesai."]);
            exit;
        }

        if ($rating < 1 || $rating > 5) $rating = 5;

        $finalImageUrl = null;
        if (!empty($imageBase64) && preg_match('/^data:image\/(\w+);base64,/', $imageBase64, $type)) {
            $imgData = substr($imageBase64, strpos($imageBase64, ',') + 1);
            $decodedImg = base64_decode($imgData);

            if ($decodedImg !== false) {
                $dir = __DIR__ . '/../../assets/images/reviews/';
                if (!file_exists($dir)) {
                    mkdir($dir, 0777, true);
                }
                $fileName = 'rev_' . $subOrderId . '_' . $productId . '_' . time() . '.webp';
                file_put_contents($dir . $fileName, $decodedImg);
                $finalImageUrl = '/on-shop/assets/images/reviews/' . $fileName;
            }
        }

        $stmt = $db->prepare("
            INSERT INTO reviews (sub_order_id, product_id, user_id, rating, comment, review_image_url, created_at)
            VALUES (:sub_id, :pid, :uid, :rating, :comment, :img, NOW())
            ON DUPLICATE KEY UPDATE 
                rating = :rating, 
                comment = :comment, 
                review_image_url = COALESCE(:img, review_image_url), 
                created_at = NOW()
        ");
        $stmt->execute([
            'sub_id'  => $subOrderId,
            'pid'     => $productId,
            'uid'     => $userId,
            'rating'  => $rating,
            'comment' => $comment,
            'img'     => $finalImageUrl
        ]);

        echo json_encode(["status" => true, "message" => "Ulasan berhasil dikirim!"]);
        exit;
    }

    echo json_encode(["status" => false, "message" => "Aksi tidak dikenal."]);

} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Orders error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Terjadi kesalahan pada sistem."]);
    exit;
}