<?php
// Library file: only other pages may include it, it can never be opened directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(403); exit('Forbidden'); }
// =========================================================================
// DATABASE CONNECTION: sys_db_tariffs ($tariff_db_conn)
// The connection itself is made by lum_db() in /var/www/Lynx/bootstrap.php
// (opened once per page and shared). New code can call lum_db('tariffs') directly.
// =========================================================================
require_once __DIR__ . '/bootstrap.php';

// Kept for older pages that read these after including this file
$config = lum_db_config();
if ($config === null) {
    die("Critical Error: Unable to load secure database configuration.");
}
$host = $config['host'];

$tariff_db_conn = lum_db('tariffs', true);