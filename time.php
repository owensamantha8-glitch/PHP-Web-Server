<?php
// Library file: only other pages may include it, it can never be opened directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(403); exit('Forbidden'); }
// Billing months, periods and dates
// Part of reporting-engine.php; include that, never this file.

// All public holiday dates ('Y-m-d'), read once per request
function lumPublicHolidays() {
    static $dates = null;
    if ($dates !== null) return $dates;
    $dates = LUM_FALLBACK_PUBLIC_HOLIDAYS;
    $pdo = lumSysDb('sys_db_information');
    if (!$pdo) {
        error_log('LUM public holidays: database not available - using the built-in list');
        return $dates;
    }
    try {
        $dates = $pdo->query("SELECT DATE_FORMAT(holiday_date, '%Y-%m-%d') FROM lum_public_holidays ORDER BY holiday_date")->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {
        error_log('LUM public holidays: table not available (run public-holidays-setup.sql) - using the built-in list: ' . $e->getMessage());
        $dates = LUM_FALLBACK_PUBLIC_HOLIDAYS;
    }
    return $dates;
}

// South African public holidays for a year: ['Y-m-d' => ['name' => ..., 'observed' => 0|1]]
// A holiday on a Sunday is also observed on the following Monday (Public Holidays Act).
// Special once-off holidays (e.g. election days) are not included.
function lumZaPublicHolidays($year) {
    $y = (int)$year;
    $out = [];
    $easter = new DateTime(lumEasterSunday($y));
    $out[(clone $easter)->modify('-2 days')->format('Y-m-d')] = ['name' => 'Good Friday', 'observed' => 0];
    $out[(clone $easter)->modify('+1 day')->format('Y-m-d')] = ['name' => 'Family Day', 'observed' => 0];
    $fixed = [
        [1, 1, "New Year's Day"], [3, 21, 'Human Rights Day'], [4, 27, 'Freedom Day'], [5, 1, "Workers' Day"],
        [6, 16, 'Youth Day'], [8, 9, "National Women's Day"], [9, 24, 'Heritage Day'],
        [12, 16, 'Day of Reconciliation'], [12, 25, 'Christmas Day'], [12, 26, 'Day of Goodwill'],
    ];
    foreach ($fixed as $f) {
        $date = new DateTime(sprintf('%04d-%02d-%02d', $y, $f[0], $f[1]));
        $key = $date->format('Y-m-d');
        if (!isset($out[$key])) $out[$key] = ['name' => $f[2], 'observed' => 0];
        if ((int)$date->format('N') === 7) {
            $obs = (clone $date)->modify('+1 day');
            while (isset($out[$obs->format('Y-m-d')])) $obs->modify('+1 day');
            $out[$obs->format('Y-m-d')] = ['name' => $f[2] . ' (observed)', 'observed' => 1];
        }
    }
    ksort($out);
    return $out;
}

// Converts a generator hour-meter reading ("3071:14:53") into decimal hours
function lumParseRuntimeHours($value) {
    $parts = explode(':', trim((string)$value));
    return (isset($parts[0]) ? (float)$parts[0] : 0)
         + (isset($parts[1]) ? (float)$parts[1] / 60 : 0)
         + (isset($parts[2]) ? (float)$parts[2] / 3600 : 0);
}

// Generator run time from manual_readings_genrun, matched by MONTH (not exact date):
//   opening reading = latest reading in the month of the run-time start date
//   closing reading = latest reading in the month of the run-time end date
//   If both dates fall in the same month, the opening reading comes from the month before.
// Returns ['hours' => float|null, 'open' => row|null, 'close' => row|null, 'open_month' => 'Y-m', 'close_month' => 'Y-m']
// 'hours' is null when either month has no reading.
function getManualGeneratorRuntimeByMonth($manual_pdo, $property, $runtime_start, $runtime_end) {
    static $runtime_cache = [];

    $close_month = date('Y-m', strtotime($runtime_end));
    $open_month = date('Y-m', strtotime($runtime_start));
    if ($open_month >= $close_month) {
        $open_month = date('Y-m', strtotime($close_month . '-01 -1 month'));
    }
    $result = ['hours' => null, 'open' => null, 'close' => null, 'open_month' => $open_month, 'close_month' => $close_month];
    if (!$manual_pdo || empty($property) || empty($runtime_start) || empty($runtime_end)) return $result;

    $key = $property . '|' . $open_month . '|' . $close_month;
    if (isset($runtime_cache[$key])) return $runtime_cache[$key];

    try {
        $stmt = $manual_pdo->prepare("SELECT reading_date, reading_hours FROM manual_readings_genrun WHERE property = :prop AND reading_date >= :m_start AND reading_date <= :m_end ORDER BY reading_date DESC, id DESC LIMIT 1");

        $stmt->execute(['prop' => $property, 'm_start' => $close_month . '-01', 'm_end' => date('Y-m-t', strtotime($close_month . '-01'))]);
        $close_row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $stmt->execute(['prop' => $property, 'm_start' => $open_month . '-01', 'm_end' => date('Y-m-t', strtotime($open_month . '-01'))]);
        $open_row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $result['open'] = $open_row;
        $result['close'] = $close_row;
        if ($open_row && $close_row) {
            $diff = lumParseRuntimeHours($close_row['reading_hours']) - lumParseRuntimeHours($open_row['reading_hours']);
            $result['hours'] = max(0, round($diff, 2));
        }
    } catch (\Throwable $e) {
        error_log("Generator runtime error: " . $e->getMessage());
    }

    $runtime_cache[$key] = $result;
    return $result;
}

// Kept for existing callers: run time in hours between the months of $start_date and $end_date
function getManualGeneratorRuntime($manual_pdo, $property, $start_date, $end_date) {
    $info = getManualGeneratorRuntimeByMonth($manual_pdo, $property, $start_date, $end_date);
    return $info['hours'] ?? 0;
}
