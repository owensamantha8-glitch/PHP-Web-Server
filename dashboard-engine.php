<?php
// Library file: only other pages may include it, it can never be opened directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(403); exit('Forbidden'); }
// =========================================================================
// LYNX UTILITY MANAGEMENT - DASHBOARD CALCULATION ENGINE
// =========================================================================

// =========================================================================
// FIRST / LAST READING PER METER (fast)
// -------------------------------------------------------------------------
// One primary-key lookup per meter ((meter_serial, Time_stamp) ... ORDER BY Time_stamp LIMIT 1),
// about 150 meters per statement. A grouped MIN()/MAX() sub-query over a long meter list
// made MySQL read every reading in the date range instead (0.3 - 1 s per call).
// $mode: 'last_le' = last reading at or before $ts, 'last_lt' = last reading before $ts,
//        'first_ge' = first reading at or after $ts.
// Returns [meter_serial => value]. Throws on a database error (the callers catch it).
// =========================================================================
function lumDashEdgeValues($pdo, $db, $table, $col, array $serials, $ts, $mode) {
    $ops = ['last_le' => ['<=', 'DESC'], 'last_lt' => ['<', 'DESC'], 'first_ge' => ['>=', 'ASC']];
    if (!isset($ops[$mode])) return [];
    list($op, $dir) = $ops[$mode];
    $out = [];
    foreach (array_chunk(array_values(array_unique($serials)), 150) as $chunk) {
        $parts = [];
        $params = [];
        foreach ($chunk as $serial) {
            $parts[] = "(SELECT meter_serial, `$col` AS val FROM `$db`.`$table` WHERE meter_serial = ? AND Time_stamp $op ? ORDER BY Time_stamp $dir LIMIT 1)";
            $params[] = $serial;
            $params[] = $ts;
        }
        $stmt = $pdo->prepare(implode(' UNION ALL ', $parts));
        $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[$row['meter_serial']] = (float)$row['val'];
        }
    }
    return $out;
}

