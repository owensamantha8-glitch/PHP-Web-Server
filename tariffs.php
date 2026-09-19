<?php
// Library file: only other pages may include it, it can never be opened directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(403); exit('Forbidden'); }
// Tariffs: catalogue, rates, time of use
// Part of reporting-engine.php; include that, never this file.

// All catalog rows: [service => [lower-case name => row]]. $refresh re-reads the table.
function lumTariffCatalog($refresh = false) {
    static $catalog = null;
    if ($catalog !== null && !$refresh) return $catalog;
    $catalog = [];
    $pdo = lumSysDb('sys_db_tariffs');
    if (!$pdo) return $catalog;
    try {
        foreach ($pdo->query("SELECT * FROM lum_tariff_catalog") as $row) {
            $catalog[$row['service']][lumLower(trim((string)$row['tariff_name']))] = $row;
        }
    } catch (\Throwable $e) {
        static $logged = false;
        if (!$logged) {
            error_log('LUM tariff catalog: table not available (run tariff-catalog-setup.sql) - using the name rules: ' . $e->getMessage());
            $logged = true;
        }
    }
    return $catalog;
}

// Catalog row of a tariff (active or not), or null
function lumTariffLookup($name, $service) {
    $key = lumLower(trim((string)$name));
    if ($key === '') return null;
    $catalog = lumTariffCatalog();
    return $catalog[$service][$key] ?? null;
}

// ---- Old name rules (used for tariffs that are not in the catalog, and to fill the catalog) ----
function lumTariffLegacyIsTou($name) {
    // "TOU" as a separate word marks a Time Of Use tariff ("Bitou" on its own does not count)
    return (bool)preg_match('/\bTOU\b/i', (string)$name);
}

function lumTariffLegacyMunicipality($name, $service) {
    $n = (string)$name;
    // Common area charges are named like the electricity tariffs ("City of Tshwane - Common Area")
    if ($service === 'electricity' || $service === 'elec_common' || $service === 'water_common') {
        if (stripos($n, 'George') !== false) return 'George';
        if (stripos($n, 'Ekhurhuleni') !== false || stripos($n, 'Ekurhuleni') !== false) return 'Ekhurhuleni';
        if (stripos($n, 'Rustenburg') !== false) return 'Rustenburg';
        if (stripos($n, 'Mkhondo') !== false) return 'Mkhondo';
        if (stripos($n, 'Plett') !== false) return 'Plettenberg Bay';
        if (stripos($n, 'Tshwane') !== false || stripos($n, 'Low Voltage Demand') !== false || stripos($n, 'Prepaid TOU') !== false) return 'Tshwane';
        return null;
    }
    if ($service === 'water' || $service === 'sewer') {
        if (stripos($n, 'George') !== false) return 'George';
        if (stripos($n, 'Mkhondo') !== false) return 'Mkhondo';
        if (stripos($n, 'Bitou') !== false) return 'Plettenberg Bay';
        return null;
    }
    return null;
}

function lumTariffLegacyNotApplicable($name, $service) {
    $raw = (string)$name;
    if ($service === 'generator') {
        $n = trim($raw);
        return ($n === '' || $n === '0' || stripos($n, 'not app') !== false || stripos($n, 'none') !== false || stripos($n, 'n/a') !== false);
    }
    if ($service === 'electricity') {
        return (trim($raw) === '' || trim($raw) === 'Not applicable');
    }
    if ($service === 'elec_common' || $service === 'water_common') {
        return is_na($raw); // As the slips decide whether a common area charge applies
    }
    // Water and sewer: blank or "0" (as PHP empty()), or "Not app..." / "none" in the name
    return (empty($raw) || stripos($raw, 'Not app') !== false || stripos($raw, 'none') !== false);
}

function lumTariffLegacyShowsAmps($name) {
    $n = (string)$name;
    return (stripos($n, 'Tariff B') !== false || stripos($n, 'Tariff A') !== false || stripos($n, 'George - General Consumers') !== false);
}

// Old display / calculation names: Prepaid TOU tariffs are shown as the TOU tariff and billed on the Prepaid TOU rates
function lumTariffLegacyNames($name) {
    if (stripos((string)$name, 'Prepaid TOU') !== false) return ['City of Tshwane - TOU', 'City of Tshwane - Prepaid TOU'];
    return [null, null];
}

// Everything the old rules say about a tariff (used to fill the catalog)
function lumTariffDerive($name, $service) {
    $names = ($service === 'electricity') ? lumTariffLegacyNames($name) : [null, null];
    return [
        'service'        => $service,
        'tariff_name'    => trim((string)$name),
        'municipality'   => lumTariffLegacyMunicipality($name, $service),
        'not_applicable' => lumTariffLegacyNotApplicable($name, $service) ? 1 : 0,
        'is_tou'         => ($service === 'electricity' && lumTariffLegacyIsTou($name)) ? 1 : 0,
        'tou_algorithm'  => null,
        'shows_amps'     => ($service === 'electricity' && lumTariffLegacyShowsAmps($name)) ? 1 : 0,
        'display_name'   => $names[0],
        'calc_name'      => $names[1],
    ];
}

// ---- Catalog first, old rules as fallback ----
function lumTariffIsTou($name) {
    $row = lumTariffLookup($name, 'electricity');
    return $row ? (bool)$row['is_tou'] : lumTariffLegacyIsTou($name);
}

function lumTariffMunicipality($name, $service) {
    $row = lumTariffLookup($name, $service);
    if ($row) return (trim((string)$row['municipality']) !== '') ? $row['municipality'] : null;
    return lumTariffLegacyMunicipality($name, $service);
}

function lumTariffNotApplicable($name, $service) {
    $raw = (string)$name;
    if ($service === 'water' || $service === 'sewer') {
        if (empty($raw)) return true;               // blank or "0", exactly as before
    } elseif (trim($raw) === '' || ($service === 'generator' && trim($raw) === '0')) {
        return true;
    }
    $row = lumTariffLookup($name, $service);
    return $row ? (bool)$row['not_applicable'] : lumTariffLegacyNotApplicable($name, $service);
}

function lumTariffShowsAmps($name) {
    $row = lumTariffLookup($name, 'electricity');
    return $row ? (bool)$row['shows_amps'] : lumTariffLegacyShowsAmps($name);
}

// TOU algorithm chosen on the catalog ('' = use the municipality default)
function lumTariffTouAlgorithm($name) {
    $row = lumTariffLookup($name, 'electricity');
    $algo = $row ? trim((string)$row['tou_algorithm']) : '';
    return in_array($algo, lumTouAlgorithmList(), true) ? $algo : '';
}

// Name shown on the slip ('' = the tariff name)
function lumTariffDisplayName($name, $service) {
    $row = lumTariffLookup($name, $service);
    return $row ? trim((string)$row['display_name']) : '';
}

