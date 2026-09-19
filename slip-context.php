<?php
// Library file: only other pages may include it, it can never be opened directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(403); exit('Forbidden'); }
// Request and property level slip settings, shared by the single slip, the bulk slips and the financial report.
// Expects $property_name, $tenant_db_conn, $obis_db_conn, $manual_db_conn, $tariff_db_conn
// and optionally $lum_default_start / $lum_default_end.

require_once __DIR__ . '/slip-engine.php';

date_default_timezone_set('Africa/Johannesburg');

$property_name = (string)($property_name ?? '');
$property_settings = lumPropertySettings($property_name); // Billing settings of the property (lum_properties)

$global_obis_code = $_REQUEST['obis_code'] ?? '1.1.1.8.1';                 // Property main OBIS (common areas)
$non_tou_obis = $_REQUEST['non_tou_obis'] ?? '1.1.1.8.1';                  // Default billing OBIS for non-TOU tenants
if (!in_array($global_obis_code, ['1.1.1.8.0', '1.1.1.8.1'], true)) $global_obis_code = '1.1.1.8.1';
if (!in_array($non_tou_obis, ['1.1.1.8.0', '1.1.1.8.1'], true)) $non_tou_obis = '1.1.1.8.1';

$show_graph = isset($_REQUEST['show_graph']);
$show_water_graph = isset($_REQUEST['show_water_graph']);

$start_date = $_REQUEST['start_date'] ?? ($lum_default_start ?? date('Y-m-d', strtotime('first day of last month')));
$end_date = $_REQUEST['end_date'] ?? ($lum_default_end ?? date('Y-m-d', strtotime('last day of last month')));

$start_date_2 = $_REQUEST['start_date_2'] ?? '';
$end_date_2 = $_REQUEST['end_date_2'] ?? '';
$has_period_2 = (!empty($start_date_2) && !empty($end_date_2));

// Tariff ledger month always follows the end date of each period
$py1 = (int)date('Y', strtotime($end_date));
$pm1 = (int)date('m', strtotime($end_date));
$py2 = $has_period_2 ? (int)date('Y', strtotime($end_date_2)) : $py1;
$pm2 = $has_period_2 ? (int)date('m', strtotime($end_date_2)) : $pm1;
$tariff_period_1 = sprintf('%04d-%02d', $py1, $pm1);
$tariff_period_2 = sprintf('%04d-%02d', $py2, $pm2);

// Monthly tenant settings are stored against the Period 1 billing month
$report_month = $pm1;
$report_year = $py1;

$water_start_date = $_REQUEST['water_start_date'] ?? $start_date;
$water_end_date = $_REQUEST['water_end_date'] ?? $end_date;
$water_start_date_2 = $_REQUEST['water_start_date_2'] ?? '';
$water_end_date_2 = $_REQUEST['water_end_date_2'] ?? '';
$has_water_period_2 = (!empty($water_start_date_2) && !empty($water_end_date_2));

$use_estimated_readings_requested = isset($_REQUEST['use_estimated_readings']) && $_REQUEST['use_estimated_readings'] == '1';
$use_estimated_readings = $use_estimated_readings_requested; // Narrowed per tenant in slip-tenant.php

// Occupancy override: tenants whose occupancy dates are missing or expired may use the
// estimate time ranges from sys_db_information.lum_estimate_time_ranges instead
$use_estimate_time_ranges_requested = isset($_REQUEST['use_estimate_time_ranges']) && $_REQUEST['use_estimate_time_ranges'] == '1';
$estimate_time_ranges = []; // obis_code => ['start' => 'Y-m-d H:i:s', 'end' => 'Y-m-d H:i:s']
$info_db_conn = lumDbConn('sys_db_information');
if ($info_db_conn) {
    try {
        $stmt_est = $info_db_conn->query("SELECT obis_code, start_time, end_time FROM lum_estimate_time_ranges ORDER BY id ASC");
        while ($est_row = $stmt_est->fetch(PDO::FETCH_ASSOC)) {
            $est_code = trim((string)$est_row['obis_code']);
            if ($est_code === '' || empty($est_row['start_time']) || empty($est_row['end_time'])) continue;
            if (strtotime($est_row['end_time']) <= strtotime($est_row['start_time'])) continue;
            $estimate_time_ranges[$est_code] = [
                'start' => date('Y-m-d H:i:s', strtotime($est_row['start_time'])),
                'end'   => date('Y-m-d H:i:s', strtotime($est_row['end_time']))
            ];
        }
    } catch (\Throwable $e) {}
}
// Which part of the slip this is. Tenants who want water and electricity on separate
// slips are billed on one record and given two documents: part=electricity and part=water.
$slip_part = strtolower(trim((string)($_REQUEST['part'] ?? 'all')));
if (!in_array($slip_part, ['all', 'electricity', 'water'], true)) $slip_part = 'all';

