<?php
// C:/laragon/www/on-shop/api/seller/manage_shop_settings.php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/seller_auth.php'; // Proteksi multi-seller dinamis
require_once __DIR__ . '/../helpers/upload.php';

$action = $_GET['action'] ?? 'get_settings';

try {
    if ($action === 'get_settings') {
        $stmt = $db->prepare("
            SELECT shop_id, shop_name, description, logo_url, pickup_address, pickup_city, pickup_province
            FROM shops 
            WHERE shop_id = ?
        ");
        $stmt->execute([$shop_id]);
        $shop = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$shop) {
            echo json_encode(["status" => false, "message" => "Data toko tidak ditemukan."]);
            exit;
        }

        echo json_encode(["status" => true, "data" => $shop]);
        exit;
    }

    if ($action === 'update_settings') {
        $input = json_decode(file_get_contents('php://input'), true);

        $shop_name       = trim($input['shop_name'] ?? '');
        $description     = trim($input['description'] ?? '');
        $pickup_city     = trim($input['pickup_city'] ?? '');
        $pickup_province = trim($input['pickup_province'] ?? '');
        $pickup_address  = trim($input['pickup_address'] ?? '');
        $raw_logo        = trim($input['logo_url'] ?? '');

        if (empty($shop_name)) {
            echo json_encode(["status" => false, "message" => "Nama toko tidak boleh kosong."]);
            exit;
        }

        if (empty($pickup_province)) {
            $pickup_province = 'Kepulauan Bangka Belitung';
        }

        // Ambil logo sebelumnya
        $currStmt = $db->prepare("SELECT logo_url FROM shops WHERE shop_id = ?");
        $currStmt->execute([$shop_id]);
        $finalLogoPath = $currStmt->fetchColumn();

        if (!empty($raw_logo) && strpos($raw_logo, 'data:image') === 0) {
            $uploadDir = __DIR__ . '/../../assets/images/shops/';
            $savedLogo = validate_and_save_base64_image($raw_logo, $uploadDir, 'shop_' . $shop_id . '_');
            if (!$savedLogo) {
                echo json_encode(["status" => false, "message" => "File gambar logo toko tidak valid atau rusak."]);
                exit;
            }
            $finalLogoPath = '/on-shop/assets/images/shops/' . $savedLogo;
        }

        $stmt = $db->prepare("
            UPDATE shops 
            SET shop_name = :shop_name, 
                description = :description, 
                pickup_city = :pickup_city, 
                pickup_province = :pickup_province,
                pickup_address = :pickup_address, 
                logo_url = :logo_url
            WHERE shop_id = :shop_id
        ");

        $stmt->execute([
            ':shop_name'       => $shop_name,
            ':description'     => $description,
            ':pickup_city'     => $pickup_city,
            ':pickup_province' => $pickup_province,
            ':pickup_address'  => $pickup_address,
            ':logo_url'        => $finalLogoPath,
            ':shop_id'         => $shop_id
        ]);

        echo json_encode([
            "status"   => true,
            "message"  => "Profil toko berhasil diperbarui!",
            "logo_url" => $finalLogoPath
        ]);
        exit;
    }
} catch (Exception $e) {
    error_log("Seller manage_shop_settings error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Terjadi kesalahan sistem saat memperbarui pengaturan toko."]);
    exit;
}