<?php
// C:/laragon/www/on-shop/api/buyer/profile.php
require_once __DIR__ . '/../helpers/auth.php';
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// 1. Ambil identitas terpercaya dari sesi server (Wajib Login)
$userId = require_auth();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    try {
        $stmt = $conn->prepare("SELECT user_id, user_code, name, email, phone, role, has_shop, avatar_url, created_at FROM users WHERE user_id = :id");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            echo json_encode(["status" => false, "message" => "Pengguna tidak ditemukan."]);
            exit;
        }

        echo json_encode(["status" => true, "data" => $user]);
        exit;
    } catch (PDOException $e) {
        error_log("Get profile error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["status" => false, "message" => "Terjadi kesalahan pada sistem."]);
        exit;
    }
} elseif ($method === 'POST') {
    $input = json_decode(file_get_contents("php://input"), true);
    $name = isset($input['name']) ? trim($input['name']) : '';
    $email = isset($input['email']) ? trim($input['email']) : null;
    $phone = isset($input['phone']) ? trim($input['phone']) : null;

    if (empty($name)) {
        echo json_encode(["status" => false, "message" => "Nama lengkap wajib diisi."]);
        exit;
    }

    $emailVal = !empty($email) ? $email : null;
    $phoneVal = !empty($phone) ? $phone : null;

    try {
        // Cek duplikasi email pada user lain
        if ($emailVal) {
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = :email AND user_id != :id LIMIT 1");
            $stmt->execute(['email' => $emailVal, 'id' => $userId]);
            if ($stmt->fetch()) {
                echo json_encode(["status" => false, "message" => "Email sudah digunakan oleh akun lain!"]);
                exit;
            }
        }

        // Cek duplikasi no hp pada user lain
        if ($phoneVal) {
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE phone = :phone AND user_id != :id LIMIT 1");
            $stmt->execute(['phone' => $phoneVal, 'id' => $userId]);
            if ($stmt->fetch()) {
                echo json_encode(["status" => false, "message" => "Nomor telepon sudah digunakan oleh akun lain!"]);
                exit;
            }
        }

        $sql = "UPDATE users SET name = :name, email = :email, phone = :phone WHERE user_id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            'name' => $name,
            'email' => $emailVal,
            'phone' => $phoneVal,
            'id' => $userId
        ]);

        echo json_encode(["status" => true, "message" => "Biodata berhasil diperbarui!"]);
        exit;
    } catch (PDOException $e) {
        error_log("Update profile error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["status" => false, "message" => "Terjadi kesalahan pada sistem."]);
        exit;
    }
}
?>