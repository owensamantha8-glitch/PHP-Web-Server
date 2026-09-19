<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once '/var/www/Lynx/bootstrap.php';
lum_page('meters', 'view');
ini_set('pcre.jit', '0'); // Safely disable PCRE JIT to prevent memory allocation security warnings

// Meters, OBIS readings and manual readings (bootstrap.php)
lum_connect('meters', 'obis', 'manual');

$meter_id = $_GET['meter_id'] ?? null;
$meter_id = ($meter_id !== null && ctype_digit((string)$meter_id)) ? (int)$meter_id : null;

// Unregistered meters are opened by serial number and kind (electrical / water) instead of meter_id
$unreg_serial = @preg_replace('/[^a-zA-Z0-9]/', '', (string)($_GET['serial'] ?? ''));
$unreg_kind = in_array($_GET['kind'] ?? '', ['electrical', 'water'], true) ? $_GET['kind'] : '';
$is_unregistered = false;

if (!$meter_id && $unreg_serial === '') {
    die("<div style='color:white; padding:20px; font-family:sans-serif;'>No Meter ID provided. <a href='meter-overview.php' style='color:#e3000f;'>Return to Overview</a></div>");
}

try {
    if ($meter_id) {
        // Registered meter
        $stmt = $meter_db_conn->prepare("SELECT * FROM lum_meters WHERE meter_id = :id");
        $stmt->execute(['id' => $meter_id]);
        $meter = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        // Registered after all? Then open the normal view
        $stmt = $meter_db_conn->prepare("SELECT meter_id FROM lum_meters WHERE TRIM(meter_serial) = :serial ORDER BY meter_id DESC LIMIT 1");
        $stmt->execute(['serial' => $unreg_serial]);
        $registered_id = $stmt->fetchColumn();
        if ($registered_id) {
            $query = $_GET;
            unset($query['serial'], $query['kind']);
            $query['meter_id'] = (int)$registered_id;
            header('Location: view-meter.php?' . http_build_query($query));
            exit();
        }

        // The serial must have readings in the OBIS data (the first OBIS code of each category, e.g. 1.1.1.8.0 / 8.1.1.0.0)
        $kind_codes = [];
        foreach (['electrical' => 'Electrical', 'water' => 'Water'] as $kind_key => $kind_cat) {
            $kind_first = lum_obis_codes_for($kind_cat, $meter_db_conn)[0] ?? '';
            if ($kind_first !== '') $kind_codes[$kind_key] = preg_replace('/[^0-9]/', '', $kind_first);
        }
        $kinds_to_try = ($unreg_kind !== '') ? [$unreg_kind] : ['electrical', 'water'];
        $found_kind = '';
        foreach ($kinds_to_try as $k) {
            if (!isset($kind_codes[$k])) continue;
            $code = $kind_codes[$k];
            $dbs = $obis_db_conn->query("SHOW DATABASES LIKE 'db\\_obis\\_{$code}\\_%'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($dbs as $db) {
                if (!preg_match('/^db_obis_' . $code . '_(\d{4})$/', $db, $mm)) continue;
                try {
                    $chk = $obis_db_conn->prepare("SELECT 1 FROM `$db`.`tb_obis_{$code}_{$mm[1]}` WHERE meter_serial = ? LIMIT 1");
                    $chk->execute([$unreg_serial]);
                    if ($chk->fetchColumn()) { $found_kind = $k; break 2; }
                } catch (Exception $e) {}
            }
        }

        $meter = false;
        if ($found_kind !== '') {
            $is_unregistered = true;

            // Tenant link (a tenant may already use this serial even though the meter is not registered)
            $link = null;
            try {
                lum_connect('tenants');
                $col_list = ($found_kind === 'electrical')
                    ? ['tenant_electricalMeter_01', 'tenant_electricalMeter_02', 'tenant_electricalMeter_03']
                    : ['tenant_waterMeter_01', 'tenant_waterMeter_02', 'tenant_waterMeter_03', 'tenant_waterMeter_04'];
                $where = implode(' OR ', array_map(function ($c) { return "TRIM(`$c`) = :serial"; }, $col_list));
                $t_stmt = $tenant_db_conn->prepare("SELECT tenant_name, tenant_property FROM lum_tenants WHERE $where LIMIT 1");
                $t_stmt->execute(['serial' => $unreg_serial]);
                $link = $t_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Exception $e) {
                error_log('LUM view-meter tenant lookup failed: ' . $e->getMessage());
            }

            // Same fields the page uses for registered meters
            $meter = [
                'meter_id'       => null,
                'meter_serial'   => $unreg_serial,
                'meter_type'     => ($found_kind === 'electrical') ? 'Electrical' : 'Water',
                'meter_property' => $link['tenant_property'] ?? null,
                'meter_tenant'   => $link['tenant_name'] ?? null,
            ];
            $unreg_kind = $found_kind;
        }
    }

    if (!$meter) {
        die("<div style='color:white; padding:20px; font-family:sans-serif;'>Meter not found. <a href='meter-overview.php' style='color:#e3000f;'>Return to Overview</a></div>");
    }
} catch (Exception $e) {
    error_log('LUM view-meter query failed: ' . $e->getMessage());
    die("Unable to load this meter. Please contact the system administrator.");
}

// Property access: restricted users may only view meters of their own properties (or unassigned meters)
$lum_allowed = (!empty($_SESSION['assigned_properties']) ? array_map('trim', explode(',', $_SESSION['assigned_properties'])) : null);
if ($lum_allowed !== null && !empty($meter['meter_property']) && !in_array($meter['meter_property'], $lum_allowed, true)) {
    http_response_code(403);
    die("<div style='color:white; padding:20px; font-family:sans-serif;'>You do not have access to this meter. <a href='meter-overview.php' style='color:#e3000f;'>Return to Overview</a></div>");
}

// Added @ to suppress the PCRE JIT memory warning
$serial = @preg_replace('/[^a-zA-Z0-9]/', '', $meter['meter_serial']);

// Determine Meter Type
// Electrical or water, from the meter type ("Electrical - <type>" / "Water - <type>")
$meter_category = lum_meter_category($meter['meter_type'] ?? '');
$is_electrical = ($meter_category === 'Electrical');
$is_water = ($meter_category === 'Water');

if (!$is_electrical && !$is_water) {
    die("<div style='color:white; padding:20px; font-family:sans-serif;'>Analysis is only available for electrical and water meters. <a href='meter-overview.php' style='color:#e3000f;'>Return to Overview</a></div>");
}

// Default Dates: Match the Dashboard (Previous full month)
$default_start = date('Y-m-d', strtotime('first day of previous month'));
$default_end = date('Y-m-01');

// Automated readings: date AND time. A reading is shown when its time is from the start up to and including the end;
// e.g. 1 Jan 00:30 - 2 Jan 00:00 = the 48 half-hour readings of 1 January.
// Default: last month (1st 00:30 - 1st of this month 00:00). Links with a date only: 00:30 of the start date - 00:00 after the end date.
$lum_auto_default_start = $default_start . ' 00:30';
$lum_auto_default_end = $default_end . ' 00:00';
$lum_parse_datetime = function ($value, $is_end) {
    $value = trim((string)$value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        $d = DateTime::createFromFormat('!Y-m-d', $value);
        if (!$d || $d->format('Y-m-d') !== $value) return null;
        return $is_end ? $d->modify('+1 day')->format('Y-m-d H:i') : $value . ' 00:30';
    }
    foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s'] as $fmt) {
        $d = DateTime::createFromFormat('!' . $fmt, $value);
        if ($d && $d->format($fmt) === $value) return $d->format('Y-m-d H:i');
    }
    return null;
};
$auto_start_dt = $lum_parse_datetime($_GET['auto_start_date'] ?? '', false) ?? $lum_auto_default_start;
$auto_end_dt = $lum_parse_datetime($_GET['auto_end_date'] ?? '', true) ?? $lum_auto_default_end;

$manual_start_date = $_GET['manual_start_date'] ?? $default_start;
$manual_end_date = $_GET['manual_end_date'] ?? $default_end;

