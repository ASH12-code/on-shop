<?php
// api/buyer/auth_register.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);

$name     = isset($input['name']) ? trim($input['name']) : '';
$email    = isset($input['email']) ? trim($input['email']) : '';
$phone    = isset($input['phone']) ? trim($input['phone']) : '';
$password = isset($input['password']) ? trim($input['password']) : '';

// 1. Validasi Nama & Password wajib ada
if (empty($name) || empty($password)) {
    json_response(false, "Nama lengkap dan kata sandi wajib diisi!", null, 400);
}

if (strlen($password) < 6) {
    json_response(false, "Kata sandi minimal 6 karakter!", null, 400);
}

// 2. Minimal salah satu dari Email atau Telepon wajib terisi
if (empty($email) && empty($phone)) {
    json_response(false, "Harap isi minimal salah satu: Email atau Nomor Telepon!", null, 400);
}

try {
    // 3. Cek duplikasi email jika diisi
    if (!empty($email)) {
        $stmtCheckEmail = $conn->prepare("SELECT user_id FROM users WHERE email = :email LIMIT 1");
        $stmtCheckEmail->execute(['email' => $email]);
        if ($stmtCheckEmail->fetch()) {
            json_response(false, "Email tersebut sudah terdaftar!", null, 409);
        }
    }

    // 4. Cek duplikasi nomor telepon jika diisi
    if (!empty($phone)) {
        $stmtCheckPhone = $conn->prepare("SELECT user_id FROM users WHERE phone = :phone LIMIT 1");
        $stmtCheckPhone->execute(['phone' => $phone]);
        if ($stmtCheckPhone->fetch()) {
            json_response(false, "Nomor telepon tersebut sudah terdaftar!", null, 409);
        }
    }

    // 5. Generate User Code unik (USR-001, USR-002, dst)
    $countQuery = $conn->query("SELECT COUNT(*) FROM users");
    $totalUser = (int)$countQuery->fetchColumn() + 1;
    $userCode = "USR-" . str_pad($totalUser, 3, "0", STR_PAD_LEFT);

    $saveEmail = !empty($email) ? $email : null;
    $savePhone = !empty($phone) ? $phone : null;

    // 6. Enkripsi password menggunakan BCRYPT
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

    // 7. Simpan ke database
    $insertSql = "INSERT INTO users (user_code, name, email, phone, password, role, has_shop) 
                  VALUES (:code, :name, :email, :phone, :pass, 'buyer', 0)";
    
    $stmt = $conn->prepare($insertSql);
    $stmt->execute([
        'code'  => $userCode,
        'name'  => $name,
        'email' => $saveEmail,
        'phone' => $savePhone,
        'pass'  => $hashedPassword
    ]);

    $newUserId = (int)$conn->lastInsertId();

    // 8. Set session pengguna baru
    $_SESSION['user_id']  = $newUserId;
    $_SESSION['role']     = 'buyer';
    $_SESSION['has_shop'] = 0;

    $userData = [
        "user_id"   => $newUserId,
        "user_code" => $userCode,
        "name"      => $name,
        "email"     => $saveEmail,
        "phone"     => $savePhone,
        "role"      => "buyer",
        "has_shop"  => 0
    ];

    json_response(true, "Pendaftaran berhasil!", ["user" => $userData], 201);

} catch (PDOException $e) {
    error_log("Registration error: " . $e->getMessage());
    json_response(false, "Terjadi kesalahan saat memproses pendaftaran. Silakan coba lagi.", null, 500);
}
?>