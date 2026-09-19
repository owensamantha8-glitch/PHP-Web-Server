<?php
// =========================================================================
// LYNX UTILITY MANAGEMENT - CENTRAL AUTHENTICATION & ACCESS GUARD
// Location: /var/www/Lynx/Sec/auth-guard.php
// -------------------------------------------------------------------------
// Include as the FIRST line of every protected page (before any output):
//
//     require_once("/var/www/Lynx/Sec/auth-guard.php");
//     lum_require_access('tariffs', 'view');     // optional page permission
//
// Handles: secure session start, login check, idle timeout, disabled users,
// role/property refresh, CSRF token, page permissions and property access.
// Do NOT include in login.php, change-password.php, logout.php or cron jobs.
// =========================================================================

// Library file: only other pages may include it, it can never be opened directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(403); exit('Forbidden'); }
if (defined('LUM_AUTH_GUARD')) return;
define('LUM_AUTH_GUARD', true);

// Shared settings, database connections and engines (lum_db(), lum_use(), lum_connect(), lum_page())
require_once __DIR__ . '/bootstrap.php';

// ---------------- SETTINGS ----------------
const LUM_LOGIN_URL          = LUM_APP_URL . '/Sec/login.php';
const LUM_IDLE_TIMEOUT       = 3600;  // Log out after 60 minutes without activity (0 = off)
const LUM_USER_REFRESH       = 300;   // Re-check the user in the database every 5 minutes
const LUM_ALLOW_UNLISTED     = false; // Pages/levels without a rule are refused
const LUM_AUDIT_DENIED       = true;  // Record refused page access in the audit log

// Error settings and the timezone are set by bootstrap.php

// Command-line scripts (cron) have no web session: nothing to guard
if (PHP_SAPI === 'cli') return;

// ---------------- SECURE SESSION ----------------
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    ini_set('session.use_strict_mode', 1);
    session_start();
}

// ---------------- HELPERS ----------------

// Connection to sys_db_users (shared, see bootstrap.php); null when the database is not available
function lum_guard_db() {
    return lum_db('users');
}

// Does the request expect JSON (AJAX lookups) rather than a web page?
function lum_is_ajax_request() {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $xrw = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    return (stripos($accept, 'application/json') !== false && stripos($accept, 'text/html') === false)
        || strcasecmp($xrw, 'XMLHttpRequest') === 0;
}

// End the session and send the user to the login page
function lum_logout_and_redirect($timeout = false) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    @session_destroy();

    if (lum_is_ajax_request()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Your session has ended. Please log in again.']);
        exit();
    }
    header('Location: ' . LUM_LOGIN_URL . ($timeout ? '?timeout=1' : ''));
    exit();
}

// Stop with "access denied" (web page or JSON)
function lum_deny($message = 'You do not have access to this page.') {
    http_response_code(403);
    if (lum_is_ajax_request()) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $message]);
        exit();
    }
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Access Denied</title></head>"
       . "<body style='background:#121212; color:#fff; font-family:Segoe UI, sans-serif; padding:40px;'>"
       . "<h3 style='color:#e3000f;'>Access Denied</h3><p>" . htmlspecialchars($message) . "</p>"
       . "<p><a href='https://lynx-um.co.za/index.php' style='color:#0dcaf0;'>Return to the dashboard</a></p></body></html>";
    exit();
}

// The logged-in user
function lum_user() {
    return [
        'id'         => isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0,
        'name'       => (string)($_SESSION['user_name'] ?? ''),
        'role'       => (string)($_SESSION['role'] ?? ''),
        'properties' => (string)($_SESSION['assigned_properties'] ?? ''),
    ];
}

function lum_is_admin() {
    return (($_SESSION['role'] ?? '') === 'Admin');
}

function lum_require_admin() {
    if (!lum_is_admin()) lum_deny('This page is only available to administrators.');
}

// Properties the user may see: null = all properties
function lum_allowed_properties() {
    if (empty($_SESSION['assigned_properties'])) return null;
    return array_values(array_filter(array_map('trim', explode(',', $_SESSION['assigned_properties'])), 'strlen'));
}

function lum_can_access_property($property) {
    $allowed = lum_allowed_properties();
    return ($allowed === null || in_array((string)$property, $allowed, true));
}

function lum_require_property($property) {
    if (!lum_can_access_property($property)) lum_deny('You do not have access to this property.');
}

// ---------------- CSRF ----------------
function lum_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function lum_csrf_valid($token = null) {
    $token = $token ?? ($_POST['csrf_token'] ?? '');
    return hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$token);
}