// Municipality from a tenant's tariffs, in the same order of preference as before:
// George (any) > Ekhurhuleni (electricity) > Rustenburg (electricity) > Mkhondo (any)
// > Plettenberg Bay (any) > Tshwane (electricity) > any other municipality found. null = not decided.
function lumTariffMunicipalityFor($elec_tariff, $water_tariff, $sewer_tariff) {
    $e = lumTariffMunicipality($elec_tariff, 'electricity');
    $w = lumTariffMunicipality($water_tariff, 'water');
    $s = lumTariffMunicipality($sewer_tariff, 'sewer');
    $any = [$e, $w, $s];
    if (in_array('George', $any, true)) return 'George';
    if ($e === 'Ekhurhuleni') return 'Ekhurhuleni';
    if ($e === 'Rustenburg') return 'Rustenburg';
    if (in_array('Mkhondo', $any, true)) return 'Mkhondo';
    if (in_array('Plettenberg Bay', $any, true)) return 'Plettenberg Bay';
    if ($e === 'Tshwane') return 'Tshwane';
    foreach ($any as $m) if ($m !== null) return $m;
    return null;
}

// Connection to sys_db_tariffs for the TOU tables (null when unavailable)
function lumTouDb() {
    return lumSysDb('sys_db_tariffs');
}

