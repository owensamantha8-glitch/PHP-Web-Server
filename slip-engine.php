<?php
// Library file: only other pages may include it, it can never be opened directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(403); exit('Forbidden'); }
// Shared consumption slip engine: single slips, bulk slips and the financial report. Load with require_once.
require_once __DIR__ . '/bootstrap.php';

$reporting_engine = lum_resolve_path('reporting-engine.php');
if ($reporting_engine === false) lum_db_fail('Critical Error: reporting engine missing.');
require_once $reporting_engine;

if (!defined('LUM_SLIP_DIR')) {
    define('LUM_SLIP_DIR', LUM_ROOT);
}

// The CT switch-over helpers (lumDetectMeterCtStep, lumRegisterAt, ...) live in reporting-engine.php

// Shared database connection: lum_db() from bootstrap.php, otherwise its own connection from the secure config
function lumDbConn($db_name) {
    if (function_exists('lum_db')) return lum_db($db_name);
    static $conns = [];
    if (array_key_exists($db_name, $conns)) return $conns[$db_name];
    $conns[$db_name] = null;

    $config = lum_db_config();
    if ($config === null || empty($config['host']) || !isset($config['username'], $config['password'])) {
        error_log('LUM slip engine: unable to read ' . LUM_DB_CONFIG);
        return null;
    }

    try {
        $pdo = new PDO("mysql:host={$config['host']};dbname={$db_name};charset=utf8mb4", $config['username'], $config['password']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $conns[$db_name] = $pdo;
    } catch (\Throwable $e) {
        error_log("LUM slip engine: could not connect to {$db_name}: " . $e->getMessage());
    }
    return $conns[$db_name];
}

// Property billing settings (sys_db_properties.lum_properties, edited under Billing Settings).
// Without the settings table, the old property-name rules apply (lumPropertyLegacySettings).
if (!defined('LUM_ELEC_COMMON_AREA_METHODS')) {
    define('LUM_ELEC_COMMON_AREA_METHODS', [
        'manual'             => 'Manual total (entered on the slip)',
        'lambton'            => 'Lambton Gardens calculation',
        'thatchfield_centre' => 'Thatchfield Centre calculation',
        'lynnwood'           => 'Lynnwood Lane calculation',
        'greystone'          => 'Greystone Crossing calculation',
        'one_on_york'        => 'One On York calculation',
        'marketsquare'       => 'The Marketsquare calculation',
    ]);
    define('LUM_WATER_COMMON_AREA_METHODS', [
        'manual'             => 'Manual total (entered on the slip)',
        'lambton'            => 'Lambton Gardens calculation',
        'thatchfield_retail' => 'Thatchfield Retail calculation',
        'lynnwood'           => 'Lynnwood Lane calculation',
        'one_on_york'        => 'One On York calculation',
        'lintons_corner'     => "Linton's Corner calculation",
        'marketsquare'       => 'The Marketsquare calculation',
    ]);
}

// The settings the old property-name rules gave a property
function lumPropertyLegacySettings($property_name) {
    $p = (string)$property_name;
    $l = strtolower($p);
    $is_msq = (strpos($l, 'marketsquare') !== false || strpos($l, 'market square') !== false);

    $elec = 'manual';
    if ($p === 'Lambton Gardens') $elec = 'lambton';
    elseif ($p === 'Thatchfield Centre') $elec = 'thatchfield_centre';
    elseif ($p === 'Lynnwood Lane') $elec = 'lynnwood';
    elseif ($p === 'Greystone Crossing') $elec = 'greystone';
    elseif ($p === 'One On York') $elec = 'one_on_york';
    elseif ($is_msq) $elec = 'marketsquare';

    $water = 'manual';
    if ($p === 'Lambton Gardens') $water = 'lambton';
    elseif ($p === 'Thatchfield Retail') $water = 'thatchfield_retail';
    elseif ($p === 'Lynnwood Lane') $water = 'lynnwood';
    elseif ($p === 'One On York') $water = 'one_on_york';
    elseif (strpos($l, 'linton') !== false) $water = 'lintons_corner';
    elseif ($is_msq) $water = 'marketsquare';

    $cycle = '';
    if (stripos($p, 'Linton') !== false) $cycle = '%Linton%';
    elseif (stripos($p, 'Market') !== false) $cycle = '%Market%';
    elseif (stripos($p, 'Thatchfield Retail') !== false) $cycle = '%Thatchfield Retail%';
    elseif (stripos($p, 'Thatchfield Centre') !== false) $cycle = '%Thatchfield Centre%';

    $muni = null;
    if (stripos($p, 'One On York') !== false) $muni = 'George';
    elseif (stripos($p, 'Greystone Crossing') !== false) $muni = 'Rustenburg';
    elseif (stripos($p, 'Lambton') !== false) $muni = 'Ekhurhuleni';
    elseif (stripos($p, 'Thatchfield') !== false || stripos($p, 'Lynnwood') !== false) $muni = 'Tshwane';
    elseif (stripos($p, 'N2 Woodhill') !== false) $muni = 'Mkhondo';
    elseif ($is_msq || stripos($p, 'Plett') !== false) $muni = 'Plettenberg Bay';

    $greystone = (stripos($p, 'Greystone Crossing') !== false);
    return [
        'elec_common_area_method'  => $elec,
        'water_common_area_method' => $water,
        'default_generator_method' => ($p === 'Lambton Gardens') ? 'runtime' : '1.1.1.8.2',
        'generator_offline_meter'  => (stripos($p, 'N2 Woodhill') !== false) ? '32910146' : '',
        'generator_tariff_label'   => $greystone ? 'Greystone Crossing Generator Charge' : '',
        'tiered_water_label'       => $greystone ? 'Greystone Crossing - Commercial Water (Tiered)' : '',
        'forced_elec_tariff'       => (stripos($p, 'Lynnwood Lane') !== false) ? 'City of Tshwane - Prepaid TOU' : '',
        'charges_shared_nac'       => ($p === 'Greystone Crossing'),
        'charges_refuse'           => ($p === 'The Marketsquare'),
        'bills_sewer_common_area'  => !($p === 'The Marketsquare' || stripos($p, 'N2 Woodhill') !== false),
        'billing_cycle_match'      => $cycle,
        'legacy_municipality'      => $muni,   // Only used while the settings are not installed
        'source'                   => 'legacy',
    ];
}

// A property's billing settings (read once per request)
function lumPropertySettings($property_name) {
    static $cache = [];
    $key = (string)$property_name;
    if (isset($cache[$key])) return $cache[$key];

    $settings = lumPropertyLegacySettings($key);
    $pdo = ($key !== '') ? lumDbConn('sys_db_properties') : null;
    if ($pdo) {
        try {
            $st = $pdo->prepare("SELECT * FROM lum_properties WHERE Property = ? LIMIT 1");
            $st->execute([$key]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && array_key_exists('elec_common_area_method', $row)) {
                $text = function ($col) use ($row) { return trim((string)($row[$col] ?? '')); };
                $settings = [
                    'elec_common_area_method'  => isset(LUM_ELEC_COMMON_AREA_METHODS[$text('elec_common_area_method')]) ? $text('elec_common_area_method') : 'manual',
                    'water_common_area_method' => isset(LUM_WATER_COMMON_AREA_METHODS[$text('water_common_area_method')]) ? $text('water_common_area_method') : 'manual',
                    'default_generator_method' => ($text('default_generator_method') === 'runtime') ? 'runtime' : '1.1.1.8.2',
                    'generator_offline_meter'  => $text('generator_offline_meter'),
                    'generator_tariff_label'   => $text('generator_tariff_label'),
                    'tiered_water_label'       => $text('tiered_water_label'),
                    'forced_elec_tariff'       => $text('forced_elec_tariff'),
                    'charges_shared_nac'       => !empty($row['charges_shared_nac']),
                    'charges_refuse'           => !empty($row['charges_refuse']),
                    'bills_sewer_common_area'  => ($row['bills_sewer_common_area'] === null) ? true : !empty($row['bills_sewer_common_area']),
                    'billing_cycle_match'      => $text('billing_cycle_match'),
                    'legacy_municipality'      => null,
                    'source'                   => 'table',
                ];
            }
        } catch (\Throwable $e) {
            error_log('LUM property settings: using the name rules for ' . $key . ': ' . $e->getMessage());
        }
    }
    return $cache[$key] = $settings;
}

// Generator run-time hours: manual_readings_genrun, matched by the months of the run-time dates, otherwise
// the property's offline-hours meter; split between the billing periods by days.
// Returns ['info' => [...], 'hours_1' => float, 'hours_2' => float].
function lumSlipGeneratorRuntime($manual_db_conn, $obis_db_conn, $property_name, $gen_runtime_start, $gen_runtime_end,
                                 $start_date, $end_date, $start_date_2, $end_date_2, $has_period_2) {
    $info = getManualGeneratorRuntimeByMonth($manual_db_conn, $property_name, $gen_runtime_start, $gen_runtime_end);
    $info['source'] = 'manual';
    $total_gen_hours = $info['hours'];

    $offline_meter = lumPropertySettings($property_name)['generator_offline_meter'];
    if ($total_gen_hours === null && $offline_meter !== '') {
        // No manual reading for the month: the property's offline-hours meter gives the run time for the same dates
        $info['source'] = 'offline';
        $total_gen_hours = calculateMeterOfflineHours($obis_db_conn, $offline_meter, $gen_runtime_start, $gen_runtime_end);
    } elseif ($total_gen_hours === null) {
        $info['source'] = 'none';
        $total_gen_hours = 0;
    }

    $hours_1 = 0;
    $hours_2 = 0;
    if ($has_period_2) {
        $d1 = max(1, (new DateTime($start_date))->diff(new DateTime($end_date))->days + 1);
        $d2 = max(1, (new DateTime($start_date_2))->diff(new DateTime($end_date_2))->days + 1);
        $tot_d = $d1 + $d2;
        $hours_1 = round($total_gen_hours * ($d1 / $tot_d), 2);
        $hours_2 = round($total_gen_hours * ($d2 / $tot_d), 2);
    } else {
        $hours_1 = $total_gen_hours;
    }
    return ['info' => $info, 'hours_1' => $hours_1, 'hours_2' => $hours_2];
}

// Financial report <-> consumption slip settings. Changes on a slip opened from the report are carried back:
// report settings update the report; tenant settings (including its own billing cycle) are saved per tenant
// and billing month in sys_db_tenants.lum_tenant_report_settings and applied to that tenant only.
const LUM_REPORT_LEVEL_KEYS = [
    'obis_code', 'comm_start_date', 'comm_end_date', 'water_comm_start_date', 'water_comm_end_date',
    'manual_prop_elec_comm', 'manual_prop_water_comm', 'gen_start_date', 'gen_end_date',
];
// A tenant's own billing cycle (electrical and water periods)
const LUM_TENANT_DATE_KEYS = [
    'start_date', 'end_date', 'start_date_2', 'end_date_2',
    'water_start_date', 'water_end_date', 'water_start_date_2', 'water_end_date_2',
];
const LUM_TENANT_LEVEL_KEYS = [
    'start_date', 'end_date', 'start_date_2', 'end_date_2',
    'water_start_date', 'water_end_date', 'water_start_date_2', 'water_end_date_2',
    'tou_algorithm', 'elec_comm_discount', 'water_comm_discount',
    'use_estimated_readings', 'use_estimate_time_ranges',
    'manual_override_col', 'manual_override_col_t2', 't2_method', 't2_base_obis',
    'remove_elec_comm', 'remove_water_comm',
];
const LUM_TENANT_CHECKBOX_KEYS = ['use_estimated_readings', 'use_estimate_time_ranges', 'remove_elec_comm', 'remove_water_comm'];

// Readable names (slip info box, report badge, journal)
function lumTenantSettingLabel($key) {
    $labels = [
        'start_date' => 'Billing period start', 'end_date' => 'Billing period end',
        'start_date_2' => 'Period 2 start', 'end_date_2' => 'Period 2 end',
        'water_start_date' => 'Water period start', 'water_end_date' => 'Water period end',
        'water_start_date_2' => 'Water period 2 start', 'water_end_date_2' => 'Water period 2 end',
        'tou_algorithm' => 'TOU algorithm', 'elec_comm_discount' => 'Elec common area discount (%)',
        'water_comm_discount' => 'Water common area discount (%)', 'use_estimated_readings' => 'Estimated readings',
        'use_estimate_time_ranges' => 'Estimate time ranges', 'manual_override_col' => 'Manual extract (Grid/T1)',
        'manual_override_col_t2' => 'Manual extract (Gen/T2)', 't2_method' => 'Generator method',
        't2_base_obis' => 'Run-time base OBIS', 'remove_elec_comm' => 'Remove elec common area',
        'remove_water_comm' => 'Remove water common area',
    ];
    return $labels[$key] ?? $key;
}

function lumTenantSettingValue($key, $value) {
    if (in_array($key, LUM_TENANT_DATE_KEYS, true)) {
        return ((string)$value === '') ? 'None' : date('d M Y', strtotime((string)$value));
    }
    if (in_array($key, LUM_TENANT_CHECKBOX_KEYS, true)) return ((string)$value === '1') ? 'On' : 'Off';
    if ($key === 't2_method') return ($value === 'runtime') ? 'Run Time Calculation' : 'OBIS 1.1.1.8.2 Reading';
    if (($key === 'manual_override_col' || $key === 'manual_override_col_t2') && ($value === 'none' || $value === '')) return 'None';
    return (string)$value;
}

// Saved tenant settings for a billing month: [tenant_id => [key => value]]
function lumTenantReportSettingsLoad($tenant_pdo, $tenant_ids, $month, $year) {
    $out = [];
    $tenant_ids = array_values(array_filter(array_map('intval', (array)$tenant_ids)));
    if (!$tenant_pdo || empty($tenant_ids)) return $out;
    try {
        $in = implode(',', array_fill(0, count($tenant_ids), '?'));
        $st = $tenant_pdo->prepare("SELECT tenant_id, settings FROM lum_tenant_report_settings WHERE period_month = ? AND period_year = ? AND tenant_id IN ($in)");
        $st->execute(array_merge([(int)$month, (int)$year], $tenant_ids));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $settings = json_decode((string)$r['settings'], true);
            if (!is_array($settings)) continue;
            $clean = [];
            foreach ($settings as $k => $v) {
                if (!in_array($k, LUM_TENANT_LEVEL_KEYS, true) || !is_scalar($v)) continue;
                if (in_array($k, LUM_TENANT_DATE_KEYS, true) && $v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v)) continue;
                $clean[$k] = (string)$v;
            }
            if ($clean) $out[(int)$r['tenant_id']] = $clean;
        }
    } catch (\Throwable $e) {
        error_log('LUM tenant report settings not available: ' . $e->getMessage());
    }
    return $out;
}