$manual_override_col = $_REQUEST['manual_override_col'] ?? 'none';
$manual_override_col_t2 = $_REQUEST['manual_override_col_t2'] ?? 'none';

$default_t2 = $property_settings['default_generator_method'];
$t2_method = $_REQUEST['t2_method'] ?? $default_t2;
$t2_base_obis = $_REQUEST['t2_base_obis'] ?? '1.1.1.8.0';

// Generator kWh is always calculated over the billing periods:
// Period 1 = start_date..end_date, Period 2 (if any) = start_date_2..end_date_2
$gen_start_date = $start_date;
$gen_end_date = $end_date;
$gen_cycle_end_date = $has_period_2 ? $end_date_2 : $end_date;

// Run-time reading dates: chosen in the sidebar, defaulting to the billing cycle start and end.
// The readings are matched by the MONTH of these dates.
$lum_valid_date = function ($value) {
    return (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false);
};
$gen_runtime_start = $lum_valid_date($_REQUEST['gen_start_date'] ?? null) ? $_REQUEST['gen_start_date'] : $start_date;
$gen_runtime_end = $lum_valid_date($_REQUEST['gen_end_date'] ?? null) ? $_REQUEST['gen_end_date'] : $gen_cycle_end_date;
if (strtotime($gen_runtime_end) < strtotime($gen_runtime_start)) {
    $gen_runtime_start = $start_date;
    $gen_runtime_end = $gen_cycle_end_date;
}

// Run time is used when selected, and as the fallback for tenants without manual T2 readings
$gen_runtime_available = ($t2_method === 'runtime' || $manual_override_col_t2 !== 'none');

$gen_hours_1 = 0;
$gen_hours_2 = 0;
$gen_runtime_info = null; // Details of the manual_readings_genrun readings used (shown in the sidebars)
if ($gen_runtime_available && $property_name !== '') {
    $lum_gen = lumSlipGeneratorRuntime($manual_db_conn, $obis_db_conn, $property_name, $gen_runtime_start, $gen_runtime_end,
                                       $start_date, $end_date, $start_date_2, $end_date_2, $has_period_2);
    $gen_runtime_info = $lum_gen['info'];
    $gen_hours_1 = $lum_gen['hours_1'];
    $gen_hours_2 = $lum_gen['hours_2'];
}

$comm_start_date = $_REQUEST['comm_start_date'] ?? $start_date;
$comm_end_date = $_REQUEST['comm_end_date'] ?? $end_date;
$water_comm_start_date = $_REQUEST['water_comm_start_date'] ?? $water_start_date;
$water_comm_end_date = $_REQUEST['water_comm_end_date'] ?? $water_end_date;
$remove_elec_comm = isset($_REQUEST['remove_elec_comm']);
$remove_water_comm = isset($_REQUEST['remove_water_comm']);
$manual_prop_elec_comm = floatval($_REQUEST['manual_prop_elec_comm'] ?? 0);
$manual_prop_water_comm = floatval($_REQUEST['manual_prop_water_comm'] ?? 0);

// Common area discount overrides (blank = use each tenant's monthly setting)
$req_elec_comm_discount = (isset($_REQUEST['elec_comm_discount']) && trim((string)$_REQUEST['elec_comm_discount']) !== '') ? floatval($_REQUEST['elec_comm_discount']) : null;
$req_water_comm_discount = (isset($_REQUEST['water_comm_discount']) && trim((string)$_REQUEST['water_comm_discount']) !== '') ? floatval($_REQUEST['water_comm_discount']) : null;

// Property totals are calculated on first use (see slip-tenant.php)
$total_property_elec_comm_kwh = null;
$total_property_water_comm_kl = null;
$shared_nac_leftover_kva = null;

$property_account_number = '';
$property_municipality = '';
$property_electrical_billing_type = '';
$core_db_conn = lumDbConn('sys_db_properties');
if ($core_db_conn && $property_name !== '') {
    try {
        $stmt_prop = $core_db_conn->prepare("SELECT account_number, municipality, electrical_billing_type FROM lum_properties WHERE Property = :prop LIMIT 1");
        $stmt_prop->execute(['prop' => $property_name]);
        $fetched_row = $stmt_prop->fetch(PDO::FETCH_ASSOC);
        if ($fetched_row) {
            if (!empty($fetched_row['account_number'])) $property_account_number = $fetched_row['account_number'];
            if (!empty($fetched_row['municipality'])) $property_municipality = $fetched_row['municipality'];
            if (!empty($fetched_row['electrical_billing_type'])) $property_electrical_billing_type = $fetched_row['electrical_billing_type'];
        }
    } catch (\Throwable $e) {}
}

