<?php
// Library file: only other pages may include it, it can never be opened directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(403); exit('Forbidden'); }
// Per-tenant slip setup: include once per tenant after slip-context.php, with $tenant set to its lum_tenants row.
// Resets every per-tenant total, so nothing carries over between tenants in the bulk slips or the financial report.

$property_settings = $property_settings ?? lumPropertySettings($property_name); // Set by slip-context.php

// Occupancy status: 'valid', 'missing' (start or end not set) or 'expired' (ended before the billing period)
$has_occ_dates = (!empty($tenant['tenant_occupancy_start_date']) && !empty($tenant['tenant_occupancy_end_date']));
if (!$has_occ_dates) {
    $occ_status = 'missing';
} elseif (strtotime($tenant['tenant_occupancy_end_date']) < strtotime($start_date)) {
    $occ_status = 'expired';
} else {
    $occ_status = 'valid';
}

// Tenants without valid occupancy dates may use the estimate time ranges (when that override is ticked)
$use_occ_override = ($use_estimate_time_ranges_requested && $occ_status !== 'valid' && !empty($estimate_time_ranges));
$use_estimated_readings = ($use_estimated_readings_requested && ($occ_status === 'valid' || $use_occ_override));
$tenant['_estimate_time_ranges'] = $use_occ_override ? $estimate_time_ranges : null; // Read by the estimation engine

list($elec_tariff, $elec_tariff_calc) = lumSlipResolveElecTariff($tenant['tenant_electrical_tariff_charge'] ?? '', $property_name, $property_electrical_billing_type);
$water_tariff = $tenant['tenant_water_tariff_charge'] ?? '';
$sewer_tariff = $tenant['tenant_water_sewer_tariff_charge'] ?? '';
$municipality = lumSlipResolveMunicipality($property_municipality, $property_name, $elec_tariff, $water_tariff, $sewer_tariff);
// Plettenberg Bay NDC/NCC demand lines are not billed (and never have been).
// To bill them, set this to (stripos($municipality, 'Plett') !== false).
$is_plett_elec = false;

$pays_elec_basic  = (!isset($tenant['tenant_pays_electrical_basic_charge'])  || strcasecmp($tenant['tenant_pays_electrical_basic_charge'], 'No') !== 0);
$pays_water_basic = (!isset($tenant['tenant_pays_water_basic_charge'])       || strcasecmp($tenant['tenant_pays_water_basic_charge'], 'No') !== 0);
$pays_sewer_basic = (!isset($tenant['tenant_pays_sewer_basic_charge'])       || strcasecmp($tenant['tenant_pays_sewer_basic_charge'], 'No') !== 0);

$pays_elec_unit   = (!isset($tenant['tenant_pays_electrical_tariff_charge']) || strcasecmp($tenant['tenant_pays_electrical_tariff_charge'], 'No') !== 0);
$pays_demand_unit = (!isset($tenant['tenant_pays_demand_tariff_charge'])     || strcasecmp($tenant['tenant_pays_demand_tariff_charge'], 'No') !== 0);
$pays_gen_unit    = (!isset($tenant['tenant_pays_generator_tariff_charge'])  || strcasecmp($tenant['tenant_pays_generator_tariff_charge'], 'No') !== 0);

// "TOU" as a separate word marks a Time Of Use tariff ("Bitou" on its own does not count)
$is_tou_tenant = (lumIsTouTariff($elec_tariff) || lumIsTouTariff($elec_tariff_calc));
$tenant['_is_tou_tenant'] = $is_tou_tenant; // Read by the estimation engine

// Default algorithm: chosen on the tariff catalog, otherwise the municipality's
$default_tou_algorithm = lumTariffTouAlgorithm($elec_tariff);
if ($default_tou_algorithm === '') $default_tou_algorithm = lumTariffTouAlgorithm($elec_tariff_calc);
if ($default_tou_algorithm === '') {
    $default_tou_algorithm = lumTouDefaultAlgorithm($municipality); // TOU Periods: "default for" municipalities
}
$tou_algorithm = $is_tou_tenant ? ($_REQUEST['tou_algorithm'] ?? $default_tou_algorithm) : 'None';
if ($is_tou_tenant && !in_array($tou_algorithm, array_merge(lumTouAlgorithmList(), ['None']), true)) {
    $tou_algorithm = $default_tou_algorithm;
}

$curr_conf = [];
try {
    $stmt_curr = $tenant_db_conn->prepare("SELECT elec_comm_discount, water_comm_discount, elec_obis_code FROM lum_tenant_monthly_settings WHERE tenant_id = :tid AND period_month = :pm AND period_year = :py");
    $stmt_curr->execute(['tid' => $tenant['tenant_id'], 'pm' => $report_month, 'py' => $report_year]);
    $curr_conf = $stmt_curr->fetch(PDO::FETCH_ASSOC);
    if (!is_array($curr_conf)) $curr_conf = [];
} catch (\Throwable $e) {
    $curr_conf = [];
}

