<?php
// api/buyer/upload_payment_proof.php
// Endpoint pembeli untuk mengunggah berkas bukti transfer / pembayaran (QRIS atau VA)

require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/upload.php';
require_once __DIR__ . '/../helpers/response.php';

header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, "Metode HTTP tidak didukung. Harap gunakan POST.", null, 405);
}

// 1. Verifikasi identitas sesi pembeli
$sessionUserId = require_auth();

$db = get_db_connection();
if (!$db) {
    json_response(false, "Koneksi database gagal.", null, 500);
}

// 2. Ambil parameter master_order_id atau sub_order_id
$masterOrderId = isset($_POST['master_order_id']) ? (int)$_POST['master_order_id'] : 0;
$subOrderId = isset($_POST['sub_order_id']) ? (int)$_POST['sub_order_id'] : 0;

if ($masterOrderId <= 0 && $subOrderId > 0) {
    $stmtFindMid = $db->prepare("SELECT master_order_id FROM sub_orders WHERE sub_order_id = ? LIMIT 1");
    $stmtFindMid->execute([$subOrderId]);
    $foundSub = $stmtFindMid->fetch(PDO::FETCH_ASSOC);
    if ($foundSub) {
        $masterOrderId = (int)$foundSub['master_order_id'];
    }
}

if ($masterOrderId <= 0) {
    json_response(false, "ID Pesanan tidak valid atau tidak disertakan.", null, 400);
}

// 3. Validasi kepemilikan dan status pesanan
$stmtOrder = $db->prepare("
    SELECT 
        mo.master_order_id, 
        mo.buyer_id, 
        mo.invoice_number,
        p.payment_id, 
        p.payment_status, 
        p.proof_image_url
    FROM master_orders mo
    JOIN payments p ON mo.master_order_id = p.master_order_id
    WHERE mo.master_order_id = ? AND mo.buyer_id = ?
    LIMIT 1
");
$stmtOrder->execute([$masterOrderId, $sessionUserId]);
$order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    json_response(false, "Pesanan tidak ditemukan atau bukan milik akun Anda.", null, 404);
}

if ($order['payment_status'] === 'PAID') {
    json_response(false, "Pembayaran untuk pesanan ini sudah diverifikasi lunas.", null, 400);
}

// 4. Periksa ketersediaan berkas unggahan
$fileKey = null;
if (isset($_FILES['proof_image']) && !empty($_FILES['proof_image']['name'])) {
    $fileKey = 'proof_image';
} elseif (isset($_FILES['image']) && !empty($_FILES['image']['name'])) {
    $fileKey = 'image';
} elseif (isset($_FILES['file']) && !empty($_FILES['file']['name'])) {
    $fileKey = 'file';
}

if (!$fileKey) {
    json_response(false, "Harap pilih berkas foto/gambar bukti transfer Anda.", null, 400);
}

$uploadDir = __DIR__ . '/../../uploads/payment_proofs/';

try {
    // 5. Validasi dan simpan file bukti pembayaran menggunakan helper aman
    // Ekstensi: jpg, jpeg, png, webp | Maksimal 2MB | Validasi binary via getimagesize
    $savedFileName = handle_image_upload($_FILES[$fileKey], $uploadDir, ['jpg', 'jpeg', 'png', 'webp'], 2097152);

    // Hapus file bukti lama jika ada penggantian (agar tidak menumpuk)
    if (!empty($order['proof_image_url'])) {
        $oldFilePath = $uploadDir . basename($order['proof_image_url']);
        if (file_exists($oldFilePath) && is_file($oldFilePath)) {
            @unlink($oldFilePath);
        }
    }

    $db->beginTransaction();

    // 6. Update status pembayaran menjadi MENUNGGU_VERIFIKASI dan reset catatan penolakan
    $stmtUpdatePayment = $db->prepare("
        UPDATE payments 
        SET proof_image_url = :proof, 
            payment_status = 'MENUNGGU_VERIFIKASI', 
            rejection_reason = NULL 
        WHERE payment_id = :pid
    ");
    $stmtUpdatePayment->execute([
        'proof' => $savedFileName,
        'pid'   => $order['payment_id']
    ]);

    // 7. Update status sub_orders menjadi MENUNGGU_VERIFIKASI
    $stmtUpdateSub = $db->prepare("
        UPDATE sub_orders 
        SET order_status = 'MENUNGGU_VERIFIKASI' 
        WHERE master_order_id = :mid AND order_status IN ('MENUNGGU_BAYAR', 'MENUNGGU_VERIFIKASI')
    ");
    $stmtUpdateSub->execute(['mid' => $masterOrderId]);

    // 8. Catat jejak audit verifikasi
    $stmtLog = $db->prepare("
        INSERT INTO payment_verification_logs (payment_id, master_order_id, action, actor_user_id, actor_role, notes)
        VALUES (:pid, :mid, 'SUBMIT_PROOF', :uid, 'buyer', :notes)
    ");
    $stmtLog->execute([
        'pid'   => $order['payment_id'],
        'mid'   => $masterOrderId,
        'uid'   => $sessionUserId,
        'notes' => 'Pembeli mengunggah bukti pembayaran transfer: ' . $savedFileName
    ]);

    $db->commit();

    json_response(true, "Bukti pembayaran berhasil dikirim! Menunggu verifikasi dari pihak penjual.", [
        "master_order_id" => $masterOrderId,
        "payment_status"  => "MENUNGGU_VERIFIKASI",
        "proof_image_url" => $savedFileName,
        "view_url"        => "/on-shop/api/files/payment_proof.php?file=" . urlencode($savedFileName)
    ]);

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Upload payment proof error: " . $e->getMessage());
    json_response(false, "Terjadi kesalahan sistem saat memproses bukti pembayaran.", null, 500);
}
?>