// Billing cycles (sidebar drop-downs)
$billing_cycles = [];
$cycle_db_conn = lumDbConn('sys_db_billing_cycle');
if ($cycle_db_conn && $property_name !== '' && $property_name !== 'All') {
    try {
        // Billing cycle ledger name: the property's pattern (e.g. %Linton%), otherwise the exact property name
        $cycle_prop_search = ($property_settings['billing_cycle_match'] !== '') ? $property_settings['billing_cycle_match'] : $property_name;

        if (strpos($cycle_prop_search, '%') !== false) {
            $stmt_cycles = $cycle_db_conn->prepare("SELECT billing_month_label, period_start_date, period_end_date FROM lum_billing_period_ledger WHERE property_name LIKE :prop ORDER BY period_start_date DESC");
        } else {
            $stmt_cycles = $cycle_db_conn->prepare("SELECT billing_month_label, period_start_date, period_end_date FROM lum_billing_period_ledger WHERE property_name = :prop ORDER BY period_start_date DESC");
        }
        $stmt_cycles->execute(['prop' => $cycle_prop_search]);
        $billing_cycles = $stmt_cycles->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}
}

$db_tshwane_p1 = fetchMonthlyTariff($tariff_db_conn, 'lum_tariffs_city_of_tshwane', $pm1, $py1);
$db_ekur_p1    = fetchMonthlyTariff($tariff_db_conn, 'lum_tarrifs_ekhurhuleni', $pm1, $py1);
$db_rust_p1    = fetchMonthlyTariff($tariff_db_conn, 'lum_tariffs_rustenburg', $pm1, $py1);
$db_mkhondo_p1 = [];
try { $db_mkhondo_p1 = fetchMonthlyTariff($tariff_db_conn, 'lum_tariffs_mkhondo', $pm1, $py1); } catch (\Throwable $e) {}
$db_plett_p1 = [];
try { $db_plett_p1 = fetchMonthlyTariff($tariff_db_conn, 'lum_tariffs_plett', $pm1, $py1); } catch (\Throwable $e) {}
$db_george_p1 = [];
try {
    $db_george_p1 = fetchMonthlyTariff($tariff_db_conn, 'lum_tariffs_george', $pm1, $py1);
    if (empty($db_george_p1)) {
        $stmt_fb = $tariff_db_conn->query("SELECT * FROM lum_tariffs_george ORDER BY period_year DESC, period_month DESC LIMIT 1");
        if ($stmt_fb) $db_george_p1 = $stmt_fb->fetch(PDO::FETCH_ASSOC) ?: [];
    }
} catch (\Throwable $e) {}

$db_tshwane_p2 = $has_period_2 ? fetchMonthlyTariff($tariff_db_conn, 'lum_tariffs_city_of_tshwane', $pm2, $py2) : $db_tshwane_p1;
$db_ekur_p2    = $has_period_2 ? fetchMonthlyTariff($tariff_db_conn, 'lum_tarrifs_ekhurhuleni', $pm2, $py2) : $db_ekur_p1;
$db_rust_p2    = $has_period_2 ? fetchMonthlyTariff($tariff_db_conn, 'lum_tariffs_rustenburg', $pm2, $py2) : $db_rust_p1;
$db_mkhondo_p2 = [];
try { $db_mkhondo_p2 = $has_period_2 ? fetchMonthlyTariff($tariff_db_conn, 'lum_tariffs_mkhondo', $pm2, $py2) : $db_mkhondo_p1; } catch (\Throwable $e) {}
$db_plett_p2 = [];
try { $db_plett_p2 = $has_period_2 ? fetchMonthlyTariff($tariff_db_conn, 'lum_tariffs_plett', $pm2, $py2) : $db_plett_p1; } catch (\Throwable $e) {}
$db_george_p2 = [];
try {
    $db_george_p2 = $has_period_2 ? fetchMonthlyTariff($tariff_db_conn, 'lum_tariffs_george', $pm2, $py2) : $db_george_p1;
    if (empty($db_george_p2)) $db_george_p2 = $db_george_p1;
} catch (\Throwable $e) {}

$public_holidays = lumPublicHolidays();