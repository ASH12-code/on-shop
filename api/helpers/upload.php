<?php
// api/helpers/upload.php

require_once __DIR__ . '/response.php';

/**
 * Memvalidasi dan menyimpan file gambar unggahan dengan aman
 *
 * @param array $fileArray Elemen dari $_FILES (misal $_FILES['avatar'] atau $_FILES['image'])
 * @param string $destinationDir Direktori tujuan (misal '../../uploads/avatars/' atau path absolut)
 * @param array $allowedExtensions Ekstensi yang diizinkan (default: jpg, jpeg, png, webp)
 * @param int $maxSizeBytes Ukuran maksimal file dalam byte (default: 2MB = 2097152 bytes)
 * @return string Nama file baru yang tersimpan (random filename)
 */
function handle_image_upload(array $fileArray, string $destinationDir, array $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'], int $maxSizeBytes = 2097152): string {
    if (!isset($fileArray['error']) || is_array($fileArray['error'])) {
        json_response(false, "Parameter file tidak valid.", null, 400);
    }

    switch ($fileArray['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            json_response(false, "Tidak ada file yang diunggah.", null, 400);
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            json_response(false, "Ukuran file melebihi batas maksimal server.", null, 400);
            break;
        default:
            json_response(false, "Terjadi kesalahan saat mengunggah file.", null, 500);
            break;
    }

    if ($fileArray['size'] > $maxSizeBytes) {
        $maxMB = round($maxSizeBytes / 1048576, 1);
        json_response(false, "Ukuran file terlalu besar. Maksimal {$maxMB} MB.", null, 400);
    }

    // 1. Validasi ekstensi file
    $originalName = $fileArray['name'];
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions, true)) {
        json_response(false, "Format file tidak didukung. Harap unggah format: " . implode(', ', $allowedExtensions), null, 400);
    }

    // 2. Validasi MIME Type menggunakan finfo
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($fileArray['tmp_name']);
    $allowedMimes = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp'
    ];

    $expectedMime = $allowedMimes[$extension] ?? null;
    if (!$expectedMime || $mimeType !== $expectedMime) {
        // Cek fallback jika webp atau jpeg terdeteksi sedikit bervariasi
        $validMimeTypes = array_values($allowedMimes);
        if (!in_array($mimeType, $validMimeTypes, true)) {
            json_response(false, "Tipe MIME file tidak valid atau bukan gambar sah.", null, 400);
        }
    }

    // 3. Validasi struktur binary gambar menggunakan getimagesize
    $imageInfo = @getimagesize($fileArray['tmp_name']);
    if ($imageInfo === false) {
        json_response(false, "File yang diunggah bukan file gambar yang valid.", null, 400);
    }

    // 4. Pastikan direktori tujuan ada dan berizin aman
    $destinationDir = rtrim($destinationDir, '/\\') . DIRECTORY_SEPARATOR;
    if (!is_dir($destinationDir)) {
        if (!mkdir($destinationDir, 0755, true) && !is_dir($destinationDir)) {
            json_response(false, "Gagal membuat direktori penyimpanan berkas.", null, 500);
        }
    }

    // 5. Generate nama file unik dan acak untuk mencegah RCE dan overwrite
    $newFileName = bin2hex(random_bytes(16)) . '.' . $extension;
    $targetPath = $destinationDir . $newFileName;

    if (!move_uploaded_file($fileArray['tmp_name'], $targetPath)) {
        json_response(false, "Gagal menyimpan berkas ke server.", null, 500);
    }

    // Ubah permission berkas
    @chmod($targetPath, 0644);

    return $newFileName;
}
?>