// Discounts: a value typed into the sidebar wins, otherwise the tenant's monthly setting
$elec_comm_discount = ($req_elec_comm_discount !== null) ? $req_elec_comm_discount : floatval($curr_conf['elec_comm_discount'] ?? 0);
$water_comm_discount = ($req_water_comm_discount !== null) ? $req_water_comm_discount : floatval($curr_conf['water_comm_discount'] ?? 0);

// Billing OBIS: sidebar selection (single slip) > monthly setting > property default for non-TOU tenants
$req_tenant_obis = $_REQUEST['tenant_billing_obis'] ?? '';
if (in_array($req_tenant_obis, ['1.1.1.8.0', '1.1.1.8.1'], true)) {
    $tenant_obis_code = $req_tenant_obis;
} elseif (!empty($curr_conf['elec_obis_code']) && in_array($curr_conf['elec_obis_code'], ['1.1.1.8.0', '1.1.1.8.1'], true)) {
    $tenant_obis_code = $curr_conf['elec_obis_code'];
} else {
    $tenant_obis_code = $non_tou_obis;
}

// Time Of Use tenants are always billed on OBIS 1.1.1.8.0
if ($is_tou_tenant) {
    $tenant_obis_code = '1.1.1.8.0';
}

$elec_comm_charge = $tenant['tenant_electrical_commArea_charge'] ?? '';
$water_comm_charge = $tenant['tenant_water_commArea_charge'] ?? '';
$pays_elec_flag = $tenant['tenant_pays_electrical_commArea'] ?? '';
$pays_water_flag = $tenant['tenant_pays_water_commArea'] ?? '';
$pays_elec_comm = (!is_na($elec_comm_charge) && !is_na($pays_elec_flag) && strcasecmp($pays_elec_flag, 'no') !== 0);
$pays_water_comm = (!is_na($water_comm_charge) && !is_na($pays_water_flag) && strcasecmp($pays_water_flag, 'no') !== 0);

$gen_tariff_raw = trim($tenant['tenant_generator_tariff_charge'] ?? '');
$has_generator = true;
if (lumTariffNotApplicable($gen_tariff_raw, 'generator')) {
    $has_generator = false;
    $gen_tariff = 'Not applicable';
} elseif ($property_settings['generator_tariff_label'] !== '') {
    $gen_tariff = $property_settings['generator_tariff_label'];
} else {
    $gen_tariff = $municipality . ' - Generator';
}

// Not applicable / display name: from the tariff catalog, otherwise the words in the tariff names
$has_water = !lumTariffNotApplicable($water_tariff, 'water');
$has_sewer = !lumTariffNotApplicable($sewer_tariff, 'sewer');

$display_water_tariff = lumTariffDisplayName($water_tariff, 'water');
if ($display_water_tariff === '') {
    $display_water_tariff = $water_tariff;
    if ($property_settings['tiered_water_label'] !== '' && stripos($water_tariff, 'Tiered') !== false) {
        $display_water_tariff = $property_settings['tiered_water_label'];
    }
}

// Capacity (Amps) on the slip: tariff catalog, plus George "General Consumers" tariffs
$show_amps = (lumTariffShowsAmps($elec_tariff) || (stripos($municipality, 'George') !== false && stripos($elec_tariff, 'General Consumers') !== false));

// The season comes from the municipal tariff ledger
$active_db_p1 = []; $active_db_p2 = [];
if ($municipality === 'Tshwane') { $active_db_p1 = $db_tshwane_p1; $active_db_p2 = $db_tshwane_p2; }
elseif (stripos($municipality, 'Ekhurhuleni') !== false || stripos($municipality, 'Ekurhuleni') !== false) { $active_db_p1 = $db_ekur_p1; $active_db_p2 = $db_ekur_p2; }
elseif ($municipality === 'Rustenburg') { $active_db_p1 = $db_rust_p1; $active_db_p2 = $db_rust_p2; }
elseif ($municipality === 'Mkhondo') { $active_db_p1 = $db_mkhondo_p1; $active_db_p2 = $db_mkhondo_p2; }
elseif (stripos($municipality, 'Plett') !== false) { $active_db_p1 = $db_plett_p1; $active_db_p2 = $db_plett_p2; }
elseif ($municipality === 'George') { $active_db_p1 = $db_george_p1; $active_db_p2 = $db_george_p2; }

