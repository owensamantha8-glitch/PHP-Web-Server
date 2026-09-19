<?php
// Library file: only other pages may include it, it can never be opened directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(403); exit('Forbidden'); }
// Restores the report settings after a tenant billed on its own settings (see slip-tenant-settings-apply.php)

if ($lum_saved_ctx !== null) {
    // Property common area totals worked out while this tenant was billed (same common area dates) are kept
    $lum_keep_comm = [$comm_start_date, $comm_end_date, $total_property_elec_comm_kwh,
                      $water_comm_start_date, $water_comm_end_date, $total_property_water_comm_kl];
    extract($lum_saved_ctx);
    $_REQUEST = $lum_saved_request;
    if ($total_property_elec_comm_kwh === null && $lum_keep_comm[0] === $comm_start_date && $lum_keep_comm[1] === $comm_end_date) {
        $total_property_elec_comm_kwh = $lum_keep_comm[2];
    }
    if ($total_property_water_comm_kl === null && $lum_keep_comm[3] === $water_comm_start_date && $lum_keep_comm[4] === $water_comm_end_date) {
        $total_property_water_comm_kl = $lum_keep_comm[5];
    }
}
$lum_saved_ctx = null;