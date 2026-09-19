<?php
require_once '/var/www/Lynx/bootstrap.php';
lum_page('tenants', 'view');

// Notices and warnings are not logged on this page
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

lum_connect('tenants', 'obis', 'manual', 'tariffs');
lum_use('reporting');
$core_db_conn = lum_db('properties');
if (!$core_db_conn) {
    die("Core Database Connection failed. Please contact the system administrator.");
}

$tenant_id = $_GET['tenant_id'] ?? null;
$return_search = $_GET['return_search'] ?? '';

if (!$tenant_id) {
    die("<div style='color:white; padding:20px; background:#121212; height:100vh;'>Tenant ID not provided. <a href='tenant-overview.php'>Return to overview</a></div>");
}

$stmt = $tenant_db_conn->prepare("SELECT * FROM lum_tenants WHERE tenant_id = :tenant_id");
$stmt->execute(['tenant_id' => $tenant_id]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tenant) {
    die("<div style='color:white; padding:20px; background:#121212; height:100vh;'>Tenant not found.</div>");
}

// Property access: restricted users may only view tenants of their own properties
$lum_allowed = (!empty($_SESSION['assigned_properties']) ? array_map('trim', explode(',', $_SESSION['assigned_properties'])) : null);
if ($lum_allowed !== null && !in_array($tenant['tenant_property'] ?? '', $lum_allowed, true)) {
    http_response_code(403);
    die("<div style='color:white; padding:20px; background:#121212; height:100vh;'>You do not have access to this tenant.</div>");
}

// Default period: 1st of the month to today
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Only accept real dates
$lum_is_date = function ($d) {
    $dt = DateTime::createFromFormat('Y-m-d', (string)$d);
    return ($dt && $dt->format('Y-m-d') === $d);
};
if (!$lum_is_date($start_date)) $start_date = date('Y-m-01');
if (!$lum_is_date($end_date)) $end_date = date('Y-m-d');
if (strtotime($end_date) < strtotime($start_date)) { $start_date = date('Y-m-01'); $end_date = date('Y-m-d'); }

$elec_meters = array_filter([$tenant['tenant_electricalMeter_01'], $tenant['tenant_electricalMeter_02'], $tenant['tenant_electricalMeter_03']]);
$water_meters = array_filter([$tenant['tenant_waterMeter_01'], $tenant['tenant_waterMeter_02'], $tenant['tenant_waterMeter_03']]);

// Tenant history (lum_tenants_legacy)
$legacy_history = [];
try {
    // Older records under another tenant_id are matched on the main keyword of the tenant's name
    $search_tokens = explode(' ', preg_replace('/[^a-zA-Z0-9 ]/', '', $tenant['tenant_name']));
    $match_token = '';
    $banned_tokens = ['the', 'shop', 'store', 'and', 'trading', 'pty', 'ltd', 'inc'];
    
    foreach ($search_tokens as $token) {
        if (strlen($token) >= 3 && !in_array(strtolower($token), $banned_tokens)) {
            $match_token = $token;
            break;
        }
    }

    $params = ['tid' => $tenant_id];
    $legacy_sql = "SELECT * FROM lum_tenants_legacy WHERE tenant_id = :tid";
    
    if (!empty($match_token)) {
        $legacy_sql .= " OR (tenant_property = :prop AND tenant_name LIKE :name_like)";
        $params['prop'] = $tenant['tenant_property'];
        $params['name_like'] = '%' . $match_token . '%';
    }
    
    $legacy_sql .= " ORDER BY action_date DESC";
    
    $legacy_stmt = $tenant_db_conn->prepare($legacy_sql);
    $legacy_stmt->execute($params);
    $legacy_history = $legacy_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // No history table yet
}

// CSV export
if (isset($_GET['export_elec'])) {
    $target_obis = preg_replace('/[^0-9.]/', '', (string)$_GET['export_elec']); // e.g. 1.1.1.8.0
    $cleanCode = preg_replace('/[^0-9]/', '', $target_obis);
    $yr = date('Y', strtotime($start_date));
    $db = "db_obis_{$cleanCode}_{$yr}";
    $table = "tb_obis_{$cleanCode}_{$yr}";
    $col = $cleanCode . "_value";
    
    $clean_serials = array_filter(array_map(function($s) { return preg_replace('/[^a-zA-Z0-9]/', '', $s); }, $elec_meters));
    
    if (!empty($clean_serials)) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=Elec_Logs_' . $target_obis . '_' . (int)$tenant_id . '_' . date('Ymd') . '.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Meter Serial', 'Time Stamp', 'Reading Value (' . $target_obis . ')']);
        
        $inQuery = implode(',', array_fill(0, count($clean_serials), '?'));
        try {
            $stmt = $obis_db_conn->prepare("SELECT meter_serial, Time_stamp, `$col` FROM `$db`.`$table` WHERE meter_serial IN ($inQuery) AND Time_stamp >= ? AND Time_stamp <= ? ORDER BY Time_stamp ASC");
            $params = array_values($clean_serials);
            $params[] = $start_date . ' 00:00:00';
            $params[] = $end_date . ' 23:59:59';
            $stmt->execute($params);
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                fputcsv($output, $row);
            }
        } catch (Exception $e) {
            error_log('LUM view-tenant export failed: ' . $e->getMessage()); fputcsv($output, ['Error fetching data. Please contact the system administrator.']);
        }
        fclose($output);
        exit();
    }
}

if (isset($_GET['export_water'])) {
    $yr = date('Y', strtotime($start_date));
    $db = "db_obis_81100_{$yr}";
    $table = "tb_obis_81100_{$yr}";
    $col = "81100_value";
    
    $clean_serials = array_filter(array_map(function($s) { return preg_replace('/[^a-zA-Z0-9]/', '', $s); }, $water_meters));
    
    if (!empty($clean_serials)) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=Water_Logs_' . (int)$tenant_id . '_' . date('Ymd') . '.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Meter Serial', 'Time Stamp', 'Reading Value (8.1.1.0.0)']);
        
        $inQuery = implode(',', array_fill(0, count($clean_serials), '?'));
        try {
            $stmt = $obis_db_conn->prepare("SELECT meter_serial, Time_stamp, `$col` FROM `$db`.`$table` WHERE meter_serial IN ($inQuery) AND Time_stamp >= ? AND Time_stamp <= ? ORDER BY Time_stamp ASC");
            $params = array_values($clean_serials);
            $params[] = $start_date . ' 00:00:00';
            $params[] = $end_date . ' 23:59:59';
            $stmt->execute($params);
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                fputcsv($output, $row);
            }
        } catch (Exception $e) {
            error_log('LUM view-tenant export failed: ' . $e->getMessage()); fputcsv($output, ['Error fetching data. Please contact the system administrator.']);
        }
        fclose($output);
        exit();
    }
}

