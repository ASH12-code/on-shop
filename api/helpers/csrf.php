<?php
// api/helpers/csrf.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Menghasilkan CSRF token baru dan menyimpannya di session
 */
function generate_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Memvalidasi CSRF token dari header X-CSRF-Token atau request body
 */
function validate_csrf_token(?string $token = null): bool {
    if ($token === null) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!$token && isset($_POST['csrf_token'])) {
            $token = $_POST['csrf_token'];
        }
    }
    
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (empty($sessionToken) || empty($token)) {
        return false;
    }
    
    return hash_equals($sessionToken, $token);
}
?>
