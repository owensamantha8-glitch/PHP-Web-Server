<?php
// Library file: only other pages may include it, it can never be opened directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(403); exit('Forbidden'); }
// Meters and readings
// Part of reporting-engine.php; include that, never this file.

// Meters of properties in the meter register, by type: ['grid' => [...], 'solar' => [...], 'water_main' => [...]]
function lumDashboardAutoMeters($meter_db_conn, array $properties) {
    $out = ['grid' => [], 'solar' => [], 'water_main' => []];
    if (!$meter_db_conn || empty($properties)) return $out;
    try {
        $in = implode(',', array_fill(0, count($properties), '?'));
        $st = $meter_db_conn->prepare("SELECT meter_serial, meter_type FROM lum_meters WHERE meter_property IN ($in)");
        $st->execute(array_values($properties));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $serial = trim((string)$m['meter_serial']);
            if ($serial === '') continue;
            if (stripos($m['meter_type'], 'Solar') !== false) $out['solar'][] = $serial;
            elseif (stripos($m['meter_type'], 'Electrical') !== false) $out['grid'][] = $serial;
            if (stripos($m['meter_type'], 'Water') !== false) $out['water_main'][] = $serial;
        }
    } catch (\Throwable $e) {
        error_log('LUM dashboard: meter register not read: ' . $e->getMessage());
    }
    return $out;
}

// Global cached function to dynamically fetch OBIS time boundaries
function get_obis_time_ranges($pdo) {
    static $ranges = null;
    if ($ranges === null) {
        $ranges = [
            '1.1.1.8.0' => ['start' => ' 00:30:00', 'end' => ' 23:30:00'],
            '1.1.1.8.1' => ['start' => ' 00:00:00', 'end' => ' 00:00:00'],
            '1.1.1.8.2' => ['start' => ' 00:00:00', 'end' => ' 00:00:00'],
            '8.1.1.0.0' => ['start' => ' 00:00:00', 'end' => ' 23:59:59']
        ];
        try {
            // Because OBIS connections don't strictly bind to a database, we reference the sys_db_information db directly
            $stmt = $pdo->query("SELECT obis_code, start_time, end_time FROM `sys_db_information`.`lum_obis_time_ranges`");
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $ranges[$row['obis_code']] = [
                        'start' => ' ' . $row['start_time'], 
                        'end' => ' ' . $row['end_time']
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Safely fallback to defaults if table is offline
        }
    }
    return $ranges;
}

function getDailyMeterUsage($pdo, $serial, $start_date, $end_date, $obis_code = '1.1.1.8.0') {
    $clean_serial = @preg_replace('/[^a-zA-Z0-9]/', '', $serial);
    $cleanCode = @preg_replace('/[^0-9]/', '', $obis_code);
    $readingYear = date('Y', strtotime($start_date));
    
    // Dynamic Time Boundaries
    $ranges = get_obis_time_ranges($pdo);
    $time_start = $ranges[$obis_code]['start'] ?? ' 00:30:00';
    $time_end = $ranges[$obis_code]['end'] ?? ' 23:30:00';
    
    $db = "db_obis_" . $cleanCode . "_" . $readingYear;
    $table = "tb_obis_" . $cleanCode . "_" . $readingYear;
    $col = $cleanCode . "_value";
    
    $daily = [];
    $period = new DatePeriod(new DateTime($start_date), new DateInterval('P1D'), (new DateTime($end_date))->modify('+1 day'));
    foreach ($period as $dt) {
        $daily[$dt->format("Y-m-d")] = 0;
    }

    try {
        $stmt_base = $pdo->prepare("SELECT `$col` FROM `$db`.`$table` WHERE meter_serial = :serial AND Time_stamp < :s ORDER BY Time_stamp DESC LIMIT 1");
        $stmt_base->execute(['serial' => $clean_serial, 's' => $start_date . $time_start]);
        $prev_max = $stmt_base->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT DATE(Time_stamp) as dt, MAX(`$col`) as max_v, MIN(`$col`) as min_v FROM `$db`.`$table` WHERE meter_serial = :serial AND Time_stamp >= :s AND Time_stamp <= :e GROUP BY DATE(Time_stamp) ORDER BY DATE(Time_stamp) ASC");
        $stmt->execute(['serial' => $clean_serial, 's' => $start_date . $time_start, 'e' => $end_date . $time_end]);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $current_max = (float)$row['max_v'];
            if ($prev_max !== false) {
                $usage = max(0, $current_max - (float)$prev_max);
            } else {
                $usage = max(0, $current_max - (float)$row['min_v']);
            }
            if (isset($daily[$row['dt']])) {
                $daily[$row['dt']] = $usage;
            }
            $prev_max = $current_max;
        }
    } catch(Exception $e) {}
    return $daily;
}