// Billing as on the consumption slip (shared slip engine) for this tenant and the selected dates:
// municipality, Time Of Use, tariffs and the charges of the estimate below
lum_use('slips');
$slip_calc = null;
try {
    $slip_calc = lumSlipCalculateTenant($tenant, ['start_date' => $start_date, 'end_date' => $end_date]);
} catch (\Throwable $e) {
    error_log('LUM view-tenant: slip calculation failed for tenant ' . $tenant_id . ': ' . $e->getMessage());
}
$property_name = $tenant['tenant_property'] ?? '';
$elec_tariff = $slip_calc['elec_tariff'] ?? ($tenant['tenant_electrical_tariff_charge'] ?? '');
$municipality = $slip_calc['municipality'] ?? '';
$is_tou_tenant = !empty($slip_calc['is_tou']);
$tou_algorithm = $slip_calc['tou_algorithm'] ?? 'None';

$total_elec_kwh = 0;
$total_elec_kva = 0;
$total_gen_kwh = 0;
$total_water_kl = 0;
$elec_data = [];
$water_data = [];

$public_holidays = lumPublicHolidays();

foreach ($elec_meters as $meter) {
    $rdg_tot = getRealElecReadings($obis_db_conn, $meter, $start_date, $end_date, '1.1.1.8.0', 'None', $public_holidays);
    $rdg_grid = getRealElecReadings($obis_db_conn, $meter, $start_date, $end_date, '1.1.1.8.1', 'None', $public_holidays);
    $rdg_gen = getRealElecReadings($obis_db_conn, $meter, $start_date, $end_date, '1.1.1.8.2', 'None', $public_holidays);
    
    if ($rdg_tot || $rdg_grid || $rdg_gen) {
        $elec_data[] = [
            'meter' => $meter,
            'tot' => $rdg_tot ? $rdg_tot : ['open'=>0, 'close'=>0, 'kwh'=>0],
            'grid' => $rdg_grid ? $rdg_grid : ['open'=>0, 'close'=>0, 'kwh'=>0],
            'gen' => $rdg_gen ? $rdg_gen : ['open'=>0, 'close'=>0, 'kwh'=>0]
        ];
        if ($rdg_tot) {
            $total_elec_kwh += $rdg_tot['kwh']; 
            $total_elec_kva += $rdg_tot['kva'] ?? 0;
        }
        if ($rdg_gen) {
            $total_gen_kwh += $rdg_gen['kwh'];
        }
    }
}

foreach ($water_meters as $meter) {
    $rdg = getRealWaterReadings($obis_db_conn, $manual_db_conn, $meter, $start_date, $end_date);
    if ($rdg) {
        $total_water_kl += $rdg['kl'];
        $water_data[] = [
            'meter' => $meter,
            'open' => $rdg['open'],
            'close' => $rdg['close'],
            'usage' => $rdg['kl']
        ];
    }
}

// Graph data
function getTenantDailyUsage($pdo, $serials, $start_date, $end_date, $obis_code = '1.1.1.8.0') {
    $daily = [];
    try {
        $period = new DatePeriod(new DateTime($start_date), new DateInterval('P1D'), (new DateTime($end_date))->modify('+1 day'));
        foreach ($period as $dt) {
            $daily[$dt->format("Y-m-d")] = 0;
        }
    } catch (Exception $e) {}
    
    $clean_serials = array_filter(array_map(function($s) { return @preg_replace('/[^a-zA-Z0-9]/', '', $s); }, $serials));
    if (empty($clean_serials)) return $daily;
    
    $cleanCode = @preg_replace('/[^0-9]/', '', $obis_code);
    $readingYear = date('Y', strtotime($start_date));
    $db = "db_obis_" . $cleanCode . "_" . $readingYear;
    $table = "tb_obis_" . $cleanCode . "_" . $readingYear;
    $col = $cleanCode . "_value";
    
    $inQuery = implode(',', array_fill(0, count($clean_serials), '?'));
    
    try {
        $baseSql = "SELECT t1.meter_serial, t1.`$col` as val 
                    FROM `$db`.`$table` t1 
                    INNER JOIN (
                        SELECT meter_serial, MAX(Time_stamp) as max_ts 
                        FROM `$db`.`$table` 
                        WHERE meter_serial IN ($inQuery) AND Time_stamp < ? 
                        GROUP BY meter_serial
                    ) t2 ON t1.meter_serial = t2.meter_serial AND t1.Time_stamp = t2.max_ts";
        
        $paramsBase = array_values($clean_serials);
        $paramsBase[] = $start_date . ' 00:00:00';
        
        $stmt_base = $pdo->prepare($baseSql);
        $stmt_base->execute($paramsBase);
        $baselines = [];
        while ($row = $stmt_base->fetch(PDO::FETCH_ASSOC)) {
            $baselines[$row['meter_serial']] = (float)$row['val'];
        }
        
        $dailySql = "SELECT meter_serial, DATE(Time_stamp) as dt, MAX(`$col`) as max_v, MIN(`$col`) as min_v 
                     FROM `$db`.`$table` 
                     WHERE meter_serial IN ($inQuery) AND Time_stamp >= ? AND Time_stamp <= ? 
                     GROUP BY meter_serial, DATE(Time_stamp) 
                     ORDER BY DATE(Time_stamp) ASC";
                     
        $paramsDaily = array_values($clean_serials);
        $paramsDaily[] = $start_date . ' 00:00:00';
        $paramsDaily[] = $end_date . ' 23:59:59';
        
        $stmt = $pdo->prepare($dailySql);
        $stmt->execute($paramsDaily);
        
        $prev_maxes = $baselines;
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($results as $row) {
            $m = $row['meter_serial'];
            $dt = $row['dt'];
            $current_max = (float)$row['max_v'];
            $prev = isset($prev_maxes[$m]) ? $prev_maxes[$m] : false;
            
            if ($prev !== false) {
                $usage = max(0, $current_max - $prev);
            } else {
                $usage = max(0, $current_max - (float)$row['min_v']);
            }
            
            if (isset($daily[$dt])) {
                $daily[$dt] += $usage;
            }
            $prev_maxes[$m] = $current_max;
        }
    } catch(Exception $e) {}
    
    return $daily;
}

