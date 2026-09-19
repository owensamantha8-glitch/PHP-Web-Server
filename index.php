<?php
require_once __DIR__ . '/bootstrap.php';
lum_page('dashboard', 'view');

// The dashboard never writes to the session: release the lock so other pages are not kept waiting
session_write_close();

// Load timing (admins: add ?timing=1 to the address)
$lum_timing = [];
$lum_t_start = microtime(true);
$lum_t_last = $lum_t_start;
function lum_mark($label) {
    global $lum_timing, $lum_t_last;
    $now = microtime(true);
    $lum_timing[] = [$label, $now - $lum_t_last];
    $lum_t_last = $now;
}
$lum_show_timing = (lum_is_admin() && isset($_GET['timing']));

// Never cache the page in the browser
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

lum_connect('tenants', 'obis', 'manual', 'meters');
// Common areas are calculated exactly as on the consumption slips
lum_use('reporting', 'slips');

require_once LUM_ROOT . '/Dashboard functions/dashboard-engine.php';

$all_properties = [];
$obis_time_ranges = [];
$electrical_meter_types = []; 
$water_meter_types = [];

try {
    $core_db_conn = lum_db('properties');
    if (!$core_db_conn) throw new PDOException('sys_db_properties is not available');
    $info_db_conn = lum_db('information');
    if (!$info_db_conn) throw new PDOException('sys_db_information is not available');
    
    $stmt = $info_db_conn->query("SELECT obis_code, start_time, end_time FROM lum_obis_time_ranges");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $obis_time_ranges[$row['obis_code']] = [
            'start' => ' ' . $row['start_time'],
            'end'   => ' ' . $row['end_time']
        ];
    }
    
    $elec_types_stmt = $info_db_conn->query("SELECT type_name FROM lum_meter_type_electrical ORDER BY type_name ASC");
    $electrical_meter_types = $elec_types_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $water_types_stmt = $info_db_conn->query("SELECT type_name FROM lum_meter_type_water ORDER BY type_name ASC");
    $water_meter_types = $water_types_stmt->fetchAll(PDO::FETCH_COLUMN);
    
} catch(PDOException $e) {
    error_log('LUM dashboard DB connection failed: ' . $e->getMessage());
    die("Database connection failed. Please contact the system administrator.");
}
lum_mark('Database connections & settings');

// Default period: this month to date (on the 1st: from the start of last month)
$today_day = date('j'); 

if ($today_day == 1) {
    $default_start = date('Y-m-01', strtotime('-1 month'));
} else {
    $default_start = date('Y-m-01');
}

$default_end = date('Y-m-d');

$lum_valid_date = function ($d) {
    $dt = DateTime::createFromFormat('Y-m-d', (string)$d);
    return ($dt && $dt->format('Y-m-d') === $d);
};
$start_date = $_GET['start_date'] ?? $default_start;
$end_date = $_GET['end_date'] ?? $default_end;
if (!$lum_valid_date($start_date)) $start_date = $default_start;
if (!$lum_valid_date($end_date)) $end_date = $default_end;
if (strtotime($end_date) < strtotime($start_date)) { $start_date = $default_start; $end_date = $default_end; }
$selected_property = (string)($_GET['property'] ?? 'All');

// Page cache: the finished dashboard is kept for a few minutes per user and filter.
// "Refresh" on the page recalculates immediately.
const LUM_DASH_CACHE_SECONDS = 300;
$lum_cache_dir = sys_get_temp_dir() . '/lynx_dashboard_cache';
$lum_cache_key = hash('sha256', implode('|', [
    $_SESSION['user_id'] ?? '', $_SESSION['role'] ?? '', $_SESSION['assigned_properties'] ?? '',
    $selected_property, $start_date, $end_date, date('Y-m-d')
]));
$lum_cache_file = $lum_cache_dir . '/' . $lum_cache_key . '.html';
$lum_use_cache = !$lum_show_timing && !isset($_GET['refresh']);

// Note at the bottom of the page: calculation time and a refresh link
function lum_dash_cache_badge($html, $built_at) {
    $params = $_GET;
    unset($params['refresh'], $params['timing']);
    $params['refresh'] = 1;
    $url = '?' . http_build_query($params);
    $badge = "<div class='d-print-none' style='position:fixed; right:14px; bottom:10px; z-index:2000; background:#000; border:1px solid #333; color:#aaa; font-size:12px; padding:4px 10px;'>"
           . "Figures as at " . date('H:i', $built_at) . " &middot; <a href='" . htmlspecialchars($url, ENT_QUOTES) . "' style='color:#0dcaf0;'>Refresh</a></div>";
    $pos = strripos($html, '</body>');
    return ($pos === false) ? $html . $badge : substr($html, 0, $pos) . $badge . substr($html, $pos);
}

if ($lum_use_cache && is_file($lum_cache_file) && (time() - filemtime($lum_cache_file)) < LUM_DASH_CACHE_SECONDS) {
    $lum_cached = @file_get_contents($lum_cache_file);
    if ($lum_cached !== false && $lum_cached !== '') {
        echo lum_dash_cache_badge($lum_cached, filemtime($lum_cache_file));
        exit();
    }
}
ob_start(); // Captured for the page cache

$prop_stmt = $core_db_conn->query("SELECT COUNT(*) FROM lum_properties");
$total_properties = $prop_stmt->fetchColumn();

$tenant_stmt = $tenant_db_conn->query("SELECT COUNT(*) FROM lum_tenants");
$total_tenants = $tenant_stmt->fetchColumn();

$elec_meters_count = 0;
$water_meters_count = 0;
try {
    $e_res = $meter_db_conn->query("SELECT COUNT(*) FROM lum_meters WHERE meter_type LIKE '%Electrical%' OR meter_type LIKE '%Solar%' OR meter_type LIKE '%Generator%'");
    $elec_meters_count = $e_res->fetchColumn();
    
    $w_res = $meter_db_conn->query("SELECT COUNT(*) FROM lum_meters WHERE meter_type LIKE '%Water%'");
    $water_meters_count = $w_res->fetchColumn();
} catch (Exception $e) {}

// Properties come from the property register (Configurations)
$db_props_stmt = $core_db_conn->query("SELECT Property FROM lum_properties ORDER BY Property ASC");
$property_list = $db_props_stmt->fetchAll(PDO::FETCH_COLUMN);
sort($property_list);

if (!empty($_SESSION['assigned_properties'])) {
    $allowed_props = explode(',', $_SESSION['assigned_properties']);
    $property_list = array_intersect($property_list, $allowed_props);
    
    if (!in_array($selected_property, $allowed_props) && $selected_property !== 'All') {
        $selected_property = reset($allowed_props); 
    }
}

