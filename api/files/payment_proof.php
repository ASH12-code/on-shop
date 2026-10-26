<?php
// api/files/payment_proof.php
// Endpoint penyaji privat berkas bukti transfer dengan otorisasi berbasis sesi (RBAC)

require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/response.php';

// 1. Verifikasi autentikasi sesi
$userId = require_auth();
$userRole = strtolower(trim($_SESSION['role'] ?? ''));

$db = get_db_connection();
if (!$db) {
    http_response_code(500);
    echo "Koneksi database gagal.";
    exit;
}

// 2. Ambil parameter permintaan
$fileName = isset($_GET['file']) ? basename(trim($_GET['file'])) : '';
$paymentId = isset($_GET['payment_id']) ? (int)$_GET['payment_id'] : 0;
$subOrderId = isset($_GET['sub_order_id']) ? (int)$_GET['sub_order_id'] : 0;
$masterOrderId = isset($_GET['master_order_id']) ? (int)$_GET['master_order_id'] : 0;

$payment = null;

if (!empty($fileName)) {
    $stmt = $db->prepare("
        SELECT p.payment_id, p.master_order_id, p.proof_image_url, mo.buyer_id
        FROM payments p
        JOIN master_orders mo ON p.master_order_id = mo.master_order_id
        WHERE p.proof_image_url = ?
        LIMIT 1
    ");
    $stmt->execute([$fileName]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
} elseif ($paymentId > 0) {
    $stmt = $db->prepare("
        SELECT p.payment_id, p.master_order_id, p.proof_image_url, mo.buyer_id
        FROM payments p
        JOIN master_orders mo ON p.master_order_id = mo.master_order_id
        WHERE p.payment_id = ?
        LIMIT 1
    ");
    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
} elseif ($subOrderId > 0) {
    $stmt = $db->prepare("
        SELECT p.payment_id, p.master_order_id, p.proof_image_url, mo.buyer_id
        FROM sub_orders so
        JOIN master_orders mo ON so.master_order_id = mo.master_order_id
        JOIN payments p ON mo.master_order_id = p.master_order_id
        WHERE so.sub_order_id = ?
        LIMIT 1
    ");
    $stmt->execute([$subOrderId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
} elseif ($masterOrderId > 0) {
    $stmt = $db->prepare("
        SELECT p.payment_id, p.master_order_id, p.proof_image_url, mo.buyer_id
        FROM payments p
        JOIN master_orders mo ON p.master_order_id = mo.master_order_id
        WHERE p.master_order_id = ?
        LIMIT 1
    ");
    $stmt->execute([$masterOrderId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$payment || empty($payment['proof_image_url'])) {
    http_response_code(404);
    header("Content-Type: text/plain; charset=UTF-8");
    echo "Berkas bukti transfer tidak ditemukan di sistem.";
    exit;
}

$safeFileName = basename($payment['proof_image_url']);

// 3. Pengecekan Otorisasi (RBAC)
// Syarat boleh akses:
// a) Pengguna adalah admin
// b) Pengguna adalah pembeli pemilik pesanan (buyer_id == $userId)
// c) Pengguna adalah pemilik toko (penjual) dari salah satu sub_orders pada master_order_id ini
$isAuthorized = false;

if ($userRole === 'admin') {
    $isAuthorized = true;
} elseif ((int)$payment['buyer_id'] === $userId) {
    $isAuthorized = true;
} else {
    // Cek apakah user adalah penjual dari salah satu sub_orders terkait
    $stmtSeller = $db->prepare("
        SELECT so.sub_order_id
        FROM sub_orders so
        JOIN shops s ON so.shop_id = s.shop_id
        WHERE so.master_order_id = ? AND s.user_id = ?
        LIMIT 1
    ");
    $stmtSeller->execute([$payment['master_order_id'], $userId]);
    if ($stmtSeller->fetch()) {
        $isAuthorized = true;
    }
}

if (!$isAuthorized) {
    http_response_code(403);
    header("Content-Type: text/plain; charset=UTF-8");
    echo "Akses ditolak. Anda tidak memiliki izin untuk melihat bukti transfer ini.";
    exit;
}

// 4. Verifikasi keberadaan file fisik di direktori terisolasi
$filePath = __DIR__ . '/../../uploads/payment_proofs/' . $safeFileName;
if (!file_exists($filePath) || !is_file($filePath)) {
    http_response_code(404);
    header("Content-Type: text/plain; charset=UTF-8");
    echo "File fisik bukti pembayaran tidak ditemukan di server penyimpanan.";
    exit;
}

// 5. Tentukan tipe konten gambar
$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$mimeMap = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp'
];
$mimeType = $mimeMap[$ext] ?? mime_content_type($filePath) ?: 'application/octet-stream';

// 6. Sajikan berkas secara aman
header("Content-Type: " . $mimeType);
header("Content-Length: " . filesize($filePath));
header("Content-Disposition: inline; filename=\"" . $safeFileName . "\"");
header("Cache-Control: private, max-age=86400");
header("X-Content-Type-Options: nosniff");

readfile($filePath);
exit;
