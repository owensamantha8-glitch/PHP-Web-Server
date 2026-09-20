<?php
// Login page: no login check here.
require_once __DIR__ . '/bootstrap.php';
lum_use('audit');

// Session cookie: HTTPS only, not readable by JavaScript, not sent on cross-site requests
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    ini_set('session.use_strict_mode', 1);
}
session_start();
$login_db_conn = lum_db_mysqli_users(true);

$error = "";
$max_attempts = 5;
$lockout_time_minutes = 15;

// CSRF token for the login form (a login submitted from another site is refused)
if (empty($_SESSION['login_csrf'])) {
    $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
}

if (isset($_GET['timeout']) && $_GET['timeout'] == '1') {
    $error = "Logged out due to inactivity.";
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim((string)($_POST['user_name'] ?? ''));
    $password = trim((string)($_POST['user_password'] ?? ''));
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';

    if (!hash_equals($_SESSION['login_csrf'], (string)($_POST['login_csrf'] ?? ''))) {
        // Missing or expired token (e.g. the page was open for a long time): show a fresh form
        $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
        $error = "Your session has expired. Please try again.";
    } else {
        // Brute force lockout per IP address
        $check_attempts = $login_db_conn->prepare("SELECT attempts, last_attempt FROM lum_login_attempts WHERE ip_address = ?");
        $check_attempts->bind_param("s", $ip_address);
        $check_attempts->execute();
        $res_attempts = $check_attempts->get_result();

        $is_locked = false;
        if ($res_attempts && $res_attempts->num_rows > 0) {
            $attempt_data = $res_attempts->fetch_assoc();
            $time_diff_minutes = (time() - strtotime($attempt_data['last_attempt'])) / 60;

            if ($attempt_data['attempts'] >= $max_attempts && $time_diff_minutes < $lockout_time_minutes) {
                $is_locked = true;
                $remaining_time = ceil($lockout_time_minutes - $time_diff_minutes);
                $error = "Too many failed login attempts. Please try again in {$remaining_time} minute(s).";
            } elseif ($time_diff_minutes >= $lockout_time_minutes) {
                $reset_stmt = $login_db_conn->prepare("DELETE FROM lum_login_attempts WHERE ip_address = ?");
                $reset_stmt->bind_param("s", $ip_address);
                $reset_stmt->execute();
                $reset_stmt->close();
            }
        }
        $check_attempts->close();

        if (!$is_locked) {
            if (!empty($username) && !empty($password)) {
                $stmt = $login_db_conn->prepare("SELECT user_id, user_name, user_password, user_role, user_status, require_password_change, assigned_properties FROM lum_sys_users WHERE user_name = ? OR user_email = ? LIMIT 1");
                $stmt->bind_param("ss", $username, $username);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result && $result->num_rows == 1) {
                    $row = $result->fetch_assoc();

                    if (!password_verify($password, $row['user_password'])) {
                        // Same message as an unknown user
                        $error = "Your Login Name or Password is invalid";
                        $attempt = record_failed_attempt($login_db_conn, $ip_address);
                        login_audit('LOGIN_FAIL', $row['user_id'], $row['user_name'], $row['user_role'],
                                    "wrong password (attempt {$attempt} of {$max_attempts} from this address)");
                    } elseif ($row['user_status'] === 'Disabled') {
                        // Only revealed once the correct password was given
                        $error = "This account has been disabled. Please contact an administrator.";
                        login_audit('LOGIN_FAIL', $row['user_id'], $row['user_name'], $row['user_role'], 'account disabled');
                    } else {
                        $clear_stmt = $login_db_conn->prepare("DELETE FROM lum_login_attempts WHERE ip_address = ?");
                        $clear_stmt->bind_param("s", $ip_address);
                        $clear_stmt->execute();
                        $clear_stmt->close();

                        // New session id on login (prevents session fixation); the login token is no longer needed
                        session_regenerate_id(true);
                        unset($_SESSION['login_csrf']);

                        if ($row['require_password_change'] == 1) {
                            // Not logged in yet: change-password.php completes the login (within 15 minutes)
                            $_SESSION['temp_user_id'] = $row['user_id'];
                            $_SESSION['temp_user_name'] = $row['user_name'];
                            $_SESSION['temp_user_role'] = $row['user_role'];
                            $_SESSION['temp_assigned_properties'] = $row['assigned_properties'];
                            $_SESSION['temp_started_at'] = time();
                            login_audit('LOGIN', $row['user_id'], $row['user_name'], $row['user_role'], 'password change required');

                            header("Location: change-password.php");
                            exit();
                        }

                        $_SESSION['user_id'] = $row['user_id'];
                        $_SESSION['user_name'] = $row['user_name'];
                        $_SESSION['role'] = $row['user_role'];
                        $_SESSION['assigned_properties'] = $row['assigned_properties'];

                        $update_login = $login_db_conn->prepare("UPDATE lum_sys_users SET last_login = NOW() WHERE user_id = ?");
                        $update_login->bind_param("i", $row['user_id']);
                        $update_login->execute();
                        login_audit('LOGIN', $row['user_id'], $row['user_name'], $row['user_role']);

                        header('Location: ' . lum_app_url('/index.php'));
                        exit();
                    }
                } else {
                    // Unknown user: spend the same time as a real password check, so response times don't reveal valid names.
                    // The name typed is not logged (it may be a mistyped password).
                    password_verify($password, '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234');
                    $error = "Your Login Name or Password is invalid";
                    $attempt = record_failed_attempt($login_db_conn, $ip_address);
                    login_audit('LOGIN_FAIL', null, 'unknown user', null,
                                "unknown name (attempt {$attempt} of {$max_attempts} from this address)");
                }
                $stmt->close();
            } else {
                $error = "Please enter both username and password.";
            }
        }
    }
}

