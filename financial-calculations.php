<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once __DIR__ . '/bootstrap.php';
lum_page('financial_reports', 'view');

// 1. DATABASE CONNECTIONS, SHARED SLIP ENGINE (also loads reporting-engine.php), JOURNAL AND AUDIT (bootstrap.php)
lum_connect('tenants', 'obis', 'manual', 'tariffs');
lum_use('slips', 'journal', 'audit');
if (is_readable(LUM_ROOT . LUM_LIBRARIES['back_billing'])) lum_use('back_billing'); // Back billing adjustments (optional until installed)

// --- DYNAMIC PROPERTY LIST GENERATION ---

// Properties come from the property register only (Configurations)
$core_props_conn = lumDbConn('sys_db_properties');
if (!$core_props_conn) {
    die("Core Database Connection failed.");
}
$db_props_stmt = $core_props_conn->query("SELECT Property FROM lum_properties ORDER BY Property ASC");
$properties = $db_props_stmt->fetchAll(PDO::FETCH_COLUMN);

// If the user has assigned properties, restrict their options
if (!empty($_SESSION['assigned_properties'])) {
    $allowed_props = explode(',', $_SESSION['assigned_properties']);
    $properties = array_intersect($properties, $allowed_props);
}

// =========================================================================
// 2. SHARED BILLING SETUP - identical to the single and bulk consumption slips
// =========================================================================
$selected_property = $_GET['property'] ?? '';
// Property access: only properties in this user's (filtered) list may be reported on
if ($selected_property !== '' && !in_array($selected_property, $properties, true)) {
    $selected_property = '';
}
$property_name = $selected_property;
require LUM_SLIP_DIR . '/slip-context.php';

$is_submitted = isset($_GET['start_date']);

// Sidebar values: discounts stay blank unless typed in (blank = each tenant's own monthly setting)
$elec_comm_discount = ($req_elec_comm_discount === null) ? '' : $req_elec_comm_discount;
$water_comm_discount = ($req_water_comm_discount === null) ? '' : $req_water_comm_discount;
$season_1 = 'Low';
$season_2 = 'Low';
$has_generator = false;

// --- DISCOVER AVAILABLE MANUAL EXTRACT COLUMNS FOR THE SELECTED PROPERTY ---
$manual_override_col = $_GET['manual_override_col'] ?? 'none';
$manual_override_col_t2 = $_GET['manual_override_col_t2'] ?? 'none';
$available_manual_columns = [];

if (!empty($selected_property)) {
    try {
        $meter_stmt = $tenant_db_conn->prepare("SELECT tenant_electricalMeter_01, tenant_electricalMeter_02, tenant_electricalMeter_03 FROM lum_tenants WHERE tenant_property = :prop");
        $meter_stmt->execute(['prop' => $selected_property]);
        $prop_meters = [];
        
        while ($m_row = $meter_stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($m_row['tenant_electricalMeter_01'])) $prop_meters[] = $m_row['tenant_electricalMeter_01'];
            if (!empty($m_row['tenant_electricalMeter_02'])) $prop_meters[] = $m_row['tenant_electricalMeter_02'];
            if (!empty($m_row['tenant_electricalMeter_03'])) $prop_meters[] = $m_row['tenant_electricalMeter_03'];
        }
        $prop_meters = array_unique($prop_meters);
        
        $manual_dbs_to_scan = ['db_manual_analysis_', 'db_manual_debit1_', 'db_manual_debit2_', 'db_manual_debit_'];
        $sYear = (int)date('Y', strtotime($start_date));
        $eYear = (int)date('Y', strtotime($end_date));

        $found_cols = false;
        foreach ($prop_meters as $meter_serial) {
            if ($found_cols) break;
            $clean_serial = @preg_replace('/[^a-zA-Z0-9]/', '', $meter_serial);
            if (empty($clean_serial)) continue;

            foreach ($manual_dbs_to_scan as $dbPrefix) {
                for ($y = $sYear; $y <= $eYear; $y++) {
                    $dbName = $dbPrefix . $y;
                    try {
                        $dbCheck = $manual_db_conn->query("SHOW DATABASES LIKE '$dbName'");
                        if ($dbCheck->rowCount() === 0) continue;
                        
                        $tbStmt = $manual_db_conn->query("SHOW TABLES IN `$dbName`");
                        $tables = $tbStmt->fetchAll(PDO::FETCH_COLUMN);
                        
                        foreach ($tables as $tb) {
                            $colStmt = $manual_db_conn->query("SHOW COLUMNS FROM `$dbName`.`$tb`");
                            $columns = $colStmt->fetchAll(PDO::FETCH_COLUMN);
                            
                            if (!in_array('meter_serial', $columns)) continue;
                            
                            $checkStmt = $manual_db_conn->prepare("SELECT 1 FROM `$dbName`.`$tb` WHERE meter_serial = ? LIMIT 1");
                            $checkStmt->execute([$clean_serial]);
                            if (!$checkStmt->fetchColumn()) continue;
                            
                            foreach ($columns as $c) {
                                if (@preg_match('/(kwh|kvarh|kva|kw|v|a|tariff)/i', $c) && !@preg_match('/(status|quality|log_id|rtc|counter)/i', $c)) {
                                    if (!in_array($c, $available_manual_columns)) {
                                        $available_manual_columns[] = $c;
                                        $found_cols = true;
                                    }
                                }
                            }
                        }
                    } catch (Exception $e) {}
                }
            }
        }
    } catch (Exception $e) {}
}

