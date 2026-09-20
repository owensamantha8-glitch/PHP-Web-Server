<?php
// Library file: only other pages may include it, it can never be opened directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(403); exit('Forbidden'); }
// Properties: areas, common area shares, property settings
// Part of reporting-engine.php; include that, never this file.

// All active property meter rows (read once per request)
function lumPropertyMeterRows($refresh = false) {
    static $rows = null;
    if ($rows !== null && !$refresh) return $rows;
    $rows = [];
    foreach (LUM_PROPERTY_METERS_DEFAULT as $d) {
        $rows[] = ['property' => $d[0], 'meter_serial' => $d[1], 'role' => $d[2], 'label' => $d[3], 'icon' => $d[4], 'color' => $d[5], 'sort_order' => $d[6]];
    }
    $pdo = lumSysDb('sys_db_meters');
    if ($pdo) {
        try {
            $db_rows = $pdo->query("SELECT property, meter_serial, role, label, icon, color, sort_order FROM lum_property_meters
                                    WHERE active = 1 ORDER BY property, role, sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
            // Common area meters: until common-area-meters-setup.sql has added any, the built-in ones stay in use
            // (otherwise every common area would suddenly be 0)
            $has_ca = false;
            $any_ca = $pdo->query("SELECT COUNT(*) FROM lum_property_meters WHERE role LIKE 'ca\\_%'")->fetchColumn();
            if ((int)$any_ca > 0) $has_ca = true;
            if (!$has_ca) {
                foreach ($rows as $builtin) {
                    if (strpos($builtin['role'], 'ca_') === 0) $db_rows[] = $builtin;
                }
            }
            $rows = $db_rows;
        } catch (\Throwable $e) {
            error_log('LUM property meters: table not available (run property-meters-setup.sql) - using the built-in lists: ' . $e->getMessage());
        }
    }
    return $rows;
}

// Meter serials with a role for one or more properties (unique, in order)
function lumPropertyMeterList(array $properties, $role) {
    $out = [];
    foreach (lumPropertyMeterRows() as $r) {
        if ($r['role'] === $role && in_array($r['property'], $properties, true)) {
            $serial = trim((string)$r['meter_serial']);
            if ($serial !== '' && !in_array($serial, $out, true)) $out[] = $serial;
        }
    }
    return $out;
}

// Meter rows of a property and role (labels, icons, colours), in order
function lumPropertyMeterNodes($property, $role) {
    $out = [];
    foreach (lumPropertyMeterRows() as $r) {
        if ($r['role'] === $role && $r['property'] === $property) $out[] = $r;
    }
    usort($out, function ($a, $b) { return (int)$a['sort_order'] <=> (int)$b['sort_order']; });
    return $out;
}

// Properties that have meters of a role (all roles when $role is null)
function lumPropertyMeterProperties($role = null) {
    $out = [];
    foreach (lumPropertyMeterRows() as $r) {
        if (($role === null || $r['role'] === $role) && !in_array($r['property'], $out, true)) $out[] = $r['property'];
    }
    return $out;
}

// Properties whose meters are also taken from the meter register by type
function lumDashboardAutoProperties() {
    static $list = null;
    if ($list !== null) return $list;
    $list = ['One On York'];
    $pdo = lumSysDb('sys_db_properties');
    if ($pdo) {
        try {
            $list = $pdo->query("SELECT Property FROM lum_properties WHERE dashboard_auto_meters = 1")->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            // Settings not installed: built-in list
        }
    }
    return $list;
}

// Common area meters of a property (Configurations -> Property Meters: roles ca_elec_main, ca_elec_less, ca_water_main)
function lumCommonAreaMeters($property, $role) {
    return lumPropertyMeterList([(string)$property], $role);
}

// Lambton Gardens: main meters - every tenant electrical meter (except the main meters)
function calculateLambtonCommonArea($start_date, $end_date, $obis_code, $obis_db_conn, $tenant_db_conn) {
    $mains_meters = lumCommonAreaMeters('Lambton Gardens', 'ca_elec_main');
    $mains = 0;
    foreach ($mains_meters as $serial) { $mains += fetch_lambton_meter_kwh($serial, $start_date, $end_date, $obis_db_conn, $obis_code); }
    
    $tenant_sum = 0;
    try {
        $stmt = $tenant_db_conn->prepare("SELECT tenant_electricalMeter_01, tenant_electricalMeter_02, tenant_electricalMeter_03 FROM lum_tenants WHERE tenant_property = 'Lambton Gardens'");
        $stmt->execute();
        $tenants_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($tenants_data as $td) {
            $e_meters = array_filter([$td['tenant_electricalMeter_01'], $td['tenant_electricalMeter_02'], $td['tenant_electricalMeter_03']]);
            foreach ($e_meters as $em) {
                if (!in_array($em, $mains_meters)) { 
                    $tenant_sum += fetch_lambton_meter_kwh($em, $start_date, $end_date, $obis_db_conn, $obis_code);
                }
            }
        }
    } catch (Exception $e) {}
    
    return max(0, $mains - $tenant_sum);
}

// Thatchfield Centre: main meters - a fixed list of meters
function calculateThatchfieldCommonArea($start_date, $end_date, $obis_code, $pdo) {
    $tenant_meters = lumCommonAreaMeters('Thatchfield Centre', 'ca_elec_less');
    $mains_meters = lumCommonAreaMeters('Thatchfield Centre', 'ca_elec_main');
    $tenant_usage = 0;
    foreach ($tenant_meters as $serial) { $tenant_usage += fetch_lambton_meter_kwh($serial, $start_date, $end_date, $pdo, $obis_code); }
    $mains_usage = 0;
    foreach ($mains_meters as $serial) { $mains_usage += fetch_lambton_meter_kwh($serial, $start_date, $end_date, $pdo, $obis_code); }
    return round(max(0, $mains_usage - $tenant_usage), 2); 
}

// Lynnwood Lane: main meters - a fixed list of meters
function calculateLynnwoodCommonArea($start_date, $end_date, $obis_code, $pdo) {
    $mains_meters = lumCommonAreaMeters('Lynnwood Lane', 'ca_elec_main');
    $tenant_meters = lumCommonAreaMeters('Lynnwood Lane', 'ca_elec_less');
    $mains_total = 0;
    foreach ($mains_meters as $serial) { $mains_total += fetch_lambton_meter_kwh($serial, $start_date, $end_date, $pdo, $obis_code); }
    $tenant_total = 0;
    foreach ($tenant_meters as $serial) { $tenant_total += fetch_lambton_meter_kwh($serial, $start_date, $end_date, $pdo, $obis_code); }
    return max(0, round(($mains_total - $tenant_total), 2));
}

// Greystone Crossing: the sum of the common area meters
function calculateGreystoneCommonArea($start_date, $end_date, $obis_code, $pdo) {
    $meters = lumCommonAreaMeters('Greystone Crossing', 'ca_elec_main');
    $total_usage = 0;
    foreach ($meters as $serial) {
        $total_usage += fetch_lambton_meter_kwh($serial, $start_date, $end_date, $pdo, $obis_code);
    }
    return round($total_usage, 2);
}

// One On York: main meters - every tenant electrical meter (except the main meters)
function calculateOneOnYorkCommonArea($start_date, $end_date, $obis_code, $obis_db_conn, $tenant_db_conn) {
    $mains_meters = lumCommonAreaMeters('One On York', 'ca_elec_main');
    $mains = 0;
    foreach ($mains_meters as $serial) { $mains += fetch_lambton_meter_kwh($serial, $start_date, $end_date, $obis_db_conn, $obis_code); }
    
    $tenant_sum = 0;
    try {
        $stmt = $tenant_db_conn->prepare("SELECT tenant_electricalMeter_01, tenant_electricalMeter_02, tenant_electricalMeter_03 FROM lum_tenants WHERE tenant_property = 'One On York'");
        $stmt->execute();
        $tenants_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($tenants_data as $td) {
            $e_meters = array_filter([$td['tenant_electricalMeter_01'], $td['tenant_electricalMeter_02'], $td['tenant_electricalMeter_03']]);
            foreach ($e_meters as $em) {
                if (!in_array($em, $mains_meters, true)) { 
                    $tenant_sum += fetch_lambton_meter_kwh($em, $start_date, $end_date, $obis_db_conn, $obis_code);
                }
            }
        }
    } catch (Exception $e) {}
    
    return max(0, $mains - $tenant_sum);
}

function calculateMarketsquareCommonArea($start_date, $end_date, $obis_code, $obis_db_conn, $tenant_db_conn = null) {
    // =====================================================================
    // THE MARKETSQUARE - ELECTRICAL COMMON AREA
    // Mirrors the dashboard calculation in index.php exactly:
    //   Common Area = 36922141 - Total Tenant Usage (lum_tenants)
    //   OBIS 1.1.1.8.1 on both sides, totals via getBulkMeterTotal() from dashboard-engine.php
    // =====================================================================
    if (!$obis_db_conn || !$tenant_db_conn) return 0;

    // Load the dashboard engine so the slip uses the identical meter-total function
    if (!function_exists('getBulkMeterTotal')) {
        $dashboard_engine = lum_resolve_path('/Dashboard functions/dashboard-engine.php');
        if (!file_exists($dashboard_engine)) return 0;
        require_once $dashboard_engine;
    }

    $selected_property = 'The Marketsquare';
    $grid_meters = lumCommonAreaMeters($selected_property, 'ca_elec_main');
    $target_obis = '1.1.1.8.1';

    // OBIS time ranges, built the same way index.php builds $obis_time_ranges
    static $obis_time_ranges = null;
    if ($obis_time_ranges === null) {
        $obis_time_ranges = [];
        try {
            $stmt_ranges = $obis_db_conn->query("SELECT obis_code, start_time, end_time FROM `sys_db_information`.`lum_obis_time_ranges`");
            while ($row = $stmt_ranges->fetch(PDO::FETCH_ASSOC)) {
                $obis_time_ranges[$row['obis_code']] = [
                    'start' => ' ' . $row['start_time'],
                    'end'   => ' ' . $row['end_time']
                ];
            }
        } catch (\Throwable $e) {}
    }

    // 1. Grid consumption (36922141)
    $total_grid_kwh = getBulkMeterTotal($obis_db_conn, $grid_meters, $start_date, $end_date, $target_obis, $obis_time_ranges);

    // 2. Total tenant electrical usage - every electrical meter of every Marketsquare tenant in lum_tenants
    $all_tenant_e_meters = [];
    try {
        $stmt = $tenant_db_conn->prepare("SELECT tenant_electricalMeter_01, tenant_electricalMeter_02, tenant_electricalMeter_03 FROM lum_tenants WHERE tenant_property = :prop");
        $stmt->execute(['prop' => $selected_property]);
        $tenants_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($tenants_data as $td) {
            $e_meters = array_filter([$td['tenant_electricalMeter_01'], $td['tenant_electricalMeter_02'], $td['tenant_electricalMeter_03']]);
            foreach ($e_meters as $em) {
                $all_tenant_e_meters[] = $em;
            }
        }
    } catch (\Throwable $e) {}

    $total_tenant_elec = getBulkMeterTotal($obis_db_conn, array_unique($all_tenant_e_meters), $start_date, $end_date, $target_obis, $obis_time_ranges);

    // 3. Common area
    return max(0, $total_grid_kwh - $total_tenant_elec);
}

// Linton's Corner: water main meters (Borehole 2 outgoing + Council bulk) - every Linton's Corner tenant water meter
function calculateLintonsCornerWaterCommonArea($start_date, $end_date, $obis_db_conn, $manual_db_conn, $tenant_db_conn) {
    $mains_serials = lumCommonAreaMeters("Linton's Corner", 'ca_water_main');
    $mains_usage = 0;
    foreach ($mains_serials as $serial) {
        $mains = getRealWaterReadings($obis_db_conn, $manual_db_conn, $serial, $start_date, $end_date);
        $mains_usage += $mains ? $mains['kl'] : 0;
    }
    
    $tenant_sum = 0;
    try {
        $stmt = $tenant_db_conn->prepare("SELECT tenant_waterMeter_01, tenant_waterMeter_02, tenant_waterMeter_03, tenant_waterMeter_04 FROM lum_tenants WHERE tenant_property LIKE '%Linton%'");
        $stmt->execute();
        $tenants_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($tenants_data as $td) {
            $w_meters = array_filter([$td['tenant_waterMeter_01'], $td['tenant_waterMeter_02'], $td['tenant_waterMeter_03'], $td['tenant_waterMeter_04'] ?? null]);
            foreach ($w_meters as $wm) {
                $clean_wm = preg_replace('/[^a-zA-Z0-9]/', '', $wm);
                if (!in_array($clean_wm, $mains_serials)) {
                    $res = getRealWaterReadings($obis_db_conn, $manual_db_conn, $clean_wm, $start_date, $end_date);
                    if ($res) $tenant_sum += $res['kl'];
                }
            }
        }
    } catch (Exception $e) {}
    
    return max(0, $mains_usage - $tenant_sum);
}

// The Marketsquare: (each water main meter / 5, added) / 5
function calculateMarketsquareWaterCommonArea($start_date, $end_date, $obis_db_conn, $manual_db_conn, $tenant_db_conn) {
    $share = 0;
    foreach (lumCommonAreaMeters('The Marketsquare', 'ca_water_main') as $serial) {
        $readings = getRealWaterReadings($obis_db_conn, $manual_db_conn, $serial, $start_date, $end_date);
        $share += ($readings ? $readings['kl'] : 0) / 5;
    }
    $common_area = $share / 5;
    return $common_area;
}

// Lambton Gardens: the sum of the water main meters
function calculateLambtonWaterCommonArea($start_date, $end_date, $obis_pdo, $manual_pdo) {
    $meters = lumCommonAreaMeters('Lambton Gardens', 'ca_water_main');
    $total_usage = 0;
    foreach ($meters as $serial) {
        $readings = getRealWaterReadings($obis_pdo, $manual_pdo, $serial, $start_date, $end_date);
        if ($readings) { $total_usage += $readings['kl']; }
    }
    return $total_usage;
}

// Thatchfield Retail: the sum of the water main meters
function calculateThatchfieldWaterCommonArea($start_date, $end_date, $obis_pdo, $manual_pdo) {
    $meters = lumCommonAreaMeters('Thatchfield Retail', 'ca_water_main');
    $total_usage = 0;
    foreach ($meters as $serial) {
        $readings = getRealWaterReadings($obis_pdo, $manual_pdo, $serial, $start_date, $end_date);
        if ($readings) { $total_usage += $readings['kl']; }
    }
    return $total_usage;
}

// Lynnwood Lane: water main meters - every tenant water meter (except the main meters)
function calculateLynnwoodLaneWaterCommonArea($start_date, $end_date, $obis_db_conn, $manual_db_conn, $tenant_db_conn) {
    $mains_serials = lumCommonAreaMeters('Lynnwood Lane', 'ca_water_main');
    $mains_usage = 0;
    foreach ($mains_serials as $mains_serial) {
        $mains = getRealWaterReadings($obis_db_conn, $manual_db_conn, $mains_serial, $start_date, $end_date);
        $mains_usage += $mains ? $mains['kl'] : 0;
    }
    
    $tenant_sum = 0;
    try {
        $stmt = $tenant_db_conn->prepare("SELECT tenant_waterMeter_01, tenant_waterMeter_02, tenant_waterMeter_03, tenant_waterMeter_04 FROM lum_tenants WHERE tenant_property = 'Lynnwood Lane'");
        $stmt->execute();
        $tenants_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($tenants_data as $td) {
            $w_meters = array_filter([$td['tenant_waterMeter_01'], $td['tenant_waterMeter_02'], $td['tenant_waterMeter_03'], $td['tenant_waterMeter_04'] ?? null]);
            foreach ($w_meters as $wm) {
                if (!in_array($wm, $mains_serials, true)) {
                    $res = getRealWaterReadings($obis_db_conn, $manual_db_conn, $wm, $start_date, $end_date);
                    if ($res) $tenant_sum += $res['kl'];
                }
            }
        }
    } catch (Exception $e) {}
    
    return max(0, $mains_usage - $tenant_sum);
}

// One On York: water main meters - every tenant water meter (except the main meters)
function calculateOneOnYorkWaterCommonArea($start_date, $end_date, $obis_db_conn, $manual_db_conn, $tenant_db_conn) {
    $mains_serials = lumCommonAreaMeters('One On York', 'ca_water_main');
    $mains_usage = 0;
    foreach ($mains_serials as $mains_serial) {
        $mains = getRealWaterReadings($obis_db_conn, $manual_db_conn, $mains_serial, $start_date, $end_date);
        $mains_usage += $mains ? $mains['kl'] : 0;
    }
    
    $tenant_sum = 0;
    try {
        $stmt = $tenant_db_conn->prepare("SELECT tenant_waterMeter_01, tenant_waterMeter_02, tenant_waterMeter_03, tenant_waterMeter_04 FROM lum_tenants WHERE tenant_property = 'One On York'");
        $stmt->execute();
        $tenants_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($tenants_data as $td) {
            $w_meters = array_filter([$td['tenant_waterMeter_01'], $td['tenant_waterMeter_02'], $td['tenant_waterMeter_03'], $td['tenant_waterMeter_04'] ?? null]);
            foreach ($w_meters as $wm) {
                if (!in_array($wm, $mains_serials, true)) {
                    $res = getRealWaterReadings($obis_db_conn, $manual_db_conn, $wm, $start_date, $end_date);
                    if ($res) $tenant_sum += $res['kl'];
                }
            }
        }
    } catch (Exception $e) {}
    
    return max(0, $mains_usage - $tenant_sum);
}
