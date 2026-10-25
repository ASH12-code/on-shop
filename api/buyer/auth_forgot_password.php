<?php
// api/buyer/auth_forgot_password.php
// Endpoint alur pemulihan kata sandi mandiri (Lupa Password) dengan verifikasi OTP 2 Jalur (SMS & Gmail)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/captcha.php';
require_once __DIR__ . '/../helpers/otp.php';
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$action = isset($_GET['action']) ? trim($_GET['action']) : 'request_otp';
$input  = json_decode(file_get_contents("php://input"), true) ?: [];

try {
    // =========================================================================
    // 1. AKSI: MINTA KODE OTP (REQUEST_OTP) DENGAN PROTEKSI TURNSTILE & SKENARIO 1
    // =========================================================================
    if ($action === 'request_otp') {
        $identifier      = trim($input['identifier'] ?? '');
        $selectedChannel = strtoupper(trim($input['selected_channel'] ?? ''));
        $turnstileToken  = trim($input['turnstile_token'] ?? '');

        if (empty($identifier)) {
            json_response(false, "Harap masukkan email atau nomor telepon akun Anda!", null, 400);
        }

        // Validasi Turnstile Captcha
        if (!verify_turnstile($turnstileToken, $_SERVER['REMOTE_ADDR'] ?? null)) {
            json_response(false, "Verifikasi keamanan captcha Turnstile gagal. Silakan coba lagi.", null, 400);
        }

        // Cari data pengguna di tabel users
        $stmt = $conn->prepare("
            SELECT user_id, user_code, name, email, phone, is_banned, banned_reason 
            FROM users 
            WHERE email = :id OR phone = :id 
            LIMIT 1
        ");
        $stmt->execute(['id' => $identifier]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            // Berikan respons santun tanpa membocorkan data (anti-account enumeration)
            json_response(false, "Akun dengan email atau nomor telepon tersebut tidak terdaftar di sistem.", null, 404);
        }

        if (!empty($user['is_banned']) && (int)$user['is_banned'] === 1) {
            json_response(false, "Akun Anda saat ini ditangguhkan. Silakan hubungi admin.", null, 403);
        }

        $hasEmail = !empty($user['email']);
        $hasPhone = !empty($user['phone']);

        // Skenario 1: Jika memiliki KEDUA-DUANYA dan pengguna belum memilih saluran
        if ($hasEmail && $hasPhone && empty($selectedChannel)) {
            json_response(true, "Silakan pilih metode pengiriman kode OTP verifikasi.", [
                'need_channel_selection' => true,
                'user_id'  => (int)$user['user_id'],
                'channels' => [
                    [
                        'type'   => 'EMAIL',
                        'label'  => 'Email / Gmail',
                        'masked' => mask_email($user['email'])
                    ],
                    [
                        'type'   => 'PHONE',
                        'label'  => 'SMS / WhatsApp',
                        'masked' => mask_phone($user['phone'])
                    ]
                ]
            ], 200);
        }

        // Tentukan channel & target destinasi yang digunakan
        if (!empty($selectedChannel)) {
            if ($selectedChannel === 'EMAIL' && $hasEmail) {
                $targetDestination = $user['email'];
            } elseif ($selectedChannel === 'PHONE' && $hasPhone) {
                $targetDestination = $user['phone'];
            } else {
                json_response(false, "Metode verifikasi yang dipilih tidak tersedia pada akun ini.", null, 400);
            }
        } else {
            // Jika akun hanya memiliki salah satu kontak
            if ($hasEmail) {
                $selectedChannel   = 'EMAIL';
                $targetDestination = $user['email'];
            } else {
                $selectedChannel   = 'PHONE';
                $targetDestination = $user['phone'];
            }
        }

        // Hasilkan kode OTP 6 digit
        $otpCode = generate_otp(6);

        // Nonaktifkan kode OTP lama yang belum terpakai untuk user ini
        $stmtInvalidate = $conn->prepare("UPDATE password_resets SET is_used = 1 WHERE user_id = :uid AND is_used = 0");
        $stmtInvalidate->execute(['uid' => $user['user_id']]);

        // Simpan OTP ke tabel password_resets (Masa aktif: 5 Menit)
        $stmtInsert = $conn->prepare("
            INSERT INTO password_resets (user_id, otp_code, target_type, target_destination, expires_at, is_used) 
            VALUES (:uid, :otp, :type, :dest, DATE_ADD(NOW(), INTERVAL 5 MINUTE), 0)
        ");
        $stmtInsert->execute([
            'uid'  => $user['user_id'],
            'otp'  => $otpCode,
            'type' => $selectedChannel,
            'dest' => $targetDestination
        ]);

        // Kirim notifikasi OTP
        $dispatchResult = send_otp_notification($targetDestination, $selectedChannel, $otpCode, $user['name']);

        $maskedText = ($selectedChannel === 'EMAIL') ? mask_email($targetDestination) : mask_phone($targetDestination);
        $channelLabel = ($selectedChannel === 'EMAIL') ? 'Email/Gmail' : 'SMS/WhatsApp';

        json_response(true, "Kode OTP 6 digit telah dikirimkan ke {$channelLabel} ({$maskedText}).", [
            'need_channel_selection' => false,
            'user_id'            => (int)$user['user_id'],
            'channel'            => $selectedChannel,
            'masked_destination' => $maskedText,
            'expires_in_seconds' => 300,
            'debug_otp'          => $dispatchResult['debug_otp'] ?? null
        ], 200);
    }

    // =========================================================================
    // 2. AKSI: VALIDASI KODE OTP (VERIFY_OTP)
    // =========================================================================
    if ($action === 'verify_otp') {
        $userId  = intval($input['user_id'] ?? 0);
        $otpCode = trim($input['otp_code'] ?? '');

        if ($userId <= 0 || empty($otpCode)) {
            json_response(false, "User ID dan 6 digit kode OTP wajib diisi!", null, 400);
        }

        // Cari OTP yang cocok dan belum kedaluwarsa
        $stmtCheck = $conn->prepare("
            SELECT reset_id, user_id, expires_at 
            FROM password_resets 
            WHERE user_id = :uid 
              AND otp_code = :otp 
              AND is_used = 0 
              AND expires_at >= NOW() 
            ORDER BY reset_id DESC 
            LIMIT 1
        ");
        $stmtCheck->execute([
            'uid' => $userId,
            'otp' => $otpCode
        ]);
        $resetRecord = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$resetRecord) {
            json_response(false, "Kode OTP yang Anda masukkan salah atau telah kedaluwarsa!", null, 400);
        }

        // Generate token reset sandi acak (berlaku 15 menit)
        $resetToken = bin2hex(random_bytes(32));

        // Tandai OTP telah digunakan dan simpan reset_token
        $stmtUse = $conn->prepare("
            UPDATE password_resets 
            SET is_used = 1, reset_token = :token 
            WHERE reset_id = :rid
        ");
        $stmtUse->execute([
            'token' => $resetToken,
            'rid'   => $resetRecord['reset_id']
        ]);

        json_response(true, "Verifikasi OTP berhasil! Silakan tentukan kata sandi baru Anda.", [
            'reset_token' => $resetToken
        ], 200);
    }

    // =========================================================================
    // 3. AKSI: ATUR KATA SANDI BARU (RESET_PASSWORD)
    // =========================================================================
    if ($action === 'reset_password') {
        $resetToken      = trim($input['reset_token'] ?? '');
        $newPassword     = trim($input['new_password'] ?? '');
        $confirmPassword = trim($input['confirm_password'] ?? '');

        if (empty($resetToken)) {
            json_response(false, "Sesi token reset sandi tidak valid atau kedaluwarsa.", null, 400);
        }

        if (empty($newPassword) || strlen($newPassword) < 6) {
            json_response(false, "Kata sandi baru minimal 6 karakter!", null, 400);
        }

        if ($newPassword !== $confirmPassword) {
            json_response(false, "Konfirmasi kata sandi tidak cocok!", null, 400);
        }

        // Verifikasi token reset sandi yang sah dalam rentang 15 menit terakhir
        $stmtToken = $conn->prepare("
            SELECT reset_id, user_id 
            FROM password_resets 
            WHERE reset_token = :token 
              AND is_used = 1 
              AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) 
            LIMIT 1
        ");
        $stmtToken->execute(['token' => $resetToken]);
        $tokenRecord = $stmtToken->fetch(PDO::FETCH_ASSOC);

        if (!$tokenRecord) {
            json_response(false, "Sesi penggantian sandi telah berakhir. Silakan ulangi permintaan dari awal.", null, 400);
        }

        // Enkripsi kata sandi baru menggunakan BCRYPT standar
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);

        // Perbarui kata sandi pengguna di tabel users
        $stmtUpdatePass = $conn->prepare("UPDATE users SET password = :pass WHERE user_id = :uid");
        $stmtUpdatePass->execute([
            'pass' => $hashedPassword,
            'uid'  => $tokenRecord['user_id']
        ]);

        // Hanguskan reset_token agar tidak bisa dipakai kembali
        $stmtRevoke = $conn->prepare("UPDATE password_resets SET reset_token = NULL WHERE reset_id = :rid");
        $stmtRevoke->execute(['rid' => $tokenRecord['reset_id']]);

        json_response(true, "Kata sandi Anda berhasil diperbarui! Silakan masuk dengan kata sandi baru.", [
            'redirect' => 'login.html'
        ], 200);
    }

    // Jika aksi tidak dikenal
    json_response(false, "Aksi permintaan tidak dikenal.", null, 400);

} catch (PDOException $e) {
    error_log("Forgot Password PDO Error: " . $e->getMessage());
    json_response(false, "Terjadi gangguan saat memproses pemulihan kata sandi. Silakan coba lagi.", null, 500);
}
?>
