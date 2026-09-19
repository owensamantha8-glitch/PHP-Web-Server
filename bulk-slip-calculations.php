<?php
// Library file: only other pages may include it, it can never be opened directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(403); exit('Forbidden'); }
// =========================================================================
// BULK SLIP - PER TENANT SETUP
// Uses the exact same tenant setup as the single consumption slip,
// including the tenant's own report settings and billing cycle.
// =========================================================================
require LUM_SLIP_DIR . '/slip-tenant-settings-apply.php'; // Restored in bulk-slip-render.php
require LUM_SLIP_DIR . '/slip-tenant.php';

$slip_part = $slip_part ?? 'all';
// A separate slip says which part it is, in the name and on the code, so the two are
// told apart when they are sent
$slip_part_suffix = ($slip_part === 'electricity') ? '_Electricity' : (($slip_part === 'water') ? '_Water' : '');
$slip_code_for_name = ($slip_part === 'water' && !empty($tenant['water_slip_code']))
    ? $tenant['water_slip_code'] : ($tenant['tenant_code'] ?? '');
$slip_filename = preg_replace('/[^a-zA-Z0-9\s]/', '', $tenant['tenant_name'] ?? '')
               . '_(' . htmlspecialchars($slip_code_for_name) . ')' . $slip_part_suffix . '.pdf';
?>