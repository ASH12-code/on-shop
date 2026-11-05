<?php
// api/buyer/logout.php
// Menghancurkan sesi PHP aktif saat pengguna melakukan logout

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

json_response(true, "Berhasil keluar dari sesi akun.", null, 200);
?>
