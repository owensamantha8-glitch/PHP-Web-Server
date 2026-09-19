<?php
// Logout: records the sign-out, ends the session and returns to the login page.
require_once '/var/www/Lynx/bootstrap.php';
lum_use('audit');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

if (!empty($_SESSION['user_id']) && function_exists('lum_audit_login_event')) {
    lum_audit_login_event('LOGOUT', $_SESSION['user_id'], $_SESSION['user_name'] ?? '', $_SESSION['role'] ?? null);
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $params['path'],
        'domain'   => $params['domain'],
        'secure'   => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?: 'Lax',
    ]);
}
session_destroy();

header('Location: https://lynx-um.co.za/Sec/login.php');
exit();