// Save (or clear, when $settings is empty) a tenant's settings for a billing month
function lumTenantReportSettingsSave($tenant_pdo, $tenant_id, $month, $year, $settings) {
    if (!$tenant_pdo) return false;
    try {
        if (empty($settings)) {
            $st = $tenant_pdo->prepare("DELETE FROM lum_tenant_report_settings WHERE tenant_id = ? AND period_month = ? AND period_year = ?");
            $st->execute([(int)$tenant_id, (int)$month, (int)$year]);
            return true;
        }
        ksort($settings);
        $st = $tenant_pdo->prepare("INSERT INTO lum_tenant_report_settings (tenant_id, period_month, period_year, settings, updated_by_user_id, updated_by_name)
                                    VALUES (?, ?, ?, ?, ?, ?)
                                    ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by_user_id = VALUES(updated_by_user_id),
                                                            updated_by_name = VALUES(updated_by_name), updated_at = CURRENT_TIMESTAMP");
        $st->execute([(int)$tenant_id, (int)$month, (int)$year, json_encode($settings, JSON_UNESCAPED_UNICODE),
                      isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null, substr((string)($_SESSION['user_name'] ?? 'system'), 0, 50)]);
        return true;
    } catch (\Throwable $e) {
        error_log('LUM tenant report settings could not be saved: ' . $e->getMessage());
        return false;
    }
}

// How a tenant was billed (recorded in the financial journal). Built by slip-render.php.
function lumSlipBillingSources($elec_results, $has_generator, $manual_col_t1, $manual_col_t2) {
    $meters = [];
    foreach ((array)$elec_results as $res) {
        $elec_sources = [];
        foreach ([$res['rdg1'] ?? null, $res['rdg2'] ?? null] as $rdg) {
            foreach ((array)($rdg['splits'] ?? []) as $sp) {
                $elec_sources[] = $sp['source'] ?? 'meter';
            }
        }
        $gen_source = null;
        $gen_manual_col = null;
        // Generator readings taken from the manual extract (either billing period)
        $gen_manual_used = false;
        foreach (['gen1', 'gen2'] as $gk) {
            if (in_array('manual', array_column((array)($res[$gk]['splits'] ?? []), 'source'), true)) $gen_manual_used = true;
        }
        if ($has_generator) {
            if (!empty($res['gen_runtime'])) {
                $gen_source = 'Run Time Calculation';
            } elseif ($manual_col_t2 !== 'none' && ($res['gen1'] ?? null) !== null && $gen_manual_used) {
                $gen_source = 'Manual extract: ' . $manual_col_t2;
                $gen_manual_col = (string)$manual_col_t2;
            } else {
                $gen_source = 'OBIS 1.1.1.8.2';
                foreach ((array)($res['gen1']['splits'] ?? []) as $sp) {
                    if (($sp['source'] ?? '') === 'estimate') { $gen_source = 'OBIS 1.1.1.8.2 (estimated)'; break; }
                }
            }
        }
        $elec_sources = array_values(array_unique($elec_sources));
        $elec_manual_used = (in_array('manual', $elec_sources, true) && $manual_col_t1 !== 'none');
        $elec_label = $elec_manual_used ? 'Manual extract: ' . $manual_col_t1
                    : (in_array('estimate', $elec_sources, true) ? 'Estimated' : 'Meter');
        $meters[] = [
            'serial'     => (string)($res['id'] ?? ''),
            'ct_setting' => (float)($res['ct_setting'] ?? 1),
            'applied_ct' => (float)($res['applied_ct'] ?? 1),
            'kwh'        => round((float)($res['total_kwh'] ?? 0), 3),
            'elec_source'=> $elec_label,
            'gen_kwh'    => round((float)($res['total_gen'] ?? 0), 3),
            'gen_source' => $gen_source,
            // Manual readings column actually used for this meter (null = not used)
            'elec_manual_col' => $elec_manual_used ? (string)$manual_col_t1 : null,
            'gen_manual_col'  => $gen_manual_col,
        ];
    }
    return $meters;
}

// Meter change-over with a Period 2: a meter repeated in Period 2 with the same opening and closing readings
// is the same consumption counted twice, so the repeat is removed from Period 2. Only applies when the
// periods hold more than one serial; covers T1 ($rdg1/$rdg2) and T2 ($rdg_gen1/$rdg_gen2).
function lumSlipSplitKey($split) {
    return trim((string)($split['serial'] ?? '')) . '|'
         . number_format(round((float)($split['open'] ?? 0), 2), 2, '.', '') . '|'
         . number_format(round((float)($split['close'] ?? 0), 2), 2, '.', '');
}

function lumSlipDropRepeatedSplits($rdg_p1, $rdg_p2) {
    if (!is_array($rdg_p1) || !is_array($rdg_p2) || empty($rdg_p1['splits']) || empty($rdg_p2['splits'])) return $rdg_p2;

    // Only for meter change-overs
    $serials = [];
    foreach (array_merge((array)$rdg_p1['splits'], (array)$rdg_p2['splits']) as $sp) {
        $serials[trim((string)($sp['serial'] ?? ''))] = true;
    }
    if (count($serials) < 2) return $rdg_p2;

    $p1_keys = [];
    foreach ((array)$rdg_p1['splits'] as $sp) {
        $p1_keys[lumSlipSplitKey($sp)] = true;
    }

    $kept = [];
    $dropped = [];
    foreach ((array)$rdg_p2['splits'] as $sp) {
        if (isset($p1_keys[lumSlipSplitKey($sp)])) {
            $dropped[] = $sp;
        } else {
            $kept[] = $sp;
        }
    }
    if (empty($dropped)) return $rdg_p2;

    // Take the repeated consumption out of the Period 2 totals
    foreach ($dropped as $sp) {
        foreach (['kwh', 'peak', 'std', 'off'] as $k) {
            if (isset($rdg_p2[$k])) {
                $rdg_p2[$k] = max(0, round((float)$rdg_p2[$k] - (float)($sp[$k] ?? 0), 6));
            }
        }
        if (!empty($sp['daily']) && !empty($rdg_p2['daily'])) {
            foreach ($sp['daily'] as $d => $v) {
                if (!isset($rdg_p2['daily'][$d])) continue;
                foreach (['total', 'peak', 'std', 'off'] as $k) {
                    $rdg_p2['daily'][$d][$k] = max(0, round((float)($rdg_p2['daily'][$d][$k] ?? 0) - (float)($v[$k] ?? 0), 6));
                }
                if ($rdg_p2['daily'][$d]['total'] <= 0) unset($rdg_p2['daily'][$d]);
            }
        }
    }

    // Opening, closing and kVA from the Period 2 readings that remain
    $rdg_p2['splits'] = $kept;
    if (!empty($kept)) {
        $rdg_p2['open'] = $kept[0]['open'];
        $rdg_p2['close'] = $kept[count($kept) - 1]['close'];
        if (isset($rdg_p2['kva'])) {
            $rdg_p2['kva'] = max(array_map(function ($sp) { return (float)($sp['kva'] ?? 0); }, $kept));
        }
    } else {
        $rdg_p2['open'] = 0;
        $rdg_p2['close'] = 0;
        if (isset($rdg_p2['kva'])) $rdg_p2['kva'] = 0;
    }
    return $rdg_p2;
}

// Property electrical common area total (kWh) - one rule for every page
function lumSlipElecCommonArea($property_name, $start_date, $end_date, $obis_code, $obis_db_conn, $tenant_db_conn, $manual_value = 0) {
    // Calculation chosen in the property's billing settings
    switch (lumPropertySettings($property_name)['elec_common_area_method']) {
        case 'lambton':            return calculateLambtonCommonArea($start_date, $end_date, $obis_code, $obis_db_conn, $tenant_db_conn);
        case 'thatchfield_centre': return calculateThatchfieldCommonArea($start_date, $end_date, $obis_code, $obis_db_conn);
        case 'lynnwood':           return calculateLynnwoodCommonArea($start_date, $end_date, $obis_code, $obis_db_conn);
        case 'greystone':          return calculateGreystoneCommonArea($start_date, $end_date, $obis_code, $obis_db_conn);
        case 'one_on_york':        return calculateOneOnYorkCommonArea($start_date, $end_date, $obis_code, $obis_db_conn, $tenant_db_conn);
        case 'marketsquare':       return calculateMarketsquareCommonArea($start_date, $end_date, $obis_code, $obis_db_conn, $tenant_db_conn);
    }
    return floatval($manual_value);
}

// Property water common area total (kL) - one rule for every page
function lumSlipWaterCommonArea($property_name, $start_date, $end_date, $obis_db_conn, $manual_db_conn, $tenant_db_conn, $manual_value = 0) {
    // Calculation chosen in the property's billing settings
    switch (lumPropertySettings($property_name)['water_common_area_method']) {
        case 'lambton':            return calculateLambtonWaterCommonArea($start_date, $end_date, $obis_db_conn, $manual_db_conn);
        case 'thatchfield_retail': return calculateThatchfieldWaterCommonArea($start_date, $end_date, $obis_db_conn, $manual_db_conn);
        case 'lynnwood':           return calculateLynnwoodLaneWaterCommonArea($start_date, $end_date, $obis_db_conn, $manual_db_conn, $tenant_db_conn);
        case 'one_on_york':        return calculateOneOnYorkWaterCommonArea($start_date, $end_date, $obis_db_conn, $manual_db_conn, $tenant_db_conn);
        case 'lintons_corner':     return calculateLintonsCornerWaterCommonArea($start_date, $end_date, $obis_db_conn, $manual_db_conn, $tenant_db_conn);
        case 'marketsquare':       return calculateMarketsquareWaterCommonArea($start_date, $end_date, $obis_db_conn, $manual_db_conn, $tenant_db_conn);
    }
    return floatval($manual_value);
}

// Display tariff and calculation tariff for a tenant (property tariff setting and Tshwane pre-paid rule)
function lumSlipResolveElecTariff($raw_tariff, $property_name, $property_electrical_billing_type) {
    $elec_tariff = (string)$raw_tariff;

    // Property setting: one electrical tariff for every tenant of the property
    $forced = lumPropertySettings($property_name)['forced_elec_tariff'];
    if ($forced !== '') {
        $elec_tariff = $forced;
    }

    // Display and calculation names: from the tariff catalog, otherwise the old name rule
    // (Prepaid TOU tariffs are shown as the TOU tariff and billed on the Prepaid TOU rates)
    $row = function_exists('lumTariffLookup') ? lumTariffLookup($elec_tariff, 'electricity') : null;
    if ($row) {
        $display = trim((string)$row['display_name']);
        $calc = trim((string)$row['calc_name']);
    } else {
        list($display, $calc) = function_exists('lumTariffLegacyNames') ? lumTariffLegacyNames($elec_tariff)
            : ((stripos($elec_tariff, 'Prepaid TOU') !== false) ? ['City of Tshwane - TOU', 'City of Tshwane - Prepaid TOU'] : [null, null]);
    }
    $elec_tariff_calc = ((string)$calc !== '') ? $calc : $elec_tariff;
    if ((string)$display !== '') $elec_tariff = $display;

    // Property rule: a Tshwane TOU tariff at a pre-paid property is billed on the Prepaid TOU rates
    if (stripos($elec_tariff_calc, 'City of Tshwane - TOU') !== false && stripos((string)$property_electrical_billing_type, 'Pre-paid') !== false) {
        $elec_tariff_calc = 'City of Tshwane - Prepaid TOU';
    }

    return [$elec_tariff, $elec_tariff_calc];
}

// Municipality for a tenant - property setting first, then property name, then tariff names
function lumSlipResolveMunicipality($property_municipality, $property_name, $elec_tariff, $water_tariff, $sewer_tariff) {
    $municipality = 'Tshwane';
    if (!empty($property_municipality)) {
        $municipality = $property_municipality;
    } elseif (($legacy_muni = lumPropertySettings($property_name)['legacy_municipality']) !== null) {
        // Settings not installed yet: the old property-name rule
        $municipality = $legacy_muni;
    } else {
        // From the tenant's tariffs (tariff catalog, otherwise the words in the tariff names)
        $from_tariffs = lumTariffMunicipalityFor($elec_tariff, $water_tariff, $sewer_tariff);
        if ($from_tariffs !== null) $municipality = $from_tariffs;
    }

    // Normalise the municipality name
    if (stripos($municipality, 'Rustenburg') !== false) $municipality = 'Rustenburg';
    elseif (stripos($municipality, 'Ekhurhuleni') !== false || stripos($municipality, 'Ekurhuleni') !== false) $municipality = 'Ekhurhuleni';
    elseif (stripos($municipality, 'Mkhondo') !== false) $municipality = 'Mkhondo';
    elseif (stripos($municipality, 'Plett') !== false || stripos($municipality, 'Plettenberg') !== false) $municipality = 'Plettenberg Bay';
    elseif (stripos($municipality, 'George') !== false) $municipality = 'George';
    elseif (stripos($municipality, 'Tshwane') !== false) $municipality = 'Tshwane';

    return $municipality;
}

// Meter timelines (current tenant row and lum_tenants_legacy)

    function resolveMeterTimelines($tenant_db, $obis_db, $tenant, $current_meter, $start, $end, $obis_code, $meter_type = 'elec') {
        $timelines = [];
        $curr_occ_start = $tenant['tenant_occupancy_start_date'] ?? null;
        $curr_occ_end = $tenant['tenant_occupancy_end_date'] ?? null;

        $t_name = (string)($tenant['tenant_name'] ?? '');
        $search_tokens = explode(' ', preg_replace('/[^a-zA-Z0-9 ]/', '', $t_name));
        $match_token = '';
        foreach ($search_tokens as $token) {
            if (strlen($token) >= 3 && !in_array(strtolower($token), ['the', 'shop', 'store', 'and', 'trading', 'pty', 'ltd'])) {
                $match_token = $token; break;
            }
        }
        
        $col_name = ($meter_type === 'water') ? 'tenant_waterMeter_01' : 'tenant_electricalMeter_01';

        $old_meter = null;
        $old_occ_start = null;
        $old_occ_end = null;

        if (!empty($match_token)) {
            try {
                $legacy_sql = "SELECT {$col_name} as old_meter, tenant_occupancy_start_date, tenant_occupancy_end_date 
                               FROM lum_tenants_legacy 
                               WHERE (tenant_id = :tid OR (tenant_property = :prop AND tenant_name LIKE :name))
                               AND {$col_name} IS NOT NULL AND {$col_name} != '' AND {$col_name} != :curr
                               ORDER BY action_date DESC LIMIT 1";
                               
                $stmt = $tenant_db->prepare($legacy_sql);
                $stmt->execute([
                    'tid' => $tenant['tenant_id'], 
                    'prop' => $tenant['tenant_property'] ?? '', 
                    'name' => "%$match_token%",
                    'curr' => $current_meter
                ]);
                $leg = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($leg && !empty($leg['old_meter'])) {
                    $old_meter = $leg['old_meter'];
                    $old_occ_start = $leg['tenant_occupancy_start_date'];
                    $old_occ_end = $leg['tenant_occupancy_end_date'];
                }
            } catch (\Throwable $e) {}
        }

        if ($old_meter) {
            $trans_time = null;
            
            // If explicit occupancy dates exist, use them as the source of truth for the crossover
            if (!empty($curr_occ_start)) {
                $trans_time = strtotime($curr_occ_start) - 86400; // The day before the new occupancy started
            } else {
                // Fallback to database presence if dates are blank
                $cleanCode = preg_replace('/[^0-9]/', '', (string)$obis_code);
                $yr = date('Y', strtotime($start));
                $db = "db_obis_{$cleanCode}_{$yr}";
                $table = "tb_obis_{$cleanCode}_{$yr}";
                try {
                    $trans_stmt = $obis_db->prepare("SELECT MAX(Time_stamp) FROM `$db`.`$table` WHERE meter_serial = ?");
                    $trans_stmt->execute([preg_replace('/[^a-zA-Z0-9]/', '', $old_meter)]);
                    $last_ts = $trans_stmt->fetchColumn();
                    if ($last_ts) {
                        $trans_time = strtotime($last_ts); 
                    }
                } catch (\Throwable $e) {}
            }

            if ($trans_time) {
                $start_time = strtotime($start . ' 00:00:00');
                $end_time = strtotime($end . ' 23:59:59');

                if ($trans_time > $start_time && $trans_time < $end_time) {
                    $trans_date = date('Y-m-d', $trans_time);
                    $timelines[] = ['serial' => $old_meter, 'start' => $start, 'end' => $trans_date, 'is_legacy' => true, 'occ_start' => $old_occ_start, 'occ_end' => $old_occ_end];
                    $timelines[] = ['serial' => $current_meter, 'start' => date('Y-m-d', strtotime($trans_date . ' +1 day')), 'end' => $end, 'is_legacy' => false, 'occ_start' => $curr_occ_start, 'occ_end' => $curr_occ_end];
                    return $timelines;
                } elseif ($trans_time >= $end_time) {
                    return [['serial' => $old_meter, 'start' => $start, 'end' => $end, 'is_legacy' => true, 'occ_start' => $old_occ_start, 'occ_end' => $old_occ_end]];
                }
            }
        }
        
        return [['serial' => $current_meter, 'start' => $start, 'end' => $end, 'is_legacy' => false, 'occ_start' => $curr_occ_start, 'occ_end' => $curr_occ_end]];
    }

// Electrical estimate across meter changes and leases: history per meter slot (current row and lum_tenants_legacy);
// each meter is read only inside its own occupancy window and multiplied by its own CT ratio.

// Time Of Use check: "TOU" must appear as a separate word ("Ekhurhuleni - TOU" yes, "Bitou Water Shops" no)
function lumIsTouTariff($tariff) {
    // Tariff catalog first; tariffs not in the catalog: "TOU" as a separate word
    if (function_exists('lumTariffIsTou')) return lumTariffIsTou($tariff);
    return (bool)preg_match('/\bTOU\b/i', (string)$tariff);
}

// Picks the first meaningful word of a tenant name for legacy name matching
function lumTenantNameToken($name) {
    $tokens = explode(' ', preg_replace('/[^a-zA-Z0-9 ]/', '', (string)$name));
    foreach ($tokens as $token) {
        if (strlen($token) >= 3 && !in_array(strtolower($token), ['the', 'shop', 'store', 'and', 'trading', 'pty', 'ltd'])) {
            return $token;
        }
    }
    return '';
}

// Builds the full meter timeline for one meter slot of a tenant.
// Each entry: serial, ct, start (Y-m-d or null = earliest data), end (Y-m-d, never after today),
// display_end (Y-m-d or null = open-ended), is_current
// The recorded meter assignments for one tenant and slot (sys_db_tenants.lum_tenant_meters).
// These say which meter served this business between which dates, which is what the
// timeline below was working out from the tenant's history and its name. Returns an
// empty array when there is nothing recorded, and the old way is used instead.
function lumAssignedMeterHistory($tenant_db, $tenant, $kind, $slot) {
    static $assignment_cache = [];
    if (!$tenant_db) return [];

    $ref = trim((string)($tenant['tenant_ref'] ?? ''));
    if ($ref === '' && !empty($tenant['tenant_id'])) {
        $ref = 'T' . str_pad((string)$tenant['tenant_id'], 5, '0', STR_PAD_LEFT);
    }
    if ($ref === '') return [];

    $cache_key = $ref . '_' . $kind;
    if (!isset($assignment_cache[$cache_key])) {
        // Which slot each serial sits in on the tenant profile today. A profile that has been
        // tidied up can move a meter between slots, and that must not look like a meter change:
        // the serial decides the slot, not the slot recorded on the assignment.
        $form_slot = [];
        $max_slot = ($kind === 'water') ? 4 : 3;
        $col = ($kind === 'water') ? 'tenant_waterMeter_0' : 'tenant_electricalMeter_0';
        for ($i = 1; $i <= $max_slot; $i++) {
            $serial = trim((string)($tenant[$col . $i] ?? ''));
            if ($serial !== '' && $serial !== '0') $form_slot[$serial] = $i;
        }

        $by_slot = [];
        try {
            $stmt = $tenant_db->prepare("SELECT meter_serial, ct_ratio, slot, valid_from, valid_to
                                           FROM lum_tenant_meters
                                          WHERE tenant_ref = :ref AND meter_kind = :kind
                                          ORDER BY COALESCE(valid_from, '1970-01-01') ASC, link_id ASC");
            $stmt->execute(['ref' => $ref, 'kind' => $kind]);
            $today = date('Y-m-d');
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $serial = trim((string)$row['meter_serial']);
                if ($serial === '' || $serial === '0') continue;
                // On the profile now: use that slot. Otherwise (a meter of an earlier period)
                // keep the slot the assignment was recorded in.
                $use_slot = $form_slot[$serial] ?? max(1, min($max_slot, (int)$row['slot']));
                $display_end = !empty($row['valid_to']) ? $row['valid_to'] : null;
                $end = ($display_end === null || $display_end > $today) ? $today : $display_end;
                $by_slot[$use_slot][] = [
                    'serial'      => $serial,
                    'ct'          => (float)($row['ct_ratio'] ?? 1.0) > 0 ? (float)$row['ct_ratio'] : 1.0,
                    'start'       => !empty($row['valid_from']) ? $row['valid_from'] : null,
                    'end'         => $end,
                    'display_end' => $display_end,
                    'is_current'  => ($display_end === null),
                ];
            }
        } catch (\Throwable $e) {
            // The assignment table is not there, or cannot be read: fall back to the old way
            error_log('LUM slip: meter assignments not read: ' . $e->getMessage());
            $by_slot = [];
        }
        $assignment_cache[$cache_key] = $by_slot;
    }

    $slot = max(1, (int)$slot);
    return $assignment_cache[$cache_key][$slot] ?? [];
}

// Every meter assigned to this tenant for a kind, whatever slot it sits in. Used for the
// note on the slip, so a meter is never reported as missing just because it moved slot.
function lumAssignedSerials($tenant_db, $tenant, $kind) {
    $serials = [];
    $max_slot = ($kind === 'water') ? 4 : 3;
    for ($i = 1; $i <= $max_slot; $i++) {
        foreach (lumAssignedMeterHistory($tenant_db, $tenant, $kind, $i) as $w) {
            $serials[$w['serial']][] = $w;
        }
    }
    return $serials;
}

function getTenantElecMeterHistory($tenant_db, $tenant, $meter_slot = 1) {
    static $history_cache = [];

    $slot = max(1, min(3, (int)$meter_slot));

    // Recorded assignments come first: they say which meter served this business and when.
    // Only when there are none does the timeline below work it out from the tenant history.
    $assigned = lumAssignedMeterHistory($tenant_db, $tenant, 'electricity', $slot);
    if (!empty($assigned)) {
        $history_cache[($tenant['tenant_id'] ?? '') . '_' . $slot] = $assigned;
        return $assigned;
    }
    $cache_key = ($tenant['tenant_id'] ?? '') . '_' . $slot;
    if (isset($history_cache[$cache_key])) return $history_cache[$cache_key];

    $meter_col = 'tenant_electricalMeter_0' . $slot;
    $ct_col = $meter_col . '_ct_ratio';
    $today = date('Y-m-d');
    $entries = [];

    // 1. Legacy snapshots, oldest action first (covers deleted/re-registered tenant records via name match)
    if ($tenant_db) {
        try {
            $params = ['tid' => $tenant['tenant_id'] ?? 0];
            $where = "tenant_id = :tid";
            $match_token = lumTenantNameToken($tenant['tenant_name'] ?? '');
            if ($match_token !== '') {
                $where = "(tenant_id = :tid OR (tenant_property = :prop AND tenant_name LIKE :name))";
                $params['prop'] = $tenant['tenant_property'] ?? '';
                $params['name'] = "%{$match_token}%";
            }

            $legacy_sql = "SELECT `{$meter_col}` AS serial, `{$ct_col}` AS ct,
                                  tenant_occupancy_start_date AS occ_start, tenant_occupancy_end_date AS occ_end
                           FROM lum_tenants_legacy
                           WHERE {$where} AND `{$meter_col}` IS NOT NULL AND `{$meter_col}` > 0
                           ORDER BY action_date ASC, legacy_id ASC";
            $stmt = $tenant_db->prepare($legacy_sql);
            $stmt->execute($params);

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $entries[] = [
                    'serial' => trim((string)$row['serial']),
                    'ct'     => (float)($row['ct'] ?? 1.0),
                    'start'  => !empty($row['occ_start']) ? $row['occ_start'] : null,
                    'end'    => !empty($row['occ_end']) ? $row['occ_end'] : null
                ];
            }
        } catch (\Throwable $e) {}
    }

    // 2. The tenant's current meter in this slot is always the newest entry
    $current_serial = trim((string)($tenant[$meter_col] ?? ''));
    if ($current_serial !== '' && $current_serial !== '0') {
        $entries[] = [
            'serial' => $current_serial,
            'ct'     => (float)($tenant[$ct_col] ?? 1.0),
            'start'  => !empty($tenant['tenant_occupancy_start_date']) ? $tenant['tenant_occupancy_start_date'] : null,
            'end'    => !empty($tenant['tenant_occupancy_end_date']) ? $tenant['tenant_occupancy_end_date'] : null
        ];
    }

    // 3. Close each meter's window the day before the next (different) meter's occupancy began
    $count = count($entries);
    for ($i = 0; $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            if ($entries[$j]['serial'] !== $entries[$i]['serial'] && !empty($entries[$j]['start'])) {
                $cutoff = date('Y-m-d', strtotime($entries[$j]['start'] . ' -1 day'));
                if (empty($entries[$i]['end']) || $entries[$i]['end'] > $cutoff) {
                    $entries[$i]['end'] = $cutoff;
                }
                break;
            }
        }
        $entries[$i]['display_end'] = $entries[$i]['end'];
        if (empty($entries[$i]['end']) || $entries[$i]['end'] > $today) {
            $entries[$i]['end'] = $today;
        }
    }

    // 4. Group per meter and merge overlapping / back-to-back windows (lease renewals, repeated snapshots)
    $by_serial = [];
    $ct_by_serial = [];
    foreach ($entries as $e) {
        if ($e['serial'] === '') continue;
        $by_serial[$e['serial']][] = $e;
        $ct_by_serial[$e['serial']] = $e['ct']; // latest entry wins (current tenant row is last)
    }

    $history = [];
    foreach ($by_serial as $serial => $list) {
        // A snapshot without an occupancy start must not widen a window whose start is known
        $known_starts = array_filter(array_column($list, 'start'));
        if (!empty($known_starts)) {
            $earliest_known = min($known_starts);
            foreach ($list as &$list_entry) {
                if (empty($list_entry['start'])) $list_entry['start'] = $earliest_known;
            }
            unset($list_entry);
        }

        usort($list, function ($a, $b) { return strcmp((string)$a['start'], (string)$b['start']); });
        $merged = [];
        foreach ($list as $e) {
            if (!empty($e['start']) && $e['start'] > $e['end']) continue; // window closed before it opened

            if (!empty($merged)) {
                $k = count($merged) - 1;
                $day_after_prev = date('Y-m-d', strtotime($merged[$k]['end'] . ' +1 day'));
                if (empty($e['start']) || $e['start'] <= $day_after_prev) {
                    if ($e['end'] > $merged[$k]['end']) $merged[$k]['end'] = $e['end'];
                    if (empty($merged[$k]['display_end']) || empty($e['display_end'])) {
                        $merged[$k]['display_end'] = null;
                    } elseif ($e['display_end'] > $merged[$k]['display_end']) {
                        $merged[$k]['display_end'] = $e['display_end'];
                    }
                    continue;
                }
            }
            $merged[] = $e;
        }

        foreach ($merged as $m) {
            $m['serial'] = (string)$serial;
            $m['ct'] = $ct_by_serial[$serial] > 0 ? $ct_by_serial[$serial] : 1.0;
            $m['is_current'] = ((string)$serial === $current_serial);
            $history[] = $m;
        }
    }

    usort($history, function ($a, $b) { return strcmp((string)$a['start'], (string)$b['start']); });

    $history_cache[$cache_key] = $history;
    return $history;
}

