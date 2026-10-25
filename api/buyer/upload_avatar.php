<?php
// C:/laragon/www/on-shop/api/buyer/upload_avatar.php
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/upload.php';

header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// 1. Ambil user_id dari sesi login aktif
$userId = require_auth();

if (!isset($_FILES['avatar'])) {
    json_response(false, "File foto avatar wajib dipilih.", null, 400);
}

// 2. Simpan gambar dengan helper aman
$targetDir = __DIR__ . '/../../assets/images/avatars/';
$newFileName = handle_image_upload($_FILES['avatar'], $targetDir, ['jpg', 'jpeg', 'png', 'webp'], 2097152);
$avatarUrl = "/on-shop/assets/images/avatars/" . $newFileName;

try {
    $stmt = $conn->prepare("UPDATE users SET avatar_url = :url WHERE user_id = :id");
    $stmt->execute(['url' => $avatarUrl, 'id' => $userId]);

    json_response(true, "Foto profil berhasil diperbarui!", [
        "avatar_url" => $avatarUrl
    ], 200);

} catch (PDOException $e) {
    error_log("Upload avatar error: " . $e->getMessage());
    json_response(false, "Terjadi kesalahan saat memperbarui database.", null, 500);
}
?>