function lum_require_csrf($token = null) {
    if (!lum_csrf_valid($token)) lum_deny('Security Error: Invalid CSRF Token. Request Blocked.');
}

// ---------------- PAGE PERMISSIONS ----------------
// $level: 'view', 'edit' or 'delete'
// Order: Admin = always allowed > user override > role rule > LUM_ALLOW_UNLISTED
function lum_can($page_key, $level = 'view') {
    if (lum_is_admin()) return true;
    if (!in_array($level, ['view', 'edit', 'delete'], true)) return false;

    static $rules = null;
    if ($rules === null) {
        $rules = ['role' => [], 'user' => []];
        $pdo = lum_guard_db();
        $user = lum_user();
        if ($pdo) {
            try {
                $st = $pdo->prepare("SELECT page_key, can_view, can_edit, can_delete FROM lum_sys_role_access WHERE role = :r");
                $st->execute([':r' => $user['role']]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $rules['role'][$r['page_key']] = $r;

                $st = $pdo->prepare("SELECT page_key, can_view, can_edit, can_delete FROM lum_sys_users_access WHERE user_id = :u");
                $st->execute([':u' => $user['id']]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $rules['user'][$r['page_key']] = $r;
            } catch (\Throwable $e) {
                // Tables not created yet, or database problem: fall back to the default below
                error_log('LUM guard: could not read access rules: ' . $e->getMessage());
            }
        }
    }

    $col = 'can_' . $level;
    if (isset($rules['user'][$page_key]) && $rules['user'][$page_key][$col] !== null) {
        return (bool)$rules['user'][$page_key][$col];
    }
    if (isset($rules['role'][$page_key])) {
        return (bool)$rules['role'][$page_key][$col];
    }
    return LUM_ALLOW_UNLISTED;
}

function lum_require_access($page_key, $level = 'view') {
    if (lum_can($page_key, $level)) return;

    $audit_logger = function_exists('lum_resolve_path')
        ? lum_resolve_path('/Audit/audit-logger.php')
        : (__DIR__ . '/audit-logger.php');
    if (LUM_AUDIT_DENIED && is_readable($audit_logger)) {
        require_once($audit_logger);
        if (function_exists('lum_audit_log')) {
            lum_audit_log('DENIED', 'page_access', 0, $page_key . ' (' . $level . ')', null, null,
                ['page' => $page_key, 'level' => $level, 'url' => (string)($_SERVER['REQUEST_URI'] ?? '')]);
        }
    }
    lum_deny();
}

// =========================================================================
// RUN THE CHECKS
// =========================================================================

// 1. Logged in?
if (empty($_SESSION['user_name'])) {
    lum_logout_and_redirect(true);
}

// 2. Idle timeout
$lum_now = time();
if (LUM_IDLE_TIMEOUT > 0 && isset($_SESSION['last_activity']) && ($lum_now - (int)$_SESSION['last_activity']) > LUM_IDLE_TIMEOUT) {
    lum_logout_and_redirect(true);
}
$_SESSION['last_activity'] = $lum_now;

// 3. Is the account still active? Pick up role / property changes without a new login
if (!isset($_SESSION['user_checked_at']) || ($lum_now - (int)$_SESSION['user_checked_at']) > LUM_USER_REFRESH) {
    $lum_pdo = lum_guard_db();
    if ($lum_pdo && !empty($_SESSION['user_id'])) {
        try {
            $st = $lum_pdo->prepare("SELECT user_name, user_role, user_status, assigned_properties FROM lum_sys_users WHERE user_id = :id LIMIT 1");
            $st->execute([':id' => (int)$_SESSION['user_id']]);
            $lum_row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$lum_row || $lum_row['user_status'] === 'Disabled') {
                lum_logout_and_redirect(false);
            }
            $_SESSION['user_name'] = $lum_row['user_name'];
            $_SESSION['role'] = $lum_row['user_role'];
            $_SESSION['assigned_properties'] = $lum_row['assigned_properties'];
            $_SESSION['user_checked_at'] = $lum_now;
        } catch (\Throwable $e) {
            // Database hiccup: keep the current session and try again on the next request
            error_log('LUM guard: user refresh failed: ' . $e->getMessage());
        }
    }
    unset($lum_pdo, $lum_row, $st);
}
unset($lum_now);

// 4. Shared values most pages already use. Set as GLOBALS: this file is usually loaded
//    from inside lum_page() (bootstrap.php), where plain variables would stay inside that function.
$GLOBALS['csrf_token'] = $csrf_token = lum_csrf_token();
$GLOBALS['is_admin'] = $is_admin = lum_is_admin();