// All TOU algorithms (sorted), with their hour patterns. $refresh re-reads the tables.
function lumTouConfig($refresh = false) {
    static $config = null;
    if ($config !== null && !$refresh) return $config;
    $config = LUM_TOU_DEFAULTS;
    $pdo = lumTouDb();
    if ($pdo) {
        try {
            $algos = $pdo->query("SELECT * FROM lum_tou_algorithms ORDER BY sort_order, algorithm")->fetchAll(PDO::FETCH_ASSOC);
            if ($algos) {
                $blank = str_repeat('O', 24);
                $config = [];
                foreach ($algos as $a) {
                    $config[$a['algorithm']] = [
                        'label' => (string)$a['label'],
                        'holiday_rule' => in_array($a['holiday_rule'], ['saturday', 'sunday', 'weekday'], true) ? $a['holiday_rule'] : 'saturday',
                        'season_source' => in_array($a['season_source'], ['months', 'ledger', 'none'], true) ? $a['season_source'] : 'months',
                        'high_season_months' => (string)$a['high_season_months'],
                        'default_for' => (string)$a['default_for'],
                        'aliases' => (string)$a['aliases'],
                        'season_ledger' => (string)$a['season_ledger'],
                        'sort_order' => (int)$a['sort_order'],
                        'active' => (int)$a['active'],
                        'bands' => ['high' => ['weekday' => $blank, 'saturday' => $blank, 'sunday' => $blank],
                                    'low'  => ['weekday' => $blank, 'saturday' => $blank, 'sunday' => $blank]],
                    ];
                }
                foreach ($pdo->query("SELECT algorithm, season, day_type, hours FROM lum_tou_bands") as $b) {
                    if (isset($config[$b['algorithm']]['bands'][$b['season']][$b['day_type']]) && preg_match('/^[PSO]{24}$/', (string)$b['hours'])) {
                        $config[$b['algorithm']]['bands'][$b['season']][$b['day_type']] = $b['hours'];
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('LUM TOU periods: tables not available (run tou-periods-setup.sql) - using the built-in periods: ' . $e->getMessage());
            $config = LUM_TOU_DEFAULTS;
        }
    }
    return $config;
}

// Algorithm key for a name used on slips, reports and tariffs (null = not a TOU algorithm -> everything standard)
function lumTouResolve($algorithm) {
    static $cache = [];
    $name = (string)$algorithm;
    if (array_key_exists($name, $cache)) return $cache[$name];
    $config = lumTouConfig();
    if (isset($config[$name])) return $cache[$name] = $name;
    // Exact other names first, then "*text*" names in the algorithm order
    foreach ($config as $key => $a) {
        foreach (array_map('trim', explode(',', $a['aliases'])) as $alias) {
            if ($alias !== '' && $alias[0] !== '*' && $alias === $name) return $cache[$name] = $key;
        }
    }
    foreach ($config as $key => $a) {
        foreach (array_map('trim', explode(',', $a['aliases'])) as $alias) {
            if (strlen($alias) > 2 && $alias[0] === '*' && substr($alias, -1) === '*' && stripos($name, substr($alias, 1, -1)) !== false) {
                return $cache[$name] = $key;
            }
        }
    }
    return $cache[$name] = null;
}

// demand_season of a municipal tariff month: 'high', 'low' or null (no ledger row)
function lumTouLedgerSeason($table, $year, $month) {
    static $cache = [];
    if (!isset(LUM_TOU_SEASON_LEDGERS[$table])) return null;
    $key = $table . '|' . (int)$year . '|' . (int)$month;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $cache[$key] = null;
    $pdo = lumTouDb();
    if ($pdo) {
        try {
            $st = $pdo->prepare("SELECT demand_season FROM `$table` WHERE period_year = ? AND period_month = ? LIMIT 1");
            $st->execute([(int)$year, (int)$month]);
            $v = $st->fetchColumn();
            if ($v !== false && trim((string)$v) !== '') $cache[$key] = (strcasecmp(trim((string)$v), 'High') === 0) ? 'high' : 'low';
        } catch (\Throwable $e) {
            error_log('LUM TOU periods: season not read from ' . $table . ': ' . $e->getMessage());
        }
    }
    return $cache[$key];
}

// Season of an algorithm in a calendar month: 'high' or 'low'
function lumTouSeason($key, $year, $month) {
    static $cache = [];
    $ck = $key . '|' . (int)$year . '|' . (int)$month;
    if (isset($cache[$ck])) return $cache[$ck];
    $a = lumTouConfig()[$key] ?? null;
    if (!$a || $a['season_source'] === 'none') return $cache[$ck] = 'low';
    if ($a['season_source'] === 'ledger') {
        $ledger = lumTouLedgerSeason($a['season_ledger'], $year, $month);
        if ($ledger !== null) return $cache[$ck] = $ledger;
        // No tariff month in the ledger: the high season months apply
    }
    $months = array_map('intval', array_filter(array_map('trim', explode(',', $a['high_season_months'])), 'strlen'));
    return $cache[$ck] = in_array((int)$month, $months, true) ? 'high' : 'low';
}

// Active algorithm keys, in order (for drop-downs and validation)
function lumTouAlgorithmList() {
    $list = [];
    foreach (lumTouConfig() as $key => $a) {
        if (!empty($a['active'])) $list[] = $key;
    }
    return $list;
}

// Default algorithm for a municipality
function lumTouDefaultAlgorithm($municipality) {
    $fallback = null;
    foreach (lumTouConfig() as $key => $a) {
        if (empty($a['active'])) continue;
        $for = array_map('trim', explode(',', $a['default_for']));
        if (in_array((string)$municipality, $for, true)) return $key;
        if ($fallback === null && in_array('*', $for, true)) $fallback = $key;
    }
    return $fallback ?? 'Tshwane';
}

function fetchMonthlyTariff($db_conn, $table_name, $month, $year) {
    $stmt = $db_conn->prepare("SELECT * FROM {$table_name} WHERE period_year = ? AND period_month = ?");
    $stmt->execute([$year, $month]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$data) {
        $stmt = $db_conn->prepare("SELECT * FROM {$table_name} WHERE (period_year = ? AND period_month < ?) OR (period_year < ?) ORDER BY period_year DESC, period_month DESC LIMIT 1");
        $stmt->execute([$year, $month, $year]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$data) { 
            $data = $db_conn->query("SELECT * FROM {$table_name} ORDER BY period_year DESC, period_month DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        }
    }
    return $data ?: [];
}

// The rate map: ['municipality' => [muni => rows], 'electricity'|'water'|'sewer' => [lower name => [muni => rows]]]
// null = not installed or empty (old rules everywhere)
function lumRateMap($refresh = false) {
    static $map = false;
    if ($map !== false && !$refresh) return $map;
    $map = null;
    $pdo = lumSysDb('sys_db_tariffs');
    if (!$pdo) return $map;
    try {
        $rows = $pdo->query("SELECT map_type, tariff_name, municipality, rate_key, ledger, column_name, fallback_column, positive_only FROM lum_tariff_rate_map")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return $map;
        $map = ['municipality' => [], 'electricity' => [], 'water' => [], 'sewer' => []];
        foreach ($rows as $r) {
            $type = (string)$r['map_type'];
            if (!isset($map[$type])) continue;
            $muni = (string)$r['municipality'];
            $entry = ($r['rate_key'] === '-') ? null : $r;
            if ($type === 'municipality') {
                if (!isset($map[$type][$muni])) $map[$type][$muni] = [];
                if ($entry) $map[$type][$muni][] = $entry;
            } else {
                $name = lumLower(trim((string)$r['tariff_name']));
                if (!isset($map[$type][$name][$muni])) $map[$type][$name][$muni] = [];
                if ($entry) $map[$type][$name][$muni][] = $entry;
            }
        }
    } catch (\Throwable $e) {
        error_log('LUM rate map: table not available (run tariff-rate-map-setup.sql) - using the tariff name rules: ' . $e->getMessage());
        $map = null;
    }
    return $map;
}

// Which municipality's generator, water, sewer and common area rates apply (same order as the old rules)
function lumRateMapMunicipalityKey($municipality, $elec_tariff) {
    $m = (string)$municipality;
    if ($m === 'Ekhurhuleni' || $m === 'Ekurhuleni') return 'Ekhurhuleni';
    if ($m === 'Rustenburg') return 'Rustenburg';
    if ($m === 'Mkhondo') return 'Mkhondo';
    if (stripos($m, 'Plett') !== false) return 'Plettenberg Bay';
    if (stripos($m, 'George') !== false || lumTariffMunicipality($elec_tariff, 'electricity') === 'George') return 'George';
    return 'Tshwane';
}

// Put mapped rates into $r
function lumRateMapApply(array &$r, array $rows, array $ledgers) {
    foreach ($rows as $m) {
        $key = (string)$m['rate_key'];
        if ($key === 'is_tiered_water') { $r['is_tiered_water'] = true; continue; }
        if (!array_key_exists($key, $r)) continue;
        $row = $ledgers[$m['ledger']] ?? null;
        $v = is_array($row) ? floatval($row[$m['column_name']] ?? 0) : 0.0;
        if (!empty($m['positive_only']) && $v <= 0) {
            $fb = (string)$m['fallback_column'];
            $v = ($fb !== '' && is_array($row)) ? floatval($row[$fb] ?? 0) : 0.0;
        }
        $r[$key] = $v;
    }
}

// Rates from the map, or null when the combination is not fully in the map
function lumRateMapCalc($elec_tariff, $municipality, $water, $sewer, array $ledgers) {
    $map = lumRateMap();
    if ($map === null) return null;
    $key = lumRateMapMunicipalityKey($municipality, $elec_tariff);
    $sub = in_array($key, LUM_RATE_SUBTARIFF_MUNICIPALITIES, true);
    $elec = trim((string)$elec_tariff);
    $m_rows = $map['municipality'][$key] ?? null;
    $e_rows = ($elec === '') ? [] : ($map['electricity'][lumLower($elec)][(string)$municipality] ?? null);
    $w_rows = (!$sub || trim($water) === '') ? [] : ($map['water'][lumLower(trim($water))][$key] ?? null);
    $s_rows = (!$sub || trim($sewer) === '') ? [] : ($map['sewer'][lumLower(trim($sewer))][$key] ?? null);
    if ($m_rows === null || $e_rows === null || $w_rows === null || $s_rows === null) return null;
    $r = lumRateDefaults();
    lumRateMapApply($r, $m_rows, $ledgers);
    lumRateMapApply($r, $w_rows, $ledgers);
    lumRateMapApply($r, $s_rows, $ledgers);
    lumRateMapApply($r, $e_rows, $ledgers);
    return $r;
}

// The rates of a tariff for a month: from the rate map, otherwise the old tariff-name rules.
// Pass the water and sewer tariff names (otherwise the global $water_tariff / $sewer_tariff are used).
function getTariffRates($elec_tariff, $season, $db_tshwane, $db_ekur, $municipality, $db_rust = null, $db_mkhondo = null, $db_plett = null, $db_george = null, $water_tariff_name = null, $sewer_tariff_name = null) {
    global $water_tariff, $sewer_tariff;
    $wt = ($water_tariff_name !== null) ? (string)$water_tariff_name : (is_string($water_tariff) ? $water_tariff : '');
    $st = ($sewer_tariff_name !== null) ? (string)$sewer_tariff_name : (is_string($sewer_tariff) ? $sewer_tariff : '');
    $ledgers = [
        'lum_tariffs_city_of_tshwane' => $db_tshwane, 'lum_tarrifs_ekhurhuleni' => $db_ekur, 'lum_tariffs_rustenburg' => $db_rust,
        'lum_tariffs_mkhondo' => $db_mkhondo, 'lum_tariffs_plett' => $db_plett, 'lum_tariffs_george' => $db_george,
    ];
    $mapped = lumRateMapCalc($elec_tariff, $municipality, $wt, $st, $ledgers);
    if ($mapped !== null) return $mapped;
    return getTariffRatesLegacy($elec_tariff, $season, $db_tshwane, $db_ekur, $municipality, $db_rust, $db_mkhondo, $db_plett, $db_george, $wt, $st);
}

// ---- Working the map out from the old rules ----

// Columns of the six ledger tables: [table => [column, ...]]
function lumRateLedgerColumns() {
    static $cols = null;
    if ($cols !== null) return $cols;
    $cols = [];
    $pdo = lumSysDb('sys_db_tariffs');
    if (!$pdo) return $cols;
    try {
        $in = implode(',', array_fill(0, count(LUM_RATE_LEDGERS), '?'));
        $st = $pdo->prepare("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = 'sys_db_tariffs' AND TABLE_NAME IN ($in) ORDER BY TABLE_NAME, ORDINAL_POSITION");
        $st->execute(array_keys(LUM_RATE_LEDGERS));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) $cols[$c['TABLE_NAME']][] = $c['COLUMN_NAME'];
    } catch (\Throwable $e) {
        error_log('LUM rate map: ledger columns not read: ' . $e->getMessage());
    }
    return $cols;
}

// Test ledger rows where every column holds its own number: [ledgers, number => [table, column]]
function lumRateProbeLedgers() {
    $ledgers = [];
    $decode = [];
    $t = 0;
    $cols = lumRateLedgerColumns();
    foreach (array_keys(LUM_RATE_LEDGERS) as $table) {
        $t++;
        $ledgers[$table] = [];
        foreach (($cols[$table] ?? []) as $i => $col) {
            $v = $t * 100000 + $i + 1;
            $ledgers[$table][$col] = $v;
            $decode[$v] = [$table, $col];
        }
    }
    return [$ledgers, $decode];
}

function lumRateProbeCall($elec, $muni, $water, $sewer, array $L) {
    return getTariffRatesLegacy($elec, 'High', $L['lum_tariffs_city_of_tshwane'] ?? [], $L['lum_tarrifs_ekhurhuleni'] ?? [], $muni,
                                !empty($L['lum_tariffs_rustenburg']) ? $L['lum_tariffs_rustenburg'] : null,
                                !empty($L['lum_tariffs_mkhondo']) ? $L['lum_tariffs_mkhondo'] : null,
                                $L['lum_tariffs_plett'] ?? [], $L['lum_tariffs_george'] ?? [], (string)$water, (string)$sewer);
}

// Which ledger column each rate key reads for a combination under the old rules: [rate_key => mapping]
function lumRateProbe($elec, $muni, $water, $sewer, array $keys, array $L, array $decode, array &$errors) {
    $base = lumRateProbeCall($elec, $muni, $water, $sewer, $L);
    $rows = [];
    $label = trim($elec . ' ' . $water . ' ' . $sewer) . ' @ ' . $muni;
    foreach ($keys as $k) {
        if ($k === 'is_tiered_water') continue;
        $v = (float)($base[$k] ?? 0);
        if ($v == 0) continue;
        if ((float)(int)$v !== $v || !isset($decode[(int)$v])) { $errors[] = "$label: $k is not read from a single ledger column"; continue; }
        list($table, $col) = $decode[(int)$v];
        $L0 = $L;
        $L0[$table][$col] = 0;
        $v0 = (float)(lumRateProbeCall($elec, $muni, $water, $sewer, $L0)[$k] ?? 0);
        $positive_only = 0;
        $fallback = '';
        if ($v0 != 0) {
            if ((float)(int)$v0 !== $v0 || !isset($decode[(int)$v0]) || $decode[(int)$v0][0] !== $table) { $errors[] = "$label: $k has an unusual fallback"; continue; }
            $positive_only = 1;
            $fallback = $decode[(int)$v0][1];
        } else {
            $Ln = $L;
            $Ln[$table][$col] = -7;
            $vn = (float)(lumRateProbeCall($elec, $muni, $water, $sewer, $Ln)[$k] ?? 0);
            if ($vn == 0) $positive_only = 1;
            elseif ($vn != -7) { $errors[] = "$label: $k changes a negative value unexpectedly"; continue; }
        }
        $rows[$k] = ['ledger' => $table, 'column_name' => $col, 'fallback_column' => $fallback, 'positive_only' => $positive_only];
    }
    if (in_array('is_tiered_water', $keys, true) && !empty($base['is_tiered_water'])) {
        $rows['is_tiered_water'] = ['ledger' => '', 'column_name' => '', 'fallback_column' => '', 'positive_only' => 0];
    }
    return $rows;
}

// Map entries for tariff combinations (['elec', 'muni', 'water', 'sewer']), worked out from the old rules:
// [ "type|lower name|municipality" => ['type', 'name', 'municipality', 'rows' => [rate_key => mapping]] ]
function lumRateMapBuild(array $combos, array &$errors) {
    list($L, $decode) = lumRateProbeLedgers();
    $out = [];
    if (empty($decode)) { $errors[] = 'The tariff ledger columns could not be read.'; return $out; }
    $m_keys = array_merge(LUM_RATE_KEYS_MUNICIPALITY, LUM_RATE_KEYS_WATER, LUM_RATE_KEYS_SEWER, ['is_tiered_water']);
    foreach (LUM_RATE_LEDGERS as $table => $muni) {
        $out["municipality||$muni"] = ['type' => 'municipality', 'name' => '', 'municipality' => $muni,
                                      'rows' => lumRateProbe('', $muni, '', '', $m_keys, $L, $decode, $errors)];
    }
    foreach ($combos as $c) {
        $elec = trim((string)$c['elec']);
        $muni = (string)$c['muni'];
        if ($elec !== '') {
            $k = 'electricity|' . lumLower($elec) . '|' . $muni;
            if (!isset($out[$k])) {
                $out[$k] = ['type' => 'electricity', 'name' => $elec, 'municipality' => $muni,
                            'rows' => lumRateProbe($elec, $muni, '', '', LUM_RATE_KEYS_ELEC, $L, $decode, $errors)];
            }
        }
        $mk = lumRateMapMunicipalityKey($muni, $elec);
        if (!in_array($mk, LUM_RATE_SUBTARIFF_MUNICIPALITIES, true)) continue;
        $muni_tiered = !empty($out["municipality||$mk"]['rows']['is_tiered_water']);
        $water = trim((string)$c['water']);
        if ($water !== '') {
            $k = 'water|' . lumLower($water) . '|' . $mk;
            if (!isset($out[$k])) {
                $rows = lumRateProbe('', $mk, $water, '', array_merge(LUM_RATE_KEYS_WATER, ['is_tiered_water']), $L, $decode, $errors);
                if ($muni_tiered) unset($rows['is_tiered_water']);
                $out[$k] = ['type' => 'water', 'name' => $water, 'municipality' => $mk, 'rows' => $rows];
            }
        }
        $sewer = trim((string)$c['sewer']);
        if ($sewer !== '') {
            $k = 'sewer|' . lumLower($sewer) . '|' . $mk;
            if (!isset($out[$k])) {
                $out[$k] = ['type' => 'sewer', 'name' => $sewer, 'municipality' => $mk,
                            'rows' => lumRateProbe('', $mk, '', $sewer, LUM_RATE_KEYS_SEWER, $L, $decode, $errors)];
            }
        }
    }
    return $out;
}

// Compare the map with the old rules for combinations and ledger rows.
// Returns ['checked' => n, 'not_mapped' => n, 'differences' => [[combo, key, old, map], ...]]
function lumRateMapCompare(array $combos, array $L) {
    $result = ['checked' => 0, 'not_mapped' => 0, 'differences' => []];
    foreach ($combos as $c) {
        $map = lumRateMapCalc($c['elec'], $c['muni'], (string)$c['water'], (string)$c['sewer'], $L);
        if ($map === null) { $result['not_mapped']++; continue; }
        $old = lumRateProbeCall($c['elec'], $c['muni'], $c['water'], $c['sewer'], $L);
        $result['checked']++;
        foreach ($old as $k => $v) {
            $mv = $map[$k] ?? null;
            if ($k === 'is_tiered_water' ? ((bool)$v !== (bool)$mv) : (abs((float)$v - (float)$mv) > 0.0000001)) {
                $result['differences'][] = [$c, $k, $v, $mv];
            }
        }
    }
    return $result;
}

// Rates with every value at zero
function lumRateDefaults() {
    return [
        'basic' => 0, 'kwh_std' => 0, 'kwh_peak' => 0, 'kwh_off' => 0, 
        'kva_demand' => 0, 'kva_network' => 0, 'amps' => 0,
        'generator' => 0, 'water' => 0, 'sewer' => 0, 
        'comm_elec_kwh' => 0, 'comm_water_kl' => 0, 'comm_sewer_kl' => 0,
        'water_basic' => 0, 'sewer_basic' => 0,
        'water_tier_1' => 0, 'water_tier_2' => 0, 'water_tier_3' => 0, 'water_tier_4' => 0,
        'water_tier_5' => 0, 'water_tier_6' => 0, 'water_tier_7' => 0,
        'rustenburg_shared_network_access_charge' => 0,
        'is_tiered_water' => false
    ];
}

// The rates of a tariff by the OLD tariff-name rules (used for combinations not in the rate map,
// and to fill the rate map). $water_tariff_name / $sewer_tariff_name: null = the global $water_tariff / $sewer_tariff.
function getTariffRatesLegacy($elec_tariff, $season, $db_tshwane, $db_ekur, $municipality, $db_rust = null, $db_mkhondo = null, $db_plett = null, $db_george = null, $water_tariff_name = null, $sewer_tariff_name = null) {
    global $water_tariff, $sewer_tariff;
    $water_src = ($water_tariff_name !== null) ? $water_tariff_name : $water_tariff;
    $sewer_src = ($sewer_tariff_name !== null) ? $sewer_tariff_name : $sewer_tariff;

    $r = lumRateDefaults();

    // =========================================================================
    // BLOCK 1: WATER, SEWER, COMMON AREA & GENERATOR ASSIGNMENT
    // =========================================================================
    if ($municipality === 'Ekhurhuleni' or $municipality === 'Ekurhuleni') {
        $r['water'] = floatval($db_ekur['ekhurhuleni_charge_tariff_water'] ?? 0);
        $r['sewer'] = floatval($db_ekur['ekhurhuleni_charge_tariff_water_sewer'] ?? 0);
        $r['comm_water_kl'] = floatval($db_ekur['ekhurhuleni_charge_comn_area_water'] ?? 0);
        $r['comm_sewer_kl'] = floatval($db_ekur['ekhurhuleni_charge_tariff_water_sewer'] ?? 0); 
        $r['comm_elec_kwh'] = floatval($db_ekur['ekhurhuleni_charge_comn_area_elec'] ?? 0);
        $r['generator'] = floatval($db_ekur['ekhurhuleni_charge_generator'] ?? 0);
    } elseif ($municipality === 'Rustenburg') {
        $r['is_tiered_water'] = true;
        $r['water_basic'] = floatval($db_rust['rustenburg_water_commercial_tiered_basic_charge'] ?? 0);
        $r['sewer_basic'] = floatval($db_rust['rustenburg_water_sewer_basic_charge'] ?? 0);
        $r['water_tier_1'] = floatval($db_rust['rustenburg_water_commercial_tiered_0_60_charge'] ?? 0);
        $r['water_tier_2'] = floatval($db_rust['rustenburg_water_commercial_tiered_61_100_charge'] ?? 0);
        $r['water_tier_3'] = floatval($db_rust['rustenburg_water_commercial_tiered_101_150_charge'] ?? 0);
        $r['water_tier_4'] = floatval($db_rust['rustenburg_water_commercial_tiered_151_plus_charge'] ?? 0);
        $r['comm_water_kl'] = floatval($db_rust['rustenburg_comm_area_water'] ?? 0);
        $r['comm_sewer_kl'] = floatval($db_rust['rustenburg_water_sewer_basic_charge'] ?? 0); 
        $r['comm_elec_kwh'] = floatval($db_rust['rustenburg_comm_area_electrical'] ?? 0);
        $r['generator'] = floatval($db_rust['rustenburg_generator_charge'] ?? 0);
        $r['rustenburg_shared_network_access_charge'] = floatval($db_rust['rustenburg_shared_network_access_charge'] ?? 0);
    } elseif ($municipality === 'Mkhondo') {
        $r['is_tiered_water'] = true;
        $r['water_basic'] = floatval($db_mkhondo['mkhondo_water_business_basic'] ?? 0);
        $r['sewer_basic'] = floatval($db_mkhondo['mkhondo_sewer_basic_mkhondo'] ?? 0);
        $r['water_tier_1'] = floatval($db_mkhondo['mkhondo_water_tier_1_0_to_6'] ?? 0);
        $r['water_tier_2'] = floatval($db_mkhondo['mkhondo_water_tier_2_7_to_20'] ?? 0);
        $r['water_tier_3'] = floatval($db_mkhondo['mkhondo_water_tier_3_21_to_40'] ?? 0);
        $r['water_tier_4'] = floatval($db_mkhondo['mkhondo_water_tier_4_41_to_60'] ?? 0);
        // Above 60 kL; months without this rate use the 41-60 kL rate, so no water is ever billed at R 0
        $r['water_tier_5'] = floatval($db_mkhondo['mkhondo_water_tier_5_above_60'] ?? 0);
        if ($r['water_tier_5'] <= 0) $r['water_tier_5'] = $r['water_tier_4'];
        $r['comm_water_kl'] = floatval($db_mkhondo['mkhondo_comm_water_contribution'] ?? 0);
        $r['comm_elec_kwh'] = floatval($db_mkhondo['mkhondo_comm_gen_electricity'] ?? 0);
        $r['generator'] = floatval($db_mkhondo['mkhondo_elec_generator_rate'] ?? 0);
    } elseif (stripos($municipality, 'Plett') !== false || stripos($municipality, 'Plettenberg') !== false) {
        $r['is_tiered_water'] = true;
        $r['generator'] = floatval($db_plett['plett_generator_rate'] ?? 0);
        $r['comm_water_kl'] = floatval($db_plett['plett_comm_area_water'] ?? 0);
        $r['comm_sewer_kl'] = 0; 
        $r['comm_elec_kwh'] = floatval($db_plett['plett_comm_area_electrical'] ?? 0);

        $wt = is_string($water_src) ? $water_src : '';
        $st = is_string($sewer_src) ? $sewer_src : '';

        if (stripos($wt, 'Shops') !== false) {
            $r['water_basic'] = floatval($db_plett['bitou_water_shops_basic'] ?? 0);
            $r['water_tier_1'] = floatval($db_plett['bitou_water_shops_tier_1_0_60'] ?? 0);
            $r['water_tier_2'] = floatval($db_plett['bitou_water_shops_tier_2_60_100'] ?? 0);
            $r['water_tier_3'] = floatval($db_plett['bitou_water_shops_tier_3_100_200'] ?? 0);
            $r['water_tier_4'] = floatval($db_plett['bitou_water_shops_tier_4_above_200'] ?? 0);
        } elseif (stripos($wt, 'Business') !== false) {
            $r['water_basic'] = floatval($db_plett['bitou_water_buss_basic'] ?? 0);
            $r['water_tier_1'] = floatval($db_plett['bitou_water_buss_tier_1_0_60'] ?? 0);
            $r['water_tier_2'] = floatval($db_plett['bitou_water_buss_tier_2_60_100'] ?? 0);
            $r['water_tier_3'] = floatval($db_plett['bitou_water_buss_tier_3_100_200'] ?? 0);
            $r['water_tier_4'] = floatval($db_plett['bitou_water_buss_tier_4_above_200'] ?? 0);
        } elseif (stripos($wt, 'Restaurant') !== false) {
            $r['water_basic'] = floatval($db_plett['bitou_water_rest_basic'] ?? 0);
            $r['water_tier_1'] = floatval($db_plett['bitou_water_rest_tier_1_0_60'] ?? 0);
            $r['water_tier_2'] = floatval($db_plett['bitou_water_rest_tier_2_60_100'] ?? 0);
            $r['water_tier_3'] = floatval($db_plett['bitou_water_rest_tier_3_100_200'] ?? 0);
            $r['water_tier_4'] = floatval($db_plett['bitou_water_rest_tier_4_above_200'] ?? 0);
        }

        if (stripos($st, 'Business') !== false) {
            $r['sewer_basic'] = floatval($db_plett['bitou_sewer_bus_basic'] ?? 0);
        } elseif (stripos($st, 'Restaurant') !== false) {
            $r['sewer_basic'] = floatval($db_plett['bitou_sewer_rest_basic'] ?? 0);
        }
    } elseif (stripos($municipality, 'George') !== false || stripos($elec_tariff, 'George') !== false) {
        $r['generator'] = floatval($db_george['george_generator_rate'] ?? 0);
        $r['comm_water_kl'] = floatval($db_george['george_comm_area_water'] ?? 0);
        $r['comm_elec_kwh'] = floatval($db_george['george_comm_area_electrical'] ?? 0);
        
        $wt = is_string($water_src) ? $water_src : '';
        $st = is_string($sewer_src) ? $sewer_src : '';

        if (stripos($wt, 'Industries') !== false || stripos($wt, 'Businesses') !== false) {
            $r['is_tiered_water'] = true;
            $r['water_basic'] = floatval($db_george['george_water_ind_basic'] ?? 0);
            $r['water_tier_1'] = floatval($db_george['george_water_ind_tier_1_0_6'] ?? 0);
            $r['water_tier_2'] = floatval($db_george['george_water_ind_tier_2_6_15'] ?? 0);
            $r['water_tier_3'] = floatval($db_george['george_water_ind_tier_3_15_20'] ?? 0);
            $r['water_tier_4'] = floatval($db_george['george_water_ind_tier_4_20_30'] ?? 0);
            $r['water_tier_5'] = floatval($db_george['george_water_ind_tier_5_30_50'] ?? 0);
            $r['water_tier_6'] = floatval($db_george['george_water_ind_tier_6_50_75'] ?? 0);
            $r['water_tier_7'] = floatval($db_george['george_water_ind_tier_7_above_75'] ?? 0);
        }
        if (stripos($st, 'Sewer Basic Fee') !== false) {
            $r['sewer_basic'] = floatval($db_george['george_sewer_basic'] ?? 0);
        }
    } else {
        $r['water'] = floatval($db_tshwane['tshwane_water_non_domestic'] ?? 0);
        $r['sewer'] = floatval($db_tshwane['tshwane_water_sewer'] ?? 0);
        $r['comm_water_kl'] = floatval($db_tshwane['tshwane_comm_area_water'] ?? 0);
        $r['comm_sewer_kl'] = floatval($db_tshwane['tshwane_water_sewer'] ?? 0); 
        $r['comm_elec_kwh'] = floatval($db_tshwane['tshwane_comm_area_electrical'] ?? 0);
        $r['generator'] = floatval($db_tshwane['tshwane_generator'] ?? 0);
    }

    // =========================================================================
    // BLOCK 2: ELECTRICITY TARIFF ASSIGNMENT
    // =========================================================================
    if (stripos($elec_tariff, 'George') !== false || stripos($municipality, 'George') !== false) {
        if (stripos($elec_tariff, 'General Consumers') !== false || stripos($elec_tariff, 'General') !== false) {
            $r['basic'] = floatval($db_george['george_elec_general_basic'] ?? 0);
            $r['kwh_std'] = floatval($db_george['george_elec_general_kwh'] ?? 0);
            $r['amps'] = floatval($db_george['george_elec_general_amps'] ?? 0);
        } elseif (stripos($elec_tariff, 'Bulk TOU') !== false || stripos($elec_tariff, 'TOU') !== false) {
            $r['basic'] = floatval($db_george['george_elec_bulk_tou_basic'] ?? 0);
            $r['kwh_peak'] = floatval($db_george['george_elec_bulk_tou_peak'] ?? 0);
            $r['kwh_std'] = floatval($db_george['george_elec_bulk_tou_standard'] ?? 0);
            $r['kwh_off'] = floatval($db_george['george_elec_bulk_tou_offpeak'] ?? 0);
            $r['kva_demand'] = floatval($db_george['george_elec_bulk_tou_demand_kva'] ?? 0);
            $r['kva_network'] = floatval($db_george['george_elec_bulk_tou_access_kva'] ?? 0);
        }
    } elseif (stripos($elec_tariff, 'Plettenberg Bay') !== false || stripos($elec_tariff, 'Plett') !== false || stripos($municipality, 'Plett') !== false || stripos($municipality, 'Plettenberg') !== false) {
        if (stripos($elec_tariff, '1 Phase 15A') !== false) {
            $r['basic'] = floatval($db_plett['plett_1ph_15a_basic'] ?? 0);
            $r['kwh_std'] = floatval($db_plett['plett_1ph_15a_kwh'] ?? 0);
        } elseif (stripos($elec_tariff, '1 Phase 30A') !== false) {
            $r['basic'] = floatval($db_plett['plett_1ph_30a_basic'] ?? 0);
            $r['kwh_std'] = floatval($db_plett['plett_1ph_30a_kwh'] ?? 0);
        } elseif (stripos($elec_tariff, '1 Phase 40A') !== false) {
            $r['basic'] = floatval($db_plett['plett_1ph_40a_basic'] ?? 0);
            $r['kwh_std'] = floatval($db_plett['plett_1ph_40a_kwh'] ?? 0);
        } elseif (stripos($elec_tariff, '1 Phase 60A') !== false) {
            $r['basic'] = floatval($db_plett['plett_1ph_60a_basic'] ?? 0);
            $r['kwh_std'] = floatval($db_plett['plett_1ph_60a_kwh'] ?? 0);
        } elseif (stripos($elec_tariff, '3 Phase 60A-63A') !== false) {
            $r['basic'] = floatval($db_plett['plett_3ph_60a_63a_basic'] ?? 0);
            $r['kwh_std'] = floatval($db_plett['plett_3ph_60a_63a_kwh'] ?? 0);
        } elseif (stripos($elec_tariff, '3 Phase 60A') !== false) {
            $r['basic'] = floatval($db_plett['plett_3ph_60a_basic'] ?? 0);
            $r['kwh_std'] = floatval($db_plett['plett_3ph_60a_kwh'] ?? 0);
        } elseif (stripos($elec_tariff, '3 Phase 100A') !== false) {
            $r['basic'] = floatval($db_plett['plett_3ph_100a_basic'] ?? 0);
            $r['kwh_std'] = floatval($db_plett['plett_3ph_100a_kwh'] ?? 0);
        } elseif (stripos($elec_tariff, 'LV Electricity') !== false || stripos($elec_tariff, 'LV') !== false) {
            $r['basic'] = floatval($db_plett['plett_lv_basic'] ?? 0);
            $r['kwh_std'] = floatval($db_plett['plett_lv_kwh'] ?? 0);
            $r['kva_demand'] = floatval($db_plett['plett_lv_demand_kva'] ?? 0);
            $r['kva_network'] = floatval($db_plett['plett_lv_access_kva'] ?? 0);
        } elseif (stripos($elec_tariff, 'Bitou TOU') !== false) {
            $r['basic'] = floatval($db_plett['plett_bitou_tou_basic'] ?? 0);
            $r['kwh_peak'] = floatval($db_plett['plett_bitou_tou_peak'] ?? 0);
            $r['kwh_std'] = floatval($db_plett['plett_bitou_tou_standard'] ?? 0);
            $r['kwh_off'] = floatval($db_plett['plett_bitou_tou_offpeak'] ?? 0);
            $r['kva_demand'] = floatval($db_plett['plett_bitou_tou_demand_kva'] ?? 0);
            $r['kva_network'] = floatval($db_plett['plett_bitou_tou_access_kva'] ?? 0);
        }
    } elseif (stripos($elec_tariff, 'Tshwane') !== false || $municipality === 'Tshwane' || stripos($elec_tariff, 'Low Voltage Demand') !== false || stripos($elec_tariff, 'Prepaid TOU') !== false) {
        
        if (stripos($elec_tariff, 'Prepaid TOU') !== false || stripos($elec_tariff, 'Pre paid') !== false) {
            $r['basic'] = floatval($db_tshwane['tshwane_prepaid_tou_charge_basic'] ?? 0);
            $r['kva_demand'] = floatval($db_tshwane['tshwane_prepaid_tou_charge_kva'] ?? 0);
            $r['kwh_peak'] = floatval($db_tshwane['tshwane_prepaid_tou_charge_peak'] ?? 0);
            $r['kwh_std'] = floatval($db_tshwane['tshwane_prepaid_tou_charge_standard'] ?? 0);
            $r['kwh_off'] = floatval($db_tshwane['tshwane_prepaid_tou_charge_offpeak'] ?? 0);
        } elseif (stripos($elec_tariff, 'TOU') !== false) {
            $r['basic'] = floatval($db_tshwane['tshwane_tou_charge_basic'] ?? 0);
            $r['kva_demand'] = floatval($db_tshwane['tshwane_tou_charge_kva'] ?? 0);
            $r['kwh_peak'] = floatval($db_tshwane['tshwane_tou_charge_peak'] ?? 0);
            $r['kwh_std'] = floatval($db_tshwane['tshwane_tou_charge_standard'] ?? 0);
            $r['kwh_off'] = floatval($db_tshwane['tshwane_tou_charge_offpeak'] ?? 0);
        } elseif (stripos($elec_tariff, 'Low Voltage Demand Scale') !== false || stripos($elec_tariff, 'Low Voltage Demand - Scale') !== false) {
            $basic1 = floatval($db_tshwane['tshwane_lvds_charge_basic'] ?? 0);
            $r['basic'] = ($basic1 > 0) ? $basic1 : 0;
            $kva1 = floatval($db_tshwane['tshwane_lvds_charge_kva'] ?? 0);
            $r['kva_demand'] = ($kva1 > 0) ? $kva1 : 0;
            $std1 = floatval($db_tshwane['tshwane_lvds_charge_unit'] ?? 0);
            $r['kwh_std'] = ($std1 > 0) ? $std1 : 0;
        } elseif (stripos($elec_tariff, 'Low Voltage Demand') !== false) {
            $basic1 = floatval($db_tshwane['tshwane_low_voltage_demand_charge_basic'] ?? 0);
            $r['basic'] = ($basic1 > 0) ? $basic1 : 0;
            $kva1 = floatval($db_tshwane['tshwane_low_voltage_demand_charge_kva'] ?? 0);
            $r['kva_demand'] = ($kva1 > 0) ? $kva1 : 0;
            $std1 = floatval($db_tshwane['tshwane_low_voltage_demand_charge_unit'] ?? 0);
            $r['kwh_std'] = ($std1 > 0) ? $std1 : 0;
        } elseif (stripos($elec_tariff, 'Low Demand Three Phase') !== false || stripos($elec_tariff, 'Three Phase Low Demand') !== false) {
            $basic1 = floatval($db_tshwane['tshwane_low_voltage_demand_charge_basic'] ?? 0);
            $r['basic'] = ($basic1 > 0) ? $basic1 : 0;
            $kva1 = floatval($db_tshwane['tshwane_low_voltage_demand_charge_kva'] ?? 0);
            $r['kva_demand'] = ($kva1 > 0) ? $kva1 : 0;
            $std1 = floatval($db_tshwane['tshwane_low_demand_three_phase_energy'] ?? 0);
            $std2 = floatval($db_tshwane['tshwane_non_domestic_three_phase'] ?? 0);
            $r['kwh_std'] = ($std1 > 0) ? $std1 : $std2;
        } elseif (stripos($elec_tariff, 'Business L') !== false) {
            $r['basic'] = floatval($db_tshwane['tshwane_business_L_charge_basic'] ?? 0);
            $r['kwh_std'] = floatval($db_tshwane['tshwane_business_L_charge_unit'] ?? 0);
        } elseif (stripos($elec_tariff, 'Business S') !== false) {
            $r['basic'] = floatval($db_tshwane['tshwane_business_S_charge_basic'] ?? 0);
            $r['kwh_std'] = floatval($db_tshwane['tshwane_business_S_charge_unit'] ?? 0);
        } elseif (stripos($elec_tariff, 'Three Phase Conventional') !== false) {
            $r['basic'] = floatval($db_tshwane['tshwane_non_domestic_three_phase_basic'] ?? 0);
            $r['kwh_std'] = floatval($db_tshwane['tshwane_non_domestic_three_phase'] ?? 0);
        } elseif (stripos($elec_tariff, 'Business') !== false) {
            $r['basic'] = floatval($db_tshwane['tshwane_business_charge_basic'] ?? 0);
            $r['kwh_std'] = floatval($db_tshwane['tshwane_business_charge_unit'] ?? 0);
        }
    } elseif (stripos($elec_tariff, 'Ekhurhuleni') !== false or stripos($elec_tariff, 'Ekurhuleni') !== false) {
        if (stripos($elec_tariff, 'TOU') !== false) {
            $r['basic'] = floatval($db_ekur['ekhurhuleni_charge_tariff_tou_basic'] ?? 0);
            $r['kva_demand'] = floatval($db_ekur['ekhurhuleni_charge_tariff_tou_demand'] ?? 0);
            $r['kva_network'] = floatval($db_ekur['ekhurhuleni_charge_tariff_tou_demand_nac'] ?? 0);
            $r['kwh_peak'] = floatval($db_ekur['ekhurhuleni_charge_tariff_tou_peak'] ?? 0);
            $r['kwh_std'] = floatval($db_ekur['ekhurhuleni_charge_tariff_tou_standard'] ?? 0);
            $r['kwh_off'] = floatval($db_ekur['ekhurhuleni_charge_tariff_tou_offpeak'] ?? 0);
        } elseif (stripos($elec_tariff, 'Tariff A') !== false) {
            $r['basic'] = floatval($db_ekur['ekhurhuleni_charge_tariff_a_basic'] ?? 0);
            $r['kwh_std'] = floatval($db_ekur['ekhurhuleni_charge_tariff_a_unit'] ?? 0);
            $r['amps'] = floatval($db_ekur['ekhurhuleni_charge_tariff_a_capacity'] ?? 0);
        } elseif (stripos($elec_tariff, 'Tariff B') !== false) {
            $r['basic'] = floatval($db_ekur['ekhurhuleni_charge_tariff_b_basic'] ?? 0);
            $r['kwh_std'] = floatval($db_ekur['ekhurhuleni_charge_tariff_b_unit'] ?? 0);
            $r['amps'] = floatval($db_ekur['ekhurhuleni_charge_tariff_b_capacity'] ?? 0);
        } elseif (stripos($elec_tariff, 'Tariff C') !== false) {
            $r['basic'] = floatval($db_ekur['ekhurhuleni_charge_tariff_c_basic'] ?? 0);
            $r['kwh_std'] = floatval($db_ekur['ekhurhuleni_charge_tariff_c_unit'] ?? 0);
            $r['kva_demand'] = floatval($db_ekur['ekhurhuleni_charge_tariff_c_demand'] ?? 0);
            $r['kva_network'] = floatval($db_ekur['ekhurhuleni_charge_tariff_c_nac'] ?? 0);
        }
    } elseif (stripos($elec_tariff, 'Rustenburg') !== false && $db_rust) {
        if (stripos($elec_tariff, 'Non-Domestic Conventional') !== false) {
            $r['basic'] = floatval($db_rust['rustenburg_non_domestic_conventional_basic_charge'] ?? 0);
            $r['kwh_std'] = floatval($db_rust['rustenburg_non_domestic_conventional_unit'] ?? 0);
        } elseif (stripos($elec_tariff, 'Bulk Supply') !== false) {
            $r['basic'] = floatval($db_rust['rustenburg_bulk_supply_and_rural_400V_basic_charge'] ?? 0);
            $r['kwh_std'] = floatval($db_rust['rustenburg_bulk_supply_and_rural_400V_unit'] ?? 0);
            $r['kva_demand'] = floatval($db_rust['rustenburg_all_season_network_demand_charge_bulk_and_rural_400V'] ?? 0);
            $r['kva_network'] = floatval($db_rust['rustenburg_all_season_network_access_charge_bulk_and_rural_400V'] ?? 0);
        }
    } elseif (stripos($elec_tariff, 'Mkhondo') !== false && $db_mkhondo) {
        if (stripos($elec_tariff, 'Business (Less Than 80') !== false) {
            $r['basic'] = floatval($db_mkhondo['mkhondo_elec_business_less_80_basic'] ?? 0);
            $r['kwh_std'] = floatval($db_mkhondo['mkhondo_elec_business_less_80_kwh'] ?? 0);
        } elseif (stripos($elec_tariff, 'Business (More Than 80') !== false) {
            $r['basic'] = floatval($db_mkhondo['mkhondo_elec_business_more_80_basic'] ?? 0);
            $r['kwh_std'] = floatval($db_mkhondo['mkhondo_elec_business_more_80_kwh'] ?? 0);
        } elseif (stripos($elec_tariff, 'Industrial Small') !== false) {
            $r['basic'] = floatval($db_mkhondo['mkhondo_elec_industrial_small_less_50_basic'] ?? 0);
            $r['kwh_std'] = floatval($db_mkhondo['mkhondo_elec_industrial_small_less_50_kwh'] ?? 0);
            $r['kva_demand'] = floatval($db_mkhondo['mkhondo_elec_industrial_small_less_50_kva'] ?? 0);
        } elseif (stripos($elec_tariff, 'Industrial (More than 50') !== false) {
            $r['basic'] = floatval($db_mkhondo['mkhondo_elec_industrial_more_50_basic'] ?? 0);
            $r['kwh_std'] = floatval($db_mkhondo['mkhondo_elec_industrial_more_50_kwh'] ?? 0);
            $r['kva_demand'] = floatval($db_mkhondo['mkhondo_elec_industrial_more_50_kva'] ?? 0);
        }
    }

    return $r;
}

// Band of one reading: 'peak', 'std' or 'off' (TOU periods from lum_tou_algorithms / lum_tou_bands).
// $season is not used: the season comes from the algorithm's settings (calendar months or the tariff ledger).
function get_tou_bucket($timestamp, $algorithm, $holidays, $season = 'High') {
    $key = lumTouResolve($algorithm);
    if ($key === null) return 'std';
    $a = lumTouConfig()[$key];

    $dow = (int)date('N', $timestamp);
    if ($dow <= 5 && $a['holiday_rule'] !== 'weekday' && in_array(date('Y-m-d', $timestamp), $holidays)) {
        $dow = ($a['holiday_rule'] === 'sunday') ? 7 : 6;
    }
    $day = ($dow <= 5) ? 'weekday' : (($dow === 6) ? 'saturday' : 'sunday');
    $band = lumTouSeason($key, (int)date('Y', $timestamp), (int)date('n', $timestamp));
    $c = $a['bands'][$band][$day][(int)date('G', $timestamp)] ?? 'S';
    return ($c === 'P') ? 'peak' : (($c === 'O') ? 'off' : 'std');
}
