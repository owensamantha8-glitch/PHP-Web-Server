<?php
require_once '/var/www/Lynx/bootstrap.php';
lum_page('consumption_slips', 'view');

// Slip settings arrive hex-encoded in chunks (p0, p1, ...) so the web application firewall does not block them
$hex = '';
for ($i = 0; $i < 50; $i++) {
    if (isset($_POST['p' . $i])) {
        $hex .= $_POST['p' . $i];
    } else {
        break;
    }
}

if (!empty($hex)) {
    $json = hex2bin($hex);
    if ($json !== false) {
        $arr = json_decode($json, true);
        if (is_array($arr)) {
            foreach ($arr as $k => $v) {
                $_REQUEST[$k] = $v;
                $_POST[$k] = $v;
                $_GET[$k] = $v;
            }
        }
    }
}

lum_connect('tenants', 'obis', 'manual', 'tariffs');
lum_use('slips', 'audit');

$tenant_id = $_REQUEST['tenant_id'] ?? null;
$return_search = $_REQUEST['return_search'] ?? '';

// Back button: the financial report (with its settings) when opened from there, otherwise the tenant overview
$return_to = (($_REQUEST['return_to'] ?? '') === 'financial') ? 'financial' : '';
$return_query = '';
if ($return_to === 'financial') {
    $lum_rq = [];
    parse_str(substr((string)($_REQUEST['return_query'] ?? ''), 0, 4000), $lum_rq);
    $return_query = http_build_query($lum_rq); // Rebuilt, so only plain query parameters are passed on
    $back_url = 'https://lynx-um.co.za/Reporting/financial-reporting-overview.php' . ($return_query !== '' ? '?' . $return_query : '');
    $back_title = 'Back to the Financial Report';
} else {
    $back_url = 'https://lynx-um.co.za/Tenant%20Management/tenant-overview.php';
    $back_title = 'Back to Tenant Overview';
}
$is_submitted = isset($_REQUEST['start_date']);

$stmt = $tenant_db_conn->prepare("SELECT * FROM lum_tenants WHERE tenant_id = :tenant_id");
$stmt->execute(['tenant_id' => $tenant_id]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tenant) die("<div style='color:white; padding:20px;'>Tenant not found.</div>");

// Property access: restricted users may only open slips for tenants of their own properties
if (!empty($_SESSION['assigned_properties'])
    && !in_array($tenant['tenant_property'] ?? '', array_map('trim', explode(',', $_SESSION['assigned_properties'])), true)) {
    http_response_code(403);
    die("<div style='color:white; padding:20px;'>You do not have access to this tenant.</div>");
}