$season_1 = ucfirst(strtolower($active_db_p1['demand_season'] ?? 'Low'));
$season_2 = $has_period_2 ? ucfirst(strtolower($active_db_p2['demand_season'] ?? 'Low')) : $season_1;

// Rates from the tariff rate map (Tariffs -> Rate Map). The water and sewer tariff names are passed on, so the rates are
// also right when the slip is calculated inside a function (back billing recalculations).
$rates_p1 = getTariffRates($elec_tariff_calc, $season_1, $db_tshwane_p1, $db_ekur_p1, $municipality, $db_rust_p1, $db_mkhondo_p1, $db_plett_p1, $db_george_p1, (string)$water_tariff, (string)$sewer_tariff);
$rates_p2 = getTariffRates($elec_tariff_calc, $season_2, $db_tshwane_p2, $db_ekur_p2, $municipality, $db_rust_p2, $db_mkhondo_p2, $db_plett_p2, $db_george_p2, (string)$water_tariff, (string)$sewer_tariff);

if (empty($rates_p1['comm_water_kl']) && !empty($rates_p1['is_tiered_water'])) {
    $rates_p1['comm_water_kl'] = $rates_p1['water_tier_1'];
}

$elec_meters = array_filter([$tenant['tenant_electricalMeter_01'] ?? null, $tenant['tenant_electricalMeter_02'] ?? null, $tenant['tenant_electricalMeter_03'] ?? null]);

$valid_elec_meters = [];
if (!empty($tenant['tenant_electricalMeter_01'])) $valid_elec_meters[$tenant['tenant_electricalMeter_01']] = floatval($tenant['tenant_electricalMeter_01_ct_ratio'] ?? 1.0);
if (!empty($tenant['tenant_electricalMeter_02'])) $valid_elec_meters[$tenant['tenant_electricalMeter_02']] = floatval($tenant['tenant_electricalMeter_02_ct_ratio'] ?? 1.0);
if (!empty($tenant['tenant_electricalMeter_03'])) $valid_elec_meters[$tenant['tenant_electricalMeter_03']] = floatval($tenant['tenant_electricalMeter_03_ct_ratio'] ?? 1.0);

// Meter slot (1, 2 or 3) per current serial, so the estimator follows the right meter history
$elec_meter_slots = [];
for ($slot_no = 1; $slot_no <= 3; $slot_no++) {
    $slot_serial = $tenant['tenant_electricalMeter_0' . $slot_no] ?? null;
    if (!empty($slot_serial) && !isset($elec_meter_slots[$slot_serial])) {
        $elec_meter_slots[$slot_serial] = $slot_no;
    }
}

// Water meters for this billing period. Where meter assignments are recorded, they decide:
// a period before a meter change uses the meter that served the tenant then. Without
// assignments, the tenant's current water meters are used, as before.
// What this slip is billing on, for the note at the bottom of the slip: every meter used,
// the dates it applied, its CT ratio, and where it came from. Also anything on the tenant
// profile that no assignment covers for this period, which would otherwise be billed as
// nothing without saying so.
$lum_meter_basis = [];
$lum_meter_gaps = [];
$lum_period_from = $start_date;
$lum_period_to = !empty($end_date_2) ? $end_date_2 : $end_date;

if (function_exists('lumAssignedMeterHistory')) {
    $e_form_serials = [];
    for ($e_slot = 1; $e_slot <= 3; $e_slot++) {
        $e_form = trim((string)($tenant['tenant_electricalMeter_0' . $e_slot] ?? ''));
        if ($e_form !== '' && $e_form !== '0') $e_form_serials[$e_form] = $e_slot;
    }

    $e_assigned = function_exists('lumAssignedSerials')
        ? lumAssignedSerials($tenant_db_conn, $tenant, 'electricity') : [];
    $e_billed = [];
    foreach ($e_assigned as $e_serial => $e_windows) {
        foreach ($e_windows as $w) {
            $w_from = $w['start'] ?: '1970-01-01';
            $w_to = $w['display_end'] ?: '9999-12-31';
            if ($w_from <= $lum_period_to && $w_to >= $lum_period_from) {
                $e_billed[$e_serial] = true;
                $lum_meter_basis[] = [
                    'kind' => 'Electricity', 'slot' => $e_form_serials[$e_serial] ?? 1, 'serial' => $e_serial,
                    'ct' => $w['ct'], 'from' => $w['start'], 'to' => $w['display_end'],
                    'source' => 'recorded assignment',
                ];
            }
        }
    }
    // On the profile, with no assignment at all: billed from the profile, as before
    foreach ($e_form_serials as $e_serial => $e_slot) {
        if (isset($e_billed[$e_serial])) continue;
        if (!isset($e_assigned[$e_serial])) {
            $lum_meter_basis[] = [
                'kind' => 'Electricity', 'slot' => $e_slot, 'serial' => $e_serial,
                'ct' => (float)($tenant['tenant_electricalMeter_0' . $e_slot . '_ct_ratio'] ?? 1),
                'from' => null, 'to' => null, 'source' => 'tenant profile',
            ];
        } else {
            // Assigned, but not for this period
            $lum_meter_gaps[] = 'Electricity meter ' . $e_serial . ' is on this tenant, but no assignment covers '
                              . $lum_period_from . ' to ' . $lum_period_to . ', so it is not billed on this slip.';
        }
    }
}

