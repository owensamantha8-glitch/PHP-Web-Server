<?php
// Shared bootstrap, loaded first by every page (auth-guard.php loads it too):
//   lum_page('tenants', 'view');     login + page access (skipped for cron jobs)
//   lum_db('tenants');               PDO connection, opened on first use and reused
//   lum_connect('tenants', 'obis');  sets $tenant_db_conn, $obis_db_conn, ...
//   lum_use('slips');                loads a shared engine on demand

// Library file: only other pages may include it, it can never be opened directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(403); exit('Forbidden'); }
if (defined('LUM_BOOTSTRAP')) return;
define('LUM_BOOTSTRAP', true);

$lum_root_env = getenv('LUM_APP_ROOT');
if ($lum_root_env === false || trim($lum_root_env) === '') $lum_root_env = getenv('LUM_ROOT'); // Backwards-compatible alias
$lum_root_fallback = __DIR__;
$lum_root_value = ($lum_root_env !== false && trim((string)$lum_root_env) !== '') ? trim((string)$lum_root_env) : $lum_root_fallback;
$lum_root_real = realpath($lum_root_value);
if ($lum_root_real !== false) $lum_root_value = $lum_root_real;
define('LUM_APP_ROOT', rtrim(str_replace('\\', '/', $lum_root_value), '/'));
if (!defined('LUM_ROOT')) define('LUM_ROOT', LUM_APP_ROOT); // Backwards-compatible alias

$lum_url_env = getenv('LUM_APP_URL');
$lum_url_default = 'https://lynx-um.co.za';
$lum_url_value = ($lum_url_env !== false && trim((string)$lum_url_env) !== '') ? trim((string)$lum_url_env) : $lum_url_default;
$lum_url_value = rtrim($lum_url_value, '/');
if ($lum_url_value === '') $lum_url_value = $lum_url_default;
if (!preg_match('#^https?://#i', $lum_url_value) || filter_var($lum_url_value, FILTER_VALIDATE_URL) === false) $lum_url_value = $lum_url_default;
define('LUM_APP_URL', $lum_url_value);

$lum_db_cfg_env = getenv('LUM_DB_CONFIG');
$lum_db_cfg_default = '/var/secure_configs/lynx_db.ini'; // Outside the web root
define('LUM_DB_CONFIG', ($lum_db_cfg_env !== false && trim((string)$lum_db_cfg_env) !== '') ? trim((string)$lum_db_cfg_env) : $lum_db_cfg_default);

// Short database names => MySQL databases ('' = server level, for the yearly OBIS databases)
const LUM_DATABASES = [
    'tenants'       => 'sys_db_tenants',
    'meters'        => 'sys_db_meters',
    'tariffs'       => 'sys_db_tariffs',
    'manual'        => 'manual_readings',
    'obis'          => '',
    'users'         => 'sys_db_users',
    'properties'    => 'sys_db_properties',
    'information'   => 'sys_db_information',
    'journal'       => 'sys_db_financial_journal',
    'billing_cycle' => 'sys_db_billing_cycle',
];

// Classic connection variables (lum_connect) => [short database name, variable name]
const LUM_DB_GLOBALS = [
    'tenants' => 'tenant_db_conn',
    'meters'  => 'meter_db_conn',
    'tariffs' => 'tariff_db_conn',
    'manual'  => 'manual_db_conn',
    'obis'    => 'obis_db_conn',
];

// Shared engines (lum_use) => file below LUM_ROOT
const LUM_LIBRARIES = [
    'audit'        => '/Audit/audit-logger.php',
    'reporting'    => '/Reporting/reporting-engine.php',
    'slips'        => '/Tenant Management/Tenant Consumption Slips/slip-engine.php',
    'journal'      => '/Reporting/financial-journal-engine.php',
    'back_billing' => '/Reporting/back-billing-engine.php',
    'tenant_forms' => '/Tenant Management/tenant-form-options.php',
    'meters'       => '/Meter Management/Meter Control/meter-conr.php',
    'tenants'      => '/Tenant Management/Tenant Control/tenant-conr.php',
];

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Africa/Johannesburg');
// PCRE JIT is not permitted on this server: disable it before any preg_* call
ini_set('pcre.jit', '0');

