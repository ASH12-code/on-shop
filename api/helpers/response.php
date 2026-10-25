<?php
// api/helpers/response.php

/**
 * Mengirimkan respon JSON terstandarisasi dan menghentikan eksekusi script.
 *
 * @param bool $status Status keberhasilan operasi (true/false)
 * @param string $message Pesan deskriptif
 * @param mixed $data Data muatan (opsional)
 * @param int $httpCode Kode status HTTP (default: 200, atau 400 jika status false)
 */
function json_response(bool $status, string $message, $data = null, int $httpCode = 200) {
    if (!$status && $httpCode === 200) {
        $httpCode = 400;
    }
    
    http_response_code($httpCode);
    header("Content-Type: application/json; charset=UTF-8");
    
    $payload = [
        "status"  => $status,
        "message" => $message
    ];
    
    if ($data !== null) {
        $payload["data"] = $data;
    }
    
    echo json_encode($payload);
    exit;
}

/**
 * Respon JSON sukses standar
 */
function response_success(string $message = "Sukses", $data = null, int $httpCode = 200) {
    json_response(true, $message, $data, $httpCode);
}

/**
 * Respon JSON gagal standar
 */
function response_error(string $message = "Terjadi kesalahan", $data = null, int $httpCode = 400) {
    json_response(false, $message, $data, $httpCode);
}
?>