// Last month's financial totals (cron-financial-cache.php -> sys_db_information.lum_dashboard_financials)
// for the selected property, or the properties this user may see
$global_elec_r = 0.00;
$global_grand_r = 0.00;
$fin_info = '';
$fin_props = ($selected_property === 'All') ? $property_list : [$selected_property];
$fin_rows = null;
try {
    $fin_db = function_exists('lumSysDb') ? lumSysDb('sys_db_information') : null;
    if ($fin_db) {
        $fin_last = $fin_db->query("SELECT period_year, period_month FROM lum_dashboard_financials ORDER BY period_year DESC, period_month DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $fin_rows = [];
        if ($fin_last && !empty($fin_props)) {
            $fin_in = implode(',', array_fill(0, count($fin_props), '?'));
            $fin_st = $fin_db->prepare("SELECT * FROM lum_dashboard_financials WHERE period_year = ? AND period_month = ? AND property IN ($fin_in)");
            $fin_st->execute(array_merge([(int)$fin_last['period_year'], (int)$fin_last['period_month']], array_values($fin_props)));
            $fin_rows = $fin_st->fetchAll(PDO::FETCH_ASSOC);
        }
        $fin_journaled = 0;
        $fin_updated = '';
        foreach ($fin_rows as $fr) {
            $global_elec_r += (float)$fr['total_elec'];
            $global_grand_r += (float)$fr['grand_total'];
            if ($fr['source'] === 'journal') $fin_journaled++;
            if ($fr['calculated_at'] > $fin_updated) $fin_updated = $fr['calculated_at'];
        }
        if ($fin_last) {
            $fin_info = date('F Y', mktime(0, 0, 0, (int)$fin_last['period_month'], 1, (int)$fin_last['period_year']))
                      . ' &middot; ' . $fin_journaled . ' of ' . count($fin_rows) . ' journaled'
                      . ($fin_updated !== '' ? ' &middot; updated ' . date('d M H:i', strtotime($fin_updated)) : '');
        }
    }
} catch (\Throwable $e) {
    $fin_rows = null; // Table not there yet (the nightly cron job creates it)
}
if ($fin_rows === null || $fin_info === '') {
    $fin_info = 'Totals not calculated yet (nightly job)';
}

$property_area = 0;
try {
    if ($selected_property !== 'All') {
        $stmt = $core_db_conn->prepare("SELECT property_area FROM lum_properties WHERE Property = :prop");
        $stmt->execute(['prop' => $selected_property]);
        $property_area = floatval($stmt->fetchColumn());
    } else {
        if (!empty($_SESSION['assigned_properties'])) {
            $allowed = explode(',', $_SESSION['assigned_properties']);
            $inQuery = implode(',', array_fill(0, count($allowed), '?'));
            $stmt = $core_db_conn->prepare("SELECT SUM(property_area) FROM lum_properties WHERE Property IN ($inQuery)");
            $stmt->execute($allowed);
            $property_area = floatval($stmt->fetchColumn());
        } else {
            $stmt = $core_db_conn->query("SELECT SUM(property_area) FROM lum_properties");
            $property_area = floatval($stmt->fetchColumn());
        }
    }
} catch (Exception $e) {}

// Meters per property and role: sys_db_meters.lum_property_meters (Configurations -> Property Meters)
$dash = lumDashboardSettings($selected_property);
if ($selected_property === 'All') {
    $dash_properties = lumPropertyMeterProperties();
    $dash_auto_properties = lumDashboardAutoProperties();
    if (!empty($_SESSION['assigned_properties'])) {
        // Restricted users: only the meters of their own properties
        $dash_allowed = array_map('trim', explode(',', $_SESSION['assigned_properties']));
        $dash_properties = array_values(array_intersect($dash_properties, $dash_allowed));
        $dash_auto_properties = array_values(array_intersect($dash_auto_properties, $dash_allowed));
    }
} else {
    $dash_properties = [$selected_property];
    $dash_auto_properties = $dash['auto_meters'] ? [$selected_property] : [];
}
// Properties that also use every meter in the meter register (by meter type)
$dash_auto = lumDashboardAutoMeters($meter_db_conn, $dash_auto_properties);

$grid_meters = array_values(array_unique(array_merge(lumPropertyMeterList($dash_properties, 'grid'), $dash_auto['grid'])));
$solar_meters = array_values(array_unique(array_merge(lumPropertyMeterList($dash_properties, 'solar'), $dash_auto['solar'])));
// Check meters measure a property's total usage (solar = check - grid); in the 'All' view they are added per property
$check_meters = ($selected_property === 'All') ? [] : lumPropertyMeterList($dash_properties, 'elec_check');
$dash_check_properties = ($selected_property === 'All') ? array_values(array_intersect($dash_properties, lumPropertyMeterProperties('elec_check'))) : [];
$dash_has_solar = (!empty($solar_meters) || !empty($check_meters) || !empty($dash_check_properties));

lum_mark('Property list, analytics & meter lists');
if ($selected_property === 'All') {
    $stmt = $tenant_db_conn->prepare("SELECT tenant_electricalMeter_01, tenant_electricalMeter_02, tenant_electricalMeter_03, tenant_waterMeter_01, tenant_waterMeter_02, tenant_waterMeter_03 FROM lum_tenants");
    $stmt->execute();
} else {
    $stmt = $tenant_db_conn->prepare("SELECT tenant_electricalMeter_01, tenant_electricalMeter_02, tenant_electricalMeter_03, tenant_waterMeter_01, tenant_waterMeter_02, tenant_waterMeter_03 FROM lum_tenants WHERE tenant_property = :prop");
    $stmt->execute(['prop' => $selected_property]);
}
$tenants_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tenant_elec_meters = [];
foreach ($tenants_data as $td) {
    if (!empty(trim($td['tenant_electricalMeter_01']))) $tenant_elec_meters[] = trim($td['tenant_electricalMeter_01']);
    if (!empty(trim($td['tenant_electricalMeter_02']))) $tenant_elec_meters[] = trim($td['tenant_electricalMeter_02']);
    if (!empty(trim($td['tenant_electricalMeter_03']))) $tenant_elec_meters[] = trim($td['tenant_electricalMeter_03']);
}
$tenant_elec_meters = array_unique($tenant_elec_meters);

lum_mark('Tenant data');
// OBIS for the grid meters and for the tenant electrical usage (property dashboard settings)
$target_obis = $dash['grid_obis'] ?? $dash['elec_obis'];
$tenant_target_obis = $dash['tenant_obis'] ?? $target_obis;

$daily_grid = getBulkDailyUsage($obis_db_conn, $grid_meters, $start_date, $end_date, $target_obis);
$daily_solar = getBulkDailyUsage($obis_db_conn, $solar_meters, $start_date, $end_date, '1.1.1.8.0');
$daily_gen = getBulkDailyUsage($obis_db_conn, $tenant_elec_meters, $start_date, $end_date, '1.1.1.8.2');

$daily_total = [];
if (!empty($check_meters)) {
    // Check meters: total usage; solar = total - grid
    $daily_total = getBulkDailyUsage($obis_db_conn, $check_meters, $start_date, $end_date, '1.1.1.8.0');
    $daily_solar = subtractDailyArrays($daily_total, $daily_grid);
} else {
    // 'All': add the "virtual" solar (check - grid) of every property with check meters
    foreach ($dash_check_properties as $dash_cp) {
        $cp_check_daily = getBulkDailyUsage($obis_db_conn, lumPropertyMeterList([$dash_cp], 'elec_check'), $start_date, $end_date, '1.1.1.8.0');
        $cp_grid_daily = getBulkDailyUsage($obis_db_conn, lumPropertyMeterList([$dash_cp], 'grid'), $start_date, $end_date, '1.1.1.8.0');
        $daily_solar = sumDailyArrays([$daily_solar, subtractDailyArrays($cp_check_daily, $cp_grid_daily)]);
    }
    $daily_total = sumDailyArrays([$daily_grid, $daily_solar]);
}

lum_mark('Daily graph data');
$total_grid_kwh = getBulkMeterTotal($obis_db_conn, $grid_meters, $start_date, $end_date, $target_obis, $obis_time_ranges);
$total_solar_kwh = getBulkMeterTotal($obis_db_conn, $solar_meters, $start_date, $end_date, '1.1.1.8.0', $obis_time_ranges);

if (!empty($check_meters)) {
    $total_usage_kwh = getBulkMeterTotal($obis_db_conn, $check_meters, $start_date, $end_date, '1.1.1.8.0', $obis_time_ranges);
    // Virtual solar = total usage - grid
    $total_solar_kwh = max(0, $total_usage_kwh - $total_grid_kwh);
} else {
    foreach ($dash_check_properties as $dash_cp) {
        $cp_check_kwh = getBulkMeterTotal($obis_db_conn, lumPropertyMeterList([$dash_cp], 'elec_check'), $start_date, $end_date, '1.1.1.8.0', $obis_time_ranges);
        $cp_grid_kwh = getBulkMeterTotal($obis_db_conn, lumPropertyMeterList([$dash_cp], 'grid'), $start_date, $end_date, '1.1.1.8.0', $obis_time_ranges);
        $total_solar_kwh += max(0, $cp_check_kwh - $cp_grid_kwh);
    }
    $total_usage_kwh = $total_grid_kwh + $total_solar_kwh;
}

$total_gen_kwh = getBulkMeterTotal($obis_db_conn, $tenant_elec_meters, $start_date, $end_date, '1.1.1.8.2', $obis_time_ranges);

$all_tenant_e_meters = [];
$total_tenant_water = 0;

// Property water meters (water graph): lum_property_meters, plus the meter register for "auto" properties
$prop_water_meters = array_values(array_unique(array_merge(lumPropertyMeterList($dash_properties, 'water_main'), $dash_auto['water_main'])));

lum_mark('Grid / solar / generator totals');
// Tenant water meters: period totals in one query
$water_serials_for_monthly_fetch = [];
foreach ($tenants_data as $td) {
    $w_meters = array_filter([$td['tenant_waterMeter_01'], $td['tenant_waterMeter_02'], $td['tenant_waterMeter_03']]);
    foreach ($w_meters as $wm) {
        $water_serials_for_monthly_fetch[] = trim($wm);
        if (!in_array(trim($wm), $prop_water_meters)) {
            $prop_water_meters[] = trim($wm);
        }
    }
}
$water_serials_for_monthly_fetch = array_unique($water_serials_for_monthly_fetch);
$water_monthly_obis = getIndividualMeterTotals($obis_db_conn, $water_serials_for_monthly_fetch, $start_date, $end_date, '8.1.1.0.0', $obis_time_ranges);
// Meters that have OBIS readings in the period: a zero total is real, no slow fallback needed
$water_with_period_data = getSerialsWithData($obis_db_conn, $water_serials_for_monthly_fetch, $start_date, $end_date, '8.1.1.0.0');

foreach ($tenants_data as $td) {
    $e_meters = array_filter([$td['tenant_electricalMeter_01'], $td['tenant_electricalMeter_02'], $td['tenant_electricalMeter_03']]);
    foreach ($e_meters as $em) {
        $all_tenant_e_meters[] = $em;
    }
    
    $w_meters = array_filter([$td['tenant_waterMeter_01'], $td['tenant_waterMeter_02'], $td['tenant_waterMeter_03']]);
    foreach ($w_meters as $wm) {
        $serial = trim($wm);
        
        // OBIS totals first; the manual-reading engine is much slower
        $clean_w = @preg_replace('/[^a-zA-Z0-9]/', '', $serial);
        if (isset($water_monthly_obis[$serial]) && $water_monthly_obis[$serial] > 0) {
            $total_tenant_water += $water_monthly_obis[$serial];
        } elseif (!isset($water_with_period_data[$clean_w])) {
            // No OBIS readings at all (manually read meter): use the manual-reading engine
            $w_res = getRealWaterReadings($obis_db_conn, $manual_db_conn, $serial, $start_date, $end_date);
            if ($w_res) {
                $total_tenant_water += $w_res['kl'];
            }
        }
    }
}

lum_mark('Tenant water totals');
$total_tenant_elec = getBulkMeterTotal($obis_db_conn, array_unique($all_tenant_e_meters), $start_date, $end_date, $tenant_target_obis, $obis_time_ranges);
$daily_water = getBulkDailyUsage($obis_db_conn, $prop_water_meters, $start_date, $end_date, '8.1.1.0.0');

lum_mark('Tenant electrical total & daily water');
// Common areas, calculated exactly as on the consumption slips (property Billing Settings).
// null = not calculated here: the 'All' view, or a property whose common area is entered by hand on the slip.
$elec_ca = null;
$water_ca = null;
$elec_ca_manual = false;
$water_ca_manual = false;
if ($selected_property !== 'All' && $dash['tenant_cards']) {
    $dash_billing = lumPropertySettings($selected_property);
    if ($dash_billing['elec_common_area_method'] !== 'manual') {
        $elec_ca = lumSlipElecCommonArea($selected_property, $start_date, $end_date, $target_obis, $obis_db_conn, $tenant_db_conn);
    } else {
        $elec_ca_manual = true;
    }
    if ($dash_billing['water_common_area_method'] !== 'manual') {
        $water_ca = lumSlipWaterCommonArea($selected_property, $start_date, $end_date, $obis_db_conn, $manual_db_conn, $tenant_db_conn);
    } else {
        $water_ca_manual = true;
    }
}

$chart_labels = []; 
$chart_grid = [];
$chart_solar = []; 
$chart_gen = [];
$chart_total = []; 
$chart_water = [];
$period = new DatePeriod(new DateTime($start_date), new DateInterval('P1D'), (new DateTime($end_date))->modify('+1 day'));
foreach ($period as $dt) {
    $d = $dt->format("Y-m-d");
    $chart_labels[] = $dt->format("d M");
    $chart_grid[] = round($daily_grid[$d] ?? 0, 2);
    $chart_solar[] = round($daily_solar[$d] ?? 0, 2);
    $chart_gen[] = round($daily_gen[$d] ?? 0, 2);
    $chart_total[] = round($daily_total[$d] ?? 0, 2);
    $chart_water[] = round($daily_water[$d] ?? 0, 2);
}

lum_mark('Common areas & chart');
// Meters for the tables
$elec_table_data = [];
$water_table_data = [];

try {
    if ($selected_property === 'All') {
        $e_stmt = $meter_db_conn->prepare("SELECT * FROM vw_active_meters_combined WHERE meter_type LIKE '%Electrical%' OR meter_type LIKE '%Solar%' OR meter_type LIKE '%Generator%' ORDER BY active_property ASC, meter_serial ASC");
        $e_stmt->execute();
        $elec_table_data = $e_stmt->fetchAll(PDO::FETCH_ASSOC);

        $w_stmt = $meter_db_conn->prepare("SELECT * FROM vw_active_meters_combined WHERE meter_type LIKE '%Water%' ORDER BY active_property ASC, meter_serial ASC");
        $w_stmt->execute();
        $water_table_data = $w_stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $e_stmt = $meter_db_conn->prepare("SELECT * FROM vw_active_meters_combined WHERE active_property = :prop AND (meter_type LIKE '%Electrical%' OR meter_type LIKE '%Solar%' OR meter_type LIKE '%Generator%') ORDER BY meter_serial ASC");
        $e_stmt->execute(['prop' => $selected_property]);
        $elec_table_data = $e_stmt->fetchAll(PDO::FETCH_ASSOC);

        $w_stmt = $meter_db_conn->prepare("SELECT * FROM vw_active_meters_combined WHERE active_property = :prop AND meter_type LIKE '%Water%' ORDER BY meter_serial ASC");
        $w_stmt->execute(['prop' => $selected_property]);
        $water_table_data = $w_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

lum_mark('Meter table lists');
$elec_serials_only = array_column($elec_table_data, 'meter_serial');

$usage_11180 = getIndividualMeterTotals($obis_db_conn, $elec_serials_only, $start_date, $end_date, '1.1.1.8.0', $obis_time_ranges);
$usage_11181 = getIndividualMeterTotals($obis_db_conn, $elec_serials_only, $start_date, $end_date, '1.1.1.8.1', $obis_time_ranges);
$usage_11182 = getIndividualMeterTotals($obis_db_conn, $elec_serials_only, $start_date, $end_date, '1.1.1.8.2', $obis_time_ranges);

$prev_date = date('Y-m-d', strtotime($end_date . ' -1 day'));
$usage_11180_daily = getDailyMeterTotals($obis_db_conn, $elec_serials_only, $end_date, '1.1.1.8.0', $obis_time_ranges);
$usage_11180_prev_daily = getDailyMeterTotals($obis_db_conn, $elec_serials_only, $prev_date, '1.1.1.8.0', $obis_time_ranges);
$pacing_11180 = getPacingTrends($obis_db_conn, $elec_serials_only, $end_date, $prev_date, '1.1.1.8.0', $obis_time_ranges, $usage_11180_daily, $usage_11180_prev_daily);
$elec_statuses = getMeterStatuses($obis_db_conn, $elec_serials_only, '1.1.1.8.0');

lum_mark('Electrical meter table figures');
$water_serials_only = array_column($water_table_data, 'meter_serial');
$water_daily_obis = getDailyMeterTotals($obis_db_conn, $water_serials_only, $end_date, '8.1.1.0.0', $obis_time_ranges);
$water_prev_daily_obis = getDailyMeterTotals($obis_db_conn, $water_serials_only, $prev_date, '8.1.1.0.0', $obis_time_ranges);

// Period totals for table meters that were not part of the tenant bulk fetch above
$lum_missing_w = array_values(array_diff(array_map('trim', $water_serials_only), array_keys($water_monthly_obis)));
if (!empty($lum_missing_w)) {
    $water_monthly_obis = $water_monthly_obis + getIndividualMeterTotals($obis_db_conn, $lum_missing_w, $start_date, $end_date, '8.1.1.0.0', $obis_time_ranges);
}
// Which table meters have OBIS readings (for the period, and around the last two days)
$water_tbl_period_data = getSerialsWithData($obis_db_conn, $water_serials_only, $start_date, $end_date, '8.1.1.0.0');
$water_tbl_recent_data = getSerialsWithData($obis_db_conn, $water_serials_only, $prev_date, $end_date, '8.1.1.0.0');

$water_monthly_usage = [];
$water_daily_usage = [];
$water_prev_daily_usage = [];

foreach ($water_table_data as $m) {
    $serial = trim($m['meter_serial'] ?? '');
    if (!empty($serial)) {
        $clean_w = @preg_replace('/[^a-zA-Z0-9]/', '', $serial);
        
        // Period usage: OBIS readings, otherwise the manual-reading engine
        if (isset($water_monthly_obis[$serial]) && $water_monthly_obis[$serial] > 0) {
            $water_monthly_usage[$serial] = $water_monthly_obis[$serial];
        } elseif (isset($water_tbl_period_data[$clean_w])) {
            $water_monthly_usage[$serial] = 0.00; // Has OBIS readings: no usage in the period
        } else {
            $w_res = getRealWaterReadings($obis_db_conn, $manual_db_conn, $serial, $start_date, $end_date);
            $water_monthly_usage[$serial] = $w_res ? (float)$w_res['kl'] : 0.00;
        }

        // Today's usage
        if (isset($water_daily_obis[$serial]) && $water_daily_obis[$serial] > 0) {
            $water_daily_usage[$serial] = $water_daily_obis[$serial];
        } elseif (isset($water_tbl_recent_data[$clean_w])) {
            $water_daily_usage[$serial] = 0.00; // Has OBIS readings: no usage today
        } else {
            $w_d = getRealWaterReadings($obis_db_conn, $manual_db_conn, $serial, $end_date, $end_date);
            $water_daily_usage[$serial] = $w_d ? (float)$w_d['kl'] : 0.00;
        }

        // Yesterday's usage
        if (isset($water_prev_daily_obis[$serial]) && $water_prev_daily_obis[$serial] > 0) {
            $water_prev_daily_usage[$serial] = $water_prev_daily_obis[$serial];
        } elseif (isset($water_tbl_recent_data[$clean_w])) {
            $water_prev_daily_usage[$serial] = 0.00; // Has OBIS readings: no usage yesterday
        } else {
            $w_p = getRealWaterReadings($obis_db_conn, $manual_db_conn, $serial, $prev_date, $prev_date);
            $water_prev_daily_usage[$serial] = $w_p ? (float)$w_p['kl'] : 0.00;
        }
    }
}

$water_pacing_81100 = getPacingTrends($obis_db_conn, $water_serials_only, $end_date, $prev_date, '8.1.1.0.0', $obis_time_ranges, $water_daily_usage, $water_prev_daily_usage);
$water_statuses = getMeterStatuses($obis_db_conn, $water_serials_only, '8.1.1.0.0');
lum_mark('Water meter table figures');

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>LYNX Utility Management Dashboard</title>
  <link rel="icon" type="image/x-icon" href="favicon.ico">
    <?php include LUM_ROOT . '/Layout/head-assets.php'; ?>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ" crossorigin="anonymous"></script>
  <style>
        * { 
        margin: 0; 
        padding: 0; 
        box-sizing: border-box; 
        border-radius: 0 !important; 
        font-weight: normal !important;
        text-transform: none !important;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif !important;
    }
        ::-webkit-scrollbar-track { background: #0a0a0a; border-radius: 0; }
        ::-webkit-scrollbar-thumb { background: #4a4a4a; border-radius: 0; }
        body { overflow: hidden; background: #121212; color: #ffffff; }
        .navbar { background-color: #000000; border-bottom: 1px solid #333333 !important; z-index: 1050; }
        .d-flex-wrapper { display: flex; width: 100%; overflow: hidden; }
        .sidebar { 
        width: 260px;
        min-width: 260px;
        height: calc(100vh - 46px); 
        background: linear-gradient(180deg, #000 0%, #0f0f0f 100%); 
        padding: 30px 15px; 
        overflow-y: auto; 
        transition: margin-left 0.35s cubic-bezier(0.25, 0.8, 0.25, 1); 
        z-index: 1000;
    }
        .sidebar.collapsed { margin-left: -260px; }
        .sidebar .nav-link { color: #b0b0b0; padding: 14px 18px; margin-bottom: 8px; transition: all 0.3s ease; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background: linear-gradient(90deg, #1a1a1a, #222); color: #fff; transform: translateX(4px); }
        .sidebar .nav-link i { color: #ff3b3b; }
        .content-wrapper { 
        flex-grow: 1;
        min-width: 0; 
        height: calc(100vh - 46px); 
        overflow-y: auto; 
        padding: 30px; 
        transition: all 0.35s cubic-bezier(0.25, 0.8, 0.25, 1); 
    }
        .card-stat { border: 1px solid #333; transition: all 0.3s ease; background: #1e1e1e; box-shadow: 0 4px 6px rgba(0,0,0,0.2); }
        .card-stat:hover { transform: translateY(-4px); box-shadow: 0 8px 15px rgba(0,0,0,0.4); }
        .card-stat.revenue { border-left: 5px solid #198754; }
        .card-stat.users { border-left: 5px solid #ff3b3b; }
        .card-stat.conversion { border-left: 5px solid #fd7e14; }
        .card-stat.water { border-left: 5px solid #0dcaf0; }
        .utility-card { background: linear-gradient(135deg, #1e1e1e, #2d2d2d); color: white; border: 1px solid #333; padding: 24px; transition: 0.3s; height: 100%; box-shadow: 0 4px 10px rgba(0,0,0,0.2); }
        .utility-card:hover { transform: translateY(-4px); }
        .utility-icon { width: 30px; height: 30px; background: rgba(255,255,255,0.05); display: flex; align-items: center; justify-content: center; font-size: 16px; margin-bottom: 8px; }
        .filter-box { background: #1e1e1e; border: 1px solid #333; padding: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.2); margin-bottom: 25px; }
        .form-control, .form-select { background-color: #1a1a1a !important; border: 1px solid #444 !important; color: #fff !important; }
        .form-control:focus, .form-select:focus { border-color: #e3000f !important; box-shadow: 0 0 0 0.25rem rgba(227, 0, 15, 0.25) !important; }
        .node-box { background: linear-gradient(135deg, #1a1a1a, #222222); border: 2px solid; padding: 20px; text-align: center; min-width: 240px; z-index: 2; box-shadow: 0 4px 10px rgba(0,0,0,0.3); }
        .flow-line-wrapper { display: flex; align-items: center; width: 100%; margin-right: -15px; }
        .flow-line { height: 6px; background: repeating-linear-gradient(to right, #0dcaf0 0, #0dcaf0 15px, transparent 15px, transparent 25px); background-size: 25px 100%; animation: flowAnim 1s linear infinite; width: 100%; opacity: 0.8; }
        @keyframes flowAnim { 0% { background-position: 0 0; }
        100% { background-position: 25px 0; }
        }
        @media (max-width: 768px) { 
        body { overflow: auto; }
        .sidebar { position: absolute; height: calc(100vh - 46px); }
        .content-wrapper { padding: 15px; }
        }
    </style>
</head>
<body>

  <?php
  $lum_nav_left = '<button class="btn btn-link text-white p-0 me-3 lynx-brand-hover" id="sidebarToggle" title="Toggle Sidebar"> <i class="bi bi-list fs-4"></i> </button>';
  include LUM_ROOT . '/Layout/top-navbar.php';
  ?>

  <div class="d-flex-wrapper">

    <nav id="sidebar" class="sidebar shadow collapsed">
      <ul class="nav flex-column px-2">
        <li class="nav-item"><a class="nav-link active" href="https://lynx-um.co.za/index.php"><i class="bi bi-speedometer2 me-2"></i> Dashboard</a></li>
        <li class="nav-item"><a class="nav-link" href="https://lynx-um.co.za/Meter Management/meter-overview.php"><i class="bi bi-speedometer me-2"></i> Meters</a></li>
        <li class="nav-item"><a class="nav-link" href="https://lynx-um.co.za/Tenant Management/tenant-overview.php"><i class="bi bi-people me-2"></i> Tenants</a></li>
        <li class="nav-item">
            <a class="nav-link d-flex justify-content-between align-items-center" data-bs-toggle="collapse" href="#financialReportingMenu" role="button" aria-expanded="false" aria-controls="financialReportingMenu">
                <span><i class="bi bi-file-earmark-bar-graph me-2"></i> Financial Reporting</span>
                <i class="bi bi-chevron-down small"></i>
            </a>
            <div class="collapse" id="financialReportingMenu">
                <ul class="nav flex-column ms-4">
                    <li class="nav-item"><a class="nav-link py-1" href="https://lynx-um.co.za/Reporting/financial-reporting-overview.php"><i class="bi bi-calendar-month me-2"></i> Monthly Financial Report</a></li>
                    <li class="nav-item"><a class="nav-link py-1" href="https://lynx-um.co.za/Reporting/financial-reporting-comparison.php"><i class="bi bi-arrow-left-right me-2"></i> Financial Report Comparison</a></li>
                </ul>
            </div>
        </li>
        <li class="nav-item"><a class="nav-link" href="https://lynx-um.co.za/Manual Readings/manual-water-overview.php"><i class="bi bi-pencil-square me-2"></i> Manual Readings</a></li>
<?php if (lum_can('audit_log', 'view')): // The audit trail: administrators only, by the access rules ?>
        <li class="nav-item"><a class="nav-link" href="https://lynx-um.co.za/Configs/audit-log.php"><i class="bi bi-journal-text me-2"></i> Audit Trail</a></li>
<?php endif; ?>
<?php if (lum_can('configs', 'edit')): // Configuration is for users who may change settings ?>
        <li class="nav-item"><a class="nav-link" href="https://lynx-um.co.za/Configs/view-configs.php"><i class="bi bi-gear me-2"></i> Configuration</a></li>
<?php endif; ?>
<?php if (lum_can('imports', 'view')): // Imports live outside Configuration, so Standard users can reach them ?>
        <li class="nav-item"><a class="nav-link" href="https://lynx-um.co.za/Import/bulk-import.php"><i class="bi bi-upload me-2"></i> Import</a></li>
<?php endif; ?>
<?php if (lum_can('tariffs', 'view')): // Tariffs can be looked up without opening Configuration ?>
        <li class="nav-item"><a class="nav-link" href="https://lynx-um.co.za/Tarrifs/view-tariffs.php"><i class="bi bi-cash-coin me-2"></i> View Tariffs</a></li>
<?php endif; ?>
      </ul>
    </nav>

    <main id="mainContent" class="content-wrapper">
      
      <div class="filter-box">
          <form method="GET" action="" class="row g-3 align-items-end">
              <div class="col-md-4">
                  <label class="form-label small text-light mb-1">Select Property</label>
                  <select name="property" class="form-select" onchange="this.form.submit()">
                      <option value="All" <?php echo $selected_property === 'All' ? 'selected' : ''; ?>>All Properties...</option>
                      <?php foreach($property_list as $prop): ?>
                          <option value="<?php echo htmlspecialchars($prop); ?>" <?php echo $selected_property === $prop ? 'selected' : ''; ?>><?php echo htmlspecialchars($prop); ?></option>
                      <?php endforeach; ?>
                  </select>
              </div>
              <div class="col-md-4">
                  <label class="form-label small text-light mb-1">Start Date</label>
                  <input type="date" class="form-control" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" onchange="this.form.submit()">
              </div>
              <div class="col-md-4">
                  <label class="form-label small text-light mb-1">End Date</label>
                  <input type="date" class="form-control" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" onchange="this.form.submit()">
              </div>
          </form>
      </div>

      <div class="d-flex justify-content-between align-items-center border-bottom border-secondary pb-2 mb-3 mt-4" data-bs-toggle="collapse" data-bs-target="#overviewCollapse" aria-expanded="false" aria-controls="overviewCollapse" style="cursor: pointer;" id="overviewToggleBtn">
          <h5 class="mb-0 fs-5" style="color: #0dcaf0;">
              <i class="bi bi-bar-chart-fill me-2"></i> Overview
          </h5>
          <i class="bi bi-plus-circle fs-5 text-info toggle-icon" style="transition: transform 0.2s;"></i>
      </div>

      <div class="collapse" id="overviewCollapse">
          <div class="row g-4 mb-4 mt-2">
            <div class="col-md-6 col-xl-4"><div class="card card-stat revenue h-100"><div class="card-body"><h6 class="text-secondary" style="font-size: 0.8rem;">Total Properties</h6><h2 class="mb-0 text-white fs-3"><?php echo number_format($total_properties); ?></h2></div></div></div>
            <div class="col-md-6 col-xl-4"><div class="card card-stat users h-100"><div class="card-body"><h6 class="text-secondary" style="font-size: 0.8rem;">Electrical Meters Active</h6><h2 class="mb-0 text-white fs-3"><?php echo number_format($elec_meters_count); ?></h2></div></div></div>
            <div class="col-md-6 col-xl-4"><div class="card card-stat water h-100"><div class="card-body"><h6 class="text-secondary" style="font-size: 0.8rem;">Water Meters Active</h6><h2 class="mb-0 text-white fs-3"><?php echo number_format($water_meters_count); ?></h2></div></div></div>
            <div class="col-md-6 col-xl-4"><div class="card card-stat conversion h-100"><div class="card-body"><h6 class="text-secondary" style="font-size: 0.8rem;">Registered Tenants</h6><h2 class="mb-0 text-white fs-3"><?php echo number_format($total_tenants); ?></h2></div></div></div>
            <div class="col-md-6 col-xl-4"><div class="card card-stat h-100" style="border-left: 5px solid #ffc107;"><div class="card-body"><h6 class="text-secondary" style="font-size: 0.8rem;">Total Electricity (R)</h6><h2 class="mb-0 text-white fs-3">R <?php echo number_format($global_elec_r, 2); ?></h2></div></div></div>
            <div class="col-md-6 col-xl-4"><div class="card card-stat h-100" style="border-left: 5px solid #e3000f; background: linear-gradient(135deg, #1e1e1e, #2a0000);"><div class="card-body"><h6 class="text-secondary" style="font-size: 0.8rem;">Grand Total Billed</h6><h2 class="mb-0 text-white fs-3">R <?php echo number_format($global_grand_r, 2); ?></h2><?php if ($fin_info !== ''): ?><div class="small text-secondary mt-1"><?php echo $fin_info; ?></div><?php endif; ?></div></div></div>
          </div>
      </div>

      <h5 class="border-bottom border-secondary pb-2 mb-3 mt-4 fs-5" style="color: #ff3b3b;"><i class="bi bi-lightning-charge-fill me-2"></i> <?php echo htmlspecialchars($selected_property); ?> Overview</h5>
      <div class="row g-4 mb-4">
        <div class="col-md-3">
          <div class="utility-card">
            <div class="utility-icon text-danger">
                <svg xmlns="http://www.w3.org/2000/svg" width="1.4em" height="1.4em" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2L8 10H3v2h4l-1.5 4H3v2h1.75l-2.25 4h2.25l1.5-2.66h11.5l1.5 2.66h2.25l-2.25-4H21v-2h-2.5l-1.5-4H21v-2h-5L12 2zm0 3.2l2.4 4.8H9.6L12 5.2zM8.35 12h7.3l1.5 4H6.85l1.5-4zm-2.25 6h11.8l1 1.77H6.1l1-1.77z"/></svg>
            </div>
            <h6 class="text-white-50 mb-1 fs-6">Grid Electrical Usage</h6>
            <h3 class="mb-0 text-white fs-4"><?php echo number_format($total_grid_kwh, 2); ?> <span class="fs-6 text-white-50">kWh</span></h3>
          </div>
        </div>

        <?php if ($dash_has_solar): ?>
        <div class="col-md-3">
          <div class="utility-card border border-warning" style="border-width: 2px !important;">
            <div class="utility-icon" style="color: #ffc107;"><i class="bi bi-sun-fill"></i></div>
            <h6 class="text-white-50 mb-1 fs-6">Solar Production</h6>
            <h3 class="mb-0 text-white fs-4"><?php echo number_format($total_solar_kwh, 2); ?> <span class="fs-6 text-white-50">kWh</span></h3>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($dash['has_generator']): ?>
        <div class="col-md-3">
          <div class="utility-card border border-info" style="border-width: 2px !important;">
            <div class="utility-icon" style="color: #0dcaf0;">
                <svg xmlns="http://www.w3.org/2000/svg" width="1.4em" height="1.4em" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M16 2h3v3h1v3h-3V6h-1V2z"/>
                  <rect x="2" y="8" width="20" height="12" rx="1"/>
                  <rect x="3" y="20" width="3" height="2"/>
                  <rect x="18" y="20" width="3" height="2"/>
                  <rect x="4" y="10" width="8" height="1" fill="#1e1e1e"/>
                  <rect x="4" y="12" width="8" height="1" fill="#1e1e1e"/>
                  <rect x="4" y="14" width="8" height="1" fill="#1e1e1e"/>
                  <rect x="4" y="16" width="8" height="1" fill="#1e1e1e"/>
                  <path d="M13.5 10a1.5 1.5 0 1 0 3 0 1.5 1.5 0 0 0-3 0zm1.5.5v-1l.5.5h-.5z" fill="#1e1e1e"/>
                  <path d="M17.5 10a1.5 1.5 0 1 0 3 0 1.5 1.5 0 0 0-3 0zm1.5.5v-1l-.5.5h.5z" fill="#1e1e1e"/>
                  <circle cx="16" cy="15" r="3" fill="#1e1e1e"/>
                  <path d="M16 13l-1.5 2.5h1.5v2L17.5 15H16v-2z" fill="currentColor"/>
                </svg>
            </div>
            <h6 class="text-white-50 mb-1 fs-6">Generator Usage</h6>
            <h3 class="mb-0 text-white fs-4"><?php echo number_format($total_gen_kwh, 2); ?> <span class="fs-6 text-white-50">kWh</span></h3>
          </div>
        </div>
        <?php endif; ?>

        <div class="col-md-3">
          <div class="utility-card" style="background: linear-gradient(135deg, #198754, #146c43);">
            <div class="utility-icon text-white"><i class="bi bi-lightning-charge-fill"></i></div>
            <h6 class="text-white-50 mb-1 fs-6">
                <?php echo ($selected_property === 'All') ? 'Total Properties Usage (multiple)' : 'Total Property Usage'; ?>
            </h6>
            <h3 class="mb-0 text-white fs-4"><?php echo number_format($total_usage_kwh, 2); ?> <span class="fs-6 text-white-50">kWh</span></h3>
            <?php if ($total_usage_kwh > 0 && $total_solar_kwh > 0): ?>
                <div class="mt-3 pt-3 border-top" style="border-color: rgba(255,255,255,0.2) !important;">
                    <div class="row g-0 align-items-center">
                        <div class="col-6 border-end pe-2" style="border-color: rgba(255,255,255,0.2) !important;">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="small text-white-50"><i class="bi bi-sun-fill text-warning me-1"></i> Solar</span>
                                <span class="badge bg-warning text-dark"><?php echo number_format(($total_solar_kwh / $total_usage_kwh) * 100, 1); ?>%</span>
                            </div>
                        </div>
                        <div class="col-6 ps-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="small text-white-50">kWh/m&sup2;</span>
                                <?php if($property_area > 0): ?>
                                    <span class="text-white small fw-bold"><?php echo number_format($total_usage_kwh / $property_area, 2); ?></span>
                                <?php else: ?>
                                    <span class="text-warning small fw-bold" style="font-size:0.75rem;">Update Area</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="mt-3 pt-3 border-top" style="border-color: rgba(255,255,255,0.2) !important;">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="small text-white-50">kWh/m&sup2;</span>
                        <?php if($property_area > 0): ?>
                            <span class="text-white fw-bold"><?php echo number_format($total_usage_kwh / $property_area, 2); ?></span>
                        <?php else: ?>
                            <span class="text-warning small fw-bold">Update Area</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <?php if ($dash['tenant_cards']): ?>
      <h5 class="border-bottom border-secondary pb-2 mb-3 mt-5 fs-5" style="color: #0dcaf0;"><i class="bi bi-people-fill me-2"></i> Tenant & Common Area Analysis</h5>
      <div class="row g-4 mb-4">
          <div class="col-md-3">
              <div class="utility-card border border-primary" style="border-width: 2px !important;">
                  <h6 class="text-white-50 mb-1 fs-6">Total Tenant Elec. Usage</h6>
                  <h3 class="mb-0 text-white fs-4"><?php echo number_format($total_tenant_elec, 2); ?> <span class="fs-6 text-white-50">kWh</span></h3>
              </div>
          </div>
          <div class="col-md-3">
              <div class="utility-card border border-secondary" style="border-width: 2px !important;">
                  <h6 class="text-white-50 mb-1 fs-6">Electrical Common Area</h6>
                  <h3 class="mb-0 text-white fs-4"><?php if ($elec_ca === null): ?>&ndash;<?php else: ?><?php echo number_format($elec_ca, 2); ?> <span class="fs-6 text-white-50">kWh</span><?php endif; ?></h3>
                  <?php if ($elec_ca_manual): ?><div class="small text-white-50">Entered on the slip</div><?php elseif ($selected_property === 'All'): ?><div class="small text-white-50">Select a property</div><?php endif; ?>
              </div>
          </div>
          <div class="col-md-3">
              <div class="utility-card border border-info" style="border-width: 2px !important;">
                  <h6 class="text-white-50 mb-1 fs-6">Total Tenant Water Usage</h6>
                  <h3 class="mb-0 text-white fs-4"><?php echo number_format($total_tenant_water, 2); ?> <span class="fs-6 text-white-50">kL</span></h3>
              </div>
          </div>
          <div class="col-md-3">
              <div class="utility-card border border-secondary" style="border-width: 2px !important;">
                  <h6 class="text-white-50 mb-1 fs-6">Water Common Area</h6>
                  <h3 class="mb-0 text-white fs-4"><?php if ($water_ca === null): ?>&ndash;<?php else: ?><?php echo number_format($water_ca, 2); ?> <span class="fs-6 text-white-50">kL</span><?php endif; ?></h3>
                  <?php if ($water_ca_manual): ?><div class="small text-white-50">Entered on the slip</div><?php elseif ($selected_property === 'All'): ?><div class="small text-white-50">Select a property</div><?php endif; ?>
              </div>
          </div>
      </div>
      <?php endif; ?>

      <?php
      // Water flow diagram: meters with the roles flow_in (sources) and flow_out (combined / destination)
      $flow_in = ($selected_property !== 'All') ? lumPropertyMeterNodes($selected_property, 'flow_in') : [];
      $flow_out = ($selected_property !== 'All') ? lumPropertyMeterNodes($selected_property, 'flow_out') : [];
      if (!empty($flow_in) || !empty($flow_out)):
          $flow_kl = function ($serial) use ($obis_db_conn, $manual_db_conn, $start_date, $end_date) {
              $r = getRealWaterReadings($obis_db_conn, $manual_db_conn, $serial, $start_date, $end_date);
              return $r ? number_format($r['kl'], 2) : '0.00';
          };
          $flow_color = function ($c, $default) { return isset(LUM_PROPERTY_METER_COLORS[$c]) ? $c : $default; };
          $flow_title = ($dash['flow_title'] !== '') ? $dash['flow_title'] : 'Water Flow Overview';
      ?>
      <div class="d-flex justify-content-between align-items-center border-bottom border-secondary pb-2 mb-3 mt-5" data-bs-toggle="collapse" data-bs-target="#waterFlowCollapse" aria-expanded="false" aria-controls="waterFlowCollapse" style="cursor: pointer;">
          <h5 class="mb-0 fs-5" style="color: #0dcaf0;">
              <i class="bi bi-droplet-half me-2"></i> <?php echo htmlspecialchars($flow_title); ?>
          </h5>
          <i class="bi bi-plus-circle fs-5 text-info toggle-icon" style="transition: transform 0.2s;"></i>
      </div>

      <div class="collapse" id="waterFlowCollapse">
          <div class="row g-4 mb-4 mt-2">
              <div class="col-12">
                  <div class="card border border-secondary shadow-sm p-4" style="background-color: #121212; overflow: hidden;">
                      <div class="d-flex flex-column flex-md-row w-100 justify-content-center align-items-center gap-0">
                          <div class="d-flex flex-column gap-4 w-100" style="max-width: 500px;">
                              <?php foreach ($flow_in as $fn): $fc = $flow_color($fn['color'], 'info'); $fhex = LUM_PROPERTY_METER_COLORS[$fc]; ?>
                              <div class="d-flex align-items-center w-100">
                                  <div class="node-box border-<?php echo $fc; ?> flex-shrink-0">
                                      <i class="bi bi-<?php echo htmlspecialchars($fn['icon'] ?: 'droplet'); ?> text-<?php echo $fc; ?> fs-2 mb-2 d-block"></i>
                                      <h6 class="text-white-50 mb-1" style="font-size: 0.75rem;"><?php echo htmlspecialchars(strtoupper((string)$fn['label'])); ?></h6>
                                      <div class="badge bg-dark border border-secondary mb-2 px-3 py-1"><?php echo htmlspecialchars($fn['meter_serial']); ?></div>
                                      <h4 class="text-white mb-0 fs-4"><?php echo $flow_kl($fn['meter_serial']); ?> <span class="fs-6 text-white-50">kL</span></h4>
                                  </div>
                                  <?php if (!empty($flow_out)): ?>
                                  <div class="flow-line-wrapper flex-grow-1 d-none d-md-flex px-2">
                                      <div class="flow-line"<?php echo ($fc !== 'info') ? ' style="background-image: repeating-linear-gradient(to right, ' . $fhex . ' 0, ' . $fhex . ' 15px, transparent 15px, transparent 25px);"' : ''; ?>></div>
                                      <i class="bi bi-caret-right-fill text-<?php echo $fc; ?> fs-3" style="margin-left: -6px;"></i>
                                  </div>
                                  <?php endif; ?>
                              </div>
                              <?php endforeach; ?>
                          </div>
                          <?php foreach ($flow_out as $fn): $fc = $flow_color($fn['color'], 'success'); ?>
                          <div class="mt-4 mt-md-0 flex-shrink-0" style="z-index: 2;">
                              <div class="node-box border-<?php echo $fc; ?>" style="min-width: 280px; padding: 40px 20px;">
                                  <i class="bi bi-<?php echo htmlspecialchars($fn['icon'] ?: 'speedometer'); ?> text-<?php echo $fc; ?> fs-1 mb-2 d-block"></i>
                                  <h6 class="text-white-50 mb-1" style="font-size: 0.85rem;"><?php echo htmlspecialchars(strtoupper((string)$fn['label'])); ?></h6>
                                  <div class="badge bg-dark border border-secondary mb-3 px-3 py-1"><?php echo htmlspecialchars($fn['meter_serial']); ?></div>
                                  <h3 class="text-white mb-0 fs-3"><?php echo $flow_kl($fn['meter_serial']); ?> <span class="fs-5 text-white-50">kL</span></h3>
                              </div>
                          </div>
                          <?php endforeach; ?>
                      </div>
                  </div>
              </div>
          </div>
      </div>
      <?php endif; ?>

      <div class="card border border-secondary shadow-sm p-4 mb-5 mt-5" style="background-color: #1e1e1e; color: #fff;">
          <div class="d-flex justify-content-between align-items-center mb-4">
              <h5 class="mb-0 text-white fs-5">Daily Consumption Analysis</h5>
              <select id="mainChartFilter" class="form-select form-select-sm bg-dark text-white border-secondary" style="width: auto;">
                  <option value="all">Total Property Usage</option>
                  <option value="Grid Usage (kWh)">Grid Electrical Usage</option>
                  <?php if ($dash_has_solar): ?>
                      <option value="Solar Usage (kWh)">Solar Production</option>
                  <?php endif; ?>
                  <?php if ($dash['has_generator']): ?>
                      <option value="Generator Usage (kWh)">Generator Usage</option>
                  <?php endif; ?>
                  <option value="Total Water Usage (kL)">Total Water Consumption</option>
              </select>
          </div>
          <div style="height: 400px; width: 100%;">
              <canvas id="mainConsumptionChart"></canvas>
          </div>
      </div>

      <div class="d-flex justify-content-between align-items-center border-bottom border-secondary pb-2 mb-3 mt-5">
          <h5 class="mb-0 fs-5" style="color: #ffffff;">
              <i class="bi bi-speedometer2 me-2"></i> Active Property Meters
          </h5>
      </div>
      
      <div class="row g-4 mb-5">
          
          <div class="col-12 mb-4">
              <div class="d-flex justify-content-between align-items-center mb-3">
                  <h6 class="text-white-50 mb-0 fs-6"><i class="bi bi-lightning-charge text-danger me-2"></i> Electrical Meters</h6>
                  
                  <div style="width: 250px;">
                      <select class="form-select form-select-sm border-secondary text-light bg-dark fs-6" id="elecTypeFilter" onchange="filterElecTable()">
                          <option value="All">All Types</option>
                          <?php foreach ($electrical_meter_types as $t): ?>
                              <option value="<?php echo htmlspecialchars('Electrical - ' . $t); ?>"><?php echo htmlspecialchars('Electrical - ' . $t); ?></option>
                          <?php endforeach; ?>
                      </select>
                  </div>
              </div>
              
              <div class="table-container shadow border border-secondary overflow-hidden" style="background-color: #1e1e1e;">
                  <div class="table-responsive" style="max-height: 700px; overflow-y: auto;">
                      <table class="table table-dark table-hover table-borderless align-middle mb-0">
                          <thead style="position: sticky; top: 0; z-index: 10;">
                              <tr>
                                  <th class="text-nowrap" style="background-color: #1a1a1a; border-bottom: 2px solid #333; width: 6%;">Serial</th>
                                  <th class="text-nowrap" style="background-color: #1a1a1a; border-bottom: 2px solid #333; width: 12%;">Type</th>
                                  <th class="text-nowrap" style="background-color: #1a1a1a; border-bottom: 2px solid #333; width: 16%;">Tenant</th>
                                  <th class="text-end text-nowrap" style="background-color: #1a1a1a; border-bottom: 2px solid #333; border-left: 1px solid #444;" title="Standard Grid / Main Usage">1.1.1.8.0 <br><span class="text-white" style="font-size: 0.7rem; white-space: nowrap;"><?php echo date('M', strtotime($end_date)); ?> (kWh) Accum</span></th>
                                  <th class="text-end text-nowrap" style="background-color: #1a1a1a; border-bottom: 2px solid #333; border-right: 1px solid #444;" title="Electrical Usage Per Square Meter">kWh/m² <br><span class="text-warning" style="font-size: 0.7rem; white-space: nowrap;">Efficiency</span></th>
                                  <th class="text-end text-nowrap" style="background-color: #1a1a1a; border-bottom: 2px solid #333;" title="Tariff 1 Usage">1.1.1.8.1 <br><span class="text-white" style="font-size: 0.7rem; white-space: nowrap;"><?php echo date('M', strtotime($end_date)); ?> (kWh) Accum</span></th>
                                  <th class="text-end text-nowrap" style="background-color: #1a1a1a; border-bottom: 2px solid #333; border-right: 1px solid #444;" title="Tariff 2 / Generator Usage">1.1.1.8.2 <br><span class="text-white" style="font-size: 0.7rem; white-space: nowrap;"><?php echo date('M', strtotime($end_date)); ?> (kWh) Accum</span></th>
                                  <th class="text-end text-nowrap" style="background-color: #1a1a1a; border-bottom: 2px solid #333;" title="Daily Usage on Previous Date">1.1.1.8.0 <br><span class="text-white-50" style="font-size: 0.7rem; white-space: nowrap;">(kWh) Daily: <?php echo date('d M', strtotime($prev_date)); ?></span></th>
                                  <th class="text-end text-nowrap" style="background-color: #1a1a1a; border-bottom: 2px solid #333; border-right: 1px solid #444;" title="Daily Usage on End Date">1.1.1.8.0 <br><span class="text-info" style="font-size: 0.7rem; white-space: nowrap;">(kWh) Daily: <?php echo date('d M', strtotime($end_date)); ?></span></th>
                                  <th class="text-end text-nowrap" style="background-color: #1a1a1a; border-bottom: 2px solid #333; border-right: 1px solid #444;" title="Hourly Pacing Trend">Trend <br><span class="text-white" style="font-size: 0.7rem; white-space: nowrap;">(Run Rate)</span></th>
                                  <th class="text-end text-nowrap" style="background-color: #1a1a1a; border-bottom: 2px solid #333; border-right: 1px solid #444;" title="Latest recorded OBIS ping">Last Reading <br><span class="text-white-50" style="font-size: 0.7rem; white-space: nowrap;">1.1.1.8.0</span></th>
                                  <th class="text-center text-nowrap" style="background-color: #1a1a1a; border-bottom: 2px solid #333;" title="Network Status">Status <br><span class="text-white-50" style="font-size: 0.7rem; white-space: nowrap;">Network</span></th>
                              </tr>
                          </thead>
                          <tbody id="elecMetersTableBody">
                              <?php if (count($elec_table_data) > 0): ?>
                                  <?php foreach ($elec_table_data as $m): 
                                      $type_disp = str_replace('_', ' ', $m['meter_type'] ?? 'Unknown');
                                      
                                      $assign_disp = trim($m['active_tenant'] ?? '');
                                      $shop_disp = trim($m['active_shop'] ?? '');
                                      if (!empty($shop_disp)) {
                                          $assign_disp .= " (" . $shop_disp . ")";
                                      }
                                      if (empty($assign_disp) || $assign_disp === "()") {
                                          $assign_disp = trim($m['meter_location'] ?? 'Unassigned');
                                      }
                                      
                                      $serial = trim($m['meter_serial'] ?? '');
                                      
                                      $u_0_raw = isset($usage_11180[$serial]) ? (float)$usage_11180[$serial] : 0.00;
                                      $u_0 = number_format($u_0_raw, 2);
                                      
                                      $area = (float)($m['active_area'] ?? 0);
                                      $u_per_sqm = ($area > 0) ? ($u_0_raw / $area) : 0.00;
                                      $u_per_sqm_disp = number_format($u_per_sqm, 2);

                                      $u_1 = isset($usage_11181[$serial]) ? number_format($usage_11181[$serial], 2) : '0.00';
                                      $u_2 = isset($usage_11182[$serial]) ? number_format($usage_11182[$serial], 2) : '0.00';
                                      $u_0_prev_daily = isset($usage_11180_prev_daily[$serial]) ? number_format($usage_11180_prev_daily[$serial], 2) : '0.00';
                                      $u_0_daily = isset($usage_11180_daily[$serial]) ? number_format($usage_11180_daily[$serial], 2) : '0.00';
                                      
                                      $pacing_html = $pacing_11180[$serial] ?? '';
                                      
                                      $s_data = $elec_statuses[$serial] ?? null;
                                      $last_read_str = $s_data ? $s_data['last_reading'] : 'No Data';
                                      $status_str = $s_data ? $s_data['status'] : 'Offline';
                                      $status_color = $s_data ? $s_data['color'] : 'danger';
                                  ?>
                                      <tr>
                                          <td class="text-white"><?php echo htmlspecialchars($serial); ?></td>
                                          <td><span class="badge bg-danger text-white"><?php echo htmlspecialchars($type_disp); ?></span></td>
                                          <td>
                                              <?php if ($selected_property === 'All'): ?>
                                                  <div class="small text-info mb-1"><?php echo htmlspecialchars($m['active_property'] ?? ''); ?></div>
                                              <?php endif; ?>
                                              <div class="text-light" style="font-size: 0.85rem;"><?php echo htmlspecialchars($assign_disp); ?></div>
                                          </td>
                                          <td class="text-end text-light" style="border-left: 1px solid #444;"><?php echo $u_0; ?></td>
                                          <td class="text-end text-warning" style="border-right: 1px solid #444;"><?php echo $u_per_sqm_disp; ?></td>
                                          <td class="text-end text-light"><?php echo $u_1; ?></td>
                                          <td class="text-end text-light" style="border-right: 1px solid #444;"><?php echo $u_2; ?></td>
                                          <td class="text-end" style="color: #a0a0a0;"><?php echo $u_0_prev_daily; ?></td>
                                          <td class="text-end text-info" style="border-right: 1px solid #444;"><?php echo $u_0_daily; ?></td>
                                          <td class="text-end text-nowrap" style="border-right: 1px solid #444;"><?php echo $pacing_html; ?></td>
                                          <td class="text-end text-secondary" style="font-size: 0.8rem; border-right: 1px solid #444;"><?php echo $last_read_str; ?></td>
                                          <td class="text-center"><span class="badge bg-<?php echo $status_color; ?>"><?php echo $status_str; ?></span></td>
                                      </tr>
                                  <?php endforeach; ?>
                              <?php else: ?>
                                  <tr><td colspan="12" class="text-center py-5 text-muted"><i class="bi bi-lightning-charge fs-2 d-block mb-2"></i>No active electrical meters found.</td></tr>
                              <?php endif; ?>
                          </tbody>
                      </table>
                  </div>
              </div>
          </div>
          
          <div class="col-12">
              <div class="d-flex justify-content-between align-items-center mb-3">
                  <h6 class="text-white-50 mb-0 fs-6"><i class="bi bi-droplet text-info me-2"></i> Water Meters</h6>
                  
                  <div style="width: 250px;">
                      <select class="form-select form-select-sm border-secondary text-light bg-dark fs-6" id="waterTypeFilter" onchange="filterWaterTable()">
                          <option value="All">All Types</option>
                          <?php foreach ($water_meter_types as $t): ?>
                              <option value="<?php echo htmlspecialchars('Water - ' . $t); ?>"><?php echo htmlspecialchars('Water - ' . $t); ?></option>
                          <?php endforeach; ?>
                      </select>
                  </div>
              </div>
              
              <div class="table-container shadow border border-secondary overflow-hidden" style="background-color: #1e1e1e;">
                  <div class="table-responsive" style="max-height: 700px; overflow-y: auto;">
                      <table class="table table-dark table-hover table-borderless align-middle mb-0">
                          <thead style="position: sticky; top: 0; z-index: 10;">
                              <tr>
                                  <th class="text-nowrap" style="background-color: #1a1a1a; border-bottom: 2px solid #333; width: 6%;">Serial</th>
                                  <th class="text-nowrap" style="background-color: #1a1a1a; border-bottom: 2px solid #333; width: 12%;">Type</th>
                                  <th class="text-nowrap" style="background-color: #1a1a1a; border-bottom: 2px solid #333; width: 16%;">Tenant</th>
                                  <th class="text-end text-nowrap" style="background-color: #1a1a1a; border-bottom: 2px solid #333; border-left: 1px solid #444;" title="Standard Water Usage">8.1.1.0.0 <br><span class="text-white" style="font-size: 0.7rem; white-space: nowrap;"><?php echo date('M', strtotime($end_date)); ?> (kL) Accum</span></th>
                                  <th class="text-end text-nowrap" style="background-color: #1a1a1a; border-bottom: 2px solid #333; border-right: 1px solid #444;" title="Water Usage Per Square Meter">kL/m² <br><span class="text-warning" style="font-size: 0.7rem; white-space: nowrap;">Efficiency</span></th>
                                  <th class="text-end text-nowrap" style="background-color: #1a1a1a; border-bottom: 2px solid #333;" title="Daily Usage on Previous Date">8.1.1.0.0 <br><span class="text-white-50" style="font-size: 0.7rem; white-space: nowrap;">(kL) Daily: <?php echo date('d M', strtotime($prev_date)); ?></span></th>
                                  <th class="text-end text-nowrap" style="background-color: #1a1a1a; border-bottom: 2px solid #333; border-right: 1px solid #444;" title="Daily Usage on End Date">8.1.1.0.0 <br><span class="text-info" style="font-size: 0.7rem; white-space: nowrap;">(kL) Daily: <?php echo date('d M', strtotime($end_date)); ?></span></th>
                                  <th class="text-end text-nowrap" style="background-color: #1a1a1a; border-bottom: 2px solid #333; border-right: 1px solid #444;" title="Hourly Pacing Trend">Trend <br><span class="text-white" style="font-size: 0.7rem; white-space: nowrap;">(Run Rate)</span></th>
                                  <th class="text-end text-nowrap" style="background-color: #1a1a1a; border-bottom: 2px solid #333; border-right: 1px solid #444;" title="Latest recorded OBIS ping">Last Reading <br><span class="text-white-50" style="font-size: 0.7rem; white-space: nowrap;">8.1.1.0.0</span></th>
                                  <th class="text-center text-nowrap" style="background-color: #1a1a1a; border-bottom: 2px solid #333;" title="Network Status">Status <br><span class="text-white-50" style="font-size: 0.7rem; white-space: nowrap;">Network</span></th>
                              </tr>
                          </thead>
                          <tbody id="waterMetersTableBody">
                              <?php if (count($water_table_data) > 0): ?>
                                  <?php foreach ($water_table_data as $m): 
                                      
                                      $type_disp = str_replace('_', ' ', $m['meter_type'] ?? 'Unknown');
                                      
                                      $assign_disp = trim($m['active_tenant'] ?? '');
                                      $shop_disp = trim($m['active_shop'] ?? '');
                                      if (!empty($shop_disp)) {
                                          $assign_disp .= " (" . $shop_disp . ")";
                                      }
                                      if (empty($assign_disp) || $assign_disp === "()") {
                                          $assign_disp = trim($m['meter_location'] ?? 'Unassigned');
                                      }
                                      
                                      $serial = trim($m['meter_serial'] ?? '');
                                      $u_water_raw = isset($water_monthly_usage[$serial]) ? (float)$water_monthly_usage[$serial] : 0.00;
                                      $u_water_monthly = number_format($u_water_raw, 2);
                                      
                                      $area = (float)($m['active_area'] ?? 0);
                                      $w_per_sqm = ($area > 0) ? ($u_water_raw / $area) : 0.00;
                                      $w_per_sqm_disp = number_format($w_per_sqm, 2);

                                      $u_water_prev_daily = isset($water_prev_daily_usage[$serial]) ? number_format($water_prev_daily_usage[$serial], 2) : '0.00';
                                      $u_water_daily = isset($water_daily_usage[$serial]) ? number_format($water_daily_usage[$serial], 2) : '0.00';
                                      
                                      $w_pacing_html = $water_pacing_81100[$serial] ?? '';
                                      
                                      $s_data = $water_statuses[$serial] ?? null;
                                      $last_read_str = $s_data ? $s_data['last_reading'] : 'No Data';
                                      $status_str = $s_data ? $s_data['status'] : 'Offline';
                                      $status_color = $s_data ? $s_data['color'] : 'danger';
                                  ?>
                                      <tr>
                                          <td class="text-white"><?php echo htmlspecialchars($serial); ?></td>
                                          <td><span class="badge bg-info text-dark"><?php echo htmlspecialchars($type_disp); ?></span></td>
                                          <td>
                                              <?php if ($selected_property === 'All'): ?>
                                                  <div class="small text-info mb-1"><?php echo htmlspecialchars($m['active_property'] ?? ''); ?></div>
                                              <?php endif; ?>
                                              <div class="text-light" style="font-size: 0.85rem;"><?php echo htmlspecialchars($assign_disp); ?></div>
                                          </td>
                                          <td class="text-end text-light" style="border-left: 1px solid #444;"><?php echo $u_water_monthly; ?></td>
                                          <td class="text-end text-warning" style="border-right: 1px solid #444;"><?php echo $w_per_sqm_disp; ?></td>
                                          <td class="text-end" style="color: #a0a0a0;"><?php echo $u_water_prev_daily; ?></td>
                                          <td class="text-end text-info" style="border-right: 1px solid #444;"><?php echo $u_water_daily; ?></td>
                                          <td class="text-end text-nowrap" style="border-right: 1px solid #444;"><?php echo $w_pacing_html; ?></td>
                                          <td class="text-end text-secondary" style="font-size: 0.8rem; border-right: 1px solid #444;"><?php echo $last_read_str; ?></td>
                                          <td class="text-center"><span class="badge bg-<?php echo $status_color; ?>"><?php echo $status_str; ?></span></td>
                                      </tr>
                                  <?php endforeach; ?>
                              <?php else: ?>
                                  <tr><td colspan="10" class="text-center py-5 text-muted"><i class="bi bi-droplet-half fs-2 d-block mb-2"></i>No active water meters found.</td></tr>
                              <?php endif; ?>
                          </tbody>
                      </table>
                  </div>
              </div>
          </div>

      </div>

    </main>
  </div>

    <?php include LUM_ROOT . '/Layout/foot-assets.php'; ?>
  
  <script>
    function filterElecTable() {
        const filter = document.getElementById('elecTypeFilter').value;
        const rows = document.querySelectorAll('#elecMetersTableBody tr');
        
        rows.forEach(row => {
            if (filter === 'All') {
                row.style.display = '';
            } else {
                const typeCell = row.cells[1].innerText.trim();
                if (typeCell === filter) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            }
        });
    }
    
    function filterWaterTable() {
        const filter = document.getElementById('waterTypeFilter').value;
        const rows = document.querySelectorAll('#waterMetersTableBody tr');
        
        rows.forEach(row => {
            if (filter === 'All') {
                row.style.display = '';
            } else {
                const typeCell = row.cells[1].innerText.trim();
                if (typeCell === filter) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        
        const sidebarEl = document.getElementById('sidebar');
        const sidebarToggleBtn = document.getElementById('sidebarToggle');
        
        if(sidebarToggleBtn && sidebarEl) {
            sidebarToggleBtn.addEventListener('click', function() {
                sidebarEl.classList.toggle('collapsed');
                let startTime = Date.now();
                let resizeInterval = setInterval(() => {
                    window.dispatchEvent(new Event('resize'));
                    if (Date.now() - startTime > 400) {
                        clearInterval(resizeInterval);
                    }
                }, 15);
            });
        }

        // Restore sidebar scroll position if we just reloaded
        if (sidebarEl) {
            const savedScroll = sessionStorage.getItem('lynxSidebarScroll');
            if (savedScroll) {
                sidebarEl.scrollTop = parseInt(savedScroll, 10);
                sessionStorage.removeItem('lynxSidebarScroll');
            }
        }

        const bindCollapseIcon = (collapseId, btnId) => {
            const collapseEl = document.getElementById(collapseId);
            const btnEl = document.getElementById(btnId);
            if (collapseEl && btnEl) {
                const toggleIcon = btnEl.querySelector('.toggle-icon');
                if (toggleIcon) {
                    collapseEl.addEventListener('show.bs.collapse', () => {
                        toggleIcon.classList.remove('bi-plus-circle');
                        toggleIcon.classList.add('bi-dash-circle');
                    });
                    collapseEl.addEventListener('hide.bs.collapse', () => {
                        toggleIcon.classList.remove('bi-dash-circle');
                        toggleIcon.classList.add('bi-plus-circle');
                    });
                }
            }
        };

        bindCollapseIcon('overviewCollapse', 'overviewToggleBtn');
        bindCollapseIcon('boreholeCollapse', 'boreholeToggleBtn');
        bindCollapseIcon('lintonsCollapse', 'lintonsToggleBtn');

        const ctxEl = document.getElementById('mainConsumptionChart');
        if (ctxEl) {
            const ctx = ctxEl.getContext('2d');
            
            const chartLabels = <?php echo json_encode(array_values($chart_labels)); ?> || [];
            const dataGrid = <?php echo json_encode(array_values($chart_grid)); ?> || [];
            const dataSolar = <?php echo json_encode(array_values($chart_solar)); ?> || [];
            const dataGen = <?php echo json_encode(array_values($chart_gen)); ?> || [];
            const dataTotal = <?php echo json_encode(array_values($chart_total)); ?> || [];
            const dataWater = <?php echo json_encode(array_values($chart_water)); ?> || [];
            
            const hasSolar = <?php echo $dash_has_solar ? 'true' : 'false'; ?>;
            const hideGen = <?php echo $dash['has_generator'] ? 'false' : 'true'; ?>;
            const hideTotalProp = <?php echo ($selected_property === 'All' || !$dash['total_line']) ? 'true' : 'false'; ?>;
            
            // Draw the chart only when there are dates to show
            if (chartLabels.length > 0) {
                let datasets = [{
                    label: 'Grid Usage (kWh)',
                    data: dataGrid,
                    backgroundColor: '#dc3545',
                    yAxisID: 'y'
                }];

                if (hasSolar) {
                    datasets.push({
                        label: 'Solar Usage (kWh)',
                        data: dataSolar,
                        backgroundColor: '#ffc107',
                        yAxisID: 'y'
                    });
                }

                if (!hideGen) {
                    datasets.push({
                        label: 'Generator Usage (kWh)',
                        data: dataGen,
                        backgroundColor: '#0dcaf0',
                        yAxisID: 'y'
                    });
                }

                if (!hideTotalProp) {
                    datasets.push({
                        label: 'Total Property Usage (kWh)',
                        data: dataTotal,
                        type: 'line',
                        borderColor: '#0d6efd',
                        backgroundColor: '#0d6efd',
                        borderWidth: 2,
                        tension: 0.3,
                        pointRadius: 3,
                        fill: false,
                        yAxisID: 'y'
                    });
                }

                datasets.push({
                    label: 'Total Water Usage (kL)',
                    data: dataWater,
                    type: 'line',
                    borderColor: '#20c997',
                    backgroundColor: '#20c997',
                    borderWidth: 2,
                    borderDash: [4, 4],
                    tension: 0.3,
                    pointRadius: 3,
                    fill: false,
                    yAxisID: 'y1'
                });

                const mainChart = new Chart(ctx, {
                    type: 'bar',
                    data: { labels: chartLabels, datasets: datasets },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        scales: {
                            x: { grid: { color: '#333' }, ticks: { color: '#aaa' } },
                            y: { 
                                beginAtZero: true, 
                                position: 'left',
                                title: { display: true, text: 'Electricity (kWh)', color: '#aaa' },
                                grid: { borderDash: [2, 4], color: '#333' },
                                ticks: { color: '#aaa' }
                            },
                            y1: {
                                beginAtZero: true,
                                position: 'right',
                                title: { display: true, text: 'Water (kL)', color: '#aaa' },
                                grid: { display: false },
                                ticks: { color: '#aaa' }
                            }
                        },
                        plugins: {
                            legend: { position: 'top', labels: { color: '#fff' } },
                            tooltip: { backgroundColor: 'rgba(0,0,0,0.85)', padding: 12, titleFont: { size: 14 }, bodyFont: { size: 13 } }
                        }
                    }
                });

            }
        }
    });
  </script>
</body>
</html>
<?php
lum_mark('Page rendering (incl. property sections)');
$lum_html = ob_get_clean();
$lum_total = microtime(true) - $lum_t_start;

// Store complete pages only
if (!$lum_show_timing && stripos($lum_html, '</html>') !== false) {
    if (!is_dir($lum_cache_dir)) { @mkdir($lum_cache_dir, 0700, true); }
    if (@file_put_contents($lum_cache_file . '.tmp', $lum_html) !== false) {
        @chmod($lum_cache_file . '.tmp', 0600);
        @rename($lum_cache_file . '.tmp', $lum_cache_file);
    }
    // Now and then, remove cache files older than an hour
    if (mt_rand(1, 50) === 1) {
        foreach ((array)glob($lum_cache_dir . '/*.html') as $lum_old) {
            if (is_file($lum_old) && (time() - filemtime($lum_old)) > 3600) { @unlink($lum_old); }
        }
    }
}

// Timing breakdown for administrators
if ($lum_show_timing) {
    $lum_rows = '';
    foreach ($lum_timing as $lum_step) {
        $lum_rows .= '<tr><td style="padding:2px 12px 2px 0;">' . htmlspecialchars($lum_step[0]) . '</td><td style="text-align:right;">' . number_format($lum_step[1], 2) . ' s</td></tr>';
    }
    $lum_panel = "<div style='position:fixed; right:14px; bottom:14px; z-index:3000; background:#000; border:1px solid #e3000f; color:#fff; font-size:12px; padding:10px 14px;'>"
               . "<div style='color:#e3000f; margin-bottom:6px;'>Dashboard load time: " . number_format($lum_total, 2) . " s</div>"
               . "<table>" . $lum_rows . "</table></div>";
    $lum_pos = strripos($lum_html, '</body>');
    $lum_html = ($lum_pos === false) ? $lum_html . $lum_panel : substr($lum_html, 0, $lum_pos) . $lum_panel . substr($lum_html, $lum_pos);
    echo $lum_html;
} else {
    echo lum_dash_cache_badge($lum_html, time());
}

// Log slow page builds (shows which step to optimise)
if ($lum_total > 3) {
    $lum_parts = [];
    foreach ($lum_timing as $lum_step) { $lum_parts[] = $lum_step[0] . '=' . number_format($lum_step[1], 2) . 's'; }
    error_log('LUM dashboard slow: ' . number_format($lum_total, 2) . 's (' . $selected_property . ', ' . $start_date . ' to ' . $end_date . ') ' . implode('; ', $lum_parts));
}
?>