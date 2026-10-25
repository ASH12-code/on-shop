<?php
// C:/laragon/www/on-shop/api/buyer/address.php
require_once __DIR__ . '/../helpers/auth.php';
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// 1. Dapatkan user_id terpercaya dari sesi server
$userId = require_auth();

$action = isset($_GET['action']) ? $_GET['action'] : '';

try {
    // 1. Ambil Daftar Semua Alamat Pengguna
    if ($action === 'list') {
        $stmt = $conn->prepare("
            SELECT 
                address_id,
                user_id,
                label,
                recipient_name,
                phone,
                street_address AS full_address,
                street_address,
                city,
                province,
                postal_code,
                is_default AS is_primary
            FROM addresses 
            WHERE user_id = :uid 
            ORDER BY is_default DESC, address_id DESC
        ");
        $stmt->execute(['uid' => $userId]);
        $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(["status" => true, "data" => $addresses]);
        exit;
    }

    $input = json_decode(file_get_contents("php://input"), true) ?: [];

    // 2. Simpan atau Ubah Alamat
    if ($action === 'save') {
        $addressId = isset($input['address_id']) ? intval($input['address_id']) : 0;
        $label = trim($input['label'] ?? 'Rumah');
        $recipientName = trim($input['recipient_name'] ?? '');
        $phone = trim($input['phone'] ?? '');
        
        // Menangani kompatibilitas kunci dari JavaScript (street_address atau full_address)
        $streetAddress = trim($input['street_address'] ?? ($input['full_address'] ?? ''));
        
        $city = trim($input['city'] ?? '');
        $province = trim($input['province'] ?? 'Kepulauan Bangka Belitung');
        $postalCode = trim($input['postal_code'] ?? '');
        $isDefault = !empty($input['is_default']) || !empty($input['is_primary']) ? 1 : 0;

        if (empty($recipientName) || empty($phone) || empty($city) || empty($streetAddress)) {
            echo json_encode(["status" => false, "message" => "Harap lengkapi seluruh field alamat yang wajib!"]);
            exit;
        }

        // Jika diset sebagai default/utama, reset alamat lain milik pengguna ini
        if ($isDefault === 1) {
            $resetStmt = $conn->prepare("UPDATE addresses SET is_default = 0 WHERE user_id = :uid");
            $resetStmt->execute(['uid' => $userId]);
        }

        if ($addressId > 0) {
            // Update Alamat Terpilih (wajib milik user_id sesi)
            $sql = "UPDATE addresses 
                    SET label = :lbl, recipient_name = :rec, phone = :ph, street_address = :st, city = :ct, province = :prov, postal_code = :pc, is_default = :def 
                    WHERE address_id = :aid AND user_id = :uid";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                'lbl' => $label,
                'rec' => $recipientName,
                'ph' => $phone,
                'st' => $streetAddress,
                'ct' => $city,
                'prov' => $province,
                'pc' => $postalCode,
                'def' => $isDefault,
                'aid' => $addressId,
                'uid' => $userId
            ]);
            $msg = "Alamat berhasil diperbarui!";
        } else {
            // Tambah Alamat Baru (otomatis default jika ini alamat pertama)
            $countStmt = $conn->prepare("SELECT COUNT(*) FROM addresses WHERE user_id = :uid");
            $countStmt->execute(['uid' => $userId]);
            if ((int) $countStmt->fetchColumn() === 0) {
                $isDefault = 1;
            }

            $sql = "INSERT INTO addresses (user_id, label, recipient_name, phone, street_address, city, province, postal_code, is_default) 
                    VALUES (:uid, :lbl, :rec, :ph, :st, :ct, :prov, :pc, :def)";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                'uid' => $userId,
                'lbl' => $label,
                'rec' => $recipientName,
                'ph' => $phone,
                'st' => $streetAddress,
                'ct' => $city,
                'prov' => $province,
                'pc' => $postalCode,
                'def' => $isDefault
            ]);
            $msg = "Alamat baru berhasil ditambahkan!";
        }

        echo json_encode(["status" => true, "message" => $msg]);
        exit;
    }

    // 3. Set Alamat Menjadi Utama (is_default)
    if ($action === 'set_primary') {
        $addressId = isset($input['address_id']) ? intval($input['address_id']) : 0;

        $conn->prepare("UPDATE addresses SET is_default = 0 WHERE user_id = :uid")->execute(['uid' => $userId]);
        $stmt = $conn->prepare("UPDATE addresses SET is_default = 1 WHERE address_id = :aid AND user_id = :uid");
        $stmt->execute(['aid' => $addressId, 'uid' => $userId]);

        echo json_encode(["status" => true, "message" => "Alamat utama berhasil diubah!"]);
        exit;
    }

    // 4. Hapus Alamat (Hanya alamat non-default yang boleh dihapus)
    if ($action === 'delete') {
        $addressId = isset($input['address_id']) ? intval($input['address_id']) : 0;

        $stmt = $conn->prepare("DELETE FROM addresses WHERE address_id = :aid AND user_id = :uid AND is_default = 0");
        $stmt->execute(['aid' => $addressId, 'uid' => $userId]);

        echo json_encode(["status" => true, "message" => "Alamat berhasil dihapus."]);
        exit;
    }

    echo json_encode(["status" => false, "message" => "Aksi tidak dikenali."]);

} catch (PDOException $e) {
    error_log("Address error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Terjadi kesalahan pada sistem."]);
    exit;
}
?>