// Only accept real dates in a sensible range (the year range drives how many databases are scanned):
// from the first year that has reading databases (automated readings: this meter's OBIS codes;
// manual extracts: the manual databases) up to next year. Unknown: the last five years.
$lum_year_fallback = (int)date('Y') - 5;
$lum_auto_years = lum_reading_years($obis_db_conn, lum_obis_codes_for($is_electrical ? 'Electrical' : 'Water', $meter_db_conn));
$lum_manual_years = lum_reading_years($manual_db_conn, [], true);
$lum_min_year = [
    'auto'   => $lum_auto_years ? $lum_auto_years[0] : $lum_year_fallback,
    'manual' => $lum_manual_years ? $lum_manual_years[0] : $lum_year_fallback,
];
$lum_max_year = (int)date('Y') + 1;
$lum_date_ok = function ($d, $min_year) use ($lum_max_year) {
    $dt = DateTime::createFromFormat('Y-m-d', (string)$d);
    if (!$dt || $dt->format('Y-m-d') !== $d) return false;
    $y = (int)$dt->format('Y');
    return ($y >= $min_year && $y <= $lum_max_year);
};
// Automated readings: within the year range, end after start, at most 5 years
if ((int)substr($auto_start_dt, 0, 4) < $lum_min_year['auto'] || (int)substr($auto_end_dt, 0, 4) > $lum_max_year
    || strtotime($auto_end_dt) <= strtotime($auto_start_dt)
    || ((int)substr($auto_end_dt, 0, 4) - (int)substr($auto_start_dt, 0, 4)) > 5) {
    $auto_start_dt = $lum_auto_default_start;
    $auto_end_dt = $lum_auto_default_end;
}
$auto_start_date = substr($auto_start_dt, 0, 10);
$auto_end_date = substr($auto_end_dt, 0, 10);

foreach ([['manual_start_date', 'manual_end_date', 'manual']] as $pair) {
    list($sk, $ek, $kind) = $pair;
    if (!$lum_date_ok($$sk, $lum_min_year[$kind])) $$sk = $default_start;
    if (!$lum_date_ok($$ek, $lum_min_year[$kind])) $$ek = $default_end;
    if (strtotime($$ek) < strtotime($$sk)) { $$sk = $default_start; $$ek = $default_end; }
    // At most 5 years in one view
    if (((int)date('Y', strtotime($$ek)) - (int)date('Y', strtotime($$sk))) > 5) { $$sk = $default_start; $$ek = $default_end; }
}

// --- AUTOMATED READINGS ENGINE (yearly OBIS tables db_obis_<code>_<year>) ---
// One reading: the last before $before, the first from $from, or the last up to $to (within the given years)
function lumMeterEdgeReading($pdo, $serial, $clean_code, $sql_where, array $params, $order, array $years) {
    $col = "{$clean_code}_value";
    foreach ($years as $y) {
        try {
            $st = $pdo->prepare("SELECT Time_stamp, `$col` AS val FROM `db_obis_{$clean_code}_{$y}`.`tb_obis_{$clean_code}_{$y}`
                                 WHERE meter_serial = ? AND `$col` > 0 AND $sql_where ORDER BY Time_stamp $order LIMIT 1");
            $st->execute(array_merge([$serial], $params));
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) return ['ts' => $row['Time_stamp'], 'v' => (float)$row['val']];
        } catch (Exception $e) {}
    }
    return null;
}

// Consumption per reading between two date-times (both included): [ 'Y-m-d H:i' => kWh/kL ]
// Each reading's value is the use since the reading before it (the first uses the reading just before the start).
function lumMeterReadingUsage($pdo, $serial, $clean_code, $start, $end, $baseline) {
    $col = "{$clean_code}_value";
    $out = [];
    $prev = $baseline;
    for ($y = (int)substr($start, 0, 4); $y <= (int)substr($end, 0, 4); $y++) {
        try {
            $st = $pdo->prepare("SELECT Time_stamp, `$col` AS val FROM `db_obis_{$clean_code}_{$y}`.`tb_obis_{$clean_code}_{$y}`
                                 WHERE meter_serial = ? AND Time_stamp >= ? AND Time_stamp <= ? AND `$col` > 0 ORDER BY Time_stamp ASC");
            $st->execute([$serial, $start . ':00', $end . ':00']);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $v = (float)$row['val'];
                $out[substr($row['Time_stamp'], 0, 16)] = ($prev !== null) ? max(0, $v - $prev) : null;
                $prev = $v;
            }
        } catch (Exception $e) {}
    }
    return $out;
}

// Consumption per day between two date-times: [ 'Y-m-d' => kWh/kL ].
// A reading at 00:00 ends the previous day's last half hour, so it counts towards the previous day.
function lumMeterDailyUsage($pdo, $serial, $clean_code, $start, $end, $baseline) {
    $col = "{$clean_code}_value";
    $max = [];
    $min = [];
    for ($y = (int)substr($start, 0, 4); $y <= (int)substr($end, 0, 4); $y++) {
        try {
            $st = $pdo->prepare("SELECT DATE(Time_stamp - INTERVAL 1 SECOND) AS dt, MAX(`$col`) AS max_v, MIN(`$col`) AS min_v
                                 FROM `db_obis_{$clean_code}_{$y}`.`tb_obis_{$clean_code}_{$y}`
                                 WHERE meter_serial = ? AND Time_stamp >= ? AND Time_stamp <= ? AND `$col` > 0
                                 GROUP BY DATE(Time_stamp - INTERVAL 1 SECOND)");
            $st->execute([$serial, $start . ':00', $end . ':00']);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $d = $row['dt'];
                $max[$d] = isset($max[$d]) ? max($max[$d], (float)$row['max_v']) : (float)$row['max_v'];
                $min[$d] = isset($min[$d]) ? min($min[$d], (float)$row['min_v']) : (float)$row['min_v'];
            }
        } catch (Exception $e) {}
    }
    ksort($max);
    $daily = [];
    $prev = $baseline;
    foreach ($max as $d => $m) {
        $daily[$d] = ($prev !== null) ? max(0, $m - $prev) : max(0, $m - $min[$d]);
        $prev = $m;
    }
    return $daily;
}

// --- 1. Fetch Automated Readings Summary (DYNAMIC OBIS TARGETING) ---
// Every OBIS code of the meter's category (Configurations -> OBIS Time Ranges)
$obis_list = lum_obis_codes($meter_db_conn);
$obis_codes = lum_obis_codes_for($is_electrical ? 'Electrical' : 'Water', $meter_db_conn);
$auto_data = [];
$chart_labels = [];
$auto_series = [];

// Up to 7 days: one graph point per reading; longer: one point per day
$auto_start_ts = strtotime($auto_start_dt);
$auto_end_ts = strtotime($auto_end_dt);
$auto_per_reading = (($auto_end_ts - $auto_start_ts) <= 7 * 86400);
$auto_start_year = (int)substr($auto_start_dt, 0, 4);
$auto_end_year = (int)substr($auto_end_dt, 0, 4);

foreach ($obis_codes as $code) {
    $clean_code = preg_replace('/[^0-9]/', '', $code);
    // Opening reading: the reading just before the start (otherwise the first one in the period)
    $open = lumMeterEdgeReading($obis_db_conn, $serial, $clean_code, 'Time_stamp < ?', [$auto_start_dt . ':00'], 'DESC', [$auto_start_year, $auto_start_year - 1]);
    $first = lumMeterEdgeReading($obis_db_conn, $serial, $clean_code, 'Time_stamp >= ? AND Time_stamp <= ?', [$auto_start_dt . ':00', $auto_end_dt . ':00'], 'ASC', range($auto_start_year, $auto_end_year));
    $close = lumMeterEdgeReading($obis_db_conn, $serial, $clean_code, 'Time_stamp >= ? AND Time_stamp <= ?', [$auto_start_dt . ':00', $auto_end_dt . ':00'], 'DESC', range($auto_end_year, $auto_start_year));
    if ($open === null) $open = $first;
    if ($open !== null && $close !== null) {
        $auto_data[$code] = ['open_v' => $open['v'], 'close_v' => $close['v'], 'open_ts' => $open['ts'], 'close_ts' => $close['ts']];
    }
    $baseline = $open !== null && $first !== null && $open['ts'] !== $first['ts'] ? $open['v'] : null;
    $auto_series[$code] = $auto_per_reading
        ? lumMeterReadingUsage($obis_db_conn, $serial, $clean_code, $auto_start_dt, $auto_end_dt, $baseline)
        : lumMeterDailyUsage($obis_db_conn, $serial, $clean_code, $auto_start_dt, $auto_end_dt, $baseline);
}