function getRealWaterReadings($pdo, $manual_pdo, $meter_serial, $start, $end) {
    if (!$meter_serial or empty($start) or empty($end)) return null;
    $clean_serial = @preg_replace('/[^a-zA-Z0-9]/', '', $meter_serial);
    $readingYear = date('Y', strtotime($start));
    
    // Dynamic Time Boundaries
    $ranges = get_obis_time_ranges($pdo);
    $time_start = $ranges['8.1.1.0.0']['start'] ?? ' 00:30:00';
    $time_end = $ranges['8.1.1.0.0']['end'] ?? ' 23:59:59';
    
    $db = 'db_obis_81100_' . $readingYear; 
    $table = "tb_obis_81100_" . $readingYear; 
    
    $open = false; $close = false;
    $start_y = date('Y', strtotime($start)); $start_m = date('m', strtotime($start));
    $end_y = date('Y', strtotime($end)); $end_m = date('m', strtotime($end));

    try {
        // --- CRITICAL FIX: EXACT TIMESTAMP VALIDATION TO PREVENT STALE READINGS ---
        $stmt_open = $pdo->prepare("SELECT Time_stamp, `81100_value` FROM `$db`.`$table` WHERE meter_serial = :serial AND Time_stamp >= :start ORDER BY Time_stamp ASC LIMIT 1");
        $stmt_open->execute(['serial' => $clean_serial, 'start' => $start . $time_start]);
        $open_row = $stmt_open->fetch(PDO::FETCH_ASSOC);
        
        if ($open_row) {
            if (date('Y-m', strtotime($open_row['Time_stamp'])) === date('Y-m', strtotime($start))) {
                $open = $open_row['81100_value'];
            }
        }
        
        if ($open === false) {
            $stmt_open = $pdo->prepare("SELECT Time_stamp, `81100_value` FROM `$db`.`$table` WHERE meter_serial = :serial AND YEAR(Time_stamp) = :yr AND MONTH(Time_stamp) = :mo ORDER BY Time_stamp ASC LIMIT 1");
            $stmt_open->execute(['serial' => $clean_serial, 'yr' => $start_y, 'mo' => $start_m]);
            $open_row = $stmt_open->fetch(PDO::FETCH_ASSOC);
            if ($open_row) $open = $open_row['81100_value'];
        }

        $stmt_close = $pdo->prepare("SELECT Time_stamp, `81100_value` FROM `$db`.`$table` WHERE meter_serial = :serial AND Time_stamp <= :end ORDER BY Time_stamp DESC LIMIT 1");
        $stmt_close->execute(['serial' => $clean_serial, 'end' => $end . $time_end]);
        $close_row = $stmt_close->fetch(PDO::FETCH_ASSOC);
        
        if ($close_row) {
            if (date('Y-m', strtotime($close_row['Time_stamp'])) === date('Y-m', strtotime($end))) {
                $close = $close_row['81100_value'];
            }
        }
        
        if ($close === false) {
            $stmt_close = $pdo->prepare("SELECT Time_stamp, `81100_value` FROM `$db`.`$table` WHERE meter_serial = :serial AND YEAR(Time_stamp) = :yr AND MONTH(Time_stamp) = :mo ORDER BY Time_stamp DESC LIMIT 1");
            $stmt_close->execute(['serial' => $clean_serial, 'yr' => $end_y, 'mo' => $end_m]);
            $close_row = $stmt_close->fetch(PDO::FETCH_ASSOC);
            if ($close_row) $close = $close_row['81100_value'];
        }
    } catch (\Throwable $e) {}

    // MANUAL DATABASE FALLBACK
    if (($open === false or $close === false) && $manual_pdo) {
        try {
            if ($open === false) {
                $stmt = $manual_pdo->prepare("SELECT reading FROM manual_readings_water WHERE water_serial = :serial AND reading_date = :date ORDER BY reading_date ASC LIMIT 1");
                $stmt->execute(['serial' => $clean_serial, 'date' => $start]);
                $open = $stmt->fetchColumn();
                if ($open === false) {
                    $stmt = $manual_pdo->prepare("SELECT reading FROM manual_readings_water WHERE water_serial = :serial AND YEAR(reading_date) = :yr AND MONTH(reading_date) = :mo ORDER BY reading_date ASC LIMIT 1");
                    $stmt->execute(['serial' => $clean_serial, 'yr' => $start_y, 'mo' => $start_m]);
                    $open = $stmt->fetchColumn();
                }
            }
            if ($close === false) {
                $stmt = $manual_pdo->prepare("SELECT reading FROM manual_readings_water WHERE water_serial = :serial AND reading_date = :date ORDER BY reading_date DESC LIMIT 1");
                $stmt->execute(['serial' => $clean_serial, 'date' => $end]);
                $close = $stmt->fetchColumn();
                if ($close === false) {
                    $stmt = $manual_pdo->prepare("SELECT reading FROM manual_readings_water WHERE water_serial = :serial AND YEAR(reading_date) = :yr AND MONTH(reading_date) = :mo ORDER BY reading_date DESC LIMIT 1");
                    $stmt->execute(['serial' => $clean_serial, 'yr' => $end_y, 'mo' => $end_m]);
                    $close = $stmt->fetchColumn();
                }
            }
        } catch (\Throwable $ex) {}
    }

    $open_val = $open !== false ? (float)$open : 0;
    $close_val = $close !== false ? (float)$close : 0;
    return ['open' => $open_val, 'close' => $close_val, 'kl' => max(0, $close_val - $open_val)];
}

function fetch_lambton_meter_kwh($serial, $start_date, $end_date, $pdo, $obis_code) {
    $clean_serial = @preg_replace('/[^a-zA-Z0-9]/', '', $serial);
    $cleanCode = @preg_replace('/[^0-9]/', '', $obis_code);
    $readingYear = date('Y', strtotime($start_date));
    
    // Dynamic Time Boundaries
    $ranges = get_obis_time_ranges($pdo);
    $time_start = $ranges[$obis_code]['start'] ?? ' 00:30:00';
    $time_end = $ranges[$obis_code]['end'] ?? ' 23:30:00';
    
    $db = "db_obis_" . $cleanCode . "_" . $readingYear;
    $table = "tb_obis_" . $cleanCode . "_" . $readingYear;
    $col = $cleanCode . "_value";
    
    try {
        $stmt_open = $pdo->prepare("SELECT `$col` FROM `$db`.`$table` WHERE meter_serial = :serial AND Time_stamp >= :start ORDER BY Time_stamp ASC LIMIT 1");
        $stmt_open->execute(['serial' => $clean_serial, 'start' => $start_date . $time_start]);
        $open = (float) $stmt_open->fetchColumn();

        $stmt_close = $pdo->prepare("SELECT `$col` FROM `$db`.`$table` WHERE meter_serial = :serial AND Time_stamp <= :end ORDER BY Time_stamp DESC LIMIT 1");
        $stmt_close->execute(['serial' => $clean_serial, 'end' => $end_date . $time_end]);
        $close = (float) $stmt_close->fetchColumn();

        return max(0, $close - $open);
    } catch (\Throwable $e) { return 0; }
}

