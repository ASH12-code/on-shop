<?php
// api/auth/logout.php
// Endpoint universal logout untuk menghancurkan sesi PHP aktif (Buyer / Seller / Admin)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../helpers/response.php';

$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

session_destroy();

json_response(true, "Sesi berhasil diakhiri.", null, 200);
?>
