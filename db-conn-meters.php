<?php
// Library file: only other pages may include it, it can never be opened directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(403); exit('Forbidden'); }
// =========================================================================
// DATABASE CONNECTION: sys_db_meters ($meter_db_conn, $meter_crud)
// The connection itself is made by lum_db() in /var/www/Lynx/bootstrap.php
// (opened once per page and shared). New code can call lum_db('meters') directly.
// =========================================================================
require_once __DIR__ . '/bootstrap.php';

// Kept for older pages that read these after including this file
$config = lum_db_config();
if ($config === null) {
    die("Critical Error: Unable to load secure database configuration.");
}
$host = $config['host'];

$meter_db_conn = lum_db('meters', true);

require_once lum_resolve_path('/meter-conr.php');
$meter_crud = new meter_conr($meter_db_conn);