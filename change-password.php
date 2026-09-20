<?php
// =========================================================================
// LYNX UTILITY MANAGEMENT - CHANGE PASSWORD (first login)
// Location: /var/www/Lynx/Sec/change-password.php
// -------------------------------------------------------------------------
// login.php sends users whose account is marked "require_password_change" here,
// after they entered their correct password. They choose a new password, and
// the login is then completed. No auth-guard.php: the user is not fully logged in yet.
// =========================================================================

// Shared settings and database connections (errors are logged, never displayed)
require_once __DIR__ . '/bootstrap.php';

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

const LUM_PW_CHANGE_WINDOW = 900; // The new password must be chosen within 15 minutes of logging in
const LUM_PW_MIN_LENGTH = 8;      // Same minimum as User Management
if (!defined('LUM_LOGIN_PAGE')) define('LUM_LOGIN_PAGE', lum_app_url(LUM_LOGIN_PATH));

// Forget the half-finished login and go back to the login page
function lum_pw_restart($timeout = false) {
    unset($_SESSION['temp_user_id'], $_SESSION['temp_user_name'], $_SESSION['temp_user_role'],
          $_SESSION['temp_assigned_properties'], $_SESSION['temp_started_at'], $_SESSION['pw_csrf']);
    header('Location: ' . LUM_LOGIN_PAGE . ($timeout ? '?timeout=1' : ''));
    exit();
}