// Graph points: every reading time (per reading) or every day of the period (per day)
$auto_keys = [];
if ($auto_per_reading) {
    foreach ($auto_series as $series) foreach ($series as $k => $v) $auto_keys[$k] = true;
    $auto_keys = array_keys($auto_keys);
    sort($auto_keys);
    foreach ($auto_keys as $k) $chart_labels[] = date('d M H:i', strtotime($k));
} else {
    $day = new DateTime(date('Y-m-d', $auto_start_ts - 1));
    $last_day = new DateTime(date('Y-m-d', $auto_end_ts - 1));
    while ($day <= $last_day) {
        $auto_keys[] = $day->format('Y-m-d');
        $chart_labels[] = $day->format('d M');
        $day->modify('+1 day');
    }
}

// Final arrays; generator (1.1.1.8.2) = total (1.1.1.8.0) - grid (1.1.1.8.1). Missing readings stay empty (null) per reading, 0 per day.
$final_chart_data = [];
foreach ($auto_keys as $k) {
    foreach ($obis_codes as $code) {
        $v = $auto_series[$code][$k] ?? ($auto_per_reading ? null : 0);
        if ($code === '1.1.1.8.2' && isset($auto_series['1.1.1.8.0'], $auto_series['1.1.1.8.1'])) {
            $v0 = $auto_series['1.1.1.8.0'][$k] ?? ($auto_per_reading ? null : 0);
            $v1 = $auto_series['1.1.1.8.1'][$k] ?? ($auto_per_reading ? null : 0);
            $v = ($v0 === null || $v1 === null) ? null : max(0, $v0 - $v1);
        }
        $final_chart_data[$code][] = ($v === null) ? null : round($v, 3);
    }
}

// Graph series: name and colour of each OBIS code
$auto_chart_sets = [];
foreach ($obis_codes as $code) {
    $auto_chart_sets[$code] = ['data' => $final_chart_data[$code] ?? [], 'label' => lum_obis_label($code, $meter_db_conn), 'color' => $obis_list[$code]['color'] ?? '#6c757d'];
}
$auto_first_code = $obis_codes[0] ?? '';

// --- 2. Fetch Manual Extracts with Dynamic RTC Detection & Schema Discovery ---
if ($is_electrical) {
    $manual_dbs = [
        'Analysis Logger'    => 'db_manual_analysis_',
        'Debit Logger 1'     => 'db_manual_debit1_',
        'Debit Logger 2'     => 'db_manual_debit2_',
        'Generic Debit Logs' => 'db_manual_debit_' // Fallback for legacy 202606~1.CSV formats
    ];
} else {
    // Assume water logger follows same pattern if we add it later
    $manual_dbs = [
        'Water Logger' => 'db_manual_water_'
    ];
}

$manual_data = [];

$startYear = (int)date('Y', strtotime($manual_start_date));
$endYear = (int)date('Y', strtotime($manual_end_date));

foreach ($manual_dbs as $name => $dbPrefix) {
    $manual_data[$name] = [];
    
    // Safely scan across multiple possible year boundaries
    for ($y = $startYear; $y <= $endYear; $y++) {
        $dbName = $dbPrefix . $y;
        
        try {
            // Securely check if DB exists via SHOW DATABASES (Bypasses information_schema strict blocks)
            $dbCheck = $manual_db_conn->query("SHOW DATABASES LIKE '$dbName'");
            if ($dbCheck->rowCount() === 0) continue;
            
            $tbStmt = $manual_db_conn->query("SHOW TABLES IN `$dbName`");
            $tables = $tbStmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($tables as $tb) {
                // Discover RTC column dynamically for this specific schema
                $rtc_col = null;
                $colStmt = $manual_db_conn->query("SHOW COLUMNS FROM `$dbName`.`$tb`");
                $columns = $colStmt->fetchAll(PDO::FETCH_COLUMN);
                
                // Absolute Safety Check: Ensure the table actually belongs to the meter system
                if (!in_array('meter_serial', $columns)) continue;
                
                foreach ($columns as $c) {
                    if (stripos($c, 'RTC') !== false) {
                        $rtc_col = $c;
                        break;
                    }
                }
                
                // Fallback to the second column if no 'RTC' is explicitly labelled
                if (!$rtc_col && isset($columns[1])) {
                    $rtc_col = $columns[1]; 
                }
                
                if ($rtc_col) {
                    // Extract specifically the targeted meter serial data from the shared schema table
                    $stmt = $manual_db_conn->prepare("SELECT * FROM `$dbName`.`$tb` WHERE `meter_serial` = :serial AND `$rtc_col` >= :start AND `$rtc_col` <= :end ORDER BY `$rtc_col` ASC");
                    $stmt->execute([
                        'serial' => $serial, 
                        'start' => $manual_start_date.' 00:00:00', 
                        'end' => $manual_end_date.' 23:59:59'
                    ]);
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (!empty($rows)) {
                        // Append successfully retrieved rows to our master array for this logger type
                        $manual_data[$name] = array_merge($manual_data[$name], $rows);
                    }
                }
            }
        } catch (Exception $e) { }
    }
}

