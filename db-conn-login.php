<?php
// Library file: only other pages may include it, it can never be opened directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(403); exit('Forbidden'); }
// =========================================================================
// DATABASE CONNECTION: sys_db_users, mysqli ($login_db_conn) - used by the login and user pages
// The connection itself is made by lum_db() in /var/www/Lynx/bootstrap.php
// (lum_db_mysqli_users(), opened once per page). New PDO code can call lum_db('users').
// =========================================================================
require_once '/var/www/Lynx/bootstrap.php';

// Kept for older pages that read these after including this file
$config = lum_db_config();
if ($config === null) {
    die("Critical Error: Unable to load secure database configuration.");
}
$host = $config['host'];

// Older pages use these constants
if (!defined('DB_SERVER'))   define('DB_SERVER', $config['host']);
if (!defined('DB_USERNAME')) define('DB_USERNAME', $config['username']);
if (!defined('DB_PASSWORD')) define('DB_PASSWORD', $config['password']);
if (!defined('DB_DATABASE')) define('DB_DATABASE', 'sys_db_users');

$login_db_conn = lum_db_mysqli_users(true);