// Works out one combined kWh-per-second rate from every meter window in a slot's history
function lumEstimateSlotRate($pdo, $history, $cleanCode) {
    if (empty($history)) return false;

    $years = getObisAvailableYears($pdo, $cleanCode);
    $col = $cleanCode . '_value';
    $total_kwh = 0;
    $total_seconds = 0;
    $display_ct = 1.0;
    $sources = [];

    foreach ($history as $w) {
        if (!empty($w['is_current'])) $display_ct = $w['ct'];

        $clean_serial = preg_replace('/[^a-zA-Z0-9]/', '', $w['serial']);
        if ($clean_serial === '') continue;

        $end_year = (int)date('Y', strtotime($w['end']));
        if (!empty($w['start'])) {
            $start_year = (int)date('Y', strtotime($w['start']));
            $window_start = $w['start'] . ' 00:00:00';
        } else {
            $start_year = !empty($years) ? $years[0] : $end_year - 5;
            $window_start = '1970-01-01 00:00:00';
        }
        $window_end = $w['end'] . ' 23:59:59';

        // Exact date & time windows (from lum_estimate_time_ranges) replace the whole-day boundaries
        if (!empty($w['start_dt'])) $window_start = $w['start_dt'];
        if (!empty($w['end_dt'])) $window_end = $w['end_dt'];

        $first_val = null; $first_ts = null;
        $last_val = null;  $last_ts = null;

        for ($y = $start_year; $y <= $end_year; $y++) {
            if (!empty($years) && !in_array($y, $years, true)) continue;
            $db = "db_obis_{$cleanCode}_{$y}";
            $table = "tb_obis_{$cleanCode}_{$y}";

            try {
                if ($first_val === null) {
                    $stmt_first = $pdo->prepare("SELECT Time_stamp, `$col` AS val FROM `$db`.`$table` WHERE meter_serial = :serial AND Time_stamp >= :start AND Time_stamp <= :end ORDER BY Time_stamp ASC LIMIT 1");
                    $stmt_first->execute(['serial' => $clean_serial, 'start' => $window_start, 'end' => $window_end]);
                    if ($row = $stmt_first->fetch(PDO::FETCH_ASSOC)) {
                        $first_val = (float)$row['val'];
                        $first_ts = strtotime($row['Time_stamp']);
                    }
                }

                $stmt_last = $pdo->prepare("SELECT Time_stamp, `$col` AS val FROM `$db`.`$table` WHERE meter_serial = :serial AND Time_stamp >= :start AND Time_stamp <= :end ORDER BY Time_stamp DESC LIMIT 1");
                $stmt_last->execute(['serial' => $clean_serial, 'start' => $window_start, 'end' => $window_end]);
                if ($row = $stmt_last->fetch(PDO::FETCH_ASSOC)) {
                    $last_val = (float)$row['val'];
                    $last_ts = strtotime($row['Time_stamp']);
                }
            } catch (\Throwable $e) {}
        }

        // Skip windows without two readings, or where the register went backwards (meter reset/replacement)
        if ($first_val === null || $last_val === null || $last_ts <= $first_ts || $last_val < $first_val) {
            continue;
        }

        // CT ratio only for consumption recorded before the meter was programmed with it
        $window_kwh = ($last_val - $first_val) * $w['ct'];
        if ($w['ct'] > 1) {
            $w_step = lumDetectMeterCtStep($pdo, $clean_serial, $cleanCode, $w['ct'], date('Y-m-d', $first_ts));
            if ($w_step !== null) {
                $w_step_i = strtotime($w_step);
                if ($w_step_i <= $first_ts) {
                    $window_kwh = $last_val - $first_val;
                } elseif ($w_step_i < $last_ts) {
                    $w_step_val = lumRegisterAt($pdo, $cleanCode, $clean_serial, $w_step_i);
                    if ($w_step_val !== null && $w_step_val >= $first_val && $w_step_val <= $last_val) {
                        $window_kwh = (($w_step_val - $first_val) * $w['ct']) + ($last_val - $w_step_val);
                    }
                }
            }
        }
        $total_kwh += $window_kwh;
        $total_seconds += ($last_ts - $first_ts);

        $sources[] = [
            'serial' => $w['serial'],
            'from'   => date('Y-m-d', $first_ts),
            'to'     => date('Y-m-d', $last_ts),
            'kwh'    => $window_kwh,
            'ct'     => $w['ct']
        ];
    }

    if ($total_seconds <= 0 || $total_kwh <= 0) return false;

    return [
        'kwh_per_second' => $total_kwh / $total_seconds,
        'display_ct'     => $display_ct,
        'sources'        => $sources
    ];
}

