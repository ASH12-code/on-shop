<?php
// C:/laragon/www/on-shop/api/buyer/open_shop.php
require_once __DIR__ . '/../helpers/auth.php';
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// 1. Ambil identitas terpercaya dari sesi server (Wajib Login)
$userId = require_auth();

$rawInput = file_get_contents("php://input");
$input = json_decode($rawInput, true) ?: [];

$shopName           = isset($input['shop_name']) ? trim($input['shop_name']) : '';
$description        = isset($input['description']) ? trim($input['description']) : null;
$pickupAddress      = isset($input['pickup_address']) ? trim($input['pickup_address']) : '';
$pickupCity         = isset($input['pickup_city']) ? trim($input['pickup_city']) : '';
$pickupProvince     = isset($input['pickup_province']) ? trim($input['pickup_province']) : '';
$bankName           = isset($input['bank_name']) ? trim($input['bank_name']) : '';
$bankAccountNumber  = isset($input['bank_account_number']) ? trim($input['bank_account_number']) : '';

if (empty($shopName) || empty($pickupAddress) || empty($pickupCity) || empty($pickupProvince) || empty($bankName) || empty($bankAccountNumber)) {
    echo json_encode(["status" => false, "message" => "Mohon lengkapi semua bidang bertanda bintang (*)."]);
    exit;
}

try {
    // 1. Periksa apakah pengguna sudah memiliki toko terdaftar
    $checkShop = $conn->prepare("SELECT shop_id FROM shops WHERE user_id = :uid LIMIT 1");
    $checkShop->execute(['uid' => $userId]);
    $existingShop = $checkShop->fetch(PDO::FETCH_ASSOC);

    if ($existingShop) {
        $_SESSION['shop_id']  = (int)$existingShop['shop_id'];
        $_SESSION['has_shop'] = 1;
        $_SESSION['role']     = 'seller';
        
        echo json_encode([
            "status"    => true, 
            "message"   => "Anda sudah memiliki toko yang terdaftar.",
            "shop_id"   => (int)$existingShop['shop_id']
        ]);
        exit;
    }

    // 2. Periksa keunikan nama toko
    $checkName = $conn->prepare("SELECT shop_id FROM shops WHERE shop_name = :name LIMIT 1");
    $checkName->execute(['name' => $shopName]);
    if ($checkName->fetch()) {
        echo json_encode(["status" => false, "message" => "Nama toko sudah digunakan. Silakan gunakan nama lain."]);
        exit;
    }

    $conn->beginTransaction();

    // 3. Masukkan toko baru ke tabel shops
    $stmtInsert = $conn->prepare("
        INSERT INTO shops (
            user_id, shop_name, description, pickup_address, pickup_city, 
            pickup_province, bank_name, bank_account_number, status, is_official, created_at
        ) VALUES (
            :uid, :name, :descr, :p_addr, :p_city, 
            :p_prov, :b_name, :b_acc, 'verified', 0, NOW()
        )
    ");
    $stmtInsert->execute([
        'uid'     => $userId,
        'name'    => $shopName,
        'descr'   => $description,
        'p_addr'  => $pickupAddress,
        'p_city'  => $pickupCity,
        'p_prov'  => $pickupProvince,
        'b_name'  => $bankName,
        'b_acc'   => $bankAccountNumber
    ]);

    $newShopId = (int)$conn->lastInsertId();

    // 4. Ubah status user menjadi seller dan has_shop = 1
    $stmtUpdateUser = $conn->prepare("UPDATE users SET has_shop = 1, role = 'seller' WHERE user_id = :uid");
    $stmtUpdateUser->execute(['uid' => $userId]);

    // 5. Buatkan dompet (wallet) toko jika belum ada
    $checkWallet = $conn->prepare("SELECT wallet_id FROM wallets WHERE user_id = :uid LIMIT 1");
    $checkWallet->execute(['uid' => $userId]);
    if (!$checkWallet->fetch()) {
        $stmtWallet = $conn->prepare("INSERT INTO wallets (user_id, available_balance, pending_balance) VALUES (:uid, 0.00, 0.00)");
        $stmtWallet->execute(['uid' => $userId]);
    }

    $conn->commit();

    // 6. Sinkronkan ke Sesi Server PHP
    $_SESSION['user_id']   = $userId;
    $_SESSION['has_shop']  = 1;
    $_SESSION['role']      = 'seller';
    $_SESSION['shop_id']   = $newShopId;
    $_SESSION['shop_name'] = $shopName;

    echo json_encode([
        "status"    => true,
        "message"   => "Selamat! Toko Anda berhasil didaftarkan.",
        "shop_id"   => $newShopId,
        "shop_name" => $shopName
    ]);
    exit;

} catch (PDOException $e) {
    if ($conn && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Open shop error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Terjadi kesalahan saat memproses pendaftaran toko."]);
    exit;
}
?>