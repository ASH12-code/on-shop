<?php
// C:/laragon/www/on-shop/api/seller/manage_shop_settings.php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/seller_auth.php'; // Proteksi multi-seller dinamis

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
            $uploadDir = '../../assets/images/shops/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $parts = explode(',', $raw_logo);
            if (isset($parts[1])) {
                $imgData = base64_decode($parts[1]);
                $fileName = 'shop_' . $shop_id . '_' . time() . '.webp';
                $filePath = $uploadDir . $fileName;

                if (file_put_contents($filePath, $imgData)) {
                    $finalLogoPath = '/on-shop/assets/images/shops/' . $fileName;
                }
            }
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
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Kesalahan Database: " . $e->getMessage()]);
    exit;
}