// Named calculateSlip... so it does not clash with the older calculateEstimatedElectricalReadings() in reporting-engine.php
function calculateSlipEstimatedElectricalReadings($pdo, $tenant_db, $tenant, $obis_code, $slip_start, $slip_end, $ct_ratio = 1.0, $meter_slot = 1) {
    static $rate_cache = [];

    if (!$pdo || !$tenant_db || empty($tenant) || empty($slip_start) || empty($slip_end)) return false;

    // Register: T2 = 1.1.1.8.2, Time Of Use tenants 1.1.1.8.0, all others 1.1.1.8.1
    $is_tou_record = !empty($tenant['_is_tou_tenant']) || lumIsTouTariff($tenant['tenant_electrical_tariff_charge'] ?? '');
    if ($obis_code === '1.1.1.8.2') {
        $cleanCode = '11182';
    } elseif ($is_tou_record) {
        $cleanCode = '11180';
    } else {
        $cleanCode = '11181';
    }

    // Occupancy override: tenants with missing/expired occupancy dates use the estimate time range
    // for this register (lum_estimate_time_ranges) on their current meter instead of their occupancy history
    $override_ranges = $tenant['_estimate_time_ranges'] ?? null;
    $range_obis = ['11180' => '1.1.1.8.0', '11181' => '1.1.1.8.1', '11182' => '1.1.1.8.2'][$cleanCode];

    // The rate does not depend on the slip dates, so calculate it once per tenant / slot / register
    $rate_key = ($tenant['tenant_id'] ?? '') . '_' . (int)$meter_slot . '_' . $cleanCode . (is_array($override_ranges) ? '_range' : '');
    if (!array_key_exists($rate_key, $rate_cache)) {
        if (is_array($override_ranges)) {
            $history = [];
            $range = $override_ranges[$range_obis] ?? null;
            $slot = max(1, min(3, (int)$meter_slot));
            $slot_serial = trim((string)($tenant['tenant_electricalMeter_0' . $slot] ?? ''));
            if ($range && $slot_serial !== '' && $slot_serial !== '0') {
                $slot_ct = (float)($tenant['tenant_electricalMeter_0' . $slot . '_ct_ratio'] ?? 1.0);
                $history[] = [
                    'serial' => $slot_serial,
                    'ct' => $slot_ct > 0 ? $slot_ct : 1.0,
                    'start' => date('Y-m-d', strtotime($range['start'])),
                    'end' => date('Y-m-d', strtotime($range['end'])),
                    'start_dt' => $range['start'],
                    'end_dt' => $range['end'],
                    'display_end' => date('Y-m-d', strtotime($range['end'])),
                    'is_current' => true
                ];
            }
        } else {
            $history = getTenantElecMeterHistory($tenant_db, $tenant, $meter_slot);
        }
        $rate_cache[$rate_key] = lumEstimateSlotRate($pdo, $history, $cleanCode);
    }
    $rate = $rate_cache[$rate_key];
    if (!$rate) return false;

    $slip_start_ts = strtotime($slip_start . ' 00:00:00');
    $slip_end_ts = strtotime($slip_end . ' 23:59:59');
    if ($slip_end_ts <= $slip_start_ts) return false;

    // CT ratios are already applied per meter inside the rate
    $slip_kwh = ($slip_end_ts - $slip_start_ts) * $rate['kwh_per_second'];

    $period = new DatePeriod(new DateTime($slip_start), new DateInterval('P1D'), (new DateTime($slip_end))->modify('+1 day'));
    $slip_dates = [];
    foreach ($period as $dt) {
        $slip_dates[] = $dt->format('Y-m-d');
    }
    $daily_avg = $slip_kwh / max(1, count($slip_dates));

    $daily_usage = [];
    foreach ($slip_dates as $d) {
        $daily_usage[$d] = [
            'total' => $daily_avg,
            'peak'  => 0,
            'std'   => $daily_avg,
            'off'   => 0
        ];
    }

    // An estimate can span several meters, so it has no opening or closing reading
    return [
        'open'  => 0,
        'close' => 0,
        'kwh'   => $slip_kwh,
        'kva'   => 0,
        'peak'  => 0,
        'std'   => $slip_kwh,
        'off'   => 0,
        'daily' => $daily_usage,
        'applied_ct' => $rate['display_ct'],
        'is_estimated' => true,
        'source' => 'estimate',
        'est_sources' => $rate['sources']
    ];
}
    
    function getSlipElecReadings($pdo, $manual_pdo, $meter_serial, $start, $end, $obis_code, $tou_algo, $holidays, $season = 'High', $ct_ratio = 1.00, $tenant_amps = 0, $manual_col = 'none', $use_est = false, $occ_start = null, $occ_end = null, $tenant_db_conn = null, $tenant_record = null, $meter_slot = 1) {
        if (!$meter_serial or empty($start) or empty($end)) return null;
        $clean_serial = @preg_replace('/[^a-zA-Z0-9]/', '', $meter_serial);
        $cleanCode = @preg_replace('/[^0-9]/', '', (string)$obis_code);
        $readingYear = date('Y', strtotime($start));
        
        $db = "db_obis_{$cleanCode}_{$readingYear}";
        $table = "tb_obis_{$cleanCode}_{$readingYear}";
        $col = "{$cleanCode}_value";

        $ranges = get_obis_time_ranges($pdo);
        $time_start = $ranges[$obis_code]['start'] ?? ' 00:30:00';
        $time_end = $ranges[$obis_code]['end'] ?? ' 23:59:59';
        
        $open_val = 0; $open_ts = null; $close_val = 0; $close_ts = null;
        $has_open = false; $has_close = false;
        $manual_used = false; // True only when manual extract readings were actually found for this meter

        // Manual extract readings (when selected)
        if ($manual_col !== 'none' && $manual_pdo) {
            $m_open = fetchManualExtractReading($manual_pdo, $clean_serial, $start . ' 00:00:00', $manual_col, true);
            $m_close = fetchManualExtractReading($manual_pdo, $clean_serial, $end . ' 23:59:59', $manual_col, false);
            
            if ($m_open !== false && $m_close !== false) {
                $open_val = $m_open;
                $close_val = $m_close;
                $open_ts = $start . ' 00:00:00';
                $close_ts = $end . ' 23:59:59';
                $has_open = true;
                $has_close = true;
                $manual_used = true;
            }
        }

        // Otherwise the automated readings
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

        // Estimate when readings are missing
        if ((!$has_open || !$has_close) && $use_est && $tenant_db_conn && $tenant_record) {
            $est_data = calculateSlipEstimatedElectricalReadings($pdo, $tenant_db_conn, $tenant_record, $obis_code, $start, $end, $ct_ratio, $meter_slot);
            if ($est_data !== false) {
                return $est_data; 
            }
        }

        $raw_total = max(0, $close_val - $open_val);

        // If the meter was re-programmed with its CT ratio, readings after that moment are already
        // multiplied. The tenant CT ratio is only applied to consumption recorded before it.
        $ct_step_ts = null;
        $ct_step_mode = null;           // 'before' = whole period already multiplied, 'within' = switch-over inside the period
        $pre_raw = $raw_total;          // consumption that still needs the CT ratio
        $post_raw = 0;                  // consumption already multiplied by the meter
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
        $close_val = $open_val + $kwh_total; // Opening + consumption always equals closing on the slip
        
        $tou = ['peak' => 0, 'std' => 0, 'off' => 0];
        $none_kwh = 0; 
        $daily_usage = [];

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
            $meter_db = lumDbConn('sys_db_meters');
            if (!$meter_db) throw new \RuntimeException('sys_db_meters unavailable');
            
            $stmt_prop = $meter_db->prepare("SELECT meter_property FROM lum_meters WHERE meter_serial = :serial LIMIT 1");
            $stmt_prop->execute(['serial' => $clean_serial]);
            $property_name = $stmt_prop->fetchColumn();
            
            if ($property_name) {
                $core_db = lumDbConn('sys_db_properties');
                if (!$core_db) throw new \RuntimeException('sys_db_properties unavailable');
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

// One tenant's bill: runs the consumption slip calculation with the given slip settings ($request, as on
// the slip sidebar) without touching the calling page's variables. Needs $tenant_db_conn, $obis_db_conn,
// $manual_db_conn and $tariff_db_conn (lum_connect).
function lumSlipCalculateTenant(array $tenant_row, array $request = []) {
    global $tenant_db_conn, $obis_db_conn, $manual_db_conn, $tariff_db_conn;
    $saved_request = $_REQUEST;
    $_REQUEST = $request;
    try {
        $tenant = $tenant_row;
        $property_name = (string)($tenant['tenant_property'] ?? '');
        $lum_default_start = $request['start_date'] ?? null;
        $lum_default_end = $request['end_date'] ?? null;
        require LUM_SLIP_DIR . '/slip-context.php';
        require LUM_SLIP_DIR . '/slip-tenant.php';

        $show_graph = false;
        $show_water_graph = false;
        $slip_render_mode = 'data';
        $slip_chart_key = $tenant['tenant_id'] ?? 0;
        ob_start();
        try {
            include LUM_SLIP_DIR . '/slip-render.php';
        } finally {
            ob_end_clean();
        }

        return [
            'totals'           => $slip_totals,
            'billing'          => $slip_billing ?? [],
            'municipality'     => $municipality,
            'is_tou'           => $is_tou_tenant,
            'tou_algorithm'    => $tou_algorithm,
            'elec_tariff'      => $elec_tariff,
            'elec_tariff_calc' => $elec_tariff_calc,
            'water_tariff'     => $water_tariff,
            'sewer_tariff'     => $sewer_tariff,
            'has_generator'    => $has_generator,
            'has_common_area'  => ($pays_elec_comm || $pays_water_comm),
            'season_1'         => $season_1,
            'rates'            => $rates_p1,
            'start_date'       => $start_date,
            'end_date'         => $end_date,
        ];
    } finally {
        $_REQUEST = $saved_request;
    }
}