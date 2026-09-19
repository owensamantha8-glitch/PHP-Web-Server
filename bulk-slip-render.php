<?php
// Library file: only other pages may include it, it can never be opened directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(403); exit('Forbidden'); }
// =========================================================================
// BULK SLIP - RENDER
// Renders the exact same slip template as the single consumption slip.
// Charts are collected into $all_chart_data / $all_water_chart_data and
// drawn by the page-level script in bulk-consumption-slips.php.
// =========================================================================
$slip_render_mode = 'bulk';
$slip_chart_key = $tenant['tenant_id'] . (($slip_part ?? 'all') !== 'all' ? '-' . $slip_part : '');
include LUM_SLIP_DIR . '/slip-render.php';

// Back to the report settings for the next tenant
require LUM_SLIP_DIR . '/slip-tenant-settings-restore.php';
?>