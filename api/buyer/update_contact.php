<?php
// api/buyer/update_contact.php
require_once __DIR__ . '/../helpers/auth.php';
header('Content-Type: application/json; charset=UTF-8');

$user_id = require_auth();
$input   = json_decode(file_get_contents("php://input"), true);

$email = isset($input['email']) ? trim($input['email']) : null;
$phone = isset($input['phone']) ? trim($input['phone']) : null;

try {
    // 1. Ambil data kontak pengguna saat ini dari database
    $stmt = $conn->prepare("SELECT email, phone FROM users WHERE user_id = :uid LIMIT 1");
    $stmt->execute(['uid' => $user_id]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$current) {
        echo json_encode(["status" => false, "message" => "Pengguna tidak ditemukan."]);
        exit;
    }

    $updateFields = [];
    $params = ['uid' => $user_id];

    // 2. Validasi Email: Hanya boleh diisi jika di database sebelumnya bernilai NULL atau kosong
    if (!empty($email)) {
        if (!empty($current['email'])) {
            echo json_encode(["status" => false, "message" => "Email sudah terdaftar dan telah terkunci."]);
            exit;
        }
        // Cek duplikasi email pada akun lain
        $stmtCheck = $conn->prepare("SELECT user_id FROM users WHERE email = :email AND user_id != :uid LIMIT 1");
        $stmtCheck->execute(['email' => $email, 'uid' => $user_id]);
        if ($stmtCheck->fetch()) {
            echo json_encode(["status" => false, "message" => "Email sudah digunakan oleh akun lain!"]);
            exit;
        }
        $updateFields[] = "email = :email";
        $params['email'] = $email;
    }

    // 3. Validasi Nomor Telepon: Hanya boleh diisi jika di database sebelumnya bernilai NULL atau kosong
    if (!empty($phone)) {
        if (!empty($current['phone'])) {
            echo json_encode(["status" => false, "message" => "Nomor telepon sudah terdaftar dan telah terkunci."]);
            exit;
        }
        // Cek duplikasi nomor telepon pada akun lain
        $stmtCheck = $conn->prepare("SELECT user_id FROM users WHERE phone = :phone AND user_id != :uid LIMIT 1");
        $stmtCheck->execute(['phone' => $phone, 'uid' => $user_id]);
        if ($stmtCheck->fetch()) {
            echo json_encode(["status" => false, "message" => "Nomor telepon sudah digunakan oleh akun lain!"]);
            exit;
        }
        $updateFields[] = "phone = :phone";
        $params['phone'] = $phone;
    }

    if (empty($updateFields)) {
        echo json_encode(["status" => false, "message" => "Tidak ada data baru untuk disimpan."]);
        exit;
    }

    // 4. Perbarui baris di tabel users
    $sql = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE user_id = :uid";
    $stmtUpdate = $conn->prepare($sql);
    $stmtUpdate->execute($params);

    echo json_encode([
        "status"  => true,
        "message" => "Kontak berhasil disimpan dan kini terkunci permanen!"
    ]);

} catch (PDOException $e) {
    error_log("Update contact error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Terjadi kesalahan pada sistem."]);
    exit;
}