$daily_11180 = getTenantDailyUsage($obis_db_conn, $elec_meters, $start_date, $end_date, '1.1.1.8.0');
$daily_11181 = getTenantDailyUsage($obis_db_conn, $elec_meters, $start_date, $end_date, '1.1.1.8.1');
$daily_11182 = getTenantDailyUsage($obis_db_conn, $elec_meters, $start_date, $end_date, '1.1.1.8.2');
$daily_water = getTenantDailyUsage($obis_db_conn, $water_meters, $start_date, $end_date, '8.1.1.0.0');

$chart_labels = [];
$c_11180 = [];
$c_11181 = [];
$c_11182 = [];
$c_water = [];

try {
    $period = new DatePeriod(new DateTime($start_date), new DateInterval('P1D'), (new DateTime($end_date))->modify('+1 day'));
    foreach ($period as $dt) {
        $d = $dt->format("Y-m-d");
        $chart_labels[] = $dt->format("d M");
        $c_11180[] = (float) round($daily_11180[$d] ?? 0, 2);
        $c_11181[] = (float) round($daily_11181[$d] ?? 0, 2);
        $c_11182[] = (float) round($daily_11182[$d] ?? 0, 2);
        $c_water[] = (float) round($daily_water[$d] ?? 0, 2);
    }
} catch (Exception $e) {}