// Assemble Manual Chart Arrays dynamically
$manual_charts = [];
foreach ($manual_data as $tab_name => $rows) {
    if (empty($rows)) continue;
    
    // Sort chronologically for charting
    usort($rows, function($a, $b) {
        $rtcA = ''; $rtcB = '';
        foreach($a as $k => $v) { if (stripos($k, 'RTC') !== false) { $rtcA = $v; break; } }
        foreach($b as $k => $v) { if (stripos($k, 'RTC') !== false) { $rtcB = $v; break; } }
        return strcmp($rtcA, $rtcB); 
    });

    $tab_id = @preg_replace('/[^a-zA-Z0-9]/', '', $tab_name);
    $manual_charts[$tab_id] = [
        'labels' => [],
        'datasets' => []
    ];
    
    // Auto-detect numerical columns suitable for graphing
    $numerical_cols = [];
    if (!empty($rows)) {
        $first_row = $rows[0];
        foreach ($first_row as $key => $val) {
            // Include kWh, kVArh, kVA, kW, Tariff identifiers, while explicitly excluding RTC, status, ids, and counters
            if (@preg_match('/(kwh|kvarh|kva|kw|v|a|tariff)/i', $key) && !@preg_match('/(status|quality|log_id|rtc|counter)/i', $key)) {
                $numerical_cols[] = $key;
            }
        }
    }
    
    // Build dataset template
    foreach ($numerical_cols as $col) {
        $clean_label = str_ireplace(['_plus_', '_minus_', '_plus', '_minus'], ['+ ', '- ', '+', '-'], $col);
        $clean_label = str_replace('_', ' ', $clean_label);
        $manual_charts[$tab_id]['datasets'][$col] = [
            'label' => $clean_label,
            'data' => []
        ];
    }
    
    // Populate Data
    $prev_vals = [];
    foreach ($rows as $row) {
        $rtc_val = '';
        foreach($row as $k => $v) { if (stripos($k, 'RTC') !== false) { $rtc_val = $v; break; } }
        $manual_charts[$tab_id]['labels'][] = date('d M H:i', strtotime($rtc_val));
        
        foreach ($numerical_cols as $col) {
            $cur = (float)($row[$col] ?? 0);
            
            // Calculate delta usage (Current - Previous) to avoid graphing massive lifetime accumulators
            if (isset($prev_vals[$col]) && $prev_vals[$col] > 0 && $cur >= $prev_vals[$col]) {
                $usage = $cur - $prev_vals[$col];
            } else {
                $usage = 0; // First reading or reset
            }
            $manual_charts[$tab_id]['datasets'][$col]['data'][] = round($usage, 4);
            $prev_vals[$col] = $cur;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meter Analysis - <?php echo htmlspecialchars($meter['meter_serial']); ?></title>
    
    <?php include LUM_ROOT . '/Layout/head-assets.php'; ?>
    
    <!-- DataTables for instant sorting -->
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet" integrity="sha384-5oFfLntNy8kuC2TaebWZbaHTqdh3Q+7PwYbB490gupK0YtTAB7mBJGv4bQl9g9rK" crossorigin="anonymous">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ" crossorigin="anonymous"></script>

    <style>
        body { background-color: #121212; color: #ffffff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; overflow: hidden; margin: 0; padding: 0; }
        /* Layout Structure */
        .d-flex-wrapper { display: flex; width: 100%; height: calc(100vh - 98px); overflow: hidden; }
        /* Sticky Top Navbar */
        .navbar { background-color: #000000; border-bottom: 1px solid #333333 !important; z-index: 1050; position: sticky; top: 0; height: 46px;}
        /* Full Width Sub Navbar */
        .action-bar { background-color: #0a0a0a; border-bottom: 1px solid #333333; padding: 10px 0; color: #ffffff; z-index: 1040; position: sticky; top: 46px;}
        .btn-outline-lynx { color: #ffffff; border: 1px solid #555555; background-color: transparent; transition: all 0.3s ease; border-radius: 4px !important;}
        /* Left Sidebar with independent scroll */
        .sidebar { 
            width: 320px; 
            min-width: 320px; 
            background: linear-gradient(180deg, #000 0%, #0f0f0f 100%); 
            padding: 25px 15px; 
            border-right: 1px solid #333;
            overflow-y: auto;
            transition: margin-left 0.35s cubic-bezier(0.25, 0.8, 0.25, 1);
            z-index: 1000;
        }
        .sidebar.collapsed { margin-left: -320px; }
        /* Main Content Area with independent scroll */
        .main-content { flex-grow: 1; min-width: 0; overflow-y: auto; background-color: #121212; display: flex; flex-direction: column; }
        .content-scrollable { padding: 30px; flex-grow: 1; }
        /* Custom Scrollbars */
        ::-webkit-scrollbar { width: 14px; height: 14px; }
        ::-webkit-scrollbar-track { background: #0a0a0a; border-radius: 0; }
        ::-webkit-scrollbar-thumb { background: #4a4a4a; border-radius: 0; }
        /* Form & Filter Elements */
        * { border-radius: 0 !important; font-weight: normal !important; text-transform: none !important; }
        .form-control, .form-select { background-color: #1e1e1e !important; border: 1px solid #444 !important; color: #fff !important; }
        .form-control:focus, .form-select:focus { border-color: #e3000f !important; box-shadow: 0 0 0 0.25rem rgba(227, 0, 15, 0.25) !important; }
        /* Summary Cards */
        .summary-card { background-color: #1e1e1e; border: 1px solid #333; padding: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.2); }
        .summary-card h6 { color: #b0b0b0; font-weight: 700 !important; font-size: 0.85rem; letter-spacing: 0.5px; margin-bottom: 15px;}
        .summary-card h2 { color: #ffffff; font-weight: 800 !important; font-size: 1.8rem; margin-bottom: 0; }
        .border-red { border-left: 5px solid #e3000f; }
        .border-green { border-left: 5px solid #198754; }
        .border-yellow { border-left: 5px solid #ffc107; }
        .border-blue { border-left: 5px solid #0dcaf0; }
        /* Tabs */
        .nav-pills .nav-link { color: #b0b0b0; font-weight: 600 !important; padding: 10px 20px; margin-right: 5px; }
        .nav-pills .nav-link:hover { color: #fff; background-color: #1e1e1e; }
        .nav-pills .nav-link.active { background-color: #0dcaf0; color: #000; }
        /* Tables */
        .table-container { background-color: #000000; border: 1px solid #333333; padding: 20px;}
        .table { color: #ffffff; margin-bottom: 0;}
        .table thead th { background-color: #1a1a1a; color: #ffffff; border-bottom: 2px solid #333333; font-weight: 600 !important; text-transform: uppercase !important; font-size: 0.9rem; letter-spacing: 0.5px; position: sticky; top: 0; z-index: 10; box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.4); }
        .table tbody td { border-bottom: 1px solid #222222; vertical-align: middle; background-color: transparent; color: #ffffff; font-size: 0.9rem;}
        .table tbody tr:hover td { background-color: #111111; }
        /* DataTables Dark Mode overrides */
        div.dataTables_wrapper div.dataTables_length select, div.dataTables_wrapper div.dataTables_filter input { background-color: #1e1e1e; border: 1px solid #444; color: #fff; }
        div.dataTables_wrapper div.dataTables_info { color: #aaa; display: none !important; }
        .dataTables_paginate { display: none !important; }
        .dataTables_filter { display: none !important; }
        /* Hide search filter */
    </style>
</head>
<body>

    <?php
    $lum_nav_left = '<button class="btn btn-dark p-1 me-3" id="sidebarToggle" title="Toggle Sidebar" style="background-color: #000; border: 1px solid #333;"> <i class="bi bi-list text-danger fs-5 px-1"></i> </button>';
    include LUM_ROOT . '/Layout/top-navbar.php';
    ?>

    <!-- Dynamic Top Action Bar -->
    <div class="action-bar px-4 border-bottom border-secondary w-100">
        <div class="d-flex align-items-center">
            <a href="meter-overview.php" class="btn btn-brand btn-sm px-2 shadow-sm me-3">
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>
            <h5 class="mb-0 text-white fs-6 fw-normal">
                <?php echo $is_electrical ? 'Electrical' : 'Water'; ?> Analysis — <?php echo htmlspecialchars($meter['meter_serial']); ?>
                <?php if ($is_unregistered): ?>
                    <span class="badge bg-warning text-dark ms-2 fw-normal">Not registered</span>
                <?php endif; ?>
            </h5>
        </div>
    </div>

    <div class="d-flex-wrapper">
        <!-- Scrollable Sidebar -->
        <div class="sidebar shadow" id="sidebar">
            <h5 class="text-white mb-4">Analysis Filters</h5>
            <!-- Added ID to form for seamless state recovery logic -->
            <form method="GET" action="view-meter.php" id="filterForm">
                <?php if ($is_unregistered): ?>
                <input type="hidden" name="serial" value="<?php echo htmlspecialchars($unreg_serial); ?>">
                <input type="hidden" name="kind" value="<?php echo htmlspecialchars($unreg_kind); ?>">
                <?php else: ?>
                <input type="hidden" name="meter_id" value="<?php echo htmlspecialchars((string)$meter_id); ?>">
                <?php endif; ?>
                
                <div class="p-3 mb-4 border border-secondary" style="background-color: #1a1a1a;">
                    <h6 class="text-uppercase fw-bold text-info mb-3" style="font-size: 0.85rem;"><i class="bi bi-cpu me-1"></i> Automated Readings</h6>
                    <div class="mb-3">
                        <label class="form-label small mb-1 text-light">Start Date &amp; Time</label>
                        <input type="datetime-local" name="auto_start_date" class="form-control form-control-sm auto-save auto-datetime" value="<?php echo htmlspecialchars(str_replace(' ', 'T', $auto_start_dt)); ?>" min="<?php echo (int)$lum_min_year['auto']; ?>-01-01T00:00" max="<?php echo (int)$lum_max_year; ?>-12-31T23:59" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-1 text-light">End Date &amp; Time</label>
                        <input type="datetime-local" name="auto_end_date" class="form-control form-control-sm auto-save auto-datetime" value="<?php echo htmlspecialchars(str_replace(' ', 'T', $auto_end_dt)); ?>" min="<?php echo (int)$lum_min_year['auto']; ?>-01-01T00:00" max="<?php echo (int)$lum_max_year; ?>-12-31T23:59" required>
                    </div>
                </div>

                <div class="p-3 mb-4 border border-secondary" style="background-color: #1a1a1a;">
                    <h6 class="text-uppercase fw-bold text-warning mb-3" style="font-size: 0.85rem;"><i class="bi bi-journal-text me-1"></i> Manual Readings</h6>
                    <div class="mb-3">
                        <label class="form-label small mb-1 text-light">Start Date</label>
                        <input type="date" name="manual_start_date" class="form-control form-control-sm auto-save" value="<?php echo htmlspecialchars($manual_start_date); ?>" min="<?php echo (int)$lum_min_year['manual']; ?>-01-01" max="<?php echo (int)$lum_max_year; ?>-12-31" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-1 text-light">End Date</label>
                        <input type="date" name="manual_end_date" class="form-control form-control-sm auto-save" value="<?php echo htmlspecialchars($manual_end_date); ?>" min="<?php echo (int)$lum_min_year['manual']; ?>-01-01" max="<?php echo (int)$lum_max_year; ?>-12-31" required>
                    </div>
                </div>
            </form>
            
            <div class="mt-4 border-top border-secondary pt-4">
                <h6 class="text-secondary mb-3 text-uppercase" style="font-size: 0.8rem;">Meter Details</h6>
                <div class="small text-light">
                    <p class="mb-1"><span class="text-muted">Serial:</span> <strong class="fw-bold !important"><?php echo htmlspecialchars($meter['meter_serial']); ?></strong></p>
                    <p class="mb-1"><span class="text-muted">Type:</span> <?php echo htmlspecialchars(str_replace('_', ' ', $meter['meter_type'])); ?></p>
                    <p class="mb-1"><span class="text-muted">Property:</span> <?php echo htmlspecialchars($meter['meter_property'] ?? 'Unassigned'); ?></p>
                    <p class="mb-1"><span class="text-muted">Tenant:</span> <?php echo htmlspecialchars($meter['meter_tenant'] ?? 'N/A'); ?></p>
                    <?php if ($is_unregistered): ?>
                        <div class="alert alert-warning py-2 px-2 mt-3 mb-0 small">
                            <i class="bi bi-exclamation-triangle me-1"></i>This meter has readings in the meter data but is not registered in Meter Management.
                            <?php if (lum_can('meters', 'edit')): ?>
                                <a href="https://lynx-um.co.za/Meter Management/Meter Registration/meter-register-form.php" class="alert-link d-block mt-1">Register this meter</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Main Content (Independent Scroll) -->
        <div class="main-content" id="mainContent">
            <div class="content-scrollable">
                
                <!-- 1. Automated Readings Summary Collapsible -->
                <div class="d-flex justify-content-between align-items-center border-bottom border-secondary pb-2 mb-3 mt-2" data-bs-toggle="collapse" data-bs-target="#automatedCollapse" aria-expanded="false" aria-controls="automatedCollapse" style="cursor: pointer;" id="automatedToggleBtn">
                    <h5 class="mb-0 fs-5 text-info fw-bold">Automated Meter Summary</h5>
                    <i class="bi bi-plus-circle fs-5 text-info toggle-icon" style="transition: transform 0.2s;"></i>
                </div>
                
                <div class="collapse" id="automatedCollapse">
                    <div class="row g-4 mb-4 mt-2">
                        
                        <?php if ($is_electrical): ?>
                            <!-- 1.1.1.8.0 Card (Electrical) -->
                            <div class="col-md-4">
                                <div class="summary-card border-red">
                                    <h6><?php echo htmlspecialchars(strtoupper(lum_obis_label('1.1.1.8.0', $meter_db_conn))); ?></h6>
                                    <?php if (isset($auto_data['1.1.1.8.0'])): $d = $auto_data['1.1.1.8.0']; $usage = max(0, $d['close_v'] - $d['open_v']); ?>
                                        <h2 class="text-danger mb-3"><?php echo number_format($usage, 2); ?> <span class="fs-6 text-muted">kWh</span></h2>
                                        <div class="d-flex justify-content-between small text-muted">
                                            <span title="Reading at <?php echo htmlspecialchars(date('d M Y H:i', strtotime($d['open_ts']))); ?>">Open: <strong class="text-light fw-bold !important"><?php echo number_format($d['open_v'], 2); ?></strong></span>
                                            <span title="Reading at <?php echo htmlspecialchars(date('d M Y H:i', strtotime($d['close_ts']))); ?>">Close: <strong class="text-light fw-bold !important"><?php echo number_format($d['close_v'], 2); ?></strong></span>
                                        </div>
                                    <?php else: ?>
                                        <h4 class="text-muted fst-italic mt-2">No Automated Data</h4>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- 1.1.1.8.1 Card (Electrical) -->
                            <div class="col-md-4">
                                <div class="summary-card border-green">
                                    <h6><?php echo htmlspecialchars(strtoupper(lum_obis_label('1.1.1.8.1', $meter_db_conn))); ?></h6>
                                    <?php if (isset($auto_data['1.1.1.8.1'])): $d = $auto_data['1.1.1.8.1']; $usage = max(0, $d['close_v'] - $d['open_v']); ?>
                                        <h2 class="text-success mb-3"><?php echo number_format($usage, 2); ?> <span class="fs-6 text-muted">kWh</span></h2>
                                        <div class="d-flex justify-content-between small text-muted">
                                            <span title="Reading at <?php echo htmlspecialchars(date('d M Y H:i', strtotime($d['open_ts']))); ?>">Open: <strong class="text-light fw-bold !important"><?php echo number_format($d['open_v'], 2); ?></strong></span>
                                            <span title="Reading at <?php echo htmlspecialchars(date('d M Y H:i', strtotime($d['close_ts']))); ?>">Close: <strong class="text-light fw-bold !important"><?php echo number_format($d['close_v'], 2); ?></strong></span>
                                        </div>
                                    <?php else: ?>
                                        <h4 class="text-muted fst-italic mt-2">No Automated Data</h4>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- 1.1.1.8.2 Card (Electrical) -->
                            <div class="col-md-4">
                                <div class="summary-card border-yellow">
                                    <h6><?php echo htmlspecialchars(strtoupper(lum_obis_label('1.1.1.8.2', $meter_db_conn))); ?></h6>
                                    <?php if (isset($auto_data['1.1.1.8.2'])): $d = $auto_data['1.1.1.8.2']; 
                                          // Ensuring mathematical integrity with formula 1.1.1.8.0 - 1.1.1.8.1 
                                          $usage0 = isset($auto_data['1.1.1.8.0']) ? max(0, $auto_data['1.1.1.8.0']['close_v'] - $auto_data['1.1.1.8.0']['open_v']) : 0;
                                          $usage1 = isset($auto_data['1.1.1.8.1']) ? max(0, $auto_data['1.1.1.8.1']['close_v'] - $auto_data['1.1.1.8.1']['open_v']) : 0;
                                          $usage = max(0, $usage0 - $usage1);
                                    ?>
                                        <h2 class="text-warning mb-3"><?php echo number_format($usage, 2); ?> <span class="fs-6 text-muted">kWh</span></h2>
                                        <div class="d-flex justify-content-between small text-muted">
                                            <span title="Reading at <?php echo htmlspecialchars(date('d M Y H:i', strtotime($d['open_ts']))); ?>">Open: <strong class="text-light fw-bold !important"><?php echo number_format($d['open_v'], 2); ?></strong></span>
                                            <span title="Reading at <?php echo htmlspecialchars(date('d M Y H:i', strtotime($d['close_ts']))); ?>">Close: <strong class="text-light fw-bold !important"><?php echo number_format($d['close_v'], 2); ?></strong></span>
                                        </div>
                                    <?php else: ?>
                                        <h4 class="text-muted fst-italic mt-2">No Automated Data</h4>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- 8.1.1.0.0 Card (Water) -->
                            <div class="col-md-4">
                                <div class="summary-card border-blue">
                                    <h6><?php echo htmlspecialchars(strtoupper(lum_obis_label('8.1.1.0.0', $meter_db_conn))); ?></h6>
                                    <?php if (isset($auto_data['8.1.1.0.0'])): $d = $auto_data['8.1.1.0.0']; $usage = max(0, $d['close_v'] - $d['open_v']); ?>
                                        <h2 class="text-info mb-3"><?php echo number_format($usage, 2); ?> <span class="fs-6 text-muted">kL</span></h2>
                                        <div class="d-flex justify-content-between small text-muted">
                                            <span title="Reading at <?php echo htmlspecialchars(date('d M Y H:i', strtotime($d['open_ts']))); ?>">Open: <strong class="text-light fw-bold !important"><?php echo number_format($d['open_v'], 2); ?></strong></span>
                                            <span title="Reading at <?php echo htmlspecialchars(date('d M Y H:i', strtotime($d['close_ts']))); ?>">Close: <strong class="text-light fw-bold !important"><?php echo number_format($d['close_v'], 2); ?></strong></span>
                                        </div>
                                    <?php else: ?>
                                        <h4 class="text-muted fst-italic mt-2">No Automated Data</h4>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                    </div>
                    
                    <!-- Interactive Automated Readings Graph -->
                    <div class="card border border-secondary shadow-sm p-4 mb-5" style="background-color: #1a1a1a;">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <div></div>
                            <?php $chart_type_select = function ($id, $title) { ?>
                                <select id="<?php echo $id; ?>" class="form-select form-select-sm bg-dark text-white border-secondary auto-type-filter" style="width: auto;" title="<?php echo htmlspecialchars($title); ?>">
                                    <option value="bar">Bar</option>
                                    <option value="line">Line</option>
                                </select>
                            <?php }; ?>
                            <?php if ($is_electrical): ?>
                            <!-- Up to three OBIS codes on the same graph for comparison, each as a bar or a line -->
                            <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
                                <div class="d-flex gap-1">
                                <select id="autoChartFilter" class="form-select form-select-sm bg-dark text-white border-secondary auto-obis-filter" style="width: auto;" title="Graph 1">
                                    <?php foreach ($obis_codes as $opt_code): ?><option value="<?php echo htmlspecialchars($opt_code); ?>"><?php echo htmlspecialchars(lum_obis_label($opt_code, $meter_db_conn)); ?></option><?php endforeach; ?>
                                </select>
                                <?php $chart_type_select('autoChartType', 'Graph 1: bar or line'); ?>
                                </div>
                                <?php foreach ([2, 3] as $n): ?>
                                <div class="d-flex gap-1">
                                <select id="autoChartFilter<?php echo $n; ?>" class="form-select form-select-sm bg-dark text-white border-secondary auto-obis-filter" style="width: auto;" title="Compare with (graph <?php echo $n; ?>)">
                                    <option value="">Compare: none</option>
                                    <?php foreach ($obis_codes as $opt_code): ?><option value="<?php echo htmlspecialchars($opt_code); ?>"><?php echo htmlspecialchars(lum_obis_label($opt_code, $meter_db_conn)); ?></option><?php endforeach; ?>
                                </select>
                                <?php $chart_type_select('autoChartType' . $n, 'Graph ' . $n . ': bar or line'); ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <?php $chart_type_select('autoChartType', 'Bar or line'); ?>
                            <?php endif; ?>
                        </div>
                        <div style="position: relative; height: 320px; width: 100%;">
                            <canvas id="autoConsumptionChart"></canvas>
                        </div>
                    </div>
                    
                </div>

                <!-- 2. Manual Extracts Collapsible -->
                <div class="d-flex justify-content-between align-items-center border-bottom border-secondary pb-2 mb-3 mt-4" data-bs-toggle="collapse" data-bs-target="#manualCollapse" aria-expanded="false" aria-controls="manualCollapse" style="cursor: pointer;" id="manualToggleBtn">
                    <h5 class="mb-0 fs-5 text-info fw-bold">Manual DB Extracts</h5>
                    <i class="bi bi-plus-circle fs-5 text-info toggle-icon" style="transition: transform 0.2s;"></i>
                </div>
                
                <div class="collapse" id="manualCollapse">
                    <div class="mt-3">
                        <ul class="nav nav-pills mb-3" id="manualTabs" role="tablist">
                            <?php 
                            $first = true;
                            foreach ($manual_dbs as $name => $dbPrefix): 
                                // Only render the Uncategorized/Generic logs tab if data exists to prevent clutter
                                if ($name === 'Generic Debit Logs' && empty($manual_data[$name])) continue;

                                $id = @preg_replace('/[^a-zA-Z0-9]/', '', $name);
                            ?>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?php echo $first ? 'active' : ''; ?>" id="<?php echo $id; ?>-tab" data-bs-toggle="pill" data-bs-target="#<?php echo $id; ?>" type="button" role="tab" onclick="initManualChart('<?php echo $id; ?>')"><?php echo htmlspecialchars($name); ?></button>
                            </li>
                            <?php $first = false; endforeach; ?>
                        </ul>

                        <div class="tab-content mb-5" id="manualTabsContent">
                            <?php 
                            $first = true;
                            foreach ($manual_dbs as $name => $dbPrefix): 
                                if ($name === 'Generic Debit Logs' && empty($manual_data[$name])) continue;

                                $id = @preg_replace('/[^a-zA-Z0-9]/', '', $name);
                                $data = $manual_data[$name];
                                
                                // Retrieve corresponding dynamic JSON graph config
                                $chart_json = isset($manual_charts[$id]) ? json_encode($manual_charts[$id]) : '{}';
                            ?>
                            <div class="tab-pane fade <?php echo $first ? 'show active' : ''; ?>" id="<?php echo $id; ?>" role="tabpanel">
                                
                                <div class="table-container shadow mb-4">
                                    <?php if (!empty($data)): 
                                        // Gather ALL unique headers across potentially different schemas
                                        $headerSet = [];
                                        foreach ($data as $row) {
                                            foreach (array_keys($row) as $key) {
                                                if ($key !== 'meter_serial') {
                                                    $headerSet[$key] = true;
                                                }
                                            }
                                        }
                                        $headers = array_keys($headerSet);
                                        
                                        // Chronologically sort rows by RTC if it exists across multiple merged tables
                                        // NOTE: The graph arrays were already sorted ascending during calculation.
                                        // We sort the table descending so the newest data is at the top.
                                        usort($data, function($a, $b) {
                                            $rtcA = ''; $rtcB = '';
                                            foreach($a as $k => $v) { if (stripos($k, 'RTC') !== false) { $rtcA = $v; break; } }
                                            foreach($b as $k => $v) { if (stripos($k, 'RTC') !== false) { $rtcB = $v; break; } }
                                            return strcmp($rtcB, $rtcA); 
                                        });
                                    ?>
                                    <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                                        <table class="table table-hover table-borderless manual-table w-100">
                                            <thead>
                                                <tr>
                                                    <?php foreach ($headers as $h): 
                                                        // Make header titles clean and beautiful without changing actual keys
                                                        $display_h = str_ireplace(['_plus_', '_minus_', '_plus', '_minus'], ['+ ', '- ', '+', '-'], $h);
                                                        $display_h = str_replace('_', ' ', $display_h);
                                                    ?>
                                                        <th class="text-nowrap text-white"><?php echo htmlspecialchars($display_h); ?></th>
                                                    <?php endforeach; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($data as $row): ?>
                                                <tr>
                                                    <?php foreach ($headers as $h): 
                                                        $val = $row[$h] ?? '';
                                                        if (stripos($h, 'rtc') !== false && !empty($val)) {
                                                            echo "<td class='text-white'><span class='badge bg-secondary px-2 py-1 text-white'>" . htmlspecialchars($val) . "</span></td>";
                                                        } elseif ($val !== '' && is_numeric($val)) {
                                                            // Try to format numbers nicely, keeping high precision just in case
                                                            echo "<td class='text-white'>" . (strpos((string)$val, '.') !== false ? rtrim(rtrim((string)$val, '0'), '.') : htmlspecialchars((string)$val)) . "</td>";
                                                        } else {
                                                            echo "<td class='text-white'>" . htmlspecialchars((string)$val) . "</td>";
                                                        }
                                                    ?>
                                                    <?php endforeach; ?>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php else: ?>
                                        <div class="text-center text-muted py-5">
                                            <i class="bi bi-database-x fs-1 mb-3"></i>
                                            <h5 class="fw-normal !important">No extract data recorded</h5>
                                            <p>There is no data for <strong><?php echo htmlspecialchars($name); ?></strong> within the selected date range.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Interactive Manual Chart injected below the table -->
                                <?php if (!empty($manual_charts[$id]['labels']) && !empty($manual_charts[$id]['datasets'])): ?>
                                <div class="card border border-secondary shadow-sm p-4 mb-4" style="background-color: #1a1a1a;">
                                    <div class="d-flex justify-content-between align-items-center mb-4">
                                        <h5 class="mb-0 text-white fs-6"><?php echo htmlspecialchars($name); ?> Data Visualization</h5>
                                        <div class="d-flex gap-2">
                                            <!-- First Dropdown (Primary Line) -->
                                            <select id="manualChartFilter_<?php echo $id; ?>" class="form-select form-select-sm bg-dark text-white border-secondary" style="width: auto;" onchange="updateManualChart('<?php echo $id; ?>')">
                                                <?php 
                                                    $isFirstCol = true;
                                                    foreach ($manual_charts[$id]['datasets'] as $colKey => $dataset): 
                                                ?>
                                                    <option value="<?php echo htmlspecialchars($colKey); ?>" <?php echo $isFirstCol ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($dataset['label']); ?>
                                                    </option>
                                                <?php 
                                                    $isFirstCol = false;
                                                    endforeach; 
                                                ?>
                                            </select>

                                            <!-- Second Dropdown (Comparison Line) -->
                                            <select id="manualChartFilter2_<?php echo $id; ?>" class="form-select form-select-sm bg-dark text-white border-secondary" style="width: auto;" onchange="updateManualChart('<?php echo $id; ?>')">
                                                <option value="none">-- Compare With --</option>
                                                <?php foreach ($manual_charts[$id]['datasets'] as $colKey => $dataset): ?>
                                                    <option value="<?php echo htmlspecialchars($colKey); ?>">
                                                        <?php echo htmlspecialchars($dataset['label']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div style="position: relative; height: 300px; width: 100%;">
                                        <canvas id="manualChart_<?php echo $id; ?>" data-chart='<?php echo htmlspecialchars($chart_json, ENT_QUOTES, 'UTF-8'); ?>'></canvas>
                                    </div>
                                </div>
                                <?php endif; ?>

                            </div>
                            <?php $first = false; endforeach; ?>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js" integrity="sha384-NXgwF8Kv9SSAr+jemKKcbvQsz+teULH/a5UNJvZc6kP47hZgl62M1vGnw6gHQhb1" crossorigin="anonymous"></script>
    <?php include LUM_ROOT . '/Layout/foot-assets.php'; ?>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js" integrity="sha384-k5vbMeKHbxEZ0AEBTSdR7UjAgWCcUfrS8c0c5b2AfIh7olfhNkyCZYwOfzOQhauK" crossorigin="anonymous"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js" integrity="sha384-PgPBH0hy6DTJwu7pTf6bkRqPlf/+pjUBExpr/eIfzszlGYFlF9Wi9VTAJODPhgCO" crossorigin="anonymous"></script>
    
    <script>
        // Global storage for instantiated manual charts
        const manualChartInstances = {};

        // Render specific datasets into a target tab's canvas
        function updateManualChart(tabId) {
            const chartDataRaw = document.getElementById('manualChart_' + tabId).getAttribute('data-chart');
            if (!chartDataRaw || chartDataRaw === '{}') return;
            
            const chartData = JSON.parse(chartDataRaw);
            
            const filter1 = document.getElementById('manualChartFilter_' + tabId).value;
            const filter2 = document.getElementById('manualChartFilter2_' + tabId).value;
            
            const dataset1 = chartData.datasets[filter1];
            
            if (manualChartInstances[tabId]) {
                // Always update the first line
                manualChartInstances[tabId].data.datasets[0].data = dataset1.data;
                manualChartInstances[tabId].data.datasets[0].label = dataset1.label;
                
                // If a valid second option is chosen and it's NOT the same as the first option
                if (filter2 !== 'none' && filter1 !== filter2) {
                    const dataset2 = chartData.datasets[filter2];
                    
                    if (manualChartInstances[tabId].data.datasets.length === 1) {
                        // Create the second dataset line if it doesn't exist
                        manualChartInstances[tabId].data.datasets.push({
                            label: dataset2.label,
                            data: dataset2.data,
                            borderColor: '#ffc107',
                            backgroundColor: '#ffc107',
                            borderWidth: 2,
                            tension: 0.3,
                            pointRadius: 3,
                            fill: false
                        });
                    } else {
                        // Update existing second line
                        manualChartInstances[tabId].data.datasets[1].data = dataset2.data;
                        manualChartInstances[tabId].data.datasets[1].label = dataset2.label;
                    }
                } else {
                    // Remove the second line if "None" is selected or if they try to select the exact same column twice
                    if (manualChartInstances[tabId].data.datasets.length > 1) {
                        manualChartInstances[tabId].data.datasets.pop();
                    }
                }
                
                manualChartInstances[tabId].update();
            }
        }

        // Setup the chart for a specific tab when it becomes active
        function initManualChart(tabId) {
            if (manualChartInstances[tabId]) return; // Already instantiated
            
            const ctxEl = document.getElementById('manualChart_' + tabId);
            if (!ctxEl) return;
            
            const chartDataRaw = ctxEl.getAttribute('data-chart');
            if (!chartDataRaw || chartDataRaw === '{}') return;
            
            const chartData = JSON.parse(chartDataRaw);
            
            const filterSelect1 = document.getElementById('manualChartFilter_' + tabId);
            const activeColKey1 = filterSelect1 ? filterSelect1.value : Object.keys(chartData.datasets)[0];
            const dataset1 = chartData.datasets[activeColKey1];
            
            const filterSelect2 = document.getElementById('manualChartFilter2_' + tabId);
            const activeColKey2 = filterSelect2 ? filterSelect2.value : 'none';
            
            let datasetsArray = [{
                label: dataset1.label,
                data: dataset1.data,
                borderColor: '#0dcaf0',
                backgroundColor: '#0dcaf0',
                borderWidth: 2,
                tension: 0.3,
                pointRadius: 3,
                fill: false
            }];
            
            if (activeColKey2 !== 'none' && activeColKey1 !== activeColKey2) {
                const dataset2 = chartData.datasets[activeColKey2];
                datasetsArray.push({
                    label: dataset2.label,
                    data: dataset2.data,
                    borderColor: '#ffc107',
                    backgroundColor: '#ffc107',
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: 3,
                    fill: false
                });
            }
            
            manualChartInstances[tabId] = new Chart(ctxEl.getContext('2d'), {
                type: 'line',
                data: {
                    labels: chartData.labels,
                    datasets: datasetsArray
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: { grid: { color: '#333' }, ticks: { color: '#aaa', maxTicksLimit: 15 } },
                        y: { beginAtZero: true, grid: { borderDash: [2, 4], color: '#333' }, ticks: { color: '#aaa' } }
                    },
                    plugins: {
                        legend: { labels: { color: '#fff' } }
                    }
                }
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            
            // --- STATE RESTORATION ENGINE ---
            const filterForm = document.getElementById('filterForm');
            const mainContent = document.getElementById('mainContent');
            const sidebar = document.getElementById('sidebar');

            // Restore Scroll Positions
            if (sessionStorage.getItem('lynxMeterMainScroll')) {
                if (mainContent) mainContent.scrollTop = parseInt(sessionStorage.getItem('lynxMeterMainScroll'), 10);
                sessionStorage.removeItem('lynxMeterMainScroll');
            }
            if (sessionStorage.getItem('lynxMeterSideScroll')) {
                if (sidebar) sidebar.scrollTop = parseInt(sessionStorage.getItem('lynxMeterSideScroll'), 10);
                sessionStorage.removeItem('lynxMeterSideScroll');
            }

            // Restore Collapsible States
            if (sessionStorage.getItem('lynxMeterAutoOpen') === 'true') {
                const autoCol = document.getElementById('automatedCollapse');
                const autoBtn = document.getElementById('automatedToggleBtn');
                if (autoCol) autoCol.classList.add('show');
                if (autoBtn) {
                    const icon = autoBtn.querySelector('.toggle-icon');
                    if (icon) { icon.classList.remove('bi-plus-circle'); icon.classList.add('bi-dash-circle'); }
                }
            }
            if (sessionStorage.getItem('lynxMeterManOpen') === 'true') {
                const manCol = document.getElementById('manualCollapse');
                const manBtn = document.getElementById('manualToggleBtn');
                if (manCol) manCol.classList.add('show');
                if (manBtn) {
                    const icon = manBtn.querySelector('.toggle-icon');
                    if (icon) { icon.classList.remove('bi-plus-circle'); icon.classList.add('bi-dash-circle'); }
                }
            }
            sessionStorage.removeItem('lynxMeterAutoOpen');
            sessionStorage.removeItem('lynxMeterManOpen');

            // Restore Active Tab and Initialize its Chart
            const activeTabId = sessionStorage.getItem('lynxMeterActiveTab');
            if (activeTabId) {
                const tabBtn = document.getElementById(activeTabId);
                if (tabBtn) {
                    document.querySelectorAll('#manualTabs .nav-link').forEach(b => b.classList.remove('active'));
                    document.querySelectorAll('#manualTabsContent .tab-pane').forEach(p => p.classList.remove('show', 'active'));
                    tabBtn.classList.add('active');
                    const paneId = tabBtn.getAttribute('data-bs-target');
                    const pane = document.querySelector(paneId);
                    if (pane) pane.classList.add('show', 'active');
                    
                    // Trigger chart rendering for the active tab explicitly
                    const rawTabName = paneId.replace('#', '');
                    setTimeout(() => { initManualChart(rawTabName); }, 100);
                }
                sessionStorage.removeItem('lynxMeterActiveTab');
            } else {
                // Initialize default first active tab chart
                const firstActivePane = document.querySelector('#manualTabsContent .tab-pane.active');
                if (firstActivePane) {
                    setTimeout(() => { initManualChart(firstActivePane.id); }, 100);
                }
            }

            // Restore Chart Filter State (graph 1 and the two comparison graphs)
            [['autoChartFilter', 'lynxMeterChartFilter'], ['autoChartFilter2', 'lynxMeterChartFilter2'], ['autoChartFilter3', 'lynxMeterChartFilter3'],
             ['autoChartType', 'lynxMeterChartType'], ['autoChartType2', 'lynxMeterChartType2'], ['autoChartType3', 'lynxMeterChartType3']].forEach(function (pair) {
                const saved = sessionStorage.getItem(pair[1]);
                const sel = document.getElementById(pair[0]);
                if (saved !== null && sel && Array.from(sel.options).some(o => o.value === saved)) {
                    sel.value = saved;
                }
                sessionStorage.removeItem(pair[1]);
            });

            // Restore Manual Chart Filters
            document.querySelectorAll('select[id^="manualChartFilter_"], select[id^="manualChartFilter2_"]').forEach(sel => {
                const savedManFilter = sessionStorage.getItem('lynxMeterManFilter_' + sel.id);
                if (savedManFilter) {
                    sel.value = savedManFilter;
                    // Trigger the visual update so the chart matches the restored select dropdown
                    const tabId = sel.id.replace('manualChartFilter2_', '').replace('manualChartFilter_', '');
                    updateManualChart(tabId);
                }
                
                // Track changes actively
                sel.addEventListener('change', function() {
                    sessionStorage.setItem('lynxMeterManFilter_' + this.id, this.value);
                });
            });

            // --- AUTO SUBMIT WITH STATE SAVING ---
            let submitTimeout;
            if (filterForm) {
                const inputs = filterForm.querySelectorAll('.auto-save');
                inputs.forEach(input => {
                    // Date + time fields: typing hour and minutes fires several changes, so wait a little longer
                    const submitDelay = input.classList.contains('auto-datetime') ? 1500 : 250;
                    if (input.classList.contains('auto-datetime')) {
                        input.addEventListener('keydown', function () { clearTimeout(submitTimeout); });
                    }
                    input.addEventListener('change', function() {
                        clearTimeout(submitTimeout);
                        submitTimeout = setTimeout(() => {
                            // Capture screen state just before submitting
                            if (mainContent) sessionStorage.setItem('lynxMeterMainScroll', mainContent.scrollTop);
                            if (sidebar) sessionStorage.setItem('lynxMeterSideScroll', sidebar.scrollTop);
                            
                            const autoCol = document.getElementById('automatedCollapse');
                            const manCol = document.getElementById('manualCollapse');
                            sessionStorage.setItem('lynxMeterAutoOpen', autoCol && autoCol.classList.contains('show'));
                            sessionStorage.setItem('lynxMeterManOpen', manCol && manCol.classList.contains('show'));
                            
                            const activeTab = document.querySelector('#manualTabs .nav-link.active');
                            if (activeTab) sessionStorage.setItem('lynxMeterActiveTab', activeTab.id);
                            
                            [['autoChartFilter', 'lynxMeterChartFilter'], ['autoChartFilter2', 'lynxMeterChartFilter2'], ['autoChartFilter3', 'lynxMeterChartFilter3'],
                             ['autoChartType', 'lynxMeterChartType'], ['autoChartType2', 'lynxMeterChartType2'], ['autoChartType3', 'lynxMeterChartType3']].forEach(function (pair) {
                                const sel = document.getElementById(pair[0]);
                                if (sel) sessionStorage.setItem(pair[1], sel.value);
                            });

                            filterForm.submit();
                        }, submitDelay);
                    });
                });
            }

            // Sidebar Toggle Logic
            const sidebarToggleBtn = document.getElementById('sidebarToggle');
            if(sidebarToggleBtn && sidebar) {
                sidebarToggleBtn.addEventListener('click', function() {
                    sidebar.classList.toggle('collapsed');
                    // Give DataTables & Charts time to recalculate when layout expands
                    setTimeout(() => {
                        $('.manual-table').DataTable().columns.adjust().draw();
                        window.dispatchEvent(new Event('resize'));
                    }, 360);
                });
            }

            // Generic collapse toggler function to swap icons
            const bindCollapseIcon = (collapseId, btnId) => {
                const collapseEl = document.getElementById(collapseId);
                const btnEl = document.getElementById(btnId);
                if (collapseEl && btnEl) {
                    const toggleIcon = btnEl.querySelector('.toggle-icon');
                    if (toggleIcon) {
                        collapseEl.addEventListener('show.bs.collapse', () => {
                            toggleIcon.classList.remove('bi-plus-circle');
                            toggleIcon.classList.add('bi-dash-circle');
                            // Specifically adjust datatables and charts if inside this collapse
                            setTimeout(() => {
                                $('.manual-table').DataTable().columns.adjust().draw();
                            }, 50);
                        });
                        collapseEl.addEventListener('hide.bs.collapse', () => {
                            toggleIcon.classList.remove('bi-dash-circle');
                            toggleIcon.classList.add('bi-plus-circle');
                        });
                    }
                }
            };

            bindCollapseIcon('automatedCollapse', 'automatedToggleBtn');
            bindCollapseIcon('manualCollapse', 'manualToggleBtn');

            // Force DataTables to adjust when switching tabs inside the manual section
            $('button[data-bs-toggle="pill"]').on('shown.bs.tab', function (e) {
                $('.manual-table').DataTable().columns.adjust().draw();
            });

            // Initialize DataTables with specific cleanup parameters
            $('.manual-table').DataTable({
                "pageLength": 100, // Load enough rows so native scroll handles the UI
                "paging": false, // Disable default pagination pages
                "searching": false, // Remove the "Filter Readings:" search box entirely
                "info": false, // Remove the "Showing X to Y" text entirely
                "order": [], // Let the database handle the default order
                "scrollX": true // Enable horizontal scrolling for wide columns
            });
            
            // --- AUTOMATED METER CHART LOGIC ---
            const autoChartCtxEl = document.getElementById('autoConsumptionChart');
            let autoChart = null;

            if (autoChartCtxEl) {
                const autoChartCtx = autoChartCtxEl.getContext('2d');
                const autoLabels = <?php echo json_encode($chart_labels); ?>;
                
                // One series per OBIS code (names and colours: Configurations -> OBIS Time Ranges)
                const autoDataSets = <?php echo json_encode((object)$auto_chart_sets, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
                const autoFirstCode = <?php echo json_encode($auto_first_code); ?>;
                const autoManyPoints = autoLabels.length > 96;

                // Selected series: graph 1 plus up to two comparisons, each drawn as a bar or a line
                // (the same OBIS code is drawn once per chart type)
                function selectedAutoSeries() {
                    const series = [];
                    const seen = {};
                    [['autoChartFilter', 'autoChartType'], ['autoChartFilter2', 'autoChartType2'], ['autoChartFilter3', 'autoChartType3']].forEach(function (pair) {
                        const sel = document.getElementById(pair[0]);
                        const typeSel = document.getElementById(pair[1]);
                        let code = sel ? sel.value : (pair[0] === 'autoChartFilter' ? autoFirstCode : '');
                        const type = (typeSel && typeSel.value === 'line') ? 'line' : 'bar';
                        if (!code || !autoDataSets[code] || seen[code + '|' + type]) return;
                        seen[code + '|' + type] = true;
                        series.push({ code: code, type: type });
                    });
                    if (series.length === 0 && autoFirstCode && autoDataSets[autoFirstCode]) series.push({ code: autoFirstCode, type: 'bar' });
                    return series;
                }

                function buildAutoDatasets() {
                    return selectedAutoSeries().map(function (item) {
                        const set = autoDataSets[item.code];
                        if (item.type === 'line') {
                            return {
                                type: 'line',
                                label: set.label + ' (line)',
                                data: set.data,
                                borderColor: set.color,
                                backgroundColor: set.color,
                                borderWidth: 2,
                                pointRadius: autoManyPoints ? 0 : 2,
                                pointHoverRadius: 4,
                                tension: 0.2,
                                fill: false,
                                spanGaps: false,
                                order: 0 // Lines are drawn on top of the bars
                            };
                        }
                        return {
                            type: 'bar',
                            label: set.label,
                            data: set.data,
                            backgroundColor: set.color,
                            borderColor: set.color,
                            borderRadius: 2,
                            order: 1
                        };
                    });
                }

                function renderAutoChart() {
                    const datasets = buildAutoDatasets();
                    if (autoChart) {
                        autoChart.data.datasets = datasets;
                        autoChart.update();
                    } else {
                        autoChart = new Chart(autoChartCtx, {
                            type: 'bar',
                            data: {
                                labels: autoLabels,
                                datasets: datasets
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                interaction: { mode: 'index', intersect: false }, // Tooltip shows every selected series for the point
                                scales: {
                                    x: { grid: { color: '#333' }, ticks: { color: '#aaa', autoSkip: true, maxRotation: 0 } },
                                    y: { beginAtZero: true, grid: { borderDash: [2, 4], color: '#333' }, ticks: { color: '#aaa' } }
                                },
                                plugins: {
                                    legend: { labels: { color: '#fff' } }
                                }
                            }
                        });
                    }
                }

                // Initialize based on recovered state
                renderAutoChart();

                // Redraw when any series or chart type changes
                document.querySelectorAll('.auto-obis-filter, .auto-type-filter').forEach(function (sel) {
                    sel.addEventListener('change', renderAutoChart);
                });
            }
        });
    </script>
</body>
</html>