// GLOBAL ACCUMULATORS
$accum_energy_charge = 0; $accum_basic_charge = 0; $accum_nac = 0; $accum_capacity = 0;
$accum_demand = 0; $accum_gen_charge = 0; $accum_elec_comm_charge = 0; $accum_usage_kwh = 0; $accum_comm_kwh = 0;

$accum_water_basic = 0; $accum_water_charge = 0; $accum_sewer_charge = 0; 
$accum_water_sewer_comm_charge = 0; $accum_usage_kl = 0; $accum_comm_kl = 0;

$accum_refuse_charge = 0;
$accum_adjustments = 0;      // Back billing / refunds billed in this month
$report_adjustments = [];    // [adjustment_id => tenant_id] included in this report

$tenant_count = 0;
$tenant_rows = []; 
$property_has_generator = false;

$mri_data = ['EL00' => [], 'GE00' => [], 'WT00' => [], 'SE00' => [], 'RF00' => []];

// Property-wide chart data
$fin_agg_daily_graph = [];
$fin_agg_daily_water_graph = [];
$fin_all_tou = null;
$fin_show_graph = $show_graph;
$fin_show_water_graph = $show_water_graph;
$agg_daily_graph = [];
$agg_daily_water_graph = [];
$tou_algorithm = 'None';

// ---------------------------------------------------------
// EXTREME PRECISION DAILY FINANCIAL TRACKER (INITIALIZATION)
// ---------------------------------------------------------
$daily_financials = [];
$total_days_in_period = 0;
try {
    $dt_start = new DateTime($start_date);
    $dt_end = new DateTime(empty($end_date_2) ? $end_date : $end_date_2);
    $d_period = new DatePeriod($dt_start, new DateInterval('P1D'), $dt_end->modify('+1 day'));
    foreach ($d_period as $dt) {
        $daily_financials[$dt->format('Y-m-d')] = ['elec' => 0, 'water' => 0, 'total' => 0];
        $total_days_in_period++;
    }
} catch (Exception $e) {}
if ($total_days_in_period == 0) $total_days_in_period = 1; // Failsafe division

$total_property_elec_comm_kwh = 0;
$total_property_water_comm_kl = 0;

