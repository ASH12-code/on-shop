<?php
// api/admin/manage_platform_settings.php
// Endpoint Pengelolaan Konfigurasi & Pengaturan Global Platform ON Shop

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// 1. Wajib memiliki hak akses Super Admin
$adminId = require_role('admin');
$db = $conn ?? $pdo;

if (!$db) {
    json_response(false, "Koneksi database tidak tersedia.", null, 500);
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    // =========================================================================
    // 1. AMBIL SEMUA PENGATURAN PLATFORM (GET)
    // =========================================================================
    if ($method === 'GET') {
        $stmt = $db->query("SELECT setting_key, setting_value, description, updated_at FROM platform_settings ORDER BY setting_key ASC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Jika tabel baru memiliki 1 baris, inisialisasi pengaturan standar tambahan
        $existingKeys = array_column($rows, 'setting_key');
        $defaultSettings = [
            'seller_commission_percentage' => ['5.00', 'Potongan komisi platform dari penjualan produk (persen)'],
            'handling_fee_fixed'           => ['1000', 'Biaya penanganan transaksi aplikasi (Rupiah)'],
            'platform_support_email'       => ['support@onshop.id', 'Email layanan bantuan dan mediasi pelanggan'],
            'platform_support_phone'       => ['0812-3456-7890', 'Nomor kontak WhatsApp pusat bantuan resmi'],
            'marketplace_status'           => ['ACTIVE', 'Status operasional sistem (ACTIVE / MAINTENANCE)']
        ];

        $needsRefresh = false;
        foreach ($defaultSettings as $key => [$val, $desc]) {
            if (!in_array($key, $existingKeys, true)) {
                $ins = $db->prepare("INSERT INTO platform_settings (setting_key, setting_value, description) VALUES (:k, :v, :d)");
                $ins->execute([':k' => $key, ':v' => $val, ':d' => $desc]);
                $needsRefresh = true;
            }
        }

        if ($needsRefresh) {
            $stmt = $db->query("SELECT setting_key, setting_value, description, updated_at FROM platform_settings ORDER BY setting_key ASC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $map = [];
        foreach ($rows as $r) {
            $map[$r['setting_key']] = $r['setting_value'];
        }

        json_response(true, "Pengaturan platform berhasil diambil.", [
            'settings' => $rows,
            'map'      => $map
        ]);
    }

    // =========================================================================
    // 2. SIMPAN / PERBARUI PENGATURAN PLATFORM (POST)
    // =========================================================================
    if ($method === 'POST') {
        $input = json_decode(file_get_contents("php://input"), true) ?: [];

        if (empty($input)) {
            json_response(false, "Tidak ada data pengaturan yang dikirimkan.", null, 400);
        }

        // Tangkap data berpasangan key => value
        $settingsToUpdate = [];
        if (isset($input['settings']) && is_array($input['settings'])) {
            $settingsToUpdate = $input['settings'];
        } else {
            $settingsToUpdate = $input;
        }

        $updatedKeys = [];
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $db->beginTransaction();

        $updateStmt = $db->prepare("
            INSERT INTO platform_settings (setting_key, setting_value, description) 
            VALUES (:k, :v, '')
            ON DUPLICATE KEY UPDATE 
                setting_value = VALUES(setting_value),
                updated_at = CURRENT_TIMESTAMP
        ");

        foreach ($settingsToUpdate as $key => $val) {
            $cleanKey = trim((string)$key);
            $cleanVal = trim((string)$val);

            // Validasi format angka komisi (0 - 50%)
            if ($cleanKey === 'seller_commission_percentage') {
                $num = floatval($cleanVal);
                if ($num < 0 || $num > 50) {
                    $db->rollBack();
                    json_response(false, "Komisi platform harus berada dalam rentang 0% hingga 50%.", null, 400);
                }
            }

            if (!empty($cleanKey)) {
                $updateStmt->execute([':k' => $cleanKey, ':v' => $cleanVal]);
                $updatedKeys[] = "$cleanKey = $cleanVal";
            }
        }

        // Catat ke admin_audit_logs
        $logDesc = "Admin ID #$adminId memperbarui konfigurasi platform: " . implode(", ", $updatedKeys);
        $logStmt = $db->prepare("
            INSERT INTO admin_audit_logs (admin_id, action_type, target_id, description, ip_address) 
            VALUES (:aid, 'UPDATE_PLATFORM_SETTING', 0, :desc, :ip)
        ");
        $logStmt->execute([
            ':aid'  => $adminId,
            ':desc' => $logDesc,
            ':ip'   => $ip
        ]);

        $db->commit();

        json_response(true, "Pengaturan platform berhasil diperbarui.", [
            'updated_count' => count($updatedKeys)
        ]);
    }

    json_response(false, "Metode HTTP tidak didukung.", null, 405);

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Manage Platform Settings Error: " . $e->getMessage());
    json_response(false, "Gagal mengelola pengaturan: " . $e->getMessage(), null, 500);
}