// Read once; null when the file is missing or incomplete
function lum_db_config() {
    static $cfg = false;
    if ($cfg === false) {
        $cfg = @parse_ini_file(LUM_DB_CONFIG);
        if ($cfg === false || empty($cfg['host']) || !isset($cfg['username'], $cfg['password'])) {
            error_log('LUM: unable to read ' . LUM_DB_CONFIG);
            $cfg = null;
        }
    }
    return $cfg;
}

// Resolve an app file path against the configured app root, then local fallbacks.
function lum_resolve_path($relative_path) {
    $relative = ltrim(str_replace('\\', '/', (string)$relative_path), '/');
    if ($relative === '') return LUM_ROOT;
    $relative = preg_replace('#/+#', '/', $relative);
    $segments = [];
    foreach (explode('/', $relative) as $segment) {
        if ($segment === '' || $segment === '.') continue;
        if ($segment === '..') {
            error_log('LUM: refused path traversal in lum_resolve_path(' . $relative_path . ')');
            return null;
        }
        $segments[] = $segment;
    }
    $relative = implode('/', $segments);
    if ($relative === '') return LUM_ROOT;
    $root_real = realpath(LUM_ROOT);
    $self_real = realpath(__DIR__);
    $allowed_roots = [];
    if ($root_real !== false) $allowed_roots[] = rtrim(str_replace('\\', '/', $root_real), '/');
    if ($self_real !== false) $allowed_roots[] = rtrim(str_replace('\\', '/', $self_real), '/');
    $candidates = [
        rtrim(LUM_ROOT, '/') . '/' . $relative,
        __DIR__ . '/' . $relative,
    ];
    foreach ($candidates as $candidate) {
        $real = realpath($candidate);
        if ($real === false) continue;
        $real = str_replace('\\', '/', $real);
        foreach ($allowed_roots as $allowed_root) {
            $real_cmp = $real;
            $allowed_cmp = $allowed_root;
            if (PHP_OS_FAMILY === 'Windows') {
                $real_cmp = strtolower($real_cmp);
                $allowed_cmp = strtolower($allowed_cmp);
            }
            if ($real_cmp === $allowed_cmp || strpos($real_cmp, $allowed_cmp . '/') === 0) return $real;
        }
    }
    return null;
}