if (!empty($selected_property)) {

    $stmt = $tenant_db_conn->prepare("SELECT * FROM lum_tenants WHERE tenant_property = :prop ORDER BY tenant_shop ASC");
    $stmt->execute(['prop' => $selected_property]);
    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $tenant_count = count($tenants);

    // --- GLOBAL PROPERTY GENERATOR CHECK FOR SIDEBAR UI ---
    foreach ($tenants as $t) {
        $g_tar = trim($t['tenant_generator_tariff_charge'] ?? '');
        if ($g_tar !== '' && $g_tar !== '0' && stripos($g_tar, 'not app') === false && stripos($g_tar, 'none') === false && stripos($g_tar, 'n/a') === false) {
            $property_has_generator = true;
            break;
        }
    }

    // Property common area totals (needed for the recovery summary even if no tenant pays)
    $total_property_elec_comm_kwh = lumSlipElecCommonArea($property_name, $comm_start_date, $comm_end_date, $global_obis_code, $obis_db_conn, $tenant_db_conn, $manual_prop_elec_comm);
    $total_property_water_comm_kl = lumSlipWaterCommonArea($property_name, $water_comm_start_date, $water_comm_end_date, $obis_db_conn, $manual_db_conn, $tenant_db_conn, $manual_prop_water_comm);

    $s_dt = date('Y/m/d', strtotime($start_date));
    $e_dt = date('Y/m/d', strtotime(empty($end_date_2) ? $end_date : $end_date_2));

    // Settings changed on individual tenants' slips for this billing month
    $tenant_report_settings = lumTenantReportSettingsLoad($tenant_db_conn, array_column($tenants, 'tenant_id'), $report_month, $report_year);
    $lum_gen_cache = null; // Run-time hours, calculated once if a tenant's own settings need them
    // Back billing adjustments on the slips follow the report's billing month (also for tenants with their own cycle)
    $slip_bill_month = $report_month;
    $slip_bill_year = $report_year;

    foreach ($tenants as $tenant) {

        // --- 0. This tenant's own settings (chosen on its consumption slip) apply to this tenant only ---
        require LUM_SLIP_DIR . '/slip-tenant-settings-apply.php';

        // --- 1. Same tenant setup as the consumption slip ---
        require LUM_SLIP_DIR . '/slip-tenant.php';

        // --- 2. Run the consumption slip itself; only its captured totals are kept ---
        $show_graph = true;        // Daily kWh is needed for the daily spend chart
        $show_water_graph = true;  // Daily kL is needed for the daily spend chart
        $slip_render_mode = 'data';
        $slip_chart_key = $tenant['tenant_id'];
        ob_start();
        try {
            include LUM_SLIP_DIR . '/slip-render.php';
        } finally {
            ob_end_clean();
        }
        $show_graph = $fin_show_graph;
        $show_water_graph = $fin_show_water_graph;
        unset($slip_render_mode, $slip_chart_key);

        $t = $slip_totals;
        $lum_billing = $slip_billing ?? [];
        $lum_tenant_season_1 = $season_1;
        $lum_tenant_season_2 = $has_period_2 ? $season_2 : null;
        // The billing cycle this tenant was billed on (its own, or the property's)
        $lum_period = [
            'start' => $start_date, 'end' => $end_date,
            'start_2' => $has_period_2 ? $start_date_2 : '', 'end_2' => $has_period_2 ? $end_date_2 : '',
            'water_start' => $water_start_date, 'water_end' => $water_end_date,
            'water_start_2' => $has_water_period_2 ? $water_start_date_2 : '', 'water_end_2' => $has_water_period_2 ? $water_end_date_2 : '',
            'own' => !empty($lum_own_dates),
        ];

        // Back to the report settings for the next tenant
        require LUM_SLIP_DIR . '/slip-tenant-settings-restore.php';

        // --- 3. Property accumulators (every rand comes straight from the slip) ---
        $accum_energy_charge    += $t['energy'];
        $accum_basic_charge     += $t['basic'];
        $accum_nac              += $t['network'] + $t['shared_nac'];
        $accum_capacity         += $t['capacity'];
        $accum_demand           += $t['demand'];
        $accum_gen_charge       += $t['generator'];
        $accum_elec_comm_charge += $t['elec_comm'];
        $accum_usage_kwh        += $t['kwh'];
        $accum_comm_kwh         += $t['comm_kwh'];

        $accum_water_basic             += $t['water_basic'];
        $accum_water_charge            += $t['water_usage'];
        $accum_sewer_charge            += $t['sewer_basic'] + $t['sewer_usage'] + $t['sewer_additional'];
        $accum_water_sewer_comm_charge += $t['water_comm'] + $t['sewer_comm'];
        $accum_usage_kl                += $t['water_kl'];
        $accum_comm_kl                 += $t['comm_kl'];

        $accum_refuse_charge += $t['refuse'];
        $accum_adjustments += (float)($t['adjustments'] ?? 0);
        foreach (($slip_adjustments ?? []) as $lum_adj) {
            $report_adjustments[(int)$lum_adj['adjustment_id']] = (int)$tenant['tenant_id'];
        }
        $lum_tenant_adjustments = array_map(function ($a) {
            return ['adjustment_id' => (int)$a['adjustment_id'], 'total' => (float)$a['total'], 'reason' => $a['reason'],
                    'original_month' => (int)$a['original_month'], 'original_year' => (int)$a['original_year'], 'status' => $a['status']];
        }, $slip_adjustments ?? []);

        // --- 4. Tenant row: Total Elec = slip ELECTRICITY SUBTOTAL, Grand Total = slip Total Amount Payable ---
        $tenant_rows[] = [
            'tenant_id' => $tenant['tenant_id'],
            'name' => $tenant['tenant_name'],
            'shop' => $tenant['tenant_shop'],
            'elec_tariff' => $elec_tariff,
            'elec_kwh' => $t['kwh'],
            'elec_r' => $t['elec_total'],
            'water_tariff' => $water_tariff,
            'water_kl' => $t['water_kl'],
            'water_r' => $t['water_total'],
            'comm_r' => $t['elec_comm'] + $t['water_comm'] + $t['sewer_comm'],
            'refuse_r' => $t['refuse'],
            'adj_r' => (float)($t['adjustments'] ?? 0),
            'adjustments' => $lum_tenant_adjustments,
            'total_r' => $t['total'],
            // Kept for the financial journal
            'tenant_code' => $tenant['tenant_code'] ?? '',
            'sewer_tariff' => $sewer_tariff,
            'municipality' => $municipality,
            'season_1' => $lum_tenant_season_1,
            'season_2' => $lum_tenant_season_2,
            'water_meters' => implode(', ', array_map('strval', $water_meters)),
            'totals' => $t,
            'billing' => $lum_billing,
            'settings' => $lum_ov,
            'period' => $lum_period
        ];

        // --- 5. Daily financial apportionment ---
        $tenant_daily_elec = [];
        foreach ($agg_daily_graph as $d => $v) {
            $tenant_daily_elec[$d] = ($tenant_daily_elec[$d] ?? 0) + $v['total'];
            if (!isset($fin_agg_daily_graph[$d])) $fin_agg_daily_graph[$d] = ['total' => 0, 'peak' => 0, 'std' => 0, 'off' => 0];
            $fin_agg_daily_graph[$d]['total'] += $v['total'];
            $fin_agg_daily_graph[$d]['peak'] += $v['peak'];
            $fin_agg_daily_graph[$d]['std'] += $v['std'];
            $fin_agg_daily_graph[$d]['off'] += $v['off'];
        }
        $tenant_daily_water = [];
        foreach ($agg_daily_water_graph as $d => $v) {
            $tenant_daily_water[$d] = ($tenant_daily_water[$d] ?? 0) + $v;
            $fin_agg_daily_water_graph[$d] = ($fin_agg_daily_water_graph[$d] ?? 0) + $v;
        }

        $m_energy = $t['energy'];
        $daily_elec_fixed = ($t['elec_total'] - $m_energy) / $total_days_in_period;
        $tenant_total_kwh_for_cost = array_sum($tenant_daily_elec);

        $t_water_var = $t['water_usage'] + $t['sewer_usage'];
        $daily_water_fixed = ($t['water_total'] - $t_water_var) / $total_days_in_period;
        $tenant_total_kl_for_cost = array_sum($tenant_daily_water);

        foreach ($daily_financials as $d => $vals) {
            if ($tenant_total_kwh_for_cost > 0) {
                $e_var = isset($tenant_daily_elec[$d]) ? ($tenant_daily_elec[$d] / $tenant_total_kwh_for_cost) * $m_energy : 0;
            } else {
                $e_var = $m_energy / $total_days_in_period;
            }
            if ($tenant_total_kl_for_cost > 0) {
                $w_var = isset($tenant_daily_water[$d]) ? ($tenant_daily_water[$d] / $tenant_total_kl_for_cost) * $t_water_var : 0;
            } else {
                $w_var = $t_water_var / $total_days_in_period;
            }
            $daily_financials[$d]['elec'] += ($daily_elec_fixed + $e_var);
            $daily_financials[$d]['water'] += ($daily_water_fixed + $w_var);
            $daily_financials[$d]['total'] += ($daily_elec_fixed + $e_var + $daily_water_fixed + $w_var);
        }

        // --- 6. MRI export lines (with the tenant's own billing dates) ---
        $s_dt = date('Y/m/d', strtotime($lum_period['start']));
        $e_dt = date('Y/m/d', strtotime($lum_period['end_2'] !== '' ? $lum_period['end_2'] : $lum_period['end']));
        $t_code = !empty($tenant['tenant_code']) ? trim(str_replace(',', '', $tenant['tenant_code'])) : $tenant['tenant_id'];
        $t_gen_r = $t['generator'];
        $t_elec_base_r = $t['elec_total'] - $t_gen_r;
        $t_water_base_r = $t['water_basic'] + $t['water_usage'] + $t['water_comm'];
        $t_sewer_r = $t['sewer_basic'] + $t['sewer_usage'] + $t['sewer_comm'] + $t['sewer_additional'];
        $t_refuse_r = $t['refuse'];

        if ($t_elec_base_r > 0) {
            $mri_data['EL00'][] = "11,N,{$t_code},{$t_code},EL00,{$s_dt},{$e_dt},,".number_format($t_elec_base_r, 2, '.', '').",,,,,[Insert comments]";
        }
        if ($t_gen_r > 0) {
            $mri_data['GE00'][] = "11,N,{$t_code},{$t_code},GE00,{$s_dt},{$e_dt},,".number_format($t_gen_r, 2, '.', '').",,,,,[Insert comments]";
        }
        if ($t_water_base_r > 0) {
            $mri_data['WT00'][] = "11,N,{$t_code},{$t_code},WT00,{$s_dt},{$e_dt},,".number_format($t_water_base_r, 2, '.', '').",,,,,[Insert comments]";
        }
        if ($t_sewer_r > 0) {
            $mri_data['SE00'][] = "11,N,{$t_code},{$t_code},SE00,{$s_dt},{$e_dt},,".number_format($t_sewer_r, 2, '.', '').",,,,,[Insert comments]";
        }
        if ($t_refuse_r > 0) {
            $mri_data['RF00'][] = "11,N,{$t_code},{$t_code},RF00,{$s_dt},{$e_dt},,".number_format($t_refuse_r, 2, '.', '').",,,,,[Insert comments]";
        }

        // Property TOU chart flag: stacked TOU chart only when every metered tenant is TOU
        if (!empty($valid_elec_meters)) {
            $fin_all_tou = ($fin_all_tou === null) ? $is_tou_tenant : ($fin_all_tou && $is_tou_tenant);
        }
    }

    // Property-wide values for the report charts and sidebar
    $agg_daily_graph = $fin_agg_daily_graph;
    $agg_daily_water_graph = $fin_agg_daily_water_graph;
    $tou_algorithm = $fin_all_tou ? 'Tshwane' : 'None'; // Only used as a TOU / non-TOU chart flag
    $has_generator = $property_has_generator;
    $elec_comm_discount = ($req_elec_comm_discount === null) ? '' : $req_elec_comm_discount;
    $water_comm_discount = ($req_water_comm_discount === null) ? '' : $req_water_comm_discount;

    $total_electricity_r = $accum_energy_charge + $accum_basic_charge + $accum_nac + $accum_capacity + $accum_demand + $accum_gen_charge + $accum_elec_comm_charge;
    $total_kwh_recovered = $accum_usage_kwh + $accum_comm_kwh;
    $units_not_recovered = max(0, $total_property_elec_comm_kwh - $accum_comm_kwh);
    $recovered_r_kwh = ($total_kwh_recovered > 0) ? ($total_electricity_r / $total_kwh_recovered) : 0;

    $total_water_sewer_r = $accum_water_basic + $accum_water_charge + $accum_sewer_charge + $accum_water_sewer_comm_charge;
    $total_kl_recovered = $accum_usage_kl + $accum_comm_kl;
    $water_units_not_recovered = max(0, $total_property_water_comm_kl - $accum_comm_kl);
    $recovered_r_kl = ($total_kl_recovered > 0) ? ($total_water_sewer_r / $total_kl_recovered) : 0;

    // Grand total = sum of every tenant's Total Amount Payable
    $grand_total = $total_electricity_r + $total_water_sewer_r + $accum_refuse_charge + $accum_adjustments;

    // =========================================================================
    // ADVANCED CHART DATA GENERATORS
    // =========================================================================
    
    // 1. Tenant Breakdown Bar Chart
    $tenant_bar_data = ['labels' => [], 'values' => []];
    foreach ($tenant_rows as $row) {
        $tenant_bar_data['labels'][] = $row['name'] . ' (' . $row['shop'] . ')';
        $tenant_bar_data['values'][] = round($row['total_r'], 2);
    }

    // 2. Electricity Recovery Pie Chart
    $elec_pie_data = [
        'labels' => ['Energy', 'Basic', 'Network Access', 'Capacity', 'Demand', 'Generator', 'Common Area'],
        'values' => [
            round($accum_energy_charge, 2),
            round($accum_basic_charge, 2),
            round($accum_nac, 2),
            round($accum_capacity, 2),
            round($accum_demand, 2),
            round($accum_gen_charge, 2),
            round($accum_elec_comm_charge, 2)
        ]
    ];

    // 3. Water & Sewer Recovery Pie Chart
    $water_pie_data = [
        'labels' => ['Water Basic', 'Water Usage', 'Sewer Charge', 'Common Area'],
        'values' => [
            round($accum_water_basic, 2),
            round($accum_water_charge, 2),
            round($accum_sewer_charge, 2),
            round($accum_water_sewer_comm_charge, 2)
        ]
    ];

    // 4. Daily Financial Spend Graph (Exact mathematical distribution)
    $fin_labels = [];
    $fin_elec = [];
    $fin_water = [];
    $fin_total = [];
    ksort($daily_financials);
    foreach ($daily_financials as $d => $v) {
        $fin_labels[] = date('d M', strtotime($d));
        $fin_elec[] = round($v['elec'], 2);
        $fin_water[] = round($v['water'], 2);
        $fin_total[] = round($v['total'], 2);
    }
    $daily_fin_json = json_encode([
        'labels' => $fin_labels,
        'elec' => $fin_elec,
        'water' => $fin_water,
        'total' => $fin_total
    ]);
}