$uid = (int)($_SESSION['temp_user_id'] ?? 0);
if ($uid <= 0) {
    lum_pw_restart(); // Not coming from the login page
}
$started = (int)($_SESSION['temp_started_at'] ?? 0);
if ($started === 0) {
    $_SESSION['temp_started_at'] = $started = time();
} elseif (time() - $started > LUM_PW_CHANGE_WINDOW) {
    lum_pw_restart(true);
}
if (empty($_SESSION['pw_csrf'])) {
    $_SESSION['pw_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Passwords are trimmed exactly as login.php trims them
    $new = trim((string)($_POST['new_password'] ?? ''));
    $confirm = trim((string)($_POST['confirm_password'] ?? ''));
    $len = function_exists('mb_strlen') ? mb_strlen($new, 'UTF-8') : strlen($new);

    if (!hash_equals((string)$_SESSION['pw_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session has expired. Please try again.';
    } elseif ($len < LUM_PW_MIN_LENGTH) {
        $error = 'The new password must be at least ' . LUM_PW_MIN_LENGTH . ' characters long.';
    } elseif ($new !== $confirm) {
        $error = 'The two passwords do not match.';
    } else {
        $db = lum_db('users');
        if (!$db) {
            $error = 'The password could not be changed right now. Please try again later.';
        } else {
            try {
                $st = $db->prepare("SELECT user_id, user_name, user_password, user_role, user_status, assigned_properties
                                    FROM lum_sys_users WHERE user_id = ? LIMIT 1");
                $st->execute([$uid]);
                $row = $st->fetch(PDO::FETCH_ASSOC);

                if (!$row || $row['user_status'] === 'Disabled') {
                    lum_pw_restart();
                } elseif (password_verify($new, (string)$row['user_password'])) {
                    $error = 'Please choose a password that is different from your current one.';
                } else {
                    $up = $db->prepare("UPDATE lum_sys_users SET user_password = ?, require_password_change = 0, last_login = NOW() WHERE user_id = ?");
                    $up->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);

                    // Complete the login (new session id, as on the login page)
                    session_regenerate_id(true);
                    unset($_SESSION['temp_user_id'], $_SESSION['temp_user_name'], $_SESSION['temp_user_role'],
                          $_SESSION['temp_assigned_properties'], $_SESSION['temp_started_at'], $_SESSION['pw_csrf']);
                    $_SESSION['user_id'] = $row['user_id'];
                    $_SESSION['user_name'] = $row['user_name'];
                    $_SESSION['role'] = $row['user_role'];
                    $_SESSION['assigned_properties'] = $row['assigned_properties'];

                    // Audit trail (the password itself is never logged)
                    lum_use('audit');
                    lum_audit_log('UPDATE', 'user', $uid, $row['user_name'], null,
                                  ['require_password_change' => 1], ['require_password_change' => 0], 'Password changed at first login');

                    header('Location: ' . lum_app_url('/index.php'));
                    exit();
                }
            } catch (\Throwable $e) {
                error_log('LUM change-password: ' . $e->getMessage());
                $error = 'The password could not be changed right now. Please try again later.';
            }
        }
    }
}
$minutes_left = max(1, (int)ceil((LUM_PW_CHANGE_WINDOW - (time() - $started)) / 60));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - Lynx Utility Management (Pty) Ltd</title>
    <?php include lum_resolve_path('/head-assets.php'); ?>
    <style>
        * { border-radius: 0 !important; }
        body { background-color: #121212; color: #ffffff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .login-container { background-color: #000000; border: 1px solid #333333; box-shadow: 0 8px 32px rgba(0, 0, 0, 0.8); }
        .logo-img { max-width: 350px; width: 100%; height: auto; }
        .form-control { background-color: #1e1e1e; border: 1px solid #444; color: #fff; }
        .form-control:focus { background-color: #2a2a2a; border-color: #e3000f; color: #fff; box-shadow: 0 0 0 0.25rem rgba(227, 0, 15, 0.25); }
        .btn-brand { background-color: #e3000f; color: #ffffff; border: none; font-weight: 500; letter-spacing: 0.5px; border-radius: 4px !important; }
        .btn-brand:hover, .btn-brand:focus { background-color: #bf000c; color: #ffffff; }
    </style>
</head>
<body>
    <div class="container d-flex justify-content-center align-items-center min-vh-100 py-5">
        <div class="col-12 col-md-8 col-lg-5 col-xl-4">
            <div class="card login-container p-4">
                <div class="text-center mb-4">
                    <img src="<?php echo htmlspecialchars(lum_app_url('/Additions/Style-Login/LUM-login-logo.png'), ENT_QUOTES); ?>" alt="Lynx Utilities Logo" class="logo-img">
                </div>

                <h5 class="text-center mb-2">Choose a new password</h5>
                <p class="text-center text-secondary small mb-3">
                    Welcome, <?php echo htmlspecialchars((string)($_SESSION['temp_user_name'] ?? '')); ?>.
                    Your account needs a new password before you continue (<?php echo $minutes_left; ?> min left).
                </p>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger text-center" role="alert" style="background-color: #3b0003; border-color: #e3000f; color: #ffcccc;">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form action="" method="POST" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['pw_csrf']); ?>">
                    <input type="text" name="username" value="<?php echo htmlspecialchars((string)($_SESSION['temp_user_name'] ?? '')); ?>" autocomplete="username" hidden>
                    <div class="mx-auto" style="width: 80%;">
                        <div class="mb-3">
                            <label for="new_password" class="form-label text-light">New password</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" required minlength="<?php echo LUM_PW_MIN_LENGTH; ?>" autocomplete="new-password">
                            <div class="form-text text-secondary">At least <?php echo LUM_PW_MIN_LENGTH; ?> characters.</div>
                        </div>
                        <div class="mb-4">
                            <label for="confirm_password" class="form-label text-light">Repeat the new password</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="<?php echo LUM_PW_MIN_LENGTH; ?>" autocomplete="new-password">
                        </div>
                    </div>
                    <div class="text-center mt-4 d-flex justify-content-center gap-2">
                        <a href="<?php echo htmlspecialchars(lum_app_url('/Sec/logout.php'), ENT_QUOTES); ?>" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-brand px-4">Save and continue</button>
                    </div>
                </form>

                <div class="mt-4 text-center text-white" style="font-size: 0.8rem;">
                    &copy; <?php echo date('Y'); ?> Lynx Utility Management (Pty) Ltd.<br>All rights reserved.
                </div>
            </div>
        </div>
    </div>
</body>
</html>