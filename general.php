<?php
// Library file: only other pages may include it, it can never be opened directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(403); exit('Forbidden'); }
// Shared helpers used across the reporting
// Part of reporting-engine.php; include that, never this file.

// Easter Sunday (Gregorian calendar) - does not need the PHP calendar extension
function lumEasterSunday($year) {
    $y = (int)$year;
    $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100; $d = intdiv($b, 4); $e = $b % 4;
    $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4); $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%04d-%02d-%02d', $y, $month, $day);
}

// Tier bands for a municipality and water period end date: [['up_to' => float|null, 'rate_key' => ..., 'label' => ...], ...]
function lumWaterTierBands($municipality, $date = null) {
    static $rows = null;
    if ($rows === null) {
        $rows = false;
        $pdo = lumSysDb('sys_db_tariffs');
        if ($pdo) {
            try {
                $rows = $pdo->query("SELECT municipality, DATE_FORMAT(effective_from, '%Y-%m-%d') AS effective_from, tier_no, up_to_kl, rate_key, label
                                     FROM lum_water_tiers ORDER BY municipality, effective_from, tier_no")->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                error_log('LUM water tiers: table not available (run water-tiers-setup.sql) - using the built-in bands: ' . $e->getMessage());
                $rows = false;
            }
        }
    }
    $date = (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) ? $date : date('Y-m-d');

    if (is_array($rows) && $rows) {
        foreach ([(string)$municipality, '*'] as $key) {
            // Latest set of bands that applies on the date
            $from = null;
            foreach ($rows as $r) {
                if (strcasecmp($r['municipality'], $key) === 0 && $r['effective_from'] <= $date && ($from === null || $r['effective_from'] > $from)) {
                    $from = $r['effective_from'];
                }
            }
            if ($from === null) continue;
            $bands = [];
            foreach ($rows as $r) {
                if (strcasecmp($r['municipality'], $key) === 0 && $r['effective_from'] === $from) {
                    $bands[] = ['up_to' => ($r['up_to_kl'] === null ? null : (float)$r['up_to_kl']), 'rate_key' => $r['rate_key'], 'label' => $r['label']];
                }
            }
            if ($bands) return $bands;
        }
    }

    $list = LUM_FALLBACK_WATER_TIERS[(string)$municipality] ?? LUM_FALLBACK_WATER_TIERS['*'];
    return array_map(function ($b) { return ['up_to' => $b[0], 'rate_key' => $b[1], 'label' => $b[2]]; }, $list);
}

// Readable range of one tier: "0–6 kL", "7–20 kL", "above 60 kL"
function lumWaterTierRange(array $bands, $index) {
    $fmt = function ($v) { return rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.'); };
    $prev = 0.0;
    for ($i = 0; $i < $index; $i++) {
        if (isset($bands[$i]['up_to']) && $bands[$i]['up_to'] !== null) $prev = (float)$bands[$i]['up_to'];
    }
    $up = $bands[$index]['up_to'] ?? null;
    if ($up === null) return 'above ' . $fmt($prev) . ' kL';
    if ($index === 0) return '0–' . $fmt($up) . ' kL';
    $from = (floor($prev) == $prev) ? $fmt($prev + 1) : $fmt($prev);
    return $from . '–' . $fmt($up) . ' kL';
}

// Bands for a tariff screen: always exactly $count tiers (one per rate column), the last without an upper limit
function lumWaterTierBandsFixed($municipality, $count, $date = null) {
    $bands = array_values(lumWaterTierBands($municipality, $date));
    $out = [];
    for ($i = 0; $i < $count; $i++) {
        $up = ($i < $count - 1) ? ($bands[$i]['up_to'] ?? null) : null;
        $out[] = ['up_to' => $up, 'rate_key' => 'water_tier_' . ($i + 1), 'label' => $bands[$i]['label'] ?? ('Water Charge Tier ' . ($i + 1))];
    }
    return $out;
}

// Split a water volume over tier bands: [['label', 'kl', 'rate_key'], ...]
function lumWaterTierSplit(array $bands, $total_kl) {
    $total_kl = max(0, (float)$total_kl);
    $out = [];
    $prev = 0.0;
    foreach ($bands as $b) {
        $upper = $b['up_to'];
        $kl = ($upper === null) ? max(0, $total_kl - $prev) : max(0, min((float)$upper, $total_kl) - $prev);
        $out[] = ['label' => $b['label'], 'kl' => $kl, 'rate_key' => $b['rate_key']];
        if ($upper !== null) $prev = max($prev, (float)$upper);
    }
    return $out;
}

// Lower-case text for matching tariff names (works without the mbstring extension too)
function lumLower($text) {
    return function_exists('mb_strtolower') ? mb_strtolower((string)$text, 'UTF-8') : strtolower((string)$text);
}

// Company details (read once per request). $refresh re-reads the table.
function lumCompanySettings($refresh = false) {
    static $settings = null;
    if ($settings !== null && !$refresh) return $settings;
    $settings = LUM_COMPANY_DEFAULTS;
    $pdo = lumSysDb('sys_db_information');
    if (!$pdo) return $settings;
    try {
        foreach ($pdo->query("SELECT setting_key, setting_value FROM lum_company_settings") as $row) {
            $key = (string)$row['setting_key'];
            if (!array_key_exists($key, LUM_COMPANY_DEFAULTS) || $row['setting_value'] === null) continue;
            $value = trim((string)$row['setting_value']);
            if ($value === '' && in_array($key, LUM_COMPANY_REQUIRED, true)) continue;
            $settings[$key] = $value;
        }
    } catch (\Throwable $e) {
        error_log('LUM company details: table not available (run company-details-setup.sql) - using the built-in details: ' . $e->getMessage());
        $settings = LUM_COMPANY_DEFAULTS;
    }
    return $settings;
}

// Document header (logo, title, company name and contact details) as HTML
function lumCompanyHeaderHtml($title = null, $settings = null) {
    $c = $settings ?? lumCompanySettings();
    $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $title = ($title === null || $title === '') ? $c['slip_title'] : $title;
    $html = '<div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">' . "\n";
    $html .= '    <div class="text-start">' . "\n";
    if ($c['logo_url'] !== '') {
        // The alt text is also used by the print styles to size the logo
        $html .= '        <img src="' . $h($c['logo_url']) . '" alt="Lynx Utility Management Logo" class="lum-doc-logo" style="height: 120px; width: auto;">' . "\n";
    }
    $html .= '    </div>' . "\n";
    $html .= '    <div class="text-end">' . "\n";
    $html .= '        <h2 class="text-dark mb-1 fw-normal">' . $h($title) . '</h2>' . "\n";
    $html .= '        <h4 class="text-dark mb-1 fw-normal">' . $h($c['company_name']) . '</h4>' . "\n";
    if ($c['company_address'] !== '') {
        $html .= '        <p class="text-muted mb-0">' . nl2br($h($c['company_address'])) . '</p>' . "\n";
    }
    $reg = [];
    if ($c['company_registration'] !== '') $reg[] = 'Reg No: ' . $h($c['company_registration']);
    if ($c['vat_number'] !== '') $reg[] = 'VAT No: ' . $h($c['vat_number']);
    if ($reg) $html .= '        <p class="text-muted mb-0">' . implode(' | ', $reg) . '</p>' . "\n";
    $contact = [];
    if ($c['company_phone'] !== '') $contact[] = 'Tel: ' . $h($c['company_phone']);
    if ($c['company_email'] !== '') $contact[] = 'Email: <a href="mailto:' . $h($c['company_email']) . '" class="text-decoration-none text-dark">' . $h($c['company_email']) . '</a>';
    if ($contact) $html .= '        <p class="text-muted mb-0">' . implode(' | ', $contact) . '</p>' . "\n";
    $html .= '    </div>' . "\n";
    $html .= '</div>' . "\n";
    return $html;
}

// Contact note at the bottom of a document ({document}, {phone}, {email}, {company} are filled in)
function lumCompanyContactNoteHtml($document, $settings = null) {
    $c = $settings ?? lumCompanySettings();
    $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $email_link = ($c['company_email'] !== '')
        ? '<a href="mailto:' . $h($c['company_email']) . '" class="text-decoration-none text-dark">' . $h($c['company_email']) . '</a>'
        : '';
    return strtr($h($c['contact_note']), [
        '{document}' => $h($document),
        '{phone}'    => $h($c['company_phone']),
        '{email}'    => $email_link,
        '{company}'  => $h($c['company_name']),
    ]);
}

// PDO for one of the system databases (cached, null when unavailable).
// Uses lum_db() from /var/www/Lynx/bootstrap.php, so pages and the engines share one connection per database.
function lumSysDb($database) {
    if (function_exists('lum_db')) return lum_db((string)$database);
    static $conns = [];
    if (array_key_exists($database, $conns)) return $conns[$database];
    $conns[$database] = null;
    $cfg = @parse_ini_file('/var/secure_configs/lynx_db.ini');
    if ($cfg === false || !preg_match('/^[A-Za-z0-9_]+$/', (string)$database)) return null;
    try {
        $pdo = new PDO("mysql:host={$cfg['host']};dbname={$database};charset=utf8mb4", $cfg['username'], $cfg['password']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $conns[$database] = $pdo;
    } catch (\Throwable $e) {
        error_log('LUM: database ' . $database . ' not available: ' . $e->getMessage());
    }
    return $conns[$database];
}

// Dashboard settings of a property ('All' = the combined view)
function lumDashboardSettings($property) {
    static $cache = [];
    $p = (string)$property;
    if (isset($cache[$p])) return $cache[$p];
    if ($p === 'All') {
        return $cache[$p] = ['elec_obis' => '1.1.1.8.0', 'grid_obis' => '1.1.1.8.0', 'tenant_obis' => '1.1.1.8.0',
                             'has_generator' => true, 'tenant_cards' => true, 'total_line' => false,
                             'auto_meters' => false, 'flow_title' => '', 'source' => 'fixed'];
    }
    // The dashboard as it was (used until the settings are installed, and for properties not in lum_properties)
    $no_gen = ['DHL', 'Groenkloof Chambers', 'Lambton Gardens', "Linton's Corner", 'One On York', 'The Marketsquare'];
    $no_cards = ['DHL', 'Groenkloof Chambers', 'Lambton Gardens', "Linton's Corner", 'One On York'];
    $no_total = ['DHL', 'Lambton Gardens', 'One On York', 'The Marketsquare'];
    $settings = [
        'elec_obis'     => ($p === 'The Marketsquare') ? '1.1.1.8.1' : '1.1.1.8.0',
        'has_generator' => !in_array($p, $no_gen, true),
        'tenant_cards'  => !in_array($p, $no_cards, true),
        'total_line'    => !in_array($p, $no_total, true),
        'auto_meters'   => ($p === 'One On York'),
        'flow_title'    => ($p === "Linton's Corner") ? 'Water Flow Overview' : (($p === 'Lynnwood Lane') ? 'Borehole Treatment Flow' : ''),
        'source'        => 'legacy',
    ];
    $settings['grid_obis'] = $settings['elec_obis'];
    $settings['tenant_obis'] = $settings['elec_obis'];
    $pdo = lumSysDb('sys_db_properties');
    if ($pdo && $p !== '') {
        try {
            $st = $pdo->prepare("SELECT * FROM lum_properties WHERE Property = ? LIMIT 1");
            $st->execute([$p]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && array_key_exists('dashboard_has_generator', $row)) {
                $obis = trim((string)$row['dashboard_elec_obis']);
                $grid_obis = in_array($obis, ['1.1.1.8.0', '1.1.1.8.1'], true) ? $obis : '1.1.1.8.0';
                // Tenant electrical usage OBIS (dashboard-tenant-obis-setup.sql); until then the grid OBIS
                $t_obis = trim((string)($row['dashboard_tenant_obis'] ?? ''));
                $tenant_obis = in_array($t_obis, ['1.1.1.8.0', '1.1.1.8.1'], true) ? $t_obis : $grid_obis;
                $settings = [
                    'elec_obis'     => $grid_obis, // Older name of the grid OBIS
                    'grid_obis'     => $grid_obis,
                    'tenant_obis'   => $tenant_obis,
                    'has_generator' => !empty($row['dashboard_has_generator']),
                    'tenant_cards'  => !empty($row['dashboard_tenant_cards']),
                    'total_line'    => !empty($row['dashboard_total_line']),
                    'auto_meters'   => !empty($row['dashboard_auto_meters']),
                    'flow_title'    => trim((string)$row['dashboard_flow_title']),
                    'source'        => 'table',
                ];
            }
        } catch (\Throwable $e) {
            error_log('LUM dashboard settings: using the built-in settings for ' . $p . ': ' . $e->getMessage());
        }
    }
    return $cache[$p] = $settings;
}

// A system setting (sys_db_information.lum_system_settings)
function lumSystemSetting($key, $default = null) {
    static $settings = null;
    if ($settings === null) {
        $settings = LUM_SYSTEM_SETTING_DEFAULTS;
        $pdo = lumSysDb('sys_db_information');
        if ($pdo) {
            try {
                foreach ($pdo->query("SELECT setting_key, setting_value FROM lum_system_settings") as $r) {
                    if ($r['setting_value'] !== null && trim((string)$r['setting_value']) !== '') $settings[$r['setting_key']] = trim((string)$r['setting_value']);
                }
            } catch (\Throwable $e) {
                // Table not installed: defaults
            }
        }
    }
    return $settings[$key] ?? $default;
}

function is_na($val) {
    $val = trim(strtolower((string)$val));
    return in_array($val, ['none', 'n/a', 'not applicable', '0', '0.00', '']);
}

function getDailyWaterUsage($pdo, $serial, $start_date, $end_date) {
    $clean_serial = @preg_replace('/[^a-zA-Z0-9]/', '', $serial);
    $readingYear = date('Y', strtotime($start_date));
    
    // Dynamic Time Boundaries
    $ranges = get_obis_time_ranges($pdo);
    $time_start = $ranges['8.1.1.0.0']['start'] ?? ' 00:00:00';
    $time_end = $ranges['8.1.1.0.0']['end'] ?? ' 23:59:59';
    
    $db = 'db_obis_81100_' . $readingYear; 
    $table = "tb_obis_81100_" . $readingYear; 
    $col = "81100_value";
    
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

function sumDailyArrays($arrays) {
    $result = [];
    foreach ($arrays as $arr) {
        foreach ($arr as $date => $val) {
            if (!isset($result[$date])) $result[$date] = 0;
            $result[$date] += $val;
        }
    }
    return $result;
}

function subtractDailyArrays($arr1, $arr2) {
    $result = [];
    foreach ($arr1 as $date => $val) {
        $sub = $arr2[$date] ?? 0;
        $result[$date] = max(0, $val - $sub);
    }
    return $result;
}

// Kept for older pages: Greystone Crossing's shared network access capacity
function calculateGreystoneSharedNAC($start_date, $end_date, $obis_code, $obis_db_conn, $tenant_db_conn, $core_db_conn) {
    return calculateSharedNAC('Greystone Crossing', $start_date, $end_date, $obis_code, $obis_db_conn, $tenant_db_conn, $core_db_conn);
}

// Shared network access capacity (kVA) left after the contributing tenants' own demand, for any property
// with a shared_nac_kva value (lum_properties) and tenants marked shared_nac_contribute = 'Yes'
function calculateSharedNAC($property_name, $start_date, $end_date, $obis_code, $obis_db_conn, $tenant_db_conn, $core_db_conn) {
    $shared_nac_kva = 0;
    try {
        $stmt_prop = $core_db_conn->prepare("SELECT shared_nac_kva FROM lum_properties WHERE Property = ? LIMIT 1");
        $stmt_prop->execute([(string)$property_name]);
        $shared_nac_kva = (float)$stmt_prop->fetchColumn();
    } catch (Exception $e) {}

    if ($shared_nac_kva <= 0) return 0;

    $total_tenant_kva = 0;
    try {
        $stmt = $tenant_db_conn->prepare("SELECT tenant_amps, tenant_electricalMeter_01, tenant_electricalMeter_02, tenant_electricalMeter_03, tenant_electricalMeter_01_ct_ratio, tenant_electricalMeter_02_ct_ratio, tenant_electricalMeter_03_ct_ratio FROM lum_tenants WHERE tenant_property = ? AND shared_nac_contribute = 'Yes'");
        $stmt->execute([(string)$property_name]);
        $contributing_tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $public_holidays = lumPublicHolidays();

        foreach ($contributing_tenants as $td) {
            $t_amps = floatval($td['tenant_amps'] ?? 0);
            for ($i = 1; $i <= 3; $i++) {
                $meter_col = "tenant_electricalMeter_0" . $i;
                $ct_col = "tenant_electricalMeter_0" . $i . "_ct_ratio";
                
                $raw_em = trim((string)($td[$meter_col] ?? ''));
                $clean_em = preg_replace('/[^a-zA-Z0-9]/', '', $raw_em);
                
                if (!empty($clean_em)) {
                    $ct = isset($td[$ct_col]) ? floatval($td[$ct_col]) : 1.0;
                    if ($ct <= 0) $ct = 1.0;
                    
                    $rdg = getRealElecReadings($obis_db_conn, $clean_em, $start_date, $end_date, $obis_code, 'None', $public_holidays, 'High', $ct, $t_amps);
                    if ($rdg) {
                        $total_tenant_kva += $rdg['kva'];
                    }
                }
            }
        }
    } catch (Exception $e) {}

    return max(0, $shared_nac_kva - $total_tenant_kva);
}

// =========================================================================
// CT MULTIPLIER WINDOW
// -------------------------------------------------------------------------
// Some meters were later re-programmed with their CT ratio. From that moment
// the meter stores real (already multiplied) kWh, so the tenant CT ratio may
// only be applied to readings taken BEFORE the switch-over. The switch-over
// shows up in the data as a sudden, lasting jump in daily usage of roughly
// the size of the CT ratio (e.g. 2 kWh/day -> 94 kWh/day on a CT 50 meter).
// =========================================================================

function lumMedian(array $values) {
    $values = array_values($values);
    $n = count($values);
    if ($n === 0) return 0;
    sort($values);
    $mid = intdiv($n, 2);
    return ($n % 2) ? $values[$mid] : (($values[$mid - 1] + $values[$mid]) / 2);
}

// Register reading nearest a timestamp: returns [value, time] of the last reading at/before (or first after) $ts
function lumRegisterPoint($pdo, $cleanCode, $clean_serial, $ts, $after = false) {
    $col = $cleanCode . '_value';
    $y = (int)date('Y', $ts);
    $years = $after ? [$y, $y + 1] : [$y, $y - 1];
    $op = $after ? '>' : '<=';
    $order = $after ? 'ASC' : 'DESC';
    foreach ($years as $yy) {
        try {
            $stmt = $pdo->prepare("SELECT Time_stamp, `$col` AS val FROM `db_obis_{$cleanCode}_{$yy}`.`tb_obis_{$cleanCode}_{$yy}` WHERE meter_serial = :s AND Time_stamp {$op} :t ORDER BY Time_stamp {$order} LIMIT 1");
            $stmt->execute(['s' => $clean_serial, 't' => date('Y-m-d H:i:s', $ts)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return [(float)$row['val'], strtotime($row['Time_stamp'])];
        } catch (\Throwable $e) {}
    }
    return null;
}

// Register value at a moment in time. Registers that are only stored once a day (e.g. 1.1.1.8.1) are
// interpolated between their daily readings using the shape of the half-hourly 1.1.1.8.0 register.
function lumRegisterAt($pdo, $cleanCode, $clean_serial, $ts) {
    $before = lumRegisterPoint($pdo, $cleanCode, $clean_serial, $ts, false);
    if ($before === null) return null;
    list($v0, $t0) = $before;
    if (($ts - $t0) <= 3600) return $v0;

    $next = lumRegisterPoint($pdo, $cleanCode, $clean_serial, $ts, true);
    if ($next === null) return $v0;
    list($v1, $t1) = $next;
    if ($v1 < $v0 || $t1 <= $t0) return $v0;

    $share = null;
    if ($cleanCode !== '11180') {
        $a0 = lumRegisterPoint($pdo, '11180', $clean_serial, $t0, false);
        $am = lumRegisterPoint($pdo, '11180', $clean_serial, $ts, false);
        $a1 = lumRegisterPoint($pdo, '11180', $clean_serial, $t1, false);
        if ($a0 !== null && $am !== null && $a1 !== null && $a1[0] > $a0[0]) {
            $share = ($am[0] - $a0[0]) / ($a1[0] - $a0[0]);
        }
    }
    if ($share === null) {
        $share = ($ts - $t0) / ($t1 - $t0);
    }
    $share = max(0, min(1, $share));
    return $v0 + $share * ($v1 - $v0);
}