// Opened from the financial report: this tenant's saved report settings
$lum_slip_submitted = !empty($hex);   // True when the slip settings were just changed
$lum_report_settings = [];            // Settings saved for this tenant and billing month
$lum_report_settings_msg = '';
if ($return_to === 'financial') {
    // Billing month = month of the REPORT's Period 1 end date, so a tenant with its own billing cycle
    // (even one ending in another month) stays under the property's billing month
    $lum_rq_month = [];
    parse_str($return_query, $lum_rq_month);
    $lum_end_for_month = (string)($lum_rq_month['end_date'] ?? ($_REQUEST['end_date'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $lum_end_for_month)) $lum_end_for_month = date('Y-m-d');
    $lum_rs_month = (int)date('m', strtotime($lum_end_for_month));
    $lum_rs_year = (int)date('Y', strtotime($lum_end_for_month));
    // Back billing adjustments on this slip follow the report's billing month
    $slip_bill_month = $lum_rs_month;
    $slip_bill_year = $lum_rs_year;

    // "Use report settings": forget this tenant's own settings for the month
    if (isset($_GET['use_report_settings']) && lum_can('consumption_slips', 'edit')) {
        $lum_old = lumTenantReportSettingsLoad($tenant_db_conn, [$tenant['tenant_id']], $lum_rs_month, $lum_rs_year)[(int)$tenant['tenant_id']] ?? [];
        lumTenantReportSettingsSave($tenant_db_conn, $tenant['tenant_id'], $lum_rs_month, $lum_rs_year, []);
        if ($lum_old && function_exists('lum_audit_log')) {
            lum_audit_log('DELETE', 'tenant_report_settings', $tenant['tenant_id'], $tenant['tenant_name'] ?? null, $tenant['tenant_property'] ?? null, $lum_old, null, sprintf('%02d/%04d', $lum_rs_month, $lum_rs_year));
        }
        $lum_report_settings_msg = 'This tenant now uses the report settings.';
    }

    $lum_report_settings = lumTenantReportSettingsLoad($tenant_db_conn, [$tenant['tenant_id']], $lum_rs_month, $lum_rs_year)[(int)$tenant['tenant_id']] ?? [];

    // Opening the slip from the report: start from the tenant's saved settings so slip and report match
    if (!$lum_slip_submitted) {
        foreach ($lum_report_settings as $lum_k => $lum_v) {
            if ($lum_v === '') {
                unset($_REQUEST[$lum_k]);
            } else {
                $_REQUEST[$lum_k] = $lum_v;
            }
        }
    }
}

// Shared billing setup (same as the bulk slips and the financial report)
$property_name = $tenant['tenant_property'] ?? '';
$lum_default_start = date('Y-m-01');
$lum_default_end = date('Y-m-d');

require __DIR__ . '/slip-context.php';
require __DIR__ . '/slip-tenant.php';

// Single slip only

// Save the sidebar configuration to the tenant's monthly settings
$lum_save_token_ok = isset($_REQUEST['csrf_token'])
    && hash_equals($_SESSION['csrf_token'] ?? '', (string)$_REQUEST['csrf_token']);
if (isset($_REQUEST['save_settings_flag']) && $_REQUEST['save_settings_flag'] == '1'
    && $lum_save_token_ok && lum_can('consumption_slips', 'edit')) {
    $sql_sync = "INSERT INTO lum_tenant_monthly_settings (tenant_id, period_month, period_year, elec_comm_discount, water_comm_discount, elec_obis_code) 
            VALUES (:tid, :pm, :py, :ed, :wd, :ob) 
            ON DUPLICATE KEY UPDATE 
            elec_comm_discount = VALUES(elec_comm_discount), 
            water_comm_discount = VALUES(water_comm_discount), 
            elec_obis_code = VALUES(elec_obis_code)";
    $stmt_sync = $tenant_db_conn->prepare($sql_sync);
    $stmt_sync->execute(['tid' => $tenant_id, 'pm' => $report_month, 'py' => $report_year, 'ed' => $elec_comm_discount, 'wd' => $water_comm_discount, 'ob' => $tenant_obis_code]);

    $curr_conf['elec_obis_code'] = $tenant_obis_code;
    $curr_conf['elec_comm_discount'] = $elec_comm_discount;
    $curr_conf['water_comm_discount'] = $water_comm_discount;
}

// Opened from the report and the slip settings changed: sync with the report
if ($return_to === 'financial' && $lum_slip_submitted) {
    $lum_report_req = [];
    parse_str($return_query, $lum_report_req);

    // a) Report settings changed on the slip update the report itself (property OBIS, common area and run-time dates)
    foreach (LUM_REPORT_LEVEL_KEYS as $lum_k) {
        $lum_v = trim((string)($_REQUEST[$lum_k] ?? ''));
        if ($lum_v === '') {
            unset($lum_report_req[$lum_k]);
        } else {
            $lum_report_req[$lum_k] = $lum_v;
        }
    }
    $return_query = http_build_query($lum_report_req);
    $back_url = 'https://lynx-um.co.za/Reporting/financial-reporting-overview.php' . ($return_query !== '' ? '?' . $return_query : '');

    // b) Tenant settings that differ from the report are saved for this tenant and billing month
    $lum_can_save = lum_can('consumption_slips', 'edit');
    $lum_defaults = [
        'tou_algorithm'          => $default_tou_algorithm,
        'manual_override_col'    => 'none',
        'manual_override_col_t2' => 'none',
        't2_method'              => $default_t2,
        't2_base_obis'           => '1.1.1.8.0',
    ];
    $lum_new_settings = [];
    foreach (LUM_TENANT_LEVEL_KEYS as $lum_k) {
        if ($lum_k === 'tou_algorithm' && !$is_tou_tenant) continue;
        $lum_slip_v = trim((string)($_REQUEST[$lum_k] ?? ''));
        $lum_rep_v = trim((string)($lum_report_req[$lum_k] ?? ''));

        if (in_array($lum_k, LUM_TENANT_DATE_KEYS, true)) {
            // The tenant's own billing cycle: only real dates are kept
            if ($lum_slip_v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $lum_slip_v)) continue;
            // Water periods default to the electrical periods
            $lum_date_default = ['water_start_date' => 'start_date', 'water_end_date' => 'end_date'][$lum_k] ?? null;
            if ($lum_date_default !== null) {
                if ($lum_slip_v === '') $lum_slip_v = trim((string)($_REQUEST[$lum_date_default] ?? ''));
                if ($lum_rep_v === '') $lum_rep_v = trim((string)($lum_report_req[$lum_date_default] ?? ''));
            }
        } elseif (in_array($lum_k, LUM_TENANT_CHECKBOX_KEYS, true)) {
            $lum_slip_v = ($lum_slip_v === '1') ? '1' : '';
            $lum_rep_v = ($lum_rep_v === '1') ? '1' : '';
        } elseif (isset($lum_defaults[$lum_k])) {
            if ($lum_slip_v === '') $lum_slip_v = $lum_defaults[$lum_k];
            if ($lum_rep_v === '') $lum_rep_v = $lum_defaults[$lum_k];
        } elseif ($lum_k === 'elec_comm_discount' || $lum_k === 'water_comm_discount') {
            // Blank report discount = each tenant's monthly setting, which the slip has just saved
            if ($lum_rep_v === '') continue;
            if ($lum_slip_v !== '' && abs((float)$lum_slip_v - (float)$lum_rep_v) < 0.0001) continue;
        }
        if ($lum_slip_v !== $lum_rep_v) {
            $lum_new_settings[$lum_k] = $lum_slip_v;
        }
    }
    ksort($lum_new_settings);
    $lum_old_settings = $lum_report_settings;
    ksort($lum_old_settings);
    if ($lum_can_save && $lum_new_settings !== $lum_old_settings) {
        if (lumTenantReportSettingsSave($tenant_db_conn, $tenant['tenant_id'], $lum_rs_month, $lum_rs_year, $lum_new_settings)
            && function_exists('lum_audit_log')) {
            lum_audit_log($lum_new_settings ? ($lum_old_settings ? 'UPDATE' : 'INSERT') : 'DELETE', 'tenant_report_settings',
                          $tenant['tenant_id'], $tenant['tenant_name'] ?? null, $tenant['tenant_property'] ?? null,
                          $lum_old_settings ?: null, $lum_new_settings ?: null, sprintf('%02d/%04d', $lum_rs_month, $lum_rs_year));
        }
        $lum_report_settings = $lum_new_settings;
    }
}

