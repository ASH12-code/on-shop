<?php
// api/helpers/otp.php
// Helper untuk pembuatan kode OTP acak, masking kontak, dan pengiriman notifikasi (SMS / Gmail)

/**
 * Menghasilkan kode OTP angka acak dengan panjang tertentu (default: 6 digit)
 */
function generate_otp(int $length = 6): string
{
    $min = (int)pow(10, $length - 1);
    $max = (int)pow(10, $length) - 1;
    return (string)random_int($min, $max);
}

/**
 * Menyamarkan alamat email demi privasi (contoh: budi4@gmail.com -> b***4@gmail.com)
 */
function mask_email(?string $email): string
{
    $email = trim((string)$email);
    if (empty($email) || strpos($email, '@') === false) {
        return '***@email.com';
    }

    $parts = explode('@', $email);
    $name = $parts[0];
    $domain = $parts[1];

    $len = strlen($name);
    if ($len <= 2) {
        $maskedName = substr($name, 0, 1) . '***';
    } else {
        $first = substr($name, 0, 1);
        $last  = substr($name, -1);
        $maskedName = $first . str_repeat('*', max(3, $len - 2)) . $last;
    }

    return $maskedName . '@' . $domain;
}

/**
 * Menyamarkan nomor telepon demi privasi (contoh: 081234567894 -> 0812****7894)
 */
function mask_phone(?string $phone): string
{
    $phone = trim((string)$phone);
    $len = strlen($phone);
    if ($len <= 6) {
        return '0812****';
    }

    $prefix = substr($phone, 0, 4);
    $suffix = substr($phone, -4);
    return $prefix . '****' . $suffix;
}

/**
 * Mengirimkan notifikasi OTP ke saluran terpilih (EMAIL atau PHONE / SMS)
 * Di lingkungan lokal Laragon, pengiriman menyertakan simulasi otomatis agar pengujian berjalan mulus.
 *
 * @param string $destination Alamat email atau nomor HP tujuan
 * @param string $type Tipe saluran: 'EMAIL' atau 'PHONE'
 * @param string $otpCode Kode OTP 6 digit
 * @param string $userName Nama pengguna
 * @return array Detail status pengiriman
 */
function send_otp_notification(string $destination, string $type, string $otpCode, string $userName = 'Pengguna'): array
{
    $type = strtoupper($type);
    $now = date('Y-m-d H:i:s');

    if ($type === 'EMAIL') {
        $subject = "Kode OTP Pemulihan Kata Sandi ON Shop: " . $otpCode;
        $message = "Halo " . htmlspecialchars($userName) . ",\n\n"
                 . "Kami menerima permintaan pengaturan ulang kata sandi untuk akun ON Shop Anda.\n"
                 . "Kode OTP verifikasi Anda adalah: " . $otpCode . "\n\n"
                 . "Kode ini hanya berlaku selama 5 menit. Jangan bagikan kode ini kepada siapa pun.\n\n"
                 . "Salam,\nTim Keamanan ON Shop";

        $headers = "From: no-reply@onshop.local\r\n"
                 . "Reply-To: support@onshop.local\r\n"
                 . "X-Mailer: PHP/" . phpversion();

        // Coba kirim via mail() jika SMTP lokal terpasang
        $sent = @mail($destination, $subject, $message, $headers);

        // Catat ke server error log untuk keperluan verifikasi pengujian lokal
        error_log("[ON-SHOP OTP EMAIL] Kepada: {$destination} | Kode: {$otpCode} | Waktu: {$now}");

        return [
            'success'   => true,
            'type'      => 'EMAIL',
            'masked'    => mask_email($destination),
            'sent_mail' => $sent,
            'debug_otp' => $otpCode // Ditampilkan untuk mempermudah demonstrasi / testing lokal
        ];
    }

    // Saluran PHONE / SMS / WhatsApp
    error_log("[ON-SHOP OTP SMS] Kepada: {$destination} | Kode: {$otpCode} | Waktu: {$now}");

    return [
        'success'   => true,
        'type'      => 'PHONE',
        'masked'    => mask_phone($destination),
        'sent_sms'  => true,
        'debug_otp' => $otpCode
    ];
}
?>