// =========================================================================
// 3. FINANCIAL JOURNAL
// =========================================================================
$journal_db = lumJournalDb();
$journal_can_create = lum_can('financial_reports', 'edit');
$journal_message = $_SESSION['journal_message'] ?? null;
unset($_SESSION['journal_message']);
$journal_active = null;
if (!empty($selected_property) && $is_submitted) {
    $journal_active = lumJournalActive($journal_db, $selected_property, $report_month, $report_year);
}

// Remember the last generated report, so returning from the Journal History restores it
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($selected_property) && $is_submitted) {
    $_SESSION['lum_last_financial_report'] = http_build_query($_GET);
}

// "Journal this Report": store the report exactly as calculated above
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['journal_action'] ?? '') === 'create') {

    // Creating a journal bills tenants, so the request must come from this system
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        die('Security Error: Invalid CSRF Token. Request Blocked.');
    }
    lum_require_csrf();
    lum_require_access('financial_reports', 'edit');
    $lum_redirect = 'financial-reporting-overview.php?' . http_build_query($_GET);

    if (empty($selected_property) || !$is_submitted || empty($tenant_rows)) {
        $_SESSION['journal_message'] = ['type' => 'warning', 'text' => 'Generate the report for a property and billing period before journaling it.'];
    } elseif (!$journal_db) {
        $_SESSION['journal_message'] = ['type' => 'danger', 'text' => 'The journal database is not available. Please run financial-journal-setup.sql.'];
    } else {
        // Billing cycle label when the report dates match a cycle
        $lum_cycle_end = $has_period_2 ? $end_date_2 : $end_date;
        $lum_label = '';
        foreach ($billing_cycles as $lum_cycle) {
            if ($lum_cycle['period_start_date'] === $start_date && in_array($lum_cycle['period_end_date'], [$lum_cycle_end, $end_date], true)) {
                $lum_label = (string)$lum_cycle['billing_month_label'];
                break;
            }
        }

        $lum_settings = $_GET;
        unset($lum_settings['show_tenant_bar'], $lum_settings['show_elec_pie'], $lum_settings['show_water_pie'], $lum_settings['show_daily_fin']);

        $lum_header = [
            'property' => $selected_property,
            'billing_month' => $report_month,
            'billing_year' => $report_year,
            'billing_label' => $lum_label,
            'period_start' => $start_date, 'period_end' => $end_date,
            'period_2_start' => $has_period_2 ? $start_date_2 : '', 'period_2_end' => $has_period_2 ? $end_date_2 : '',
            'water_start' => $water_start_date, 'water_end' => $water_end_date,
            'water_2_start' => $has_water_period_2 ? $water_start_date_2 : '', 'water_2_end' => $has_water_period_2 ? $water_end_date_2 : '',
            'total_kwh' => $accum_usage_kwh, 'total_comm_kwh' => $accum_comm_kwh,
            'total_kl' => $accum_usage_kl, 'total_comm_kl' => $accum_comm_kl,
            'total_elec' => $total_electricity_r, 'total_water' => $total_water_sewer_r,
            'total_refuse' => $accum_refuse_charge, 'grand_total' => $grand_total,
            'report_settings' => $lum_settings,
            'property_summary' => [
                'electricity' => [
                    'energy' => round($accum_energy_charge, 2), 'basic' => round($accum_basic_charge, 2),
                    'network_access' => round($accum_nac, 2), 'capacity' => round($accum_capacity, 2),
                    'demand' => round($accum_demand, 2), 'generator' => round($accum_gen_charge, 2),
                    'common_area' => round($accum_elec_comm_charge, 2), 'total' => round($total_electricity_r, 2),
                    'tenant_kwh' => round($accum_usage_kwh, 3), 'common_area_kwh_billed' => round($accum_comm_kwh, 3),
                    'property_common_area_kwh' => round((float)$total_property_elec_comm_kwh, 3),
                    'units_not_recovered' => round($units_not_recovered, 3), 'recovered_r_per_kwh' => round($recovered_r_kwh, 4),
                ],
                'water' => [
                    'basic' => round($accum_water_basic, 2), 'usage' => round($accum_water_charge, 2),
                    'sewer' => round($accum_sewer_charge, 2), 'common_area' => round($accum_water_sewer_comm_charge, 2),
                    'total' => round($total_water_sewer_r, 2), 'tenant_kl' => round($accum_usage_kl, 3),
                    'common_area_kl_billed' => round($accum_comm_kl, 3),
                    'property_common_area_kl' => round((float)$total_property_water_comm_kl, 3),
                    'units_not_recovered' => round($water_units_not_recovered, 3), 'recovered_r_per_kl' => round($recovered_r_kl, 4),
                ],
                'refuse' => round($accum_refuse_charge, 2),
                'adjustments' => round($accum_adjustments, 2),
                'adjustment_ids' => array_keys($report_adjustments),
                'grand_total' => round($grand_total, 2),
                'property_obis' => $global_obis_code,
                'non_tou_obis' => $non_tou_obis,
                'manual_col_t1' => $manual_override_col,
                'estimated_readings' => $use_estimated_readings_requested,
                'estimate_time_ranges' => $use_estimate_time_ranges_requested ? $estimate_time_ranges : null,
                'generator' => [
                    'method' => $t2_method, 'base_obis' => $t2_base_obis, 'manual_col_t2' => $manual_override_col_t2,
                    'runtime_start' => $gen_runtime_start, 'runtime_end' => $gen_runtime_end, 'runtime' => $gen_runtime_info,
                ],
                'tenants_with_own_settings' => count(array_filter(array_column($tenant_rows, 'settings'))),
                'tenants_with_own_billing_cycle' => count(array_filter(array_column(array_column($tenant_rows, 'period'), 'own'))),
            ],
            'notes' => trim((string)($_POST['journal_notes'] ?? '')),
        ];

        $lum_meter_label = function ($m) {
            return $m['serial'] . ((abs((float)$m['ct_setting'] - 1) > 0.0001) ? ' (CT ' . rtrim(rtrim(number_format((float)$m['ct_setting'], 2, '.', ''), '0'), '.') . ')' : '');
        };
        $lum_lines = [];
        foreach ($tenant_rows as $row) {
            $b = $row['billing'] ?? [];
            $meters = $b['meters'] ?? [];
            $gen_methods = array_values(array_unique(array_filter(array_column($meters, 'gen_source'))));
            // Manual readings columns used to bill this tenant (e.g. A14)
            $manual_cols_elec = array_values(array_unique(array_filter(array_column($meters, 'elec_manual_col'), 'strlen')));
            $manual_cols_gen = array_values(array_unique(array_filter(array_column($meters, 'gen_manual_col'), 'strlen')));
            $lum_lines[] = [
                'tenant_id' => $row['tenant_id'],
                'tenant_name' => $row['name'],
                'tenant_code' => $row['tenant_code'],
                'tenant_shop' => $row['shop'],
                'municipality' => $b['municipality'] ?? $row['municipality'],
                'elec_tariff' => $row['elec_tariff'],
                'water_tariff' => $row['water_tariff'],
                'sewer_tariff' => $row['sewer_tariff'],
                'season_1' => $row['season_1'],
                'season_2' => $row['season_2'],
                'elec_obis' => empty($meters) ? null : ($b['elec_obis'] ?? null),
                'is_tou' => !empty($b['is_tou']),
                'tou_algorithm' => !empty($b['is_tou']) ? ($b['tou_algorithm'] ?? null) : null,
                'gen_method' => $gen_methods ? implode(' / ', $gen_methods) : null,
                'gen_runtime_hours' => $b['gen_runtime_hours'] ?? null,
                'elec_meters' => implode(', ', array_map($lum_meter_label, $meters)),
                'water_meters' => $row['water_meters'],
                'estimated_readings' => !empty($b['estimated_readings']),
                'occupancy_status' => $b['occupancy_status'] ?? null,
                'manual_col_elec' => $manual_cols_elec ? implode(', ', $manual_cols_elec) : null,
                'manual_col_gen' => $manual_cols_gen ? implode(', ', $manual_cols_gen) : null,
                'period' => $row['period'],
                'totals' => $row['totals'],
                'tenant_settings' => $row['settings'],
                'billing_detail' => $b + ['adjustments' => $row['adjustments'] ?? [], 'adjustments_total' => round((float)($row['adj_r'] ?? 0), 2)],
            ];
        }

        try {
            $lum_journal_id = lumJournalCreate($journal_db, $lum_header, $lum_lines);

            // Back billing adjustments included in this journal are now Posted
            if (!empty($report_adjustments) && function_exists('lumAdjMarkPosted')) {
                lumAdjMarkPosted($journal_db, array_keys($report_adjustments), $lum_journal_id);
                if (function_exists('lum_audit_log')) {
                    foreach ($report_adjustments as $lum_adj_id => $lum_adj_tenant) {
                        lum_audit_log('UPDATE', 'back_billing', $lum_adj_id, lumAdjNumber($lum_adj_id), $selected_property,
                                      null, ['status' => 'Posted', 'posted_journal_id' => $lum_journal_id], 'Posted with the journal');
                    }
                }
            }
            if (function_exists('lum_audit_log') && $journal_active) {
                lum_audit_log('UPDATE', 'financial_journal', $journal_active['journal_id'],
                    $selected_property . ' ' . sprintf('%02d/%04d', $report_month, $report_year), $selected_property,
                    ['status' => 'Active'], ['status' => 'Superseded', 'superseded_by' => $lum_journal_id], 'Replaced by a new journal');
            }
            if (function_exists('lum_audit_log')) {
                lum_audit_log('INSERT', 'financial_journal', $lum_journal_id,
                    $selected_property . ' ' . sprintf('%02d/%04d', $report_month, $report_year), $selected_property, null,
                    ['tenants' => count($lum_lines), 'grand_total' => round($grand_total, 2),
                     'replaces_journal' => $journal_active['journal_id'] ?? null, 'notes' => $lum_header['notes']]);
            }
            $_SESSION['journal_message'] = ['type' => 'success', 'text' => 'Report journaled as Journal #' . $lum_journal_id
                . ($journal_active ? ' (Journal #' . $journal_active['journal_id'] . ' is now marked as superseded).' : '.')];
        } catch (\Throwable $e) {
            error_log('LUM journal create failed: ' . $e->getMessage());
            $_SESSION['journal_message'] = ['type' => 'danger', 'text' => 'The report could not be journaled. Nothing was saved. Please try again or contact the system administrator.'];
        }
    }
    header('Location: ' . $lum_redirect);
    exit();
}
?>