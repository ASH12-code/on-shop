<?php
// C:/laragon/www/on-shop/api/buyer/help_ticket.php
require_once __DIR__ . '/../helpers/auth.php';
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// 1. Ambil identitas terpercaya dari sesi server (Wajib Login)
$userId = require_auth();

$rawInput = file_get_contents("php://input");
$postData = json_decode($rawInput, true) ?: [];

$category = isset($postData['category']) ? trim($postData['category']) : '';
$subject = isset($postData['subject']) ? trim($postData['subject']) : '';
$message = isset($postData['message']) ? trim($postData['message']) : '';

if (empty($category) || empty($subject) || empty($message)) {
    echo json_encode(["status" => false, "message" => "Harap lengkapi semua kolom laporan masalah!"]);
    exit;
}

try {
    $conn->beginTransaction();

    // Buat kode tiket unik (Contoh: TKT-202609-XXXX)
    $ticketCode = 'TKT-' . date('Ym') . '-' . strtoupper(substr(uniqid(), -4));

    // 1. Simpan ke tabel help_tickets
    $stmtTicket = $conn->prepare("
        INSERT INTO help_tickets (ticket_code, user_id, category, subject, priority, status, created_at)
        VALUES (:code, :uid, :cat, :subj, 'MEDIUM', 'OPEN', NOW())
    ");
    $stmtTicket->execute([
        'code' => $ticketCode,
        'uid'  => $userId,
        'cat'  => $category,
        'subj' => $subject
    ]);

    $ticketId = $conn->lastInsertId();

    // 2. Simpan pesan pertama ke tabel help_ticket_messages
    $stmtMsg = $conn->prepare("
        INSERT INTO help_ticket_messages (ticket_id, sender_id, message, created_at)
        VALUES (:tid, :sid, :msg, NOW())
    ");
    $stmtMsg->execute([
        'tid' => $ticketId,
        'sid' => $userId,
        'msg' => $message
    ]);

    $conn->commit();

    echo json_encode([
        "status" => true,
        "message" => "Laporan masalah berhasil dikirim ke Admin!",
        "ticket_code" => $ticketCode
    ]);
    exit;
} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Help ticket error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Terjadi kesalahan pada sistem."]);
    exit;
}
?>