$tou_analysis_data = [];
if ($tou_algorithm !== 'None') {
    // OBIS 1.1.1.8.0 readings from the yearly tables (db_obis_11180_<year>.tb_obis_11180_<year>), as the billing engine reads them
    $tou_years = range((int)date('Y', strtotime($start_date)), (int)date('Y', strtotime($end_date)));
    $tou_ranges = function_exists('get_obis_time_ranges') ? get_obis_time_ranges($obis_db_conn) : [];
    $time_start = $tou_ranges['1.1.1.8.0']['start'] ?? ' 00:30:00';
    $time_end = $tou_ranges['1.1.1.8.0']['end'] ?? ' 23:30:00';
    $tou_rows = function ($serial, $where_order, array $params, $year) use ($obis_db_conn) {
        $db = 'db_obis_11180_' . (int)$year;
        $table = 'tb_obis_11180_' . (int)$year;
        try {
            $st = $obis_db_conn->prepare("SELECT Time_stamp, `11180_value` AS val FROM `$db`.`$table` WHERE meter_serial = :serial AND $where_order");
            $st->execute(['serial' => $serial] + $params);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    };

    foreach ($elec_meters as $meter_id) {
        $clean_serial = @preg_replace('/[^a-zA-Z0-9]/', '', $meter_id);

        try {
            $open_row = null;
            foreach ($tou_years as $ty) {
                $found = $tou_rows($clean_serial, "Time_stamp >= :start ORDER BY Time_stamp ASC LIMIT 1", ['start' => $start_date . $time_start], $ty);
                if ($found) { $open_row = $found[0]; break; }
            }
            $open_val = $open_row ? (float)$open_row['val'] : 0;
            $open_ts = $open_row ? $open_row['Time_stamp'] : null;

            $close_row = null;
            foreach (array_reverse($tou_years) as $ty) {
                $found = $tou_rows($clean_serial, "Time_stamp <= :end ORDER BY Time_stamp DESC LIMIT 1", ['end' => $end_date . $time_end], $ty);
                if ($found) { $close_row = $found[0]; break; }
            }
            $close_val = $close_row ? (float)$close_row['val'] : 0;
            $close_ts = $close_row ? $close_row['Time_stamp'] : null;

            $kwh_total_raw = max(0, $close_val - $open_val);

            $logs = [];
            $daily = [];
            $tou_raw = ['peak' => 0, 'std' => 0, 'off' => 0];

            if ($open_ts && $close_ts) {
                $all_rows = [];
                foreach ($tou_years as $ty) {
                    $all_rows = array_merge($all_rows, $tou_rows($clean_serial, "Time_stamp >= :start AND Time_stamp <= :end ORDER BY Time_stamp ASC",
                                                                 ['start' => $open_ts, 'end' => $close_ts], $ty));
                }

                $prev_ts = null;
                $prev_v = null;
                foreach ($all_rows as $r) {
                    $cur_v = (float)$r['val'];
                    $cur_ts = $r['Time_stamp'];

                    if ($prev_v !== null) {
                        $diff = $cur_v - $prev_v;
                        if ($diff >= 0 && $diff < 2000) {
                            $mid_ts = strtotime($cur_ts) - 900;
                            $bucket = get_tou_bucket($mid_ts, $tou_algorithm, $public_holidays);
                            $date_str = date('Y-m-d', $mid_ts);

                            $tou_raw[$bucket] += $diff;

                            if (!isset($daily[$date_str])) {
                                $daily[$date_str] = ['peak' => 0, 'std' => 0, 'off' => 0, 'total' => 0];
                            }
                            $daily[$date_str][$bucket] += $diff;
                            $daily[$date_str]['total'] += $diff;

                            $logs[] = [
                                'date' => $date_str,
                                'start_time' => date('H:i', strtotime($prev_ts)),
                                'end_time' => date('H:i', strtotime($cur_ts)),
                                'start_val' => $prev_v,
                                'end_val' => $cur_v,
                                'diff' => $diff,
                                'bucket' => ucfirst($bucket)
                            ];
                        }
                    }
                    $prev_v = $cur_v;
                    $prev_ts = $cur_ts;
                }
            }

            $tou_recon = $tou_raw;
            $ratio = 1;
            $tou_sum = $tou_raw['peak'] + $tou_raw['std'] + $tou_raw['off'];
            if ($tou_sum > 0 && abs($tou_sum - $kwh_total_raw) > 0.001) {
                $ratio = $kwh_total_raw / $tou_sum;
                $tou_recon['peak'] = round($tou_raw['peak'] * $ratio, 2);
                $tou_recon['std'] = round($tou_raw['std'] * $ratio, 2);
                $tou_recon['off'] = round($kwh_total_raw - $tou_recon['peak'] - $tou_recon['std'], 2);
            }

            if (!empty($logs)) {
                $tou_analysis_data[$meter_id] = [
                    'open_val' => $open_val,
                    'close_val' => $close_val,
                    'kwh_total' => $kwh_total_raw,
                    'daily' => $daily,
                    'logs' => $logs,
                    'tou_raw' => $tou_raw,
                    'tou_recon' => $tou_recon,
                    'tou_sum' => $tou_sum,
                    'ratio' => $ratio
                ];
            }
        } catch (Exception $e) {}
    }
}

// Financial estimate: the consumption slip's charges for the selected dates
$slip_t = $slip_calc['totals'] ?? [];
$st = function ($k) use ($slip_t) { return (float)($slip_t[$k] ?? 0); };

$has_generator = !empty($slip_calc['has_generator']);
$has_common_area = !empty($slip_calc['has_common_area']);

$est_elec = $st('basic') + $st('energy') + $st('demand') + $st('network') + $st('capacity') + $st('shared_nac');
$est_gen = $st('generator');
$est_comm_elec = $st('elec_comm');
$est_comm_water = $st('water_comm');
$est_comm_sewer = $st('sewer_comm');
$est_comm_total = $est_comm_elec + $est_comm_water + $est_comm_sewer;
$est_water = $st('water_basic') + $st('water_usage');
$est_sewer = $st('sewer_basic') + $st('sewer_usage') + $st('sewer_additional');

// Total of the charges for these dates (council refuse included; back billing adjustments are not an estimate)
$est_total = $est_elec + $est_water + $est_sewer + $est_gen + $est_comm_total + $st('refuse');

$shop_area = floatval($tenant['tenant_shop_area'] ?? 0);
$elec_rate = ($shop_area > 0) ? ($total_elec_kwh / $shop_area) : 0;
$water_rate = ($shop_area > 0) ? ($total_water_kl / $shop_area) : 0;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tenant Analysis - <?php echo htmlspecialchars($tenant['tenant_name']); ?></title>
    <?php include LUM_ROOT . '/Layout/head-assets.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ" crossorigin="anonymous"></script>
    <style>
        * {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif !important;
            font-weight: normal !important;
            text-transform: none !important;
            border-radius: 0 !important;
        }
        ::-webkit-scrollbar-track { background: #0a0a0a; border-radius: 0; }
        ::-webkit-scrollbar-thumb { background: #4a4a4a; border-radius: 0; }
        body { background-color: #121212; color: #ffffff; }
        .action-bar { background-color: #0a0a0a; border-bottom: 1px solid #333333; padding: 15px 0; }
        .card-dark { background-color: #1a1a1a; border: 1px solid #333; }
        .card-header-dark { background-color: #222; border-bottom: 1px solid #333; }
        .stat-card { background-color: #1e1e1e; border: 1px solid #333; padding: 20px; transition: 0.3s; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 4px 15px rgba(0,0,0,0.3); }
        .stat-icon { font-size: 2rem; margin-bottom: 10px; }
        .stat-value { font-size: 1.8rem; }
        .table-dark { --bs-table-bg: transparent; margin-bottom: 0; }
        .table-dark th { color: #aaa; border-bottom-color: #444; }
        .table-dark td { border-bottom-color: #333; vertical-align: middle; }
        .form-control.dark-input { background-color: #1e1e1e; border: 1px solid #444; color: #fff; }
        .form-control.dark-input:focus { border-color: #e3000f; box-shadow: 0 0 0 0.25rem rgba(227, 0, 15, 0.25); }
    </style>
</head>
<body>

    <?php include LUM_ROOT . '/Layout/top-navbar.php'; ?>

    <div class="action-bar mb-4">
        <div class="container-fluid px-4">
            <div class="row align-items-center">
                <div class="col-md-5 d-flex align-items-center">
                    <a href="tenant-overview.php<?php echo !empty($return_search) ? '?search=' . urlencode($return_search) : ''; ?>" class="btn btn-outline-light btn-sm me-3">
                        Back
                    </a>
                    <h4 class="mb-0 fs-5">Tenant Analysis View</h4>
                </div>
                <div class="col-md-7 d-flex justify-content-md-end mt-3 mt-md-0">
                    <form method="GET" class="d-flex align-items-center gap-2">
                        <input type="hidden" name="tenant_id" value="<?php echo htmlspecialchars($tenant_id); ?>">
                        <input type="hidden" name="return_search" value="<?php echo htmlspecialchars($return_search); ?>">
                        
                        <input type="date" name="start_date" class="form-control form-control-sm dark-input" value="<?php echo htmlspecialchars($start_date); ?>" onchange="this.form.submit()" required>
                        <span class="text-white">to</span>
                        <input type="date" name="end_date" class="form-control form-control-sm dark-input" value="<?php echo htmlspecialchars($end_date); ?>" onchange="this.form.submit()" required>
                        
                        <div class="border-start border-secondary ps-3 ms-1 d-flex gap-2">
                            <div class="btn-group">
                                <button type="button" class="btn btn-outline-danger btn-sm dropdown-toggle text-nowrap" data-bs-toggle="dropdown" aria-expanded="false" title="Download Raw DB Logs">
                                    <i class="bi bi-download me-1"></i> Elec Logs
                                </button>
                                <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end shadow border-secondary">
                                    <li><button type="submit" name="export_elec" value="1.1.1.8.0" class="dropdown-item btn-sm"><i class="bi bi-file-earmark-spreadsheet me-2"></i> 1.1.1.8.0 (Combined)</button></li>
                                    <li><button type="submit" name="export_elec" value="1.1.1.8.1" class="dropdown-item btn-sm"><i class="bi bi-file-earmark-spreadsheet me-2"></i> 1.1.1.8.1 (Grid)</button></li>
                                    <li><button type="submit" name="export_elec" value="1.1.1.8.2" class="dropdown-item btn-sm"><i class="bi bi-file-earmark-spreadsheet me-2"></i> 1.1.1.8.2 (Generator)</button></li>
                                </ul>
                            </div>
                            
                            <button type="submit" name="export_water" value="1" class="btn btn-brand btn-sm px-2 shadow-sm" title="Download Raw DB Logs">
                                <i class="bi bi-download me-1"></i> Water Logs
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid px-4 pb-5">
        
        <div class="card card-dark mb-4">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3 border-end border-secondary">
                        <small class="text-white d-inline-block mb-1 text-decoration-underline pb-1" style="letter-spacing: 0.5px;">Tenant Details</small>
                        <h5 class="mb-0 text-white fs-5"><?php echo htmlspecialchars($tenant['tenant_name']); ?></h5>
                        <?php if (!empty($tenant['tenant_onsite'])): ?>
                            <div class="small text-light mt-2"><i class="bi bi-person-badge me-2"></i><?php echo htmlspecialchars($tenant['tenant_onsite']); ?> (Onsite)</div>
                        <?php endif; ?>
                        <?php if (!empty($tenant['email'])): ?>
                            <div class="small text-info mt-1"><i class="bi bi-envelope me-2"></i><?php echo htmlspecialchars($tenant['email']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($tenant['cell_number'])): ?>
                            <div class="small text-info mt-1"><i class="bi bi-telephone me-2"></i><?php echo htmlspecialchars($tenant['cell_number']); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-3 border-end border-secondary">
                        <small class="text-white d-inline-block mb-1 text-decoration-underline pb-1" style="letter-spacing: 0.5px;">Account Code</small>
                        <h5 class="mb-0 text-info fs-5"><?php echo htmlspecialchars($tenant['tenant_code']); ?></h5>
                    </div>
                    <div class="col-md-3 border-end border-secondary">
                        <small class="text-white d-inline-block mb-1 text-decoration-underline pb-1" style="letter-spacing: 0.5px;">Property & Shop</small>
                        <h5 class="mb-0 text-white fs-5"><?php echo htmlspecialchars($tenant['tenant_property'] . ' - Shop ' . $tenant['tenant_shop']); ?></h5>
                        <div class="small text-warning mt-1"><?php echo number_format($shop_area, 2); ?> m&sup2;</div>
                    </div>
                    <div class="col-md-3">
                        <small class="text-white d-inline-block mb-1 text-decoration-underline pb-1" style="letter-spacing: 0.5px;">Occupancy Term</small>
                        <?php if (!empty($tenant['tenant_occupancy_start_date'])): ?>
                            <div class="text-white"><span class="text-white small">Start:</span> <?php echo date('d M Y', strtotime($tenant['tenant_occupancy_start_date'])); ?></div>
                        <?php else: ?>
                            <div class="text-white mt-1">Start Date Not Set</div>
                        <?php endif; ?>
                        <?php if (!empty($tenant['tenant_occupancy_end_date'])): ?>
                            <div class="text-white mt-1"><span class="text-white small">End:</span> <?php echo date('d M Y', strtotime($tenant['tenant_occupancy_end_date'])); ?></div>
                        <?php else: ?>
                            <div class="text-white mt-1">End Date Not Set</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($legacy_history)): ?>
        <div class="card card-dark mb-4">
            <div class="card-header card-header-dark text-warning fs-6">
                <i class="bi bi-clock-history me-2"></i> Tenant Audit History (Legacy Records)
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-dark table-striped table-hover mb-0 align-middle" style="font-size: 0.85rem;">
                        <thead style="position: sticky; top: 0; background-color: #222; z-index: 1;">
                            <tr>
                                <th class="text-white border-bottom border-secondary">Action Date</th>
                                <th class="text-white border-bottom border-secondary">Action</th>
                                <th class="text-white border-bottom border-secondary">Name / Business</th>
                                <th class="text-white border-bottom border-secondary">Shop</th>
                                <th class="text-white border-bottom border-secondary">Elec Meters</th>
                                <th class="text-white border-bottom border-secondary">Water Meters</th>
                                <th class="text-white border-bottom border-secondary">Occupancy Term</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($legacy_history as $index => $hist): 
                                $badge_color = 'bg-secondary';
                                if ($hist['action_type'] === 'INSERT' || $hist['action_type'] === 'REGISTER') $badge_color = 'bg-success';
                                elseif ($hist['action_type'] === 'UPDATE') $badge_color = 'bg-primary';
                                elseif ($hist['action_type'] === 'DELETE') $badge_color = 'bg-danger';
                                
                                $prev = $legacy_history[$index + 1] ?? null;
                                
                                $format_diff = function($curr, $old) {
                                    $curr_safe = trim((string)($curr ?? ''));
                                    $old_safe = trim((string)($old ?? ''));
                                    
                                    if ($old !== null && $curr_safe !== $old_safe) {
                                        $o_disp = $old_safe === '' ? 'None' : htmlspecialchars($old_safe);
                                        $c_disp = $curr_safe === '' ? 'None' : htmlspecialchars($curr_safe);
                                        return "<span class='text-muted text-decoration-line-through small'>$o_disp</span><br><span class='text-warning fw-bold'>$c_disp</span>";
                                    }
                                    return $curr_safe === '' ? '<span class="text-muted">None</span>' : htmlspecialchars($curr_safe);
                                };
                                
                                $format_arr_diff = function($curr_arr, $old_arr) {
                                    $c_arr = is_array($curr_arr) ? $curr_arr : [];
                                    $curr_str = implode('<br>', array_map('htmlspecialchars', $c_arr));
                                    
                                    if ($old_arr !== null) {
                                        $o_arr = is_array($old_arr) ? $old_arr : [];
                                        $old_str = implode('<br>', array_map('htmlspecialchars', $o_arr));
                                        
                                        if ($curr_str !== $old_str) {
                                            $o_disp = empty($old_str) ? 'None' : $old_str;
                                            $c_disp = empty($curr_str) ? 'None' : $curr_str;
                                            return "<span class='text-muted text-decoration-line-through small'>$o_disp</span><br><span class='text-warning fw-bold'>$c_disp</span>";
                                        }
                                    }
                                    return empty($curr_str) ? '<span class="text-muted">None</span>' : "<span class='text-info'>$curr_str</span>";
                                };

                                $name_disp = $format_diff($hist['tenant_name'] ?? '', $prev ? ($prev['tenant_name'] ?? '') : null);
                                $shop_disp = $format_diff($hist['tenant_shop'] ?? '', $prev ? ($prev['tenant_shop'] ?? '') : null);
                                
                                $h_elec = array_filter(array_map('trim', [(string)($hist['tenant_electricalMeter_01'] ?? ''), (string)($hist['tenant_electricalMeter_02'] ?? ''), (string)($hist['tenant_electricalMeter_03'] ?? '')]));
                                $p_elec = $prev ? array_filter(array_map('trim', [(string)($prev['tenant_electricalMeter_01'] ?? ''), (string)($prev['tenant_electricalMeter_02'] ?? ''), (string)($prev['tenant_electricalMeter_03'] ?? '')])) : null;
                                $e_disp = $format_arr_diff($h_elec, $p_elec);
                                
                                $h_water = array_filter(array_map('trim', [(string)($hist['tenant_waterMeter_01'] ?? ''), (string)($hist['tenant_waterMeter_02'] ?? ''), (string)($hist['tenant_waterMeter_03'] ?? ''), (string)($hist['tenant_waterMeter_04'] ?? '')]));
                                $p_water = $prev ? array_filter(array_map('trim', [(string)($prev['tenant_waterMeter_01'] ?? ''), (string)($prev['tenant_waterMeter_02'] ?? ''), (string)($prev['tenant_waterMeter_03'] ?? ''), (string)($prev['tenant_waterMeter_04'] ?? '')])) : null;
                                $w_disp = $format_arr_diff($h_water, $p_water);
                                
                                $h_s = $hist['tenant_occupancy_start_date'] ?? null;
                                $h_e = $hist['tenant_occupancy_end_date'] ?? null;
                                $o_s_str = !empty($h_s) ? date('Y-m-d', strtotime($h_s)) : 'Not Set';
                                $o_e_str = !empty($h_e) ? date('Y-m-d', strtotime($h_e)) : 'Not Set';

                                if ($prev) {
                                    $p_s = $prev['tenant_occupancy_start_date'] ?? null;
                                    $p_e = $prev['tenant_occupancy_end_date'] ?? null;
                                    $p_s_str = !empty($p_s) ? date('Y-m-d', strtotime($p_s)) : 'Not Set';
                                    $p_e_str = !empty($p_e) ? date('Y-m-d', strtotime($p_e)) : 'Not Set';
                                    
                                    $s_disp = ($o_s_str !== $p_s_str) ? "<span class='text-muted text-decoration-line-through small'>$p_s_str</span> <span class='text-warning fw-bold'>$o_s_str</span>" : $o_s_str;
                                    $e_disp_occ = ($o_e_str !== $p_e_str) ? "<span class='text-muted text-decoration-line-through small'>$p_e_str</span> <span class='text-warning fw-bold'>$o_e_str</span>" : $o_e_str;
                                    
                                    $occ_disp = "<span class='text-white small d-block' style='line-height: 1.4;'>Start: $s_disp<br>End: $e_disp_occ</span>";
                                } else {
                                    $occ_disp = "<span class='text-white small d-block' style='line-height: 1.4;'>Start: $o_s_str<br>End: $o_e_str</span>";
                                }
                            ?>
                            <tr>
                                <td class="text-white text-nowrap"><?php echo date('Y-m-d H:i', strtotime($hist['action_date'])); ?></td>
                                <td><span class="badge <?php echo $badge_color; ?>"><?php echo htmlspecialchars($hist['action_type']); ?></span></td>
                                <td class="text-white"><?php echo $name_disp; ?></td>
                                <td class="text-white"><?php echo $shop_disp; ?></td>
                                <td><?php echo $e_disp; ?></td>
                                <td><?php echo $w_disp; ?></td>
                                <td><?php echo $occ_disp; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="card card-dark mb-4">
            <div class="card-header card-header-dark text-success fs-6">
                <i class="bi bi-currency-rand me-2"></i> Financial Estimation Dashboard
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col border-end border-secondary">
                        <small class="text-white d-block mb-1">Electricity Estimate</small>
                        <h5 class="text-danger mb-0 fs-5">R <?php echo number_format($est_elec, 2); ?></h5>
                    </div>
                    <div class="col border-end border-secondary">
                        <small class="text-white d-block mb-1">Water Estimate</small>
                        <h5 class="text-info mb-0 fs-5">R <?php echo number_format($est_water, 2); ?></h5>
                    </div>
                    <div class="col border-end border-secondary">
                        <small class="text-white d-block mb-1">Sewer Estimate</small>
                        <h5 class="text-primary mb-0 fs-5">R <?php echo number_format($est_sewer, 2); ?></h5>
                    </div>
                    
                    <?php if ($has_generator): ?>
                    <div class="col border-end border-secondary">
                        <small class="text-white d-block mb-1">Generator Estimate</small>
                        <h5 class="text-warning mb-0 fs-5">R <?php echo number_format($est_gen, 2); ?></h5>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($has_common_area): ?>
                    <div class="col border-end border-secondary">
                        <small class="text-white d-block mb-1">Common Area Estimate</small>
                        <h5 class="text-secondary mb-0 fs-5">R <?php echo number_format($est_comm_total, 2); ?></h5>
                    </div>
                    <?php endif; ?>
                    
                    <div class="col">
                        <small class="text-white d-block mb-1">Estimated Total</small>
                        <h5 class="text-success mb-0 fs-5">R <?php echo number_format($est_total, 2); ?></h5>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="stat-card border-danger" style="border-left: 5px solid #dc3545;">
                    <div class="stat-icon text-danger"><i class="bi bi-lightning-charge-fill"></i></div>
                    <div class="text-white small mb-1">Total Electricity (1.1.1.8.0)</div>
                    <div class="stat-value text-white"><?php echo number_format($total_elec_kwh, 2); ?> <span class="fs-6 text-white-50">kWh</span></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card border-warning" style="border-left: 5px solid #ffc107;">
                    <div class="stat-icon text-warning"><i class="bi bi-activity"></i></div>
                    <div class="text-white small mb-1">Electrical Rate</div>
                    <div class="stat-value text-white"><?php echo number_format($elec_rate, 2); ?> <span class="fs-6 text-white-50">kWh/m&sup2;</span></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card border-info" style="border-left: 5px solid #0dcaf0;">
                    <div class="stat-icon text-info"><i class="bi bi-droplet-fill"></i></div>
                    <div class="text-white small mb-1">Total Water</div>
                    <div class="stat-value text-white"><?php echo number_format($total_water_kl, 2); ?> <span class="fs-6 text-white-50">kL</span></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card border-primary" style="border-left: 5px solid #0d6efd;">
                    <div class="stat-icon text-primary"><i class="bi bi-speedometer2"></i></div>
                    <div class="text-white small mb-1">Water Rate</div>
                    <div class="stat-value text-white"><?php echo number_format($water_rate, 2); ?> <span class="fs-6 text-white-50">kL/m&sup2;</span></div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-md-7">
                <div class="card card-dark h-100">
                    <div class="card-header card-header-dark text-danger fs-6">
                        <i class="bi bi-lightning-charge me-2"></i> Electrical Meter Summary
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-dark mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th>Meter Serial</th>
                                        <th>OBIS Type</th>
                                        <th class="text-end">Opening</th>
                                        <th class="text-end">Closing</th>
                                        <th class="text-end">Usage (kWh)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($elec_data)): ?>
                                        <tr><td colspan="5" class="text-center py-3 text-white border-0">No electrical readings found for this period.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($elec_data as $row): ?>
                                            <tr>
                                                <td rowspan="3" class="text-info border-end border-secondary"><?php echo htmlspecialchars($row['meter']); ?></td>
                                                <td class="text-white border-bottom border-dark">1.1.1.8.0 (Combined)</td>
                                                <td class="text-end text-white border-bottom border-dark"><?php echo number_format($row['tot']['open'], 2); ?></td>
                                                <td class="text-end text-white border-bottom border-dark"><?php echo number_format($row['tot']['close'], 2); ?></td>
                                                <td class="text-end text-white border-bottom border-dark"><?php echo number_format($row['tot']['kwh'], 2); ?></td>
                                            </tr>
                                            <tr>
                                                <td class="text-white border-bottom border-dark">1.1.1.8.1 (Grid)</td>
                                                <td class="text-end text-white border-bottom border-dark"><?php echo number_format($row['grid']['open'], 2); ?></td>
                                                <td class="text-end text-white border-bottom border-dark"><?php echo number_format($row['grid']['close'], 2); ?></td>
                                                <td class="text-end text-success border-bottom border-dark"><?php echo number_format($row['grid']['kwh'], 2); ?></td>
                                            </tr>
                                            <tr>
                                                <td class="text-white border-bottom border-secondary">1.1.1.8.2 (Generator)</td>
                                                <td class="text-end text-white border-bottom border-secondary"><?php echo number_format($row['gen']['open'], 2); ?></td>
                                                <td class="text-end text-white border-bottom border-secondary"><?php echo number_format($row['gen']['close'], 2); ?></td>
                                                <td class="text-end text-warning border-bottom border-secondary"><?php echo number_format($row['gen']['kwh'], 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <tr class="table-active">
                                            <td colspan="4" class="text-end border-0 text-white">TOTAL COMBINED (1.1.1.8.0):</td>
                                            <td class="text-end text-danger border-0"><?php echo number_format($total_elec_kwh, 2); ?></td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-5">
                <div class="card card-dark h-100">
                    <div class="card-header card-header-dark text-info fs-6">
                        <i class="bi bi-droplet me-2"></i> Water Meter Summary
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-dark table-striped mb-0">
                                <thead>
                                    <tr>
                                        <th>Meter Serial</th>
                                        <th class="text-end">Opening</th>
                                        <th class="text-end">Closing</th>
                                        <th class="text-end">Usage (kL)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($water_data)): ?>
                                        <tr><td colspan="4" class="text-center py-3 text-white">No water readings found for this period.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($water_data as $row): ?>
                                            <tr>
                                                <td class="text-info"><?php echo htmlspecialchars($row['meter']); ?></td>
                                                <td class="text-end text-white"><?php echo number_format($row['open'], 2); ?></td>
                                                <td class="text-end text-white"><?php echo number_format($row['close'], 2); ?></td>
                                                <td class="text-end text-white"><?php echo number_format($row['usage'], 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <tr class="table-active">
                                            <td colspan="3" class="text-end text-white">TOTAL:</td>
                                            <td class="text-end text-info"><?php echo number_format($total_water_kl, 2); ?></td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row g-4 mb-5">
            <div class="col-lg-6">
                <div class="card border border-secondary shadow-sm p-4" style="background-color: #1e1e1e; color: #fff;">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0 text-white fs-5"><i class="bi bi-lightning-charge text-danger me-2"></i> Electrical Consumption</h5>
                        <select id="elecGraphSelector" class="form-select form-select-sm bg-dark text-white border-secondary" style="width: auto;">
                            <option value="11180">1.1.1.8.0 (Combined)</option>
                            <option value="11181">1.1.1.8.1 (Grid)</option>
                            <option value="11182">1.1.1.8.2 (Generator)</option>
                        </select>
                    </div>
                    <div style="height: 350px; width: 100%;">
                        <canvas id="tenantElecChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card border border-secondary shadow-sm p-4 h-100" style="background-color: #1e1e1e; color: #fff;">
                    <div class="mb-3">
                        <h5 class="mb-0 text-white fs-5"><i class="bi bi-droplet text-info me-2"></i> Water Consumption</h5>
                    </div>
                    <div style="height: 350px; width: 100%;">
                        <canvas id="tenantWaterChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($tou_algorithm !== 'None' && !empty($tou_analysis_data)): ?>
            <?php foreach ($tou_analysis_data as $meter_id => $data): ?>
                <div class="row g-4 mt-2">
                    <div class="col-12">
                        <h4 class="text-white border-bottom border-secondary pb-2 fs-5">TOU Analysis: <span class="text-info"><?php echo htmlspecialchars($meter_id); ?></span> <span class="fs-6 text-white-50 ms-2">(<?php echo htmlspecialchars($tou_algorithm); ?>)</span></h4>
                    </div>
                    
                    <div class="col-lg-5">
                        <div class="card card-dark p-3">
                            <h5 class="border-bottom border-secondary pb-2 mb-3 text-white fs-6">Daily Accumulation</h5>
                            <table class="table table-sm table-dark table-bordered align-middle">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th class="text-end">Peak</th>
                                        <th class="text-end">Standard</th>
                                        <th class="text-end">Off-Peak</th>
                                        <th class="text-end text-white">Daily Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php ksort($data['daily']); foreach($data['daily'] as $d => $v): ?>
                                    <tr>
                                        <td class="text-white"><?php echo date('d M Y', strtotime($d)); ?></td>
                                        <td class="text-end text-danger"><?php echo number_format($v['peak'], 2); ?></td>
                                        <td class="text-end text-warning"><?php echo number_format($v['std'], 2); ?></td>
                                        <td class="text-end text-success"><?php echo number_format($v['off'], 2); ?></td>
                                        <td class="text-end text-white"><?php echo number_format($v['total'], 2); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="table-active">
                                        <th class="text-white">RAW TOTALS</th>
                                        <th class="text-end text-danger"><?php echo number_format($data['tou_raw']['peak'], 2); ?></th>
                                        <th class="text-end text-warning"><?php echo number_format($data['tou_raw']['std'], 2); ?></th>
                                        <th class="text-end text-success"><?php echo number_format($data['tou_raw']['off'], 2); ?></th>
                                        <th class="text-end text-white fs-6"><?php echo number_format($data['tou_sum'], 2); ?></th>
                                    </tr>
                                </tfoot>
                            </table>

                            <h5 class="border-bottom border-secondary pb-2 mb-3 mt-4 text-white fs-6">Final Slip Reconciliation</h5>
                            <table class="table table-sm table-dark table-bordered align-middle">
                                <tbody>
                                    <tr><th class="text-white">Opening Reading</th><td class="text-end text-white"><?php echo number_format($data['open_val'], 2); ?></td></tr>
                                    <tr><th class="text-white">Closing Reading</th><td class="text-end text-white"><?php echo number_format($data['close_val'], 2); ?></td></tr>
                                    <tr class="table-active"><th class="text-white">Total Physical Consumption</th><td class="text-end text-white"><?php echo number_format($data['kwh_total'], 2); ?> kWh</td></tr>
                                    <tr><th class="text-white">Reconciliation Ratio Applied</th><td class="text-end text-white">x <?php echo number_format($data['ratio'], 6); ?></td></tr>
                                    <tr><th class="text-white">Final Peak</th><td class="text-end text-danger"><?php echo number_format($data['tou_recon']['peak'], 2); ?> kWh</td></tr>
                                    <tr><th class="text-white">Final Standard</th><td class="text-end text-warning"><?php echo number_format($data['tou_recon']['std'], 2); ?> kWh</td></tr>
                                    <tr><th class="text-white">Final Off-Peak</th><td class="text-end text-success"><?php echo number_format($data['tou_recon']['off'], 2); ?> kWh</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="col-lg-7">
                        <div class="card card-dark p-3 h-100">
                            <h5 class="border-bottom border-secondary pb-2 mb-3 text-white fs-6">Step-by-Step Interval Log</h5>
                            <div class="table-responsive" style="max-height: 800px; overflow-y: auto;">
                                <table class="table table-sm table-dark table-hover table-bordered align-middle" style="font-size: 0.85rem;">
                                    <thead class="sticky-top" style="background-color: #222;">
                                        <tr>
                                            <th style="background-color: #222;" class="text-white">Date</th>
                                            <th style="background-color: #222;" class="text-white">Interval Window</th>
                                            <th class="text-end text-white" style="background-color: #222;">Start Rdg</th>
                                            <th class="text-end text-white" style="background-color: #222;">End Rdg</th>
                                            <th class="text-end text-white" style="background-color: #222;">Usage Math</th>
                                            <th class="text-center text-white" style="background-color: #222;">TOU Bucket</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($data['logs'] as $log): 
                                            $badge = 'bg-warning text-dark';
                                            if ($log['bucket'] === 'Peak') $badge = 'bg-danger text-white';
                                            if ($log['bucket'] === 'Off') $badge = 'bg-success text-white';
                                        ?>
                                        <tr>
                                            <td class="text-white"><?php echo $log['date']; ?></td>
                                            <td class="text-white"><?php echo $log['start_time']; ?> - <?php echo $log['end_time']; ?></td>
                                            <td class="text-end text-white"><?php echo number_format($log['start_val'], 2, '.', ''); ?></td>
                                            <td class="text-end text-white"><?php echo number_format($log['end_val'], 2, '.', ''); ?></td>
                                            <td class="text-end text-white"><?php echo number_format($log['diff'], 2, '.', ''); ?></td>
                                            <td class="text-center"><span class="badge <?php echo $badge; ?> w-100"><?php echo $log['bucket']; ?></span></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </div>
    
    <?php include LUM_ROOT . '/Layout/foot-assets.php'; ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const chartLabels = <?php echo json_encode(array_values($chart_labels)); ?>;
            const data11180 = <?php echo json_encode(array_values($c_11180)); ?>;
            const data11181 = <?php echo json_encode(array_values($c_11181)); ?>;
            const data11182 = <?php echo json_encode(array_values($c_11182)); ?>;
            const dataWater = <?php echo json_encode(array_values($c_water)); ?>;

            const elecCtx = document.getElementById('tenantElecChart');
            if (elecCtx && chartLabels && chartLabels.length > 0) {
                let elecChart = new Chart(elecCtx.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: chartLabels,
                        datasets: [{
                            label: 'Consumption (kWh)',
                            data: data11180,
                            backgroundColor: '#dc3545',
                            borderRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: { grid: { color: '#333' }, ticks: { color: '#aaa' } },
                            y: { 
                                beginAtZero: true,
                                grid: { borderDash: [2, 4], color: '#333' },
                                ticks: { color: '#aaa' }
                            }
                        },
                        plugins: { legend: { display: false } }
                    }
                });

                // Register selector for the electricity chart
                const elecSelector = document.getElementById('elecGraphSelector');
                if (elecSelector) {
                    elecSelector.addEventListener('change', function() {
                        const val = this.value;
                        if (val === '11180') {
                            elecChart.data.datasets[0].data = data11180;
                            elecChart.data.datasets[0].backgroundColor = '#dc3545';
                        } else if (val === '11181') {
                            elecChart.data.datasets[0].data = data11181;
                            elecChart.data.datasets[0].backgroundColor = '#198754';
                        } else if (val === '11182') {
                            elecChart.data.datasets[0].data = data11182;
                            elecChart.data.datasets[0].backgroundColor = '#ffc107';
                        }
                        elecChart.update();
                    });
                }
            }

            const waterCtx = document.getElementById('tenantWaterChart');
            if (waterCtx && chartLabels && chartLabels.length > 0) {
                new Chart(waterCtx.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: chartLabels,
                        datasets: [{
                            label: 'Consumption (kL)',
                            data: dataWater,
                            backgroundColor: '#0dcaf0',
                            borderRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: { grid: { color: '#333' }, ticks: { color: '#aaa' } },
                            y: { 
                                beginAtZero: true,
                                grid: { borderDash: [2, 4], color: '#333' },
                                ticks: { color: '#aaa' }
                            }
                        },
                        plugins: { legend: { display: false } }
                    }
                });
            }
        });
    </script>
</body>
</html>