// PDO connection by short name (LUM_DATABASES) or MySQL database name, reused for the request.
// $fatal: stop the page when the connection fails (otherwise return null).
function lum_db($name, $fatal = false) {
    static $pool = [];
    static $failed = [];
    $name = (string)$name;
    $database = array_key_exists($name, LUM_DATABASES) ? LUM_DATABASES[$name] : $name;
    if ($database !== '' && !preg_match('/^[A-Za-z0-9_]+$/', $database)) {
        error_log('LUM: invalid database name ' . $name);
        return $fatal ? lum_db_fail() : null;
    }
    if (isset($pool[$database])) return $pool[$database];
    if (isset($failed[$database])) return $fatal ? lum_db_fail() : null; // Already failed on this page: do not retry

    $cfg = lum_db_config();
    if ($cfg === null) return $fatal ? lum_db_fail('Critical Error: Unable to load secure database configuration.') : null;
    $failed[$database] = true;
    try {
        $dsn = 'mysql:host=' . $cfg['host'] . ($database !== '' ? ';dbname=' . $database : '') . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $cfg['username'], $cfg['password']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (\Throwable $e) {
        // Never pass the raw exception on: its stack trace can expose connection details
        error_log('LUM DB connection failed (' . ($database !== '' ? $database : 'server') . '): ' . $e->getMessage());
        return $fatal ? lum_db_fail() : null;
    }
    unset($failed[$database]);
    return $pool[$database] = $pdo;
}

function lum_db_fail($message = 'Database connection failed. Please contact the system administrator.') {
    if (PHP_SAPI !== 'cli' && !headers_sent()) http_response_code(500);
    die($message);
}

// mysqli connection to sys_db_users (used by the login pages)
function lum_db_mysqli_users($fatal = true) {
    static $conn = false;
    if ($conn !== false) return $conn;
    $cfg = lum_db_config();
    if ($cfg === null) return $fatal ? lum_db_fail('Critical Error: Unable to load secure database configuration.') : ($conn = null);
    $conn = @mysqli_connect($cfg['host'], $cfg['username'], $cfg['password'], LUM_DATABASES['users']);
    if (!$conn) {
        error_log('LUM DB connection failed (sys_db_users): ' . mysqli_connect_error());
        $conn = null;
        if ($fatal) lum_db_fail();
    }
    return $conn;
}

// Sets the classic connection variables ($tenant_db_conn, ...) and $tenant_crud / $meter_crud; stops the page on failure
function lum_connect(...$names) {
    foreach ($names as $name) {
        if (!isset(LUM_DB_GLOBALS[$name])) {
            error_log('LUM: lum_connect() does not know ' . $name);
            continue;
        }
        $var = LUM_DB_GLOBALS[$name];
        if (!isset($GLOBALS[$var])) $GLOBALS[$var] = lum_db($name, true);
        if ($name === 'tenants' && !isset($GLOBALS['tenant_crud'])) {
            lum_use('tenants');
            $GLOBALS['tenant_crud'] = new tenant_conr($GLOBALS[$var]);
        }
        if ($name === 'meters' && !isset($GLOBALS['meter_crud'])) {
            lum_use('meters');
            $GLOBALS['meter_crud'] = new meter_conr($GLOBALS[$var]);
        }
    }
}

// Loads shared engines (LUM_LIBRARIES) once, at global scope; false if a file is missing
function lum_use(...$names) {
    $ok = true;
    foreach ($names as $name) {
        if (!isset(LUM_LIBRARIES[$name])) {
            error_log('LUM: lum_use() does not know ' . $name);
            $ok = false;
            continue;
        }
        $file = lum_resolve_path(LUM_LIBRARIES[$name]);
        if (!$file || !is_readable($file)) {
            error_log('LUM: library not found: ' . $file);
            $ok = false;
            if ($name === 'audit') lum_audit_stubs();
            continue;
        }
        lum_require_global($file);
        if ($name === 'audit') lum_audit_stubs();
    }
    return $ok;
}

// require_once with the file's variables in the global scope (as when a page includes it directly)
function lum_require_global($__lum_file) {
    foreach (array_keys($GLOBALS) as $__lum_k) {
        if (in_array($__lum_k, ['GLOBALS', '_GET', '_POST', '_COOKIE', '_FILES', '_SERVER', '_ENV', '_REQUEST', '_SESSION'], true)) continue;
        global $$__lum_k;
    }
    require_once $__lum_file;
    foreach (get_defined_vars() as $__lum_k => $__lum_v) {
        if (strpos($__lum_k, '__lum_') === 0 || $__lum_k === 'GLOBALS' || $__lum_k[0] === '_') continue;
        $GLOBALS[$__lum_k] = $__lum_v;
    }
}

// Audit logging switches itself off when the logger file is missing, so pages keep working
function lum_audit_stubs() {
    if (!function_exists('lum_audit_log')) {
        function lum_audit_log(...$args) { return false; }
    }
    if (!function_exists('lum_audit_fetch_row')) {
        function lum_audit_fetch_row(...$args) { return null; }
    }
    if (!function_exists('lum_audit_fetch_user')) {
        function lum_audit_fetch_user(...$args) { return null; }
    }
    if (!function_exists('lum_audit_stamp_tenant_legacy')) {
        function lum_audit_stamp_tenant_legacy(...$args) { return false; }
    }
    if (!function_exists('lum_audit_tenant_label')) {
        function lum_audit_tenant_label($row) { return null; }
    }
}

// Login and page access for web pages; command-line (cron) scripts are not checked
function lum_page($page_key = null, $level = 'view') {
    $auth_guard = lum_resolve_path('/Sec/auth-guard.php');
    if (!$auth_guard || !is_readable($auth_guard)) lum_db_fail('Critical Error: Authentication module not available.');
    require_once $auth_guard;
    if (PHP_SAPI === 'cli') return;
    // auth-guard.php is loaded inside this function, so its page variables must be set globally
    $GLOBALS['csrf_token'] = lum_csrf_token();
    $GLOBALS['is_admin'] = lum_is_admin();
    if ($page_key !== null && $page_key !== '') lum_require_access($page_key, $level);
}