function getBulkDailyUsage($pdo, $serials, $start_date, $end_date, $obis_code = '1.1.1.8.0') {
    $daily = [];
    $period = new DatePeriod(new DateTime($start_date), new DateInterval('P1D'), (new DateTime($end_date))->modify('+1 day'));
    foreach ($period as $dt) {
        $daily[$dt->format("Y-m-d")] = 0;
    }
    
    $clean_serials = array_filter(array_map(function($s) { return @preg_replace('/[^a-zA-Z0-9]/', '', $s); }, $serials));
    if (empty($clean_serials)) return $daily;
    
    $cleanCode = @preg_replace('/[^0-9]/', '', $obis_code);
    $readingYear = date('Y', strtotime($start_date));
    $db = "db_obis_" . $cleanCode . "_" . $readingYear;
    $table = "tb_obis_" . $cleanCode . "_" . $readingYear;
    $col = $cleanCode . "_value";
    
    $inQuery = implode(',', array_fill(0, count($clean_serials), '?'));
    
    try {
        // Last reading before the period of each meter
        $baselines = lumDashEdgeValues($pdo, $db, $table, $col, $clean_serials, $start_date . ' 00:00:00', 'last_lt');
        
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

function getBulkMeterTotal($pdo, $serials, $start_date, $end_date, $obis_code, $ranges) {
    $clean_serials = array_filter(array_map(function($s) { return @preg_replace('/[^a-zA-Z0-9]/', '', $s); }, $serials));
    if (empty($clean_serials)) return 0;
    
    $cleanCode = @preg_replace('/[^0-9]/', '', $obis_code);
    $readingYear = date('Y', strtotime($start_date));
    $db = "db_obis_" . $cleanCode . "_" . $readingYear;
    $table = "tb_obis_" . $cleanCode . "_" . $readingYear;
    $col = $cleanCode . "_value";
    
    $time_start = $ranges[$obis_code]['start'] ?? ' 00:00:00';
    $time_end = $ranges[$obis_code]['end'] ?? ' 23:59:59';
    
    $actual_start_date = date('Y-m-d', strtotime($start_date . ' -1 day'));

    $inQuery = implode(',', array_fill(0, count($clean_serials), '?'));
    $total_usage = 0;
    
    try {
        // Opening reading (first from the start) and closing reading (last up to the end) of each meter
        $opens = lumDashEdgeValues($pdo, $db, $table, $col, $clean_serials, $actual_start_date . $time_start, 'first_ge');
        $closes = lumDashEdgeValues($pdo, $db, $table, $col, $clean_serials, $end_date . $time_end, 'last_le');

        foreach ($clean_serials as $s) {
            if (isset($opens[$s]) && isset($closes[$s])) {
                $total_usage += max(0, $closes[$s] - $opens[$s]);
            }
        }
    } catch (Exception $e) {}
    
    return $total_usage;
}

function getIndividualMeterTotals($pdo, $serials, $start_date, $end_date, $obis_code, $ranges) {
    $totals = [];
    $clean_serials = array_filter(array_map(function($s) { return @preg_replace('/[^a-zA-Z0-9]/', '', $s); }, $serials));
    if (empty($clean_serials)) return $totals;
    
    $cleanCode = @preg_replace('/[^0-9]/', '', $obis_code);
    $readingYear = date('Y', strtotime($start_date));
    $db = "db_obis_" . $cleanCode . "_" . $readingYear;
    $table = "tb_obis_" . $cleanCode . "_" . $readingYear;
    $col = $cleanCode . "_value";
    
    $time_start = $ranges[$obis_code]['start'] ?? ' 00:00:00';
    $time_end = $ranges[$obis_code]['end'] ?? ' 23:59:59';
    
    $actual_start_date = date('Y-m-d', strtotime($start_date . ' -1 day'));

    $inQuery = implode(',', array_fill(0, count($clean_serials), '?'));
    
    try {
        // Opening reading (first from the start) and closing reading (last up to the end) of each meter
        $opens = lumDashEdgeValues($pdo, $db, $table, $col, $clean_serials, $actual_start_date . $time_start, 'first_ge');
        $closes = lumDashEdgeValues($pdo, $db, $table, $col, $clean_serials, $end_date . $time_end, 'last_le');

        foreach ($clean_serials as $s) {
            if (isset($opens[$s]) && isset($closes[$s])) {
                $totals[$s] = max(0, $closes[$s] - $opens[$s]);
            } else {
                $totals[$s] = 0.00;
            }
        }
    } catch (Exception $e) {}
    
    return $totals;
}

function getDailyMeterTotals($pdo, $serials, $target_date, $obis_code, $ranges) {
    return getIndividualMeterTotals($pdo, $serials, $target_date, $target_date, $obis_code, $ranges);
}

function getPacingTrends($pdo, $serials, $current_date, $prev_date, $obis_code, $ranges, $current_day_usages, $prev_day_usages) {
    $pacing = [];
    $clean_serials = array_filter(array_map(function($s) { return @preg_replace('/[^a-zA-Z0-9]/', '', $s); }, $serials));
    if (empty($clean_serials)) return $pacing;
    
    $cleanCode = @preg_replace('/[^0-9]/', '', $obis_code);
    $readingYear = date('Y', strtotime($current_date));
    $db = "db_obis_" . $cleanCode . "_" . $readingYear;
    $table = "tb_obis_" . $cleanCode . "_" . $readingYear;
    
    $time_start = $ranges[$obis_code]['start'] ?? ' 00:00:00';
    $raw_start_time = trim($ranges[$obis_code]['start'] ?? '00:00:00');
    $step_back = (strtotime("1970-01-01 " . $raw_start_time) >= strtotime("1970-01-01 12:00:00"));
    $curr_start_date = $step_back ? date('Y-m-d', strtotime($current_date . ' -1 day')) : $current_date;

    $inQuery = implode(',', array_fill(0, count($clean_serials), '?'));
    
    try {
        $paramsMax = array_values($clean_serials);
        $paramsMax[] = $curr_start_date . $time_start;
        $paramsMax[] = $current_date . ' 23:59:59';
        
        $maxSql = "SELECT MAX(Time_stamp) as latest_ts FROM `$db`.`$table` WHERE meter_serial IN ($inQuery) AND Time_stamp >= ? AND Time_stamp <= ?";
        $stmt_max = $pdo->prepare($maxSql);
        $stmt_max->execute($paramsMax);
        $global_latest_ts = $stmt_max->fetchColumn();
        
        $period_start_dt = strtotime($curr_start_date . ' ' . $raw_start_time);
        
        if ($global_latest_ts) {
            $latest_dt = strtotime($global_latest_ts);
        } else {
            if ($current_date === date('Y-m-d')) {
                $latest_dt = time(); 
            } else {
                $latest_dt = strtotime($current_date . ' 23:59:59');
            }
        }
        
        $hours_passed = ($latest_dt - $period_start_dt) / 3600;
        if ($hours_passed <= 0) $hours_passed = 0.5;
        
        foreach ($clean_serials as $s) {
            $pacing[$s] = '';
            
            $curr_total = (float)($current_day_usages[$s] ?? 0);
            $prev_total = (float)($prev_day_usages[$s] ?? 0);
            
            if ($prev_total > 0 && $curr_total > 0) {
                $today_rate = $curr_total / $hours_passed; 
                $yest_rate = $prev_total / 24.0; 
                
                $diff = $today_rate - $yest_rate;
                $pct = ($diff / $yest_rate) * 100;
                
                if (round($pct, 1) == 0.0) {
                    $pacing[$s] = '<span class="text-secondary small fw-normal" title="Current run-rate matches yesterday\'s average">&bull; 0%</span>';
                } elseif ($pct > 0) {
                    $pacing[$s] = '<span class="text-danger small fw-normal" title="Consuming '.number_format($pct, 1).'% faster than yesterday\'s average rate">&#9650; '.number_format(abs($pct), 1).'%</span>';
                } else {
                    $pacing[$s] = '<span class="text-success small fw-normal" title="Consuming '.number_format(abs($pct), 1).'% slower than yesterday\'s average rate">&#9660; '.number_format(abs($pct), 1).'%</span>';
                }
            } else if ($prev_total == 0 && $curr_total > 0) {
                $pacing[$s] = '<span class="text-danger small fw-normal" title="New usage detected">&#9650; +</span>';
            }
        }
    } catch (Exception $e) {}
    
    return $pacing;
}

// Which of these meters have any OBIS readings between two dates (one query per year table).
// Used to skip the slow manual-reading fallback for meters that simply used nothing.
// Returns [clean_serial => true].
function getSerialsWithData($pdo, $serials, $start_date, $end_date, $obis_code) {
    $found = [];
    $clean_serials = array_values(array_unique(array_filter(array_map(function($s) { return @preg_replace('/[^a-zA-Z0-9]/', '', (string)$s); }, $serials))));
    if (empty($clean_serials)) return $found;

    $cleanCode = @preg_replace('/[^0-9]/', '', $obis_code);
    $from = date('Y-m-d', strtotime($start_date . ' -1 day')) . ' 00:00:00';
    $to = $end_date . ' 23:59:59';
    $inQuery = implode(',', array_fill(0, count($clean_serials), '?'));

    for ($y = (int)substr($from, 0, 4); $y <= (int)substr($to, 0, 4); $y++) {
        $db = "db_obis_" . $cleanCode . "_" . $y;
        $table = "tb_obis_" . $cleanCode . "_" . $y;
        try {
            $stmt = $pdo->prepare("SELECT DISTINCT meter_serial FROM `$db`.`$table` WHERE meter_serial IN ($inQuery) AND Time_stamp >= ? AND Time_stamp <= ?");
            $stmt->execute(array_merge($clean_serials, [$from, $to]));
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $serial) {
                $found[$serial] = true;
            }
        } catch (Exception $e) {}
    }
    return $found;
}

function getMeterStatuses($pdo, $serials, $obis_code) {
    // Online / Data Lag limits in hours (Configurations -> Property Meters)
    $online_hours = function_exists('lumSystemSetting') ? (float)lumSystemSetting('meter_online_hours', 6) : 6;
    $lag_hours = function_exists('lumSystemSetting') ? (float)lumSystemSetting('meter_lag_hours', 72) : 72;
    $statuses = [];
    $clean_serials = array_filter(array_map(function($s) { return @preg_replace('/[^a-zA-Z0-9]/', '', $s); }, $serials));
    if (empty($clean_serials)) return $statuses;
    
    $cleanCode = @preg_replace('/[^0-9]/', '', $obis_code);
    $readingYear = date('Y'); 
    $db = "db_obis_" . $cleanCode . "_" . $readingYear;
    $table = "tb_obis_" . $cleanCode . "_" . $readingYear;
    
    $inQuery = implode(',', array_fill(0, count($clean_serials), '?'));
    
    try {
        $sql = "SELECT meter_serial, MAX(Time_stamp) as last_reading FROM `$db`.`$table` WHERE meter_serial IN ($inQuery) GROUP BY meter_serial";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($clean_serials));
        
        $now = time(); 
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $serial = $row['meter_serial'];
            $last_reading = $row['last_reading'];
            $reading_time = strtotime($last_reading);
            
            $hours_diff = ($now - $reading_time) / 3600;
            
            $status = 'Offline';
            $color = 'danger';
            
            if ($hours_diff <= $online_hours) {
                $status = 'Online';
                $color = 'success';
            } elseif ($hours_diff <= $lag_hours) {
                $status = 'Data Lag';
                $color = 'warning text-dark';
            }
            
            $statuses[$serial] = [
                'last_reading' => date('d M H:i', $reading_time),
                'status' => $status,
                'color' => $color
            ];
        }
    } catch (Exception $e) {}
    
    return $statuses;
}
?>