// Count a failed attempt for an IP address; returns the number of attempts so far
function record_failed_attempt($conn, $ip) {
    $stmt = $conn->prepare("INSERT INTO lum_login_attempts (ip_address, attempts, last_attempt) VALUES (?, 1, NOW()) ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = NOW()");
    $stmt->bind_param("s", $ip);
    $stmt->execute();
    $stmt->close();
    $count = $conn->prepare("SELECT attempts FROM lum_login_attempts WHERE ip_address = ?");
    $count->bind_param("s", $ip);
    $count->execute();
    $row = $count->get_result()->fetch_assoc();
    $count->close();
    return (int)($row['attempts'] ?? 1);
}

function login_audit($action, $user_id, $user_name, $role = null, $note = null) {
    if (function_exists('lum_audit_login_event')) {
        lum_audit_login_event($action, $user_id, $user_name, $role, $note);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Lynx Utility Management (Pty) Ltd</title>
    <?php include lum_resolve_path('/head-assets.php'); ?>
    <style>
        /* Strict override for completely sharp corners across all elements */
        * { border-radius: 0 !important; }
        body { background-color: #121212; color: #ffffff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .login-container { background-color: #000000; border: 1px solid #333333; box-shadow: 0 8px 32px rgba(0, 0, 0, 0.8); }
        /* Naturally enlarged logo without using transform tricks */
        .logo-img { max-width: 350px; width: 100%; height: auto; }
        .form-control { background-color: #1e1e1e; border: 1px solid #444; color: #fff; }
        .form-control:focus { background-color: #2a2a2a; border-color: #e3000f; color: #fff; box-shadow: 0 0 0 0.25rem rgba(227, 0, 15, 0.25); }
        .btn-brand { background-color: #e3000f; color: #ffffff; border: none; font-weight: 500; letter-spacing: 0.5px; border-radius: 4px !important; }
        .btn-brand:hover, .btn-brand:focus { background-color: #bf000c; color: #ffffff; }
    </style>
</head>
<body>

    <!-- Added py-5 to mathematically guarantee top and bottom spacing on shorter screens -->
    <div class="container d-flex justify-content-center align-items-center min-vh-100 py-5">
        <div class="col-12 col-md-8 col-lg-5 col-xl-4">
            <div class="card login-container p-4">
                
                <div class="text-center mb-4">
                    <img src="<?php echo htmlspecialchars(lum_app_url('/Additions/Style-Login/LUM-login-logo.png'), ENT_QUOTES); ?>" alt="Lynx Utilities Logo" class="logo-img">
                </div>

                <?php if(!empty($error)): ?>
                    <div class="alert alert-danger text-center" role="alert" style="background-color: #3b0003; border-color: #e3000f; color: #ffcccc;">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form action="" method="POST">
                    <input type="hidden" name="login_csrf" value="<?php echo htmlspecialchars($_SESSION['login_csrf'] ?? ''); ?>">
                    <div class="mx-auto" style="width: 80%;">
                        <div class="mb-3">
                            <label for="user_name" class="form-label text-light">Username or Email</label>
                            <input type="text" class="form-control" id="user_name" name="user_name" required autocomplete="username" value="<?php echo isset($_POST['user_name']) ? htmlspecialchars($_POST['user_name']) : ''; ?>">
                        </div>
                        
                        <div class="mb-4">
                            <label for="user_password" class="form-label text-light">Password</label>
                            <input type="password" class="form-control" id="user_password" name="user_password" required autocomplete="current-password">
                        </div>
                    </div>
                    
                    <div class="text-center mt-4">
                        <button type="submit" class="btn btn-brand" style="width: 33.33%;" name="btn_login">Login</button>
                    </div>
                </form>
                
                <div class="mt-4 text-center text-white" style="font-size: 0.8rem;">
                    &copy; <?php echo date('Y'); ?> Lynx Utility Management (Pty) Ltd.<br>All rights reserved.
                </div>
            </div>
        </div>
    </div>

    <?php include lum_resolve_path('/foot-assets.php'); ?>
</body>
</html>