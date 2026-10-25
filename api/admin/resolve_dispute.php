<?php
require_once __DIR__ . '/../helpers/auth.php';
require_role('admin');
// api/admin/resolve_dispute.php
require_once '../../config/database.php';

// Ambil payload JSON dari JS
$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['dispute_id']) || !isset($data['verdict']) || !isset($data['admin_notes'])) {
    echo json_encode(["status" => false, "message" => "Data tidak lengkap"]);
    exit;
}

try {
    $query = "
        UPDATE disputes 
        SET 
            dispute_status = :verdict,
            admin_notes = :admin_notes,
            resolved_at = NOW()
        WHERE dispute_id = :dispute_id
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute([
        ':verdict' => $data['verdict'],
        ':admin_notes' => $data['admin_notes'],
        ':dispute_id' => $data['dispute_id']
    ]);

    echo json_encode([
        "status" => true,
        "message" => "Putusan berhasil diperbarui di database"
    ]);
} catch (PDOException $e) {
    error_log("Admin API Error in " . basename(__FILE__) . ": " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Terjadi kesalahan pada sistem."]);
    exit;
}
?>