// =========================================================================
// MANUAL DB EXTRACT OVERRIDE INTEGRATION
// =========================================================================
function fetchManualExtractReading($pdo, $serial, $target_date, $column, $is_opening) {
    $clean_serial = @preg_replace('/[^a-zA-Z0-9]/', '', $serial);
    if (empty($clean_serial) || $column === 'none') return false;

    $manual_dbs_to_scan = ['db_manual_analysis_', 'db_manual_debit1_', 'db_manual_debit2_', 'db_manual_debit_'];
    $year = date('Y', strtotime($target_date));
    $target_ts = strtotime($target_date);

    $best_match = false;

    foreach ($manual_dbs_to_scan as $dbPrefix) {
        $dbName = $dbPrefix . $year;
        try {
            $dbCheck = $pdo->query("SHOW DATABASES LIKE '$dbName'");
            if ($dbCheck->rowCount() === 0) continue;
            
            $tbStmt = $pdo->query("SHOW TABLES IN `$dbName`");
            $tables = $tbStmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($tables as $tb) {
                $colStmt = $pdo->query("SHOW COLUMNS FROM `$dbName`.`$tb`");
                $columns = $colStmt->fetchAll(PDO::FETCH_COLUMN);
                
                if (in_array('meter_serial', $columns) && in_array($column, $columns)) {
                    $rtc_col = null;
                    foreach ($columns as $c) {
                        if (stripos($c, 'RTC') !== false) { $rtc_col = $c; break; }
                    }
                    if (!$rtc_col && isset($columns[1])) $rtc_col = $columns[1];
                    
                    if ($rtc_col) {
                        $order_dir = $is_opening ? 'ASC' : 'DESC';
                        $stmt = $pdo->prepare("SELECT `$column`, `$rtc_col` FROM `$dbName`.`$tb` WHERE `meter_serial` = ? AND `$column` IS NOT NULL ORDER BY `$rtc_col` $order_dir");
                        $stmt->execute([$clean_serial]);
                        
                        $exact_date = date('Y-m-d', $target_ts);
                        
                        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            $clean_date_str = trim(str_replace('!', '', $row[$rtc_col]));
                            $row_ts = false;
                            
                            if (strpos($clean_date_str, '/') !== false) {
                                $parts = explode(' ', $clean_date_str);
                                $d = explode('/', $parts[0]);
                                if (count($d) == 3) {
                                    $iso_date = $d[2] . '-' . $d[1] . '-' . $d[0] . (isset($parts[1]) ? ' ' . $parts[1] : '');
                                    $row_ts = strtotime($iso_date);
                                }
                            } else {
                                $row_ts = strtotime($clean_date_str);
                            }
                            
                            if ($row_ts !== false) {
                                $row_date = date('Y-m-d', $row_ts);
                                
                                if ($row_date === $exact_date) {
                                    return (float)$row[$column];
                                }
                                
                                if ($best_match === false && date('Y-m', $row_ts) === date('Y-m', $target_ts)) {
                                    $best_match = (float)$row[$column];
                                }
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {}
    }
    return $best_match;
}

// Returns the timestamp (Y-m-d H:i:s) of the last reading BEFORE the meter started
// recording already-multiplied values, or null if no such switch-over is found.
// Looks from 400 days before $period_start up to today.
function lumDetectMeterCtStep($pdo, $clean_serial, $cleanCode, $ct_ratio, $period_start) {
    static $step_cache = [];

    $ct = (float)$ct_ratio;
    // Small CT ratios can't be told apart from normal day-to-day variation, so they are not auto-detected
    if ($ct < 5 || $clean_serial === '' || $cleanCode === '') return null;

    $from = date('Y-m-d', strtotime(($period_start ?: date('Y-m-d')) . ' -400 days'));
    $key = $clean_serial . '|' . $ct . '|' . $from;
    if (array_key_exists($key, $step_cache)) return $step_cache[$key];
    $step_cache[$key] = null;

    // The switch-over is a meter event, so it is located on the half-hourly 1.1.1.8.0 register
    // (falls back to the billing register if the meter has no 1.1.1.8.0 data)
    $result = lumDetectMeterCtStepOnCode($pdo, $clean_serial, '11180', $ct, $from);
    if ($result === false && $cleanCode !== '11180') {
        $result = lumDetectMeterCtStepOnCode($pdo, $clean_serial, $cleanCode, $ct, $from);
    }
    $step_cache[$key] = ($result === false) ? null : $result;
    return $step_cache[$key];
}

// Worker for lumDetectMeterCtStep. Returns false when there is not enough data on this register,
// null when there is data but no switch-over, or the switch-over timestamp.
function lumDetectMeterCtStepOnCode($pdo, $clean_serial, $cleanCode, $ct, $from) {
    $col = $cleanCode . '_value';
    $available = getObisAvailableYears($pdo, $cleanCode);

    // 1. Highest register value per day
    $day_max = [];
    for ($y = (int)date('Y', strtotime($from)); $y <= (int)date('Y'); $y++) {
        if (!empty($available) && !in_array($y, $available, true)) continue;
        try {
            $stmt = $pdo->prepare("SELECT DATE(Time_stamp) AS d, MAX(`$col`) AS mx FROM `db_obis_{$cleanCode}_{$y}`.`tb_obis_{$cleanCode}_{$y}` WHERE meter_serial = :s AND Time_stamp >= :f GROUP BY DATE(Time_stamp)");
            $stmt->execute(['s' => $clean_serial, 'f' => $from . ' 00:00:00']);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $day_max[$row['d']] = (float)$row['mx'];
            }
        } catch (\Throwable $e) {}
    }
    if (count($day_max) < 21) return false;
    ksort($day_max);

    // 2. Daily usage. Days that follow a data gap and register resets are left out.
    $days = []; $use = [];
    $prev_d = null; $prev_v = null;
    foreach ($day_max as $d => $v) {
        if ($prev_d !== null) {
            $gap = (int)round((strtotime($d) - strtotime($prev_d)) / 86400);
            $u = $v - $prev_v;
            if ($gap === 1 && $u >= 0) { $days[] = $d; $use[] = $u; }
        }
        $prev_d = $d; $prev_v = $v;
    }

    // 3. Find a lasting jump whose size matches the CT ratio (half to double the ratio).
    //    A reprogrammed meter keeps measuring the same load, so the jump is close to the CT ratio.
    //    Windows containing zero-usage days are skipped: a meter going from 0 kWh (not yet in
    //    service / vacant) to normal use is not a CT switch-over.
    $n = count($use);
    $win = 10;
    $lo = max(3.0, $ct * 0.5);
    $hi = $ct * 2.0;
    $step_day = null; $mb = 0; $ma = 0;

    for ($i = $win; $i <= $n - $win; $i++) {
        $before_win = array_slice($use, $i - $win, $win);
        $after_win = array_slice($use, $i, $win);
        if (min($before_win) <= 0 || min($after_win) <= 0) continue;
        $mb = lumMedian($before_win);
        $ma = lumMedian($after_win);
        $ratio = $ma / $mb;
        if ($ratio < $lo || $ratio > $hi) continue;
        if (lumMedian(array_slice($use, $i)) / $mb < $lo) continue; // the higher level must last

        // First day of the higher level (two elevated days in a row)
        $threshold = sqrt($mb * $ma);
        for ($j = max(1, $i - $win); $j < min($n, $i + $win); $j++) {
            if ($use[$j] > $threshold && (!isset($use[$j + 1]) || $use[$j + 1] > $threshold)) {
                $step_day = $days[$j];
                break;
            }
        }
        if ($step_day !== null) break;
    }
    if ($step_day === null) return null;

    // 4. Pin-point the switch-over in the interval readings of the step day and the day before
    $step_ts = date('Y-m-d H:i:s', strtotime($step_day . ' 00:00:00') - 1);
    $rate_threshold = sqrt($mb * $ma) / 24; // kWh per hour
    $yy = (int)date('Y', strtotime($step_day));
    try {
        $stmt = $pdo->prepare("SELECT Time_stamp, `$col` AS val FROM `db_obis_{$cleanCode}_{$yy}`.`tb_obis_{$cleanCode}_{$yy}` WHERE meter_serial = :s AND Time_stamp >= :f AND Time_stamp <= :t ORDER BY Time_stamp ASC");
        $stmt->execute(['s' => $clean_serial, 'f' => date('Y-m-d', strtotime($step_day . ' -1 day')) . ' 00:00:00', 't' => $step_day . ' 23:59:59']);
        $pts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $rates = [];
        for ($k = 1; $k < count($pts); $k++) {
            $hrs = max(1 / 60, (strtotime($pts[$k]['Time_stamp']) - strtotime($pts[$k - 1]['Time_stamp'])) / 3600);
            $rates[$k] = ((float)$pts[$k]['val'] - (float)$pts[$k - 1]['val']) / $hrs;
        }
        foreach ($rates as $k => $r) {
            if ($r <= $rate_threshold) continue;
            $ahead = [];
            for ($m = $k; $m < $k + 4 && isset($rates[$m]); $m++) $ahead[] = $rates[$m];
            if (array_sum($ahead) / count($ahead) > $rate_threshold) {
                $step_ts = $pts[$k - 1]['Time_stamp'];
                break;
            }
        }
    } catch (\Throwable $e) {}

    return $step_ts;
}

// Returns the year-databases that exist for an OBIS code, e.g. [2024, 2025, 2026]
function getObisAvailableYears($pdo, $cleanCode) {
    static $year_cache = [];
    if (isset($year_cache[$cleanCode])) return $year_cache[$cleanCode];

    $years = [];
    try {
        $stmt = $pdo->query("SHOW DATABASES LIKE 'db\\_obis\\_{$cleanCode}\\_%'");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $db_name) {
            if (preg_match('/^db_obis_' . $cleanCode . '_(\d{4})$/', $db_name, $m)) {
                $years[] = (int)$m[1];
            }
        }
    } catch (\Throwable $e) {}

    sort($years);
    $year_cache[$cleanCode] = $years;
    return $years;
}

// --- NEW ESTIMATION ENGINE FOR MISSING READINGS ---
function calculateEstimatedElectricalReadings($pdo, $meter_serial, $obis_code, $occ_start, $occ_end, $slip_start, $slip_end, $ct_ratio = 1.0) {
    $clean_serial = @preg_replace('/[^a-zA-Z0-9]/', '', $meter_serial);
    
    // EXPLICIT OBIS OVERRIDE: Prevent the estimation engine from pulling from the 1.1.1.8.0 aggregate table.
    // If the request is for the generator (1.1.1.8.2), force 11182. For everything else, strictly force 11181.
    if ($obis_code === '1.1.1.8.2') {
        $cleanCode = '11182';
    } else {
        $cleanCode = '11181'; 
    }
    
    if (empty($occ_start)) return false;
    if (empty($occ_end)) $occ_end = date('Y-m-d');
    
    $start_year = (int)date('Y', strtotime($occ_start));
    $end_year = (int)date('Y', strtotime($occ_end));
    
    $first_reading = null; $first_ts = null;
    $last_reading = null; $last_ts = null;
    
    for ($y = $start_year; $y <= $end_year; $y++) {
        $db = "db_obis_" . $cleanCode . "_" . $y;
        $table = "tb_obis_" . $cleanCode . "_" . $y;
        $col = $cleanCode . "_value";
        
        try {
            if ($first_reading === null) {
                $stmt_first = $pdo->prepare("SELECT Time_stamp, `$col` as val FROM `$db`.`$table` WHERE meter_serial = :serial AND Time_stamp >= :start ORDER BY Time_stamp ASC LIMIT 1");
                $stmt_first->execute(['serial' => $clean_serial, 'start' => $occ_start . ' 00:00:00']);
                $row_first = $stmt_first->fetch(PDO::FETCH_ASSOC);
                if ($row_first) {
                    $first_reading = (float)$row_first['val'];
                    $first_ts = strtotime($row_first['Time_stamp']);
                }
            }
            
            $stmt_last = $pdo->prepare("SELECT Time_stamp, `$col` as val FROM `$db`.`$table` WHERE meter_serial = :serial AND Time_stamp <= :end ORDER BY Time_stamp DESC LIMIT 1");
            $stmt_last->execute(['serial' => $clean_serial, 'end' => $occ_end . ' 23:59:59']);
            $row_last = $stmt_last->fetch(PDO::FETCH_ASSOC);
            if ($row_last) {
                $last_reading = (float)$row_last['val'];
                $last_ts = strtotime($row_last['Time_stamp']);
            }
        } catch (\Throwable $e) { continue; }
    }
    
    if ($first_reading === null || $last_reading === null || $last_ts <= $first_ts) {
        return false;
    }
    
    $total_kwh = $last_reading - $first_reading;
    $total_seconds = $last_ts - $first_ts;
    $kwh_per_second = $total_kwh / $total_seconds;
    
    $slip_start_ts = strtotime($slip_start . ' 00:00:00');
    $slip_end_ts = strtotime($slip_end . ' 23:59:59');
    
    $est_open = $first_reading + (($slip_start_ts - $first_ts) * $kwh_per_second);
    $est_close = $first_reading + (($slip_end_ts - $first_ts) * $kwh_per_second);
    
    $est_open = max(0, $est_open);
    $est_close = max(0, $est_close);
    $slip_kwh = max(0, $est_close - $est_open);
    
    $applied_ct = $ct_ratio > 0 ? $ct_ratio : 1.0;

    // CT MULTIPLIER WINDOW: only history recorded before the meter was programmed with its CT needs the ratio.
    // The average multiplier over the history window is used for the estimate.
    if ($applied_ct > 1 && $total_kwh > 0) {
        $est_step = lumDetectMeterCtStep($pdo, $clean_serial, $cleanCode, $applied_ct, date('Y-m-d', $first_ts));
        if ($est_step !== null) {
            $est_step_i = strtotime($est_step);
            if ($est_step_i <= $first_ts) {
                $applied_ct = 1.0;
            } elseif ($est_step_i < $last_ts) {
                $est_step_val = lumRegisterAt($pdo, $cleanCode, $clean_serial, $est_step_i);
                if ($est_step_val !== null && $est_step_val >= $first_reading && $est_step_val <= $last_reading) {
                    $adjusted_total = (($est_step_val - $first_reading) * $applied_ct) + ($last_reading - $est_step_val);
                    $applied_ct = $adjusted_total / $total_kwh;
                }
            }
        }
    }

    $est_open *= $applied_ct;
    $est_close *= $applied_ct;
    $slip_kwh *= $applied_ct;
    
    $daily_usage = [];
    $slip_days = max(1, ($slip_end_ts - $slip_start_ts) / 86400);
    $daily_avg = $slip_kwh / $slip_days;
    
    $period = new DatePeriod(new DateTime($slip_start), new DateInterval('P1D'), (new DateTime($slip_end))->modify('+1 day'));
    foreach ($period as $dt) {
        $d = $dt->format("Y-m-d");
        $daily_usage[$d] = [
            'total' => $daily_avg,
            'peak' => 0,
            'std' => $daily_avg, 
            'off' => 0
        ];
    }
    
    return [
        'open'  => $est_open,
        'close' => $est_close,
        'kwh'   => $slip_kwh,
        'kva'   => 0, 
        'peak'  => 0,
        'std'   => $slip_kwh,
        'off'   => 0,
        'daily' => $daily_usage,
        'applied_ct' => $applied_ct,
        'is_estimated' => true
    ];
}

function getRealElecReadings($pdo, $meter_serial, $start, $end, $obis_code, $tou_algo, $holidays, $season = 'High', $ct_ratio = 1.00, $tenant_amps = 0, $manual_pdo = null, $manual_col = 'none', $use_est = false, $occ_start = null, $occ_end = null) {
    if (!$meter_serial or empty($start) or empty($end)) return null;
    $clean_serial = @preg_replace('/[^a-zA-Z0-9]/', '', $meter_serial);
    $cleanCode = @preg_replace('/[^0-9]/', '', $obis_code);
    $readingYear = date('Y', strtotime($start));
    
    // --- DATABASE MAPPING ---
    $db = "db_obis_" . $cleanCode . "_" . $readingYear;
    $table = "tb_obis_" . $cleanCode . "_" . $readingYear;
    $col = $cleanCode . "_value";
    
    $ranges = get_obis_time_ranges($pdo);
    $time_start = $ranges[$obis_code]['start'] ?? ' 00:30:00';
    $time_end = $ranges[$obis_code]['end'] ?? ' 23:59:59';
    
    $open_val = 0; $open_ts = null; $close_val = 0; $close_ts = null;
    $has_open = false; $has_close = false;
    $manual_used = false; // True only when manual extract readings were actually found for this meter

    // --- MANUAL OVERRIDE INTERCEPTION ---
    if ($manual_col !== 'none' && $manual_pdo) {
        $m_open = fetchManualExtractReading($manual_pdo, $clean_serial, $start . ' 00:00:00', $manual_col, true);
        $m_close = fetchManualExtractReading($manual_pdo, $clean_serial, $end . ' 23:59:59', $manual_col, false);
        
        if ($m_open !== false && $m_close !== false) {
            $open_val = $m_open;
            $close_val = $m_close;
            // Force timestamps to boundaries since manual extracts guarantee date adherence
            $open_ts = $start . ' 00:00:00';
            $close_ts = $end . ' 23:59:59';
            $has_open = true;
            $has_close = true;
            $manual_used = true;
        }
    }

    // Failsafe back to Automated if override returned nothing or was incomplete
    if (!$has_open || !$has_close) {
        try {
            $stmt_open = $pdo->prepare("SELECT Time_stamp, `$col` as val FROM `$db`.`$table` WHERE meter_serial = :serial AND Time_stamp >= :start ORDER BY Time_stamp ASC LIMIT 1");
            $stmt_open->execute(['serial' => $clean_serial, 'start' => $start . $time_start]);
            $open_row = $stmt_open->fetch(PDO::FETCH_ASSOC);
            if ($open_row) {
                $open_val = (float)$open_row['val'];
                $open_ts = $open_row['Time_stamp'];
                $has_open = true;
            }

            $stmt_close = $pdo->prepare("SELECT Time_stamp, `$col` as val FROM `$db`.`$table` WHERE meter_serial = :serial AND Time_stamp <= :end ORDER BY Time_stamp DESC LIMIT 1");
            $stmt_close->execute(['serial' => $clean_serial, 'end' => $end . $time_end]);
            $close_row = $stmt_close->fetch(PDO::FETCH_ASSOC);
            if ($close_row) {
                $close_val = (float)$close_row['val'];
                $close_ts = $close_row['Time_stamp'];
                $has_close = true;
            }
        } catch (\Throwable $e) {}
    }

    // --- ESTIMATION INTERCEPTOR ---
    if ((!$has_open || !$has_close) && $use_est && !empty($occ_start) && !empty($occ_end)) {
        $est_data = calculateEstimatedElectricalReadings($pdo, $meter_serial, $obis_code, $occ_start, $occ_end, $start, $end, $ct_ratio);
        if ($est_data !== false) {
            return $est_data; // Bypass everything else and return the pre-calculated estimated array
        }
    }

    $raw_total = max(0, $close_val - $open_val);

    // --- CT MULTIPLIER WINDOW ---
    // If the meter was re-programmed with its CT ratio, readings after that moment are already
    // multiplied. The CT ratio is only applied to consumption recorded before it.
    $ct_step_ts = null;
    $ct_step_mode = null;           // 'before' = whole period already multiplied, 'within' = switch-over inside the period
    $pre_raw = $raw_total;
    $post_raw = 0;
    $open_i = $open_ts ? strtotime($open_ts) : false;
    $close_i = $close_ts ? strtotime($close_ts) : false;

    if ($ct_ratio > 1 && $has_open && $has_close && !$manual_used && $open_i && $close_i) {
        $step = lumDetectMeterCtStep($pdo, $clean_serial, $cleanCode, $ct_ratio, $start);
        if ($step !== null) {
            $step_i = strtotime($step);
            if ($step_i <= $open_i) {
                $ct_step_ts = $step_i;
                $ct_step_mode = 'before';
                $pre_raw = 0;
                $post_raw = $raw_total;
            } elseif ($step_i < $close_i) {
                $step_val = lumRegisterAt($pdo, $cleanCode, $clean_serial, $step_i);
                if ($step_val !== null && $step_val >= $open_val && $step_val <= $close_val) {
                    $ct_step_ts = $step_i;
                    $ct_step_mode = 'within';
                    $pre_raw = $step_val - $open_val;
                    $post_raw = $close_val - $step_val;
                }
            }
        }
    }

    $applied_ct = 1.00;
    if ($ct_ratio > 1 && $ct_step_mode !== 'before') {
        if ($pre_raw > 0) {
            // Sanity check: ignore the CT ratio if the multiplied usage is physically impossible for the supply
            $pre_end_i = ($ct_step_mode === 'within') ? $ct_step_ts : strtotime($end . ' 23:59:59');
            $hours = max(1, ($pre_end_i - strtotime($start)) / 3600);
            $check_amps = floatval($tenant_amps) > 0 ? floatval($tenant_amps) : ($ct_ratio * 5);
            $max_kw = ($check_amps * 230 * 3) / 1000;
            $absolute_max_kwh = $max_kw * $hours;

            $applied_ct = (($pre_raw * $ct_ratio) > ($absolute_max_kwh * 1.5)) ? 1.00 : $ct_ratio;
        } else {
            $applied_ct = $ct_ratio;
        }
    }

    $kwh_total = ($pre_raw * $applied_ct) + $post_raw;
    $open_val = $open_val * $applied_ct;
    $close_val = $open_val + $kwh_total; // Opening + consumption always equals closing

    $tou = ['peak' => 0, 'std' => 0, 'off' => 0];
    $none_kwh = 0; 
    $daily_usage = [];

    // Ensure manual overrides bypass interval TOU matching
    if ($open_ts && $close_ts && $kwh_total > 0 && !$manual_used) {
        try {
            $tou_db = "db_obis_11180_" . $readingYear;
            $tou_table = "tb_obis_11180_" . $readingYear;
            $tou_col = "11180_value";
            
            $stmt_all = $pdo->prepare("SELECT Time_stamp, `$tou_col` as val FROM `$tou_db`.`$tou_table` WHERE meter_serial = :serial AND Time_stamp >= :start AND Time_stamp <= :end ORDER BY Time_stamp ASC");
            $stmt_all->execute(['serial' => $clean_serial, 'start' => $open_ts, 'end' => $close_ts]);
            
            if ($stmt_all->rowCount() <= 1) {
                $stmt_all = $pdo->prepare("SELECT Time_stamp, `$col` as val FROM `$db`.`$table` WHERE meter_serial = :serial AND Time_stamp >= :start AND Time_stamp <= :end ORDER BY Time_stamp ASC");
                $stmt_all->execute(['serial' => $clean_serial, 'start' => $open_ts, 'end' => $close_ts]);
            }

            $prev_v = null;
            while ($r = $stmt_all->fetch(PDO::FETCH_ASSOC)) {
                $cur_v = (float)$r['val'];
                if ($prev_v !== null) {
                    $int_factor = ($ct_step_ts !== null && strtotime($r['Time_stamp']) > $ct_step_ts) ? 1.00 : $applied_ct;
                    $diff = ($cur_v - $prev_v) * $int_factor;
                    if ($diff >= 0 && $diff < (2000 * $int_factor)) {
                        $mid_ts = strtotime($r['Time_stamp']) - 900;
                        $date_str = date('Y-m-d', $mid_ts);
                        if (!isset($daily_usage[$date_str])) $daily_usage[$date_str] = ['peak' => 0, 'std' => 0, 'off' => 0, 'total' => 0];
                        $daily_usage[$date_str]['total'] += $diff;
                        
                        if ($tou_algo !== 'None') {
                            $bucket = get_tou_bucket($mid_ts, $tou_algo, $holidays, $season);
                            if ($bucket !== 'none') {
                                $tou[$bucket] += $diff;
                                $daily_usage[$date_str][$bucket] += $diff;
                            } else {
                                $none_kwh += $diff;
                            }
                        }
                    }
                }
                $prev_v = $cur_v;
            }
        } catch (\Throwable $e) {}
    }

    if ($tou_algo !== 'None') {
        $valid_kwh_total = max(0, $kwh_total - $none_kwh); 
        $tou_sum = $tou['peak'] + $tou['std'] + $tou['off'];
        
        if ($tou_sum > 0 && abs($tou_sum - $valid_kwh_total) > 0.001) {
            $ratio = $valid_kwh_total / $tou_sum;
            $tou['peak'] = round($tou['peak'] * $ratio, 2);
            $tou['std'] = round($tou['std'] * $ratio, 2);
            $tou['off'] = round($valid_kwh_total - $tou['peak'] - $tou['std'], 2);
            foreach ($daily_usage as $d => $vals) {
                $daily_usage[$d]['peak'] = round($vals['peak'] * $ratio, 2);
                $daily_usage[$d]['std'] = round($vals['std'] * $ratio, 2);
                $scaled_off = round($vals['off'] * $ratio, 2);
                $daily_usage[$d]['off'] = $scaled_off;
                $daily_usage[$d]['total'] = $daily_usage[$d]['peak'] + $daily_usage[$d]['std'] + $scaled_off;
            }
        } elseif ($tou_sum == 0 && $valid_kwh_total > 0) {
            // Safe Fallback: If no intervals were captured, push mathematically required balance to off-peak
            $tou['off'] = round($valid_kwh_total, 2);
        }
    } else {
        $daily_sum = array_sum(array_column($daily_usage, 'total'));
        if ($daily_sum > 0 && abs($daily_sum - $kwh_total) > 0.001) {
            $ratio = $kwh_total / $daily_sum;
            foreach ($daily_usage as $d => $vals) {
                $daily_usage[$d]['total'] = round($vals['total'] * $ratio, 2);
            }
        }
    }

    $max_kwh_diff = 0;
    try {
        $db_11180 = "db_obis_11180_" . $readingYear; 
        $table_11180 = "tb_obis_11180_" . $readingYear; 
        $col_11180 = "11180_value";
        
        $t_start_11180 = $ranges['1.1.1.8.0']['start'] ?? ' 00:30:00';
        $t_end_11180 = $ranges['1.1.1.8.0']['end'] ?? ' 23:30:00';
        
        $stmt_11180 = $pdo->prepare("SELECT Time_stamp, `$col_11180` as val FROM `$db_11180`.`$table_11180` WHERE meter_serial = :serial AND Time_stamp >= :start AND Time_stamp <= :end ORDER BY Time_stamp ASC");
        
        $stmt_11180->execute(['serial' => $clean_serial, 'start' => $start . $t_start_11180, 'end' => $end . $t_end_11180]);
        $p_val = null;
        $p_time = null;
        
        $stagnant_intervals = 0; 
        
        while ($row = $stmt_11180->fetch(PDO::FETCH_ASSOC)) {
            $c_val = (float)$row['val'];
            $c_time = strtotime($row['Time_stamp']);
            
            if ($p_val !== null && $p_time !== null) {
                $int_factor = ($ct_step_ts !== null && $c_time > $ct_step_ts) ? 1.00 : $applied_ct;
                $diff = ($c_val - $p_val) * $int_factor;
                $t_diff = $c_time - $p_time;
                
                if ($diff == 0) {
                    $stagnant_intervals++;
                } elseif ($diff > 0 && $diff < (2000 * $int_factor)) { 
                    
                    if ($t_diff >= 1500 && $t_diff <= 2100) { 
                        
                        if ($stagnant_intervals > 0) {
                            $effective_intervals = $stagnant_intervals + 1;
                            $diff = $diff / $effective_intervals;
                        }
                        
                        $valid_demand = true;
                        if ($tou_algo !== 'None') {
                            $mid_ts = $c_time - 900;
                            $bucket = get_tou_bucket($mid_ts, $tou_algo, $holidays, $season);
                            if ($bucket === 'off' or $bucket === 'none') {
                                $valid_demand = false;
                            }
                        }
                        
                        if ($valid_demand && $diff > $max_kwh_diff) {
                            $max_kwh_diff = $diff;
                        }
                    }
                    $stagnant_intervals = 0; 
                }
            }
            $p_val = $c_val;
            $p_time = $c_time;
        }
    } catch (\Throwable $e) {}
    
    $pf = 0.85; 
    
    try {
        // Shared connections (one per database per page)
        $meter_db = lumSysDb('sys_db_meters');
        if (!$meter_db) throw new RuntimeException('sys_db_meters is not available');
        
        $stmt_prop = $meter_db->prepare("SELECT meter_property FROM lum_meters WHERE meter_serial = :serial LIMIT 1");
        $stmt_prop->execute(['serial' => $clean_serial]);
        $property_name = $stmt_prop->fetchColumn();
        
        if ($property_name) {
            $core_db = lumSysDb('sys_db_properties');
            if (!$core_db) throw new RuntimeException('sys_db_properties is not available');
            $stmt_pf = $core_db->prepare("SELECT power_factor FROM lum_properties WHERE Property = :prop LIMIT 1");
            $stmt_pf->execute(['prop' => $property_name]);
            $fetched_pf = $stmt_pf->fetchColumn();
            
            if ($fetched_pf !== false && (float)$fetched_pf > 0) {
                $pf = (float)$fetched_pf;
            }
        }
    } catch (\Throwable $e) {}

    $kw = $max_kwh_diff * 2;
    $kva = ($kw > 0) ? ($kw / $pf) : 0;

    return [
        'open'  => $open_val, 'close' => $close_val, 'kwh'   => $kwh_total,
        'kva'   => $kva, 'peak'  => $tou['peak'], 'std'   => $tou['std'], 
        'off'   => $tou['off'], 'daily' => $daily_usage, 'applied_ct' => $applied_ct,
        'ct_setting' => $ct_ratio,
        'ct_step' => ($ct_step_ts !== null) ? date('Y-m-d H:i', $ct_step_ts) : null,
        'ct_step_mode' => $ct_step_mode,
        'source' => $manual_used ? 'manual' : 'meter'
    ];
}

// --- AUTOMATED GENERATOR MANUAL RUNTIME ---
function calculateMeterOfflineHours($pdo, $serial, $start_date, $end_date) {
    if (!$pdo || empty($serial) || empty($start_date) || empty($end_date)) return 0;
    
    $clean_serial = @preg_replace('/[^a-zA-Z0-9]/', '', $serial);
    
    $start_ts = strtotime($start_date . ' 00:00:00');
    $end_ts = strtotime($end_date . ' 23:59:59');
    
    if ($start_ts === false || $end_ts === false || $start_ts > $end_ts) return 0;
    
    $year_start = date('Y', $start_ts);
    $year_end = date('Y', $end_ts);
    
    $total_offline_seconds = 0;
    $previous_timestamp = null;

    for ($y = $year_start; $y <= $year_end; $y++) {
        $db = "db_obis_11180_" . $y;
        $table = "tb_obis_11180_" . $y;
        
        try {
            $stmt = $pdo->prepare("SELECT Time_stamp FROM `$db`.`$table` WHERE meter_serial = :serial AND Time_stamp >= :start_dt AND Time_stamp <= :end_dt ORDER BY Time_stamp ASC");
            $stmt->execute([
                'serial' => $clean_serial,
                'start_dt' => date('Y-m-d H:i:s', $start_ts),
                'end_dt' => date('Y-m-d H:i:s', $end_ts)
            ]);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $current_timestamp = strtotime($row['Time_stamp']);
                
                if ($previous_timestamp !== null) {
                    $diff_seconds = $current_timestamp - $previous_timestamp;
                    if ($diff_seconds > 2000) {
                        $total_offline_seconds += $diff_seconds;
                    }
                }
                
                $previous_timestamp = $current_timestamp;
            }
        } catch (\Throwable $e) {
            continue;
        }
    }
    
    if ($total_offline_seconds > 0) {
        return round($total_offline_seconds / 3600, 2);
    }
    
    return 0;
}
