<?php
// Library file: only other pages may include it, it can never be opened directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(403); exit('Forbidden'); }
// Applies a tenant's own report settings (financial report and bulk slips), so it is billed exactly as on its slip.
// Include per tenant BEFORE slip-tenant.php with $tenant and $tenant_report_settings set; include
// slip-tenant-settings-restore.php after the slip is rendered. Sets $lum_ov and $lum_own_dates.

if (!isset($lum_ctx_vars)) {
    // Every variable slip-context.php sets (keep in step with that file); saved and restored around each tenant
    $lum_ctx_vars = ['property_name', 'global_obis_code', 'non_tou_obis', 'show_graph', 'show_water_graph', 'slip_part',
                     'start_date', 'end_date', 'start_date_2', 'end_date_2', 'has_period_2', 'py1', 'pm1', 'py2', 'pm2',
                     'tariff_period_1', 'tariff_period_2', 'report_month', 'report_year',
                     'water_start_date', 'water_end_date', 'water_start_date_2', 'water_end_date_2', 'has_water_period_2',
                     'use_estimated_readings_requested', 'use_estimated_readings', 'use_estimate_time_ranges_requested', 'estimate_time_ranges',
                     'manual_override_col', 'manual_override_col_t2', 'default_t2', 't2_method', 't2_base_obis',
                     'gen_start_date', 'gen_end_date', 'gen_cycle_end_date', 'gen_runtime_start', 'gen_runtime_end',
                     'gen_runtime_available', 'gen_hours_1', 'gen_hours_2', 'gen_runtime_info',
                     'comm_start_date', 'comm_end_date', 'water_comm_start_date', 'water_comm_end_date',
                     'remove_elec_comm', 'remove_water_comm', 'manual_prop_elec_comm', 'manual_prop_water_comm',
                     'req_elec_comm_discount', 'req_water_comm_discount',
                     'total_property_elec_comm_kwh', 'total_property_water_comm_kl', 'shared_nac_leftover_kva',
                     'property_account_number', 'property_municipality', 'property_electrical_billing_type', 'billing_cycles',
                     'db_tshwane_p1', 'db_ekur_p1', 'db_rust_p1', 'db_mkhondo_p1', 'db_plett_p1', 'db_george_p1',
                     'db_tshwane_p2', 'db_ekur_p2', 'db_rust_p2', 'db_mkhondo_p2', 'db_plett_p2', 'db_george_p2', 'public_holidays'];
}
if (!isset($lum_gen_cache)) {
    $lum_gen_cache = null; // Run-time hours for the report periods, calculated once when needed
}

$lum_ov = $tenant_report_settings[(int)$tenant['tenant_id']] ?? [];
$lum_saved_ctx = null;
$lum_own_dates = [];
if ($lum_ov) {
    // Save the report settings (existing variables only)
    $lum_saved_ctx = [];
    foreach ($lum_ctx_vars as $lum_n) {
        if (array_key_exists($lum_n, $GLOBALS)) $lum_saved_ctx[$lum_n] = $GLOBALS[$lum_n];
    }
    $lum_saved_request = $_REQUEST;

    // Own billing cycle: recalculate the period settings for the tenant's dates.
    // The property common area totals stay as calculated for the report, as on the tenant's slip.
    $lum_own_dates = array_intersect_key($lum_ov, array_flip(LUM_TENANT_DATE_KEYS));
    if ($lum_own_dates) {
        foreach ($lum_own_dates as $lum_k => $lum_v) {
            if ($lum_v === '') {
                unset($_REQUEST[$lum_k]);
            } else {
                $_REQUEST[$lum_k] = $lum_v;
            }
        }
        require LUM_SLIP_DIR . '/slip-context.php';
        $total_property_elec_comm_kwh = $lum_saved_ctx['total_property_elec_comm_kwh'] ?? $total_property_elec_comm_kwh;
        $total_property_water_comm_kl = $lum_saved_ctx['total_property_water_comm_kl'] ?? $total_property_water_comm_kl;
    }

    foreach ($lum_ov as $lum_k => $lum_v) {
        switch ($lum_k) {
            case 'tou_algorithm':            $_REQUEST['tou_algorithm'] = $lum_v; break;
            case 'elec_comm_discount':       $req_elec_comm_discount = ($lum_v === '') ? null : floatval($lum_v); break;
            case 'water_comm_discount':      $req_water_comm_discount = ($lum_v === '') ? null : floatval($lum_v); break;
            case 'use_estimated_readings':   $use_estimated_readings_requested = ($lum_v === '1'); break;
            case 'use_estimate_time_ranges': $use_estimate_time_ranges_requested = ($lum_v === '1'); break;
            case 'manual_override_col':      $manual_override_col = ($lum_v === '') ? 'none' : $lum_v; break;
            case 'manual_override_col_t2':   $manual_override_col_t2 = ($lum_v === '') ? 'none' : $lum_v; break;
            case 't2_method':                if (in_array($lum_v, ['runtime', '1.1.1.8.2'], true)) $t2_method = $lum_v; break;
            case 't2_base_obis':             if (in_array($lum_v, ['1.1.1.8.0', '1.1.1.8.1', '1.1.1.8.2'], true)) $t2_base_obis = $lum_v; break;
            case 'remove_elec_comm':         $remove_elec_comm = ($lum_v === '1'); break;
            case 'remove_water_comm':        $remove_water_comm = ($lum_v === '1'); break;
        }
    }
    // Generator run time may now be needed for this tenant even if the report does not use it
    $gen_runtime_available = ($t2_method === 'runtime' || $manual_override_col_t2 !== 'none');
    if ($gen_runtime_available && $gen_runtime_info === null && $property_name !== '') {
        if ($lum_own_dates) {
            // Own billing cycle: the run-time share follows this tenant's periods
            $lum_gen_tenant = lumSlipGeneratorRuntime($manual_db_conn, $obis_db_conn, $property_name, $gen_runtime_start, $gen_runtime_end,
                                                      $start_date, $end_date, $start_date_2, $end_date_2, $has_period_2);
        } else {
            if ($lum_gen_cache === null) {
                $lum_gen_cache = lumSlipGeneratorRuntime($manual_db_conn, $obis_db_conn, $property_name, $gen_runtime_start, $gen_runtime_end,
                                                         $start_date, $end_date, $start_date_2, $end_date_2, $has_period_2);
            }
            $lum_gen_tenant = $lum_gen_cache;
        }
        $gen_runtime_info = $lum_gen_tenant['info'];
        $gen_hours_1 = $lum_gen_tenant['hours_1'];
        $gen_hours_2 = $lum_gen_tenant['hours_2'];
    }
}