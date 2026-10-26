<?php
// C:/laragon/www/on-shop/api/seller/manage_orders.php
require_once __DIR__ . '/seller_auth.php';
header('Content-Type: application/json; charset=UTF-8');

$action = $_GET['action'] ?? 'get_orders';

try {
    // -------------------------------------------------------------
    // A. MENGAMBIL DAFTAR PESANAN LENGKAP DENGAN ITEM & BUKTI BAYAR
    // -------------------------------------------------------------
    if ($action === 'get_orders') {
        $query = "
            SELECT 
                so.sub_order_id,
                so.master_order_id,
                so.sub_order_number,
                so.courier_name,
                so.courier_service,
                so.tracking_number,
                so.shipping_cost,
                so.order_status,
                so.gross_product_amount,
                so.net_seller_amount,
                DATE_FORMAT(so.created_at, '%d %b %Y, %H:%i WIB') AS created_at_formatted,
                mo.invoice_number,
                mo.shipping_recipient_name,
                mo.shipping_phone,
                CONCAT(mo.shipping_street_address, ', ', mo.shipping_city, ', ', mo.shipping_province, ' ', mo.shipping_postal_code) AS full_shipping_address,
                COALESCE(u.name, mo.shipping_recipient_name) AS buyer_name,
                p.payment_id,
                p.payment_method,
                p.payment_status,
                p.proof_image_url,
                p.rejection_reason,
                p.verified_at,
                p.verified_by
            FROM sub_orders so
            JOIN master_orders mo ON so.master_order_id = mo.master_order_id
            JOIN users u ON mo.buyer_id = u.user_id
            LEFT JOIN payments p ON mo.master_order_id = p.master_order_id
            WHERE so.shop_id = ?
            ORDER BY so.sub_order_id DESC
        ";
        $stmt = $db->prepare($query);
        $stmt->execute([$shop_id]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Ambil rincian produk untuk masing-masing pesanan & bentuk URL bukti bayar
        foreach ($orders as &$order) {
            $itemStmt = $db->prepare("
                SELECT 
                    oi.order_item_id,
                    oi.product_name,
                    oi.variant_name,
                    oi.product_image_url,
                    oi.price_at_purchase,
                    oi.quantity,
                    oi.total_price,
                    COALESCE(pv.sku, 'RAK-01') AS sku
                FROM order_items oi
                LEFT JOIN product_variants pv ON oi.variant_id = pv.variant_id
                WHERE oi.sub_order_id = ?
            ");
            $itemStmt->execute([$order['sub_order_id']]);
            $order['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

            // Tambahkan URL akses bukti privat jika file ada
            if (!empty($order['proof_image_url'])) {
                $order['proof_view_url'] = '/on-shop/api/files/payment_proof.php?file=' . urlencode(basename($order['proof_image_url']));
            } else {
                $order['proof_view_url'] = null;
            }
        }

        echo json_encode(["status" => true, "data" => $orders]);
        exit;
    }

    // -------------------------------------------------------------
    // B. SETUJUI PEMBAYARAN & VERIFIKASI BUKTI TRANSFER (SELLER)
    // -------------------------------------------------------------
    if ($action === 'approve_payment') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $sub_order_id = (int)($input['sub_order_id'] ?? 0);

        if ($sub_order_id <= 0) {
            echo json_encode(["status" => false, "message" => "ID Pesanan tidak valid."]);
            exit;
        }

        $stmtCheck = $db->prepare("
            SELECT 
                so.sub_order_id, 
                so.master_order_id, 
                so.gross_product_amount, 
                so.order_status, 
                p.payment_id, 
                p.payment_status,
                p.proof_image_url
            FROM sub_orders so
            JOIN master_orders mo ON so.master_order_id = mo.master_order_id
            JOIN payments p ON mo.master_order_id = p.master_order_id
            WHERE so.sub_order_id = ? AND so.shop_id = ?
            LIMIT 1
        ");
        $stmtCheck->execute([$sub_order_id, $shop_id]);
        $sub = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$sub) {
            echo json_encode(["status" => false, "message" => "Pesanan tidak ditemukan pada toko Anda."]);
            exit;
        }

        $db->beginTransaction();

        // Update status payments jadi PAID
        $stmtPay = $db->prepare("
            UPDATE payments 
            SET payment_status = 'PAID', 
                paid_at = NOW(), 
                verified_at = NOW(), 
                verified_by = ? 
            WHERE payment_id = ?
        ");
        $stmtPay->execute([$user_id, $sub['payment_id']]);

        // Update status sub_order jadi DIKEMAS
        $stmtSub = $db->prepare("
            UPDATE sub_orders 
            SET order_status = 'DIKEMAS' 
            WHERE sub_order_id = ? AND shop_id = ?
        ");
        $stmtSub->execute([$sub_order_id, $shop_id]);

        // Tambah pending_balance ke dompet penjual
        $stmtWalletPending = $db->prepare("
            INSERT INTO wallets (user_id, available_balance, pending_balance, updated_at)
            VALUES (?, 0.00, ?, NOW())
            ON DUPLICATE KEY UPDATE pending_balance = pending_balance + ?, updated_at = NOW()
        ");
        $gross = floatval($sub['gross_product_amount']);
        $stmtWalletPending->execute([$user_id, $gross, $gross]);

        // Catat ke audit log
        $stmtLog = $db->prepare("
            INSERT INTO payment_verification_logs (payment_id, master_order_id, sub_order_id, action, actor_user_id, actor_role, notes)
            VALUES (?, ?, ?, 'APPROVE', ?, 'seller', 'Penjual memverifikasi dan menyetujui bukti pembayaran transfer.')
        ");
        $stmtLog->execute([
            $sub['payment_id'],
            $sub['master_order_id'],
            $sub_order_id,
            $user_id
        ]);

        $db->commit();

        echo json_encode([
            "status" => true,
            "message" => "Pembayaran berhasil disetujui! Pesanan berpindah ke status Perlu Diproses."
        ]);
        exit;
    }

    // -------------------------------------------------------------
    // C. TOLAK PEMBAYARAN BUKTI TRANSFER (SELLER)
    // -------------------------------------------------------------
    if ($action === 'reject_payment') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $sub_order_id = (int)($input['sub_order_id'] ?? 0);
        $rejection_reason = trim($input['rejection_reason'] ?? '');

        if ($sub_order_id <= 0) {
            echo json_encode(["status" => false, "message" => "ID Pesanan tidak valid."]);
            exit;
        }

        if (empty($rejection_reason)) {
            echo json_encode(["status" => false, "message" => "Alasan penolakan bukti pembayaran wajib diisi."]);
            exit;
        }

        $stmtCheck = $db->prepare("
            SELECT 
                so.sub_order_id, 
                so.master_order_id, 
                so.order_status, 
                p.payment_id, 
                p.payment_status
            FROM sub_orders so
            JOIN master_orders mo ON so.master_order_id = mo.master_order_id
            JOIN payments p ON mo.master_order_id = p.master_order_id
            WHERE so.sub_order_id = ? AND so.shop_id = ?
            LIMIT 1
        ");
        $stmtCheck->execute([$sub_order_id, $shop_id]);
        $sub = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$sub) {
            echo json_encode(["status" => false, "message" => "Pesanan tidak ditemukan pada toko Anda."]);
            exit;
        }

        $db->beginTransaction();

        // Update status payments jadi REJECTED beserta alasannya
        $stmtPay = $db->prepare("
            UPDATE payments 
            SET payment_status = 'REJECTED', 
                rejection_reason = ?, 
                verified_at = NOW(), 
                verified_by = ? 
            WHERE payment_id = ?
        ");
        $stmtPay->execute([$rejection_reason, $user_id, $sub['payment_id']]);

        // Kembalikan status sub_order jadi MENUNGGU_BAYAR agar pembeli dapat mengunggah ulang
        $stmtSub = $db->prepare("
            UPDATE sub_orders 
            SET order_status = 'MENUNGGU_BAYAR' 
            WHERE sub_order_id = ? AND shop_id = ?
        ");
        $stmtSub->execute([$sub_order_id, $shop_id]);

        // Catat ke audit log
        $stmtLog = $db->prepare("
            INSERT INTO payment_verification_logs (payment_id, master_order_id, sub_order_id, action, actor_user_id, actor_role, notes)
            VALUES (?, ?, ?, 'REJECT', ?, 'seller', ?)
        ");
        $logNotes = "Penjual menolak bukti pembayaran. Alasan: " . $rejection_reason;
        $stmtLog->execute([
            $sub['payment_id'],
            $sub['master_order_id'],
            $sub_order_id,
            $user_id,
            $logNotes
        ]);

        $db->commit();

        echo json_encode([
            "status" => true,
            "message" => "Bukti pembayaran ditolak. Pembeli dapat mengunggah bukti pembayaran baru."
        ]);
        exit;
    }

    // -------------------------------------------------------------
    // D. SERAHKAN KE KURIR (UPDATE STATUS JADI DIKIRIM & SET RESI)
    // -------------------------------------------------------------
    if ($action === 'handover_courier') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $sub_order_id = (int)($input['sub_order_id'] ?? 0);
        $tracking_number = trim($input['tracking_number'] ?? '');

        if ($sub_order_id <= 0) {
            echo json_encode(["status" => false, "message" => "ID Pesanan tidak valid."]);
            exit;
        }

        if (empty($tracking_number)) {
            $tracking_number = 'ON-TRK-' . strtoupper(substr(md5(uniqid()), 0, 10));
        }

        $stmt = $db->prepare("
            UPDATE sub_orders 
            SET order_status = 'DIKIRIM', 
                tracking_number = ?, 
                shipped_at = NOW() 
            WHERE sub_order_id = ? AND shop_id = ?
        ");
        $stmt->execute([$tracking_number, $sub_order_id, $shop_id]);

        echo json_encode([
            "status" => true, 
            "message" => "Pesanan berhasil diserahkan ke kurir!",
            "tracking_number" => $tracking_number
        ]);
        exit;
    }

    // -------------------------------------------------------------
    // E. BATALKAN PESANAN TOKO
    // -------------------------------------------------------------
    if ($action === 'cancel_order') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $sub_order_id = (int)($input['sub_order_id'] ?? 0);

        if ($sub_order_id <= 0) {
            echo json_encode(["status" => false, "message" => "ID Pesanan tidak valid."]);
            exit;
        }

        $stmt = $db->prepare("UPDATE sub_orders SET order_status = 'BATAL' WHERE sub_order_id = ? AND shop_id = ?");
        $stmt->execute([$sub_order_id, $shop_id]);

        echo json_encode(["status" => true, "message" => "Pesanan berhasil dibatalkan."]);
        exit;
    }

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Seller manage_orders error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Terjadi kesalahan pada sistem."]);
    exit;
}
?>