// Report settings used by links that reopen this slip the way the report opens it
$lum_return_report_params = [];
if ($return_to === 'financial') {
    parse_str($return_query, $lum_return_report_params);
    unset($lum_return_report_params['property'], $lum_return_report_params['show_tenant_bar'], $lum_return_report_params['show_elec_pie'],
          $lum_return_report_params['show_water_pie'], $lum_return_report_params['show_daily_fin']);
}

// Manual extract columns available for this tenant's meters
$tenant_elec_m1 = $tenant['tenant_electricalMeter_01'] ?? null;
$tenant_elec_m2 = $tenant['tenant_electricalMeter_02'] ?? null;
$tenant_elec_m3 = $tenant['tenant_electricalMeter_03'] ?? null;
$elec_meters = array_filter([$tenant_elec_m1, $tenant_elec_m2, $tenant_elec_m3]);

$available_manual_columns = [];
$manual_dbs_to_scan = [
    'db_manual_analysis_',
    'db_manual_debit1_',
    'db_manual_debit2_',
    'db_manual_debit_' 
];

foreach ($elec_meters as $meter_serial) {
    $clean_serial = @preg_replace('/[^a-zA-Z0-9]/', '', $meter_serial);
    if (empty($clean_serial)) continue;

    $sYear = (int)date('Y', strtotime($start_date));
    $eYear = (int)date('Y', strtotime($end_date));
    
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
                            }
                        }
                    }
                }
            } catch (Exception $e) {}
        }
    }
}
?>