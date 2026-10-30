<?php
// api/admin/get_audit_logs.php
// Endpoint Pengambilan Riwayat Audit Aktivitas Admin ON Shop

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

try {
    $actionType = isset($_GET['action_type']) ? trim($_GET['action_type']) : '';
    $search     = isset($_GET['search']) ? trim($_GET['search']) : '';
    $limit      = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 50;
    $offset     = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

    $conditions = ["1=1"];
    $params = [];

    if (!empty($actionType) && $actionType !== 'ALL') {
        $conditions[] = "l.action_type = :action_type";
        $params[':action_type'] = $actionType;
    }

    if (!empty($search)) {
        $conditions[] = "(l.description LIKE :kw OR u.name LIKE :kw OR u.email LIKE :kw OR l.ip_address LIKE :kw)";
        $params[':kw'] = "%{$search}%";
    }

    $whereSql = implode(" AND ", $conditions);

    // Hitung total log yang cocok
    $countStmt = $db->prepare("
        SELECT COUNT(*) 
        FROM admin_audit_logs l 
        LEFT JOIN users u ON l.admin_id = u.user_id 
        WHERE {$whereSql}
    ");
    $countStmt->execute($params);
    $totalCount = (int)$countStmt->fetchColumn();

    // Ambil data log audit
    $query = "
        SELECT 
            l.log_id,
            l.admin_id,
            l.action_type,
            l.target_id,
            l.description,
            l.ip_address,
            l.created_at,
            COALESCE(u.name, 'Admin Sistem') AS admin_name,
            COALESCE(u.email, 'admin@onshop.id') AS admin_email
        FROM admin_audit_logs l
        LEFT JOIN users u ON l.admin_id = u.user_id
        WHERE {$whereSql}
        ORDER BY l.log_id DESC
        LIMIT {$limit} OFFSET {$offset}
    ";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($logs as &$log) {
        $log['log_id']    = (int)$log['log_id'];
        $log['admin_id']  = (int)$log['admin_id'];
        $log['target_id'] = (int)$log['target_id'];
    }

    json_response(true, "Data log audit berhasil diambil.", [
        'total'  => $totalCount,
        'limit'  => $limit,
        'offset' => $offset,
        'logs'   => $logs
    ]);

} catch (Exception $e) {
    error_log("Get Audit Logs Error: " . $e->getMessage());
    json_response(false, "Gagal mengambil log audit: " . $e->getMessage(), null, 500);
}