$water_meters = [];
$water_period_from = $water_start_date;
$water_period_to = $has_water_period_2 ? $water_end_date_2 : $water_end_date;
if (function_exists('lumAssignedMeterHistory')) {
    $w_form_serials = [];
    for ($w_slot = 1; $w_slot <= 4; $w_slot++) {
        $w_form = trim((string)($tenant['tenant_waterMeter_0' . $w_slot] ?? ''));
        if ($w_form !== '' && $w_form !== '0') $w_form_serials[$w_form] = $w_slot;
    }

    $w_assigned = function_exists('lumAssignedSerials')
        ? lumAssignedSerials($tenant_db_conn, $tenant, 'water') : [];
    foreach ($w_assigned as $w_serial => $w_windows) {
        foreach ($w_windows as $w_win) {
            $w_from = $w_win['start'] ?: '1970-01-01';
            $w_to = $w_win['display_end'] ?: '9999-12-31';
            if ($w_from <= $water_period_to && $w_to >= $water_period_from) {
                $water_meters[] = $w_serial;
                $lum_meter_basis[] = [
                    'kind' => 'Water', 'slot' => $w_form_serials[$w_serial] ?? 1, 'serial' => $w_serial, 'ct' => null,
                    'from' => $w_win['start'], 'to' => $w_win['display_end'], 'source' => 'recorded assignment',
                ];
            }
        }
    }
    foreach ($w_form_serials as $w_serial => $w_slot) {
        if (in_array($w_serial, $water_meters, true)) continue;
        if (isset($w_assigned[$w_serial])) {
            $lum_meter_gaps[] = 'Water meter ' . $w_serial . ' is on this tenant, but no assignment covers '
                              . $water_period_from . ' to ' . $water_period_to . ', so it is not billed on this slip.';
        }
    }
    $water_meters = array_values(array_unique($water_meters));
}
if (empty($water_meters)) {
    $water_meters = array_filter([$tenant['tenant_waterMeter_01'] ?? null, $tenant['tenant_waterMeter_02'] ?? null, $tenant['tenant_waterMeter_03'] ?? null, $tenant['tenant_waterMeter_04'] ?? null]);
    foreach ($water_meters as $w_i => $w_serial) {
        $lum_meter_basis[] = [
            'kind' => 'Water', 'slot' => $w_i + 1, 'serial' => trim((string)$w_serial), 'ct' => null,
            'from' => null, 'to' => null, 'source' => 'tenant profile',
        ];
    }
}

// Common area allocations (property totals calculated once, on first use)
$tenant_comm_perc = floatval($tenant['tenant_comm_area'] ?? 0);

$raw_comm_elec_kwh = 0;
if ($pays_elec_comm && !$remove_elec_comm) {
    if ($total_property_elec_comm_kwh === null) {
        $total_property_elec_comm_kwh = lumSlipElecCommonArea($property_name, $comm_start_date, $comm_end_date, $global_obis_code, $obis_db_conn, $tenant_db_conn, $manual_prop_elec_comm);
    }
    $raw_comm_elec_kwh = $total_property_elec_comm_kwh * ($tenant_comm_perc / 100);
}

$raw_comm_water_kl = 0;
if ($pays_water_comm && !$remove_water_comm) {
    if ($total_property_water_comm_kl === null) {
        $total_property_water_comm_kl = lumSlipWaterCommonArea($property_name, $water_comm_start_date, $water_comm_end_date, $obis_db_conn, $manual_db_conn, $tenant_db_conn, $manual_prop_water_comm);
    }
    $raw_comm_water_kl = $total_property_water_comm_kl * ($tenant_comm_perc / 100);
}

// Shared network access charge (property setting)
if (!empty($property_settings['charges_shared_nac']) && isset($tenant['pays_shared_nac']) && $tenant['pays_shared_nac'] === 'Yes' && $shared_nac_leftover_kva === null) {
    $shared_nac_leftover_kva = calculateSharedNAC($property_name, $start_date, $end_date, $global_obis_code, $obis_db_conn, $tenant_db_conn, $core_db_conn);
}

// Per-tenant render state
$grand_total = 0;
$agg_daily_graph = [];
$agg_daily_water_graph = [];