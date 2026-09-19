<?php
// Library file: only other pages may include it, it can never be opened directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(403); exit('Forbidden'); }
$property_settings = $property_settings ?? lumPropertySettings($property_name); // Set by slip-context.php

// Sections are also shown for fixed charges without a meter
$has_active_elec_tariff = (!empty($elec_tariff) && $elec_tariff !== 'Not applicable');
$has_any_elec_section = (!empty($elec_meters) || $has_active_elec_tariff || ($pays_elec_comm && !$remove_elec_comm));

$has_active_water_tariff = (!empty($water_tariff) && $water_tariff !== 'Not applicable');
$has_active_sewer_tariff = (!empty($sewer_tariff) && $sewer_tariff !== 'Not applicable');
$has_any_water_section = (!empty($water_meters) || $has_active_water_tariff || $has_active_sewer_tariff || ($pays_water_comm && !$remove_water_comm));

// Period 2 charges are shown separately only when the season or the rates differ (floats compared to 0.001)
$rates_differ = false;
if ($has_period_2) {
    foreach ($rates_p1 as $key => $val) {
        $val2 = $rates_p2[$key] ?? null;
        if (is_numeric($val) && is_numeric($val2)) {
            if (abs(floatval($val) - floatval($val2)) > 0.001) {
                $rates_differ = true;
                break;
            }
        } elseif ($val != $val2) {
            $rates_differ = true;
            break;
        }
    }
}

$show_separate_p2_charges = ($has_period_2 && ($season_1 !== $season_2 || $rates_differ));
$show_separate_water_p2 = ($has_water_period_2 && $rates_differ);

$using_manual_override = ($manual_override_col !== 'none' || $manual_override_col_t2 !== 'none');

// Render mode: 'single' = view-consumption-slip.php, 'bulk' = bulk-consumption-slips.php,
// 'data' = financial report (output discarded)
$slip_render_mode = $slip_render_mode ?? 'single';
$slip_chart_key = $slip_chart_key ?? ($tenant['tenant_id'] ?? '');

// Captured totals (read by the financial report, so it always equals the slip)
$slip_totals = [
    'basic' => 0, 'energy' => 0, 'generator' => 0, 'demand' => 0, 'network' => 0, 'capacity' => 0,
    'elec_comm' => 0, 'shared_nac' => 0, 'elec_total' => 0, 'kwh' => 0, 'comm_kwh' => 0,
    'water_basic' => 0, 'water_usage' => 0, 'sewer_basic' => 0, 'sewer_usage' => 0, 'sewer_comm' => 0,
    'water_comm' => 0, 'sewer_additional' => 0, 'water_total' => 0, 'water_kl' => 0, 'comm_kl' => 0,
    'refuse' => 0, 'adjustments' => 0, 'total' => 0
];
?>
<?php
// Water & sewer section, built here so the info box knows whether to show the water period.
// No water meter linked = no section, unless the tenant still carries a real charge
// (e.g. a water common area contribution), so no billed amount is dropped.
$water_section_html = '';
if ($has_any_water_section) {
    ob_start();
?>
<?php 
if ($has_any_water_section): 
    $total_water_kl_1 = 0;
    $total_water_kl_2 = 0;
    
    // Property setting: is the common area sewer contribution billed on the slips?
    $bills_sewer_comm = !empty($property_settings['bills_sewer_common_area']);
?>
<div class="mb-5">
    <h4 class="border-bottom border-secondary pb-1 mb-2 text-dark text-uppercase fw-normal">Water & Sewer</h4>
    
    <?php 
    if (!empty($water_meters)) {
        foreach($water_meters as $meter_id):
            $w_timelines1 = resolveMeterTimelines($tenant_db_conn, $obis_db_conn, $tenant, $meter_id, $water_start_date, $water_end_date, '8.1.1.0.0', 'water');
            
            $w1_open = 0; $w1_close = 0; $kl1 = 0; $w_is_first = true;
            $w_splits1 = [];
            foreach ($w_timelines1 as $w_timeline) {
                $wrdg = getRealWaterReadings($obis_db_conn, $manual_db_conn, $w_timeline['serial'], $w_timeline['start'], $w_timeline['end']);
                if ($wrdg) {
                    $wrdg['serial'] = $w_timeline['serial'];
                    $w_splits1[] = $wrdg;
                    if ($w_is_first) { $w1_open = floatval($wrdg['open']); $w_is_first = false; }
                    $w1_close = floatval($wrdg['close']);
                    $kl1 += floatval($wrdg['kl']);
                }
            }

            $w2_open = 0; $w2_close = 0; $kl2 = 0; $w_is_first = true;
            $w_splits2 = [];
            if ($has_water_period_2) {
                $w_timelines2 = resolveMeterTimelines($tenant_db_conn, $obis_db_conn, $tenant, $meter_id, $water_start_date_2, $water_end_date_2, '8.1.1.0.0', 'water');
                foreach ($w_timelines2 as $w_timeline) {
                    $wrdg = getRealWaterReadings($obis_db_conn, $manual_db_conn, $w_timeline['serial'], $w_timeline['start'], $w_timeline['end']);
                    if ($wrdg) {
                        $wrdg['serial'] = $w_timeline['serial'];
                        $w_splits2[] = $wrdg;
                        if ($w_is_first) { $w2_open = floatval($wrdg['open']); $w_is_first = false; }
                        $w2_close = floatval($wrdg['close']);
                        $kl2 += floatval($wrdg['kl']);
                    }
                }
            }

            $open_val = ($w1_open > 0) ? $w1_open : (($w2_open > 0) ? $w2_open : 0);
            $close_val = ($has_water_period_2 && $w2_close > 0) ? $w2_close : (($w1_close > 0) ? $w1_close : 0);

            $overall_diff = $close_val - $open_val;
            $has_w_crossover = (count($w_splits1) > 1 || ($has_water_period_2 && count($w_splits2) > 1));

            // Data gaps at month boundaries: prorate the overall difference by days
            if (!$has_w_crossover && $overall_diff > 0 && abs($overall_diff - ($kl1 + $kl2)) > 0.01) {
                if ($has_water_period_2) {
                    $d_w1 = max(1, (new DateTime($water_start_date))->diff(new DateTime($water_end_date))->days + 1);
                    $d_w2 = max(1, (new DateTime($water_start_date_2))->diff(new DateTime($water_end_date_2))->days + 1);
                    $d_tot = $d_w1 + $d_w2;
                    $kl1 = $overall_diff * ($d_w1 / $d_tot);
                    $kl2 = $overall_diff - $kl1;
                } else {
                    $kl1 = $overall_diff;
                    $kl2 = 0;
                }
            }

            $total_water_kl_1 += $kl1;
            $total_water_kl_2 += $kl2;
            $meter_total_kl = $kl1 + $kl2;

            if ($show_water_graph) {
                $w_daily_1 = getDailyWaterUsage($obis_db_conn, $meter_id, $water_start_date, $water_end_date);
                if (is_array($w_daily_1)) {
                    foreach ($w_daily_1 as $d => $v) {
                        if (!isset($agg_daily_water_graph[$d])) $agg_daily_water_graph[$d] = 0;
                        $agg_daily_water_graph[$d] += $v;
                    }
                }
                if ($has_water_period_2) {
                    $w_daily_2 = getDailyWaterUsage($obis_db_conn, $meter_id, $water_start_date_2, $water_end_date_2);
                    if (is_array($w_daily_2)) {
                        foreach ($w_daily_2 as $d => $v) {
                            if (!isset($agg_daily_water_graph[$d])) $agg_daily_water_graph[$d] = 0;
                            $agg_daily_water_graph[$d] += $v;
                        }
                    }
                }
            }
        ?>
        <div class="table-responsive mb-2">
            <table class="table table-bordered table-sm align-middle">
                <thead class="table-dark" style="background-color: #1a365d;">
                    <tr>
                        <th colspan="2" class="text-uppercase fw-normal" style="background-color: #1a365d;">WATER & SEWER — METER READINGS <?php echo (!empty($meter_id)) ? '('.htmlspecialchars($meter_id).')' : ''; ?></th>
                        <th class="text-end fw-normal" style="width: 15%; background-color: #1a365d;">kL</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $all_w_splits = array_merge($w_splits1, $has_water_period_2 ? $w_splits2 : []);
                    
                    // Consolidate splits if there is no physical meter crossover
                    if (!$has_w_crossover && !empty($all_w_splits)) {
                        $all_w_splits = [[
                            'serial' => $all_w_splits[0]['serial'],
                            'open' => $open_val,
                            'close' => $close_val
                        ]];
                    }

                    if (empty($all_w_splits)) {
                        echo "<tr><td colspan='2'>Opening Reading</td><td class='text-end'>" . number_format($open_val, 2, '.', '') . "</td></tr>";
                        echo "<tr><td colspan='2'>Closing Reading</td><td class='text-end'>" . number_format($close_val, 2, '.', '') . "</td></tr>";
                    } else {
                        $show_serial = count($all_w_splits) > 1;
                        foreach ($all_w_splits as $ws) {
                            $serial_lbl = $show_serial ? " (Meter: " . htmlspecialchars($ws['serial']) . ")" : "";
                            echo "<tr><td colspan='2'>Opening Reading{$serial_lbl}</td><td class='text-end'>" . number_format($ws['open'], 2, '.', '') . "</td></tr>";
                            echo "<tr><td colspan='2'>Closing Reading{$serial_lbl}</td><td class='text-end'>" . number_format($ws['close'], 2, '.', '') . "</td></tr>";
                        }
                    }
                    ?>
                    <tr class="table-light text-dark fw-normal"><td colspan="2">Total Consumption</td><td class="text-end"><?php echo number_format($meter_total_kl, 2, '.', ''); ?> kL</td></tr>
                </tbody>
            </table>
        </div>
        <?php endforeach; 
    }
    $total_water_kl = $total_water_kl_1 + $total_water_kl_2;
    $slip_totals['water_kl'] = $total_water_kl;
    ?>

    <div class="table-responsive mb-3">
        <table class="table table-bordered table-sm align-middle">
            <thead class="table-dark" style="background-color: #1a365d;">
                <tr><th colspan="5" class="text-uppercase fw-normal" style="background-color: #1a365d;">WATER & SEWER — CHARGE BREAKDOWN</th></tr>
                <tr class="table-secondary text-dark fw-normal">
                    <th width="45%">Description</th>
                    <th class="text-end">Units</th>
                    <th class="text-center">Unit</th>
                    <th class="text-end">Rate (R/unit)</th>
                    <th class="text-end">Amount (R)</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($has_water): ?>
                <tr class="text-muted">
                    <td>Tariff applied: <?php echo htmlspecialchars($display_water_tariff ?? $water_tariff); ?></td>
                    <td class="text-end">0.00</td><td></td><td class="text-end">-</td><td class="text-end">-</td>
                </tr>
                <?php endif; ?>
                
                <?php if ($has_sewer && stripos($municipality, 'Rustenburg') === false): ?>
                <tr class="text-muted">
                    <td>Sewer tariff applied: <?php echo htmlspecialchars($sewer_tariff); ?></td>
                    <td class="text-end">0.00</td><td></td><td class="text-end">-</td><td class="text-end">-</td>
                </tr>
                <?php endif; ?>
                
                <?php
                $days_w1 = max(1, (new DateTime($water_start_date))->diff(new DateTime($water_end_date))->days + 1);
                
                if ($show_separate_water_p2) {
                    $days_w2 = max(1, (new DateTime($water_start_date_2))->diff(new DateTime($water_end_date_2))->days + 1);
                    $total_w_days = $days_w1 + $days_w2;
                    $w_units_1 = $days_w1 / $total_w_days;
                    $w_units_2 = $days_w2 / $total_w_days;
                } else {
                    $total_w_days = $days_w1 + (isset($water_start_date_2) && $water_start_date_2 ? max(1, (new DateTime($water_start_date_2))->diff(new DateTime($water_end_date_2))->days + 1) : 0);
                    $w_units_1 = 1.0;
                    $w_units_2 = 0.0;
                    $total_water_kl_1 = $total_water_kl;
                }
                
                $water_service_subtotal = 0;
                if ($has_water) {
                    if (stripos($municipality, 'Rustenburg') !== false || stripos($municipality, 'Mkhondo') !== false || stripos($municipality, 'Plett') !== false || stripos($municipality, 'George') !== false) {
                        if ($pays_water_basic) {
                            if ($show_separate_water_p2) {
                                $b_amt1 = ($rates_p1['water_basic'] ?? 0) * $w_units_1;
                                $water_service_subtotal += $b_amt1;
                                $slip_totals['water_basic'] += $b_amt1;
                                $d1_str = date('d M', strtotime($water_start_date)) . "–" . date('d M Y', strtotime($water_end_date));
                                echo "<tr><td>Basic Charge — {$d1_str}, {$days_w1}/{$total_w_days} days &mdash; {$display_water_tariff}</td><td class='text-end'>".number_format($w_units_1, 2)."</td><td></td><td class='text-end'>".number_format(($rates_p1['water_basic'] ?? 0), 4)."</td><td class='text-end'>".number_format($b_amt1, 2)."</td></tr>";
                                
                                $b_amt2 = ($rates_p2['water_basic'] ?? 0) * $w_units_2;
                                $water_service_subtotal += $b_amt2;
                                $slip_totals['water_basic'] += $b_amt2;
                                $d2_str = date('d M', strtotime($water_start_date_2)) . "–" . date('d M Y', strtotime($water_end_date_2));
                                echo "<tr><td>Basic Charge — {$d2_str}, {$days_w2}/{$total_w_days} days &mdash; {$display_water_tariff}</td><td class='text-end'>".number_format($w_units_2, 2)."</td><td></td><td class='text-end'>".number_format(($rates_p2['water_basic'] ?? 0), 4)."</td><td class='text-end'>".number_format($b_amt2, 2)."</td></tr>";
                            } else {
                                $b_amt1 = ($rates_p1['water_basic'] ?? 0) * 1.0;
                                $water_service_subtotal += $b_amt1;
                                $slip_totals['water_basic'] += $b_amt1;
                                if ($has_water_period_2) {
                                    $d_str = date('d M', strtotime($water_start_date)) . "–" . date('d M Y', strtotime($water_end_date_2));
                                    echo "<tr><td>Basic Charge &mdash; {$d_str}, {$total_w_days} days &mdash; {$display_water_tariff}</td><td class='text-end'>1.00</td><td></td><td class='text-end'>".number_format(($rates_p1['water_basic'] ?? 0), 4)."</td><td class='text-end'>".number_format($b_amt1, 2)."</td></tr>";
                                } else {
                                    echo "<tr><td>Basic Charge &mdash; {$display_water_tariff}</td><td class='text-end'>1.00</td><td></td><td class='text-end'>".number_format(($rates_p1['water_basic'] ?? 0), 4)."</td><td class='text-end'>".number_format($b_amt1, 2)."</td></tr>";
                                }
                            }
                        }
                    }

                    if (!empty($rates_p1['is_tiered_water'])) {
                        $frac1 = ($total_water_kl > 0) ? ($total_water_kl_1 / $total_water_kl) : ($days_w1 / $total_w_days);
                        $frac2 = ($total_water_kl > 0) ? ($total_water_kl_2 / $total_water_kl) : (($total_w_days - $days_w1) / $total_w_days);

                        // Tier bands per municipality (sys_db_tariffs.lum_water_tiers, see reporting-engine.php)
                        $tiers = lumWaterTierSplit(lumWaterTierBands($municipality, $water_end_date), $total_water_kl);

                        foreach ($tiers as $tier) {
                            if ($tier['kl'] <= 0) continue;
                            
                            if ($show_separate_water_p2) {
                                $kl1 = $tier['kl'] * $frac1;
                                $amt1 = $kl1 * ($rates_p1[$tier['rate_key']] ?? 0);
                                $water_service_subtotal += $amt1;
                                $slip_totals['water_usage'] += $amt1;
                                $d1_str = date('d M', strtotime($water_start_date)) . "–" . date('d M Y', strtotime($water_end_date));
                                echo "<tr><td>{$tier['label']} — {$d1_str}, {$days_w1}/{$total_w_days} days &mdash; {$display_water_tariff}</td><td class='text-end'>".number_format($kl1, 2)."</td><td class='text-center'>kL</td><td class='text-end'>".number_format(($rates_p1[$tier['rate_key']] ?? 0), 4)."</td><td class='text-end'>".number_format($amt1, 2)."</td></tr>";
                                
                                $kl2 = $tier['kl'] * $frac2;
                                $amt2 = $kl2 * ($rates_p2[$tier['rate_key']] ?? 0);
                                $water_service_subtotal += $amt2;
                                $slip_totals['water_usage'] += $amt2;
                                $d2_str = date('d M', strtotime($water_start_date_2)) . "–" . date('d M Y', strtotime($water_end_date_2));
                                echo "<tr><td>{$tier['label']} — {$d2_str}, {$days_w2}/{$total_w_days} days &mdash; {$display_water_tariff}</td><td class='text-end'>".number_format($kl2, 2)."</td><td class='text-center'>kL</td><td class='text-end'>".number_format(($rates_p2[$tier['rate_key']] ?? 0), 4)."</td><td class='text-end'>".number_format($amt2, 2)."</td></tr>";
                            } else {
                                $amt = $tier['kl'] * ($rates_p1[$tier['rate_key']] ?? 0);
                                $water_service_subtotal += $amt;
                                $slip_totals['water_usage'] += $amt;
                                echo "<tr><td>{$tier['label']} &mdash; {$display_water_tariff}</td><td class='text-end'>".number_format($tier['kl'], 2)."</td><td class='text-center'>kL</td><td class='text-end'>".number_format(($rates_p1[$tier['rate_key']] ?? 0), 4)."</td><td class='text-end'>".number_format($amt, 2)."</td></tr>";
                            }
                        }

                    } else {
                        if ($show_separate_water_p2) {
                            $amt1 = $total_water_kl_1 * ($rates_p1['water'] ?? 0);
                            $water_service_subtotal += $amt1;
                            $slip_totals['water_usage'] += $amt1;
                            $d1_str = date('d M', strtotime($water_start_date)) . "–" . date('d M Y', strtotime($water_end_date));
                            echo "<tr><td>{$municipality} - Water Charge — {$d1_str}</td><td class='text-end'>".number_format($total_water_kl_1, 2)."</td><td class='text-center'>kL</td><td class='text-end'>".number_format(($rates_p1['water'] ?? 0), 4)."</td><td class='text-end'>".number_format($amt1, 2)."</td></tr>";
                            
                            $amt2 = $total_water_kl_2 * ($rates_p2['water'] ?? 0);
                            $water_service_subtotal += $amt2;
                            $slip_totals['water_usage'] += $amt2;
                            $d2_str = date('d M', strtotime($water_start_date_2)) . "–" . date('d M Y', strtotime($water_end_date_2));
                            echo "<tr><td>{$municipality} - Water Charge — {$d2_str}</td><td class='text-end'>".number_format($total_water_kl_2, 2)."</td><td class='text-center'>kL</td><td class='text-end'>".number_format(($rates_p2['water'] ?? 0), 4)."</td><td class='text-end'>".number_format($amt2, 2)."</td></tr>";
                        } else {
                            $amt = $total_water_kl * ($rates_p1['water'] ?? 0);
                            $water_service_subtotal += $amt;
                            $slip_totals['water_usage'] += $amt;
                            echo "<tr><td>{$municipality} - Water Charge</td><td class='text-end'>".number_format($total_water_kl, 2)."</td><td class='text-center'>kL</td><td class='text-end'>".number_format(($rates_p1['water'] ?? 0), 4)."</td><td class='text-end'>".number_format($amt, 2)."</td></tr>";
                        }
                    }
                }
                ?>
                <tr class="table-light text-dark fw-normal">
                    <td colspan="4">Subtotal - Water & Service Charges</td>
                    <td class="text-end"><?php echo number_format($water_service_subtotal, 2); ?></td>
                </tr>

                <?php
                $sewer_subtotal = 0;
                $has_sewer_charges_to_display = false;
                
                if ($has_sewer) {
                    if (stripos($municipality, 'Mkhondo') !== false || stripos($municipality, 'Plett') !== false || stripos($municipality, 'George') !== false) {
                        if ($pays_sewer_basic) $has_sewer_charges_to_display = true;
                    } elseif (empty($rates_p1['is_tiered_water'])) {
                        $has_sewer_charges_to_display = true;
                    }
                }
                if ($pays_water_comm && !$remove_water_comm && $bills_sewer_comm) {
                    $has_sewer_charges_to_display = true;
                }

                if (stripos($municipality, 'Rustenburg') === false && $has_sewer_charges_to_display) {
                    echo "<tr class='table-secondary text-dark fw-normal'><td colspan='5'>Sewer Recovery</td></tr>";
                    
                    if ($has_sewer) {
                        if (stripos($municipality, 'Mkhondo') !== false || stripos($municipality, 'Plett') !== false || stripos($municipality, 'George') !== false) {
                            if ($pays_sewer_basic) {
                                if ($show_separate_water_p2) {
                                    $s_amt1 = ($rates_p1['sewer_basic'] ?? 0) * $w_units_1;
                                    $sewer_subtotal += $s_amt1;
                                    $slip_totals['sewer_basic'] += $s_amt1;
                                    $d1_str = date('d M', strtotime($water_start_date)) . "–" . date('d M Y', strtotime($water_end_date));
                                    echo "<tr><td>Sewer Basic Charge (fixed) — {$d1_str}, {$days_w1}/{$total_w_days} days &mdash; {$sewer_tariff}</td><td class='text-end'>".number_format($w_units_1, 2)."</td><td></td><td class='text-end'>".number_format(($rates_p1['sewer_basic'] ?? 0), 4)."</td><td class='text-end'>".number_format($s_amt1, 2)."</td></tr>";
                                    
                                    $s_amt2 = ($rates_p2['sewer_basic'] ?? 0) * $w_units_2;
                                    $sewer_subtotal += $s_amt2;
                                    $slip_totals['sewer_basic'] += $s_amt2;
                                    $d2_str = date('d M', strtotime($water_start_date_2)) . "–" . date('d M Y', strtotime($water_end_date_2));
                                    echo "<tr><td>Sewer Basic Charge (fixed) — {$d2_str}, {$days_w2}/{$total_w_days} days &mdash; {$sewer_tariff}</td><td class='text-end'>".number_format($w_units_2, 2)."</td><td></td><td class='text-end'>".number_format(($rates_p2['sewer_basic'] ?? 0), 4)."</td><td class='text-end'>".number_format($s_amt2, 2)."</td></tr>";
                                } else {
                                    $s_amt = ($rates_p1['sewer_basic'] ?? 0);
                                    $sewer_subtotal += $s_amt;
                                    $slip_totals['sewer_basic'] += $s_amt;
                                    echo "<tr><td>Sewer Basic Charge (fixed) &mdash; {$sewer_tariff}</td><td class='text-end'>1.00</td><td></td><td class='text-end'>".number_format(($rates_p1['sewer_basic'] ?? 0), 4)."</td><td class='text-end'>".number_format($s_amt, 2)."</td></tr>";
                                }
                            }
                        } elseif (empty($rates_p1['is_tiered_water'])) {
                            if ($show_separate_water_p2) {
                                $s_amt1 = $total_water_kl_1 * ($rates_p1['sewer'] ?? 0);
                                $sewer_subtotal += $s_amt1;
                                $slip_totals['sewer_usage'] += $s_amt1;
                                $d1_str = date('d M', strtotime($water_start_date)) . "–" . date('d M Y', strtotime($water_end_date));
                                echo "<tr><td>Sewer ({$municipality} - Sewer Charge) — {$d1_str}</td><td class='text-end'>".number_format($total_water_kl_1, 2)."</td><td class='text-center'>kL</td><td class='text-end'>".number_format(($rates_p1['sewer'] ?? 0), 4)."</td><td class='text-end'>".number_format($s_amt1, 2)."</td></tr>";
                                
                                $s_amt2 = $total_water_kl_2 * ($rates_p2['sewer'] ?? 0);
                                $sewer_subtotal += $s_amt2;
                                $slip_totals['sewer_usage'] += $s_amt2;
                                $d2_str = date('d M', strtotime($water_start_date_2)) . "–" . date('d M Y', strtotime($water_end_date_2));
                                echo "<tr><td>Sewer ({$municipality} - Sewer Charge) — {$d2_str}</td><td class='text-end'>".number_format($total_water_kl_2, 2)."</td><td class='text-center'>kL</td><td class='text-end'>".number_format(($rates_p2['sewer'] ?? 0), 4)."</td><td class='text-end'>".number_format($s_amt2, 2)."</td></tr>";
                            } else {
                                $s_amt = $total_water_kl * ($rates_p1['sewer'] ?? 0);
                                $sewer_subtotal += $s_amt;
                                $slip_totals['sewer_usage'] += $s_amt;
                                echo "<tr><td>Sewer ({$municipality} - Sewer Charge)</td><td class='text-end'>".number_format($total_water_kl, 2)."</td><td class='text-center'>kL</td><td class='text-end'>".number_format(($rates_p1['sewer'] ?? 0), 4)."</td><td class='text-end'>".number_format($s_amt, 2)."</td></tr>";
                            }
                        }
                    }
                    
                    if ($pays_water_comm && !$remove_water_comm && $bills_sewer_comm) {
                        $comm_kl = $raw_comm_water_kl;
                        if ($water_comm_discount > 0) {
                            $comm_kl = $comm_kl * (1 - ($water_comm_discount / 100));
                        }
                        $comm_s_amt = $comm_kl * ($rates_p1['comm_sewer_kl'] ?? 0);
                        echo "<tr><td>Common Area Sewer Contribution</td><td class='text-end align-middle'>".number_format($comm_kl, 2)."</td><td class='text-center align-middle'>kL</td><td class='text-end align-middle'>".number_format(($rates_p1['comm_sewer_kl'] ?? 0), 4)."</td><td class='text-end align-middle'>".number_format($comm_s_amt, 2)."</td></tr>";
                        $sewer_subtotal += $comm_s_amt;
                        $slip_totals['sewer_comm'] += $comm_s_amt;
                    }

                    echo "<tr class='table-light text-dark fw-normal'><td colspan='4'>Subtotal - Sewer Charges</td><td class='text-end'>".number_format($sewer_subtotal, 2)."</td></tr>";
                }
                
                $comm_water_subtotal = 0;
                if ($pays_water_comm && !$remove_water_comm) {
                    $comm_kl = $raw_comm_water_kl;
                    if ($water_comm_discount > 0) {
                        $comm_kl = $comm_kl * (1 - ($water_comm_discount / 100));
                    }
                    $comm_w_amt = $comm_kl * ($rates_p1['comm_water_kl'] ?? 0);
                    
                    echo "<tr class='table-secondary text-dark fw-normal'><td colspan='5'>Common Area Contributions</td></tr>";
                    echo "<tr><td>Common Area Water Contribution &mdash; {$display_water_tariff}</td><td class='text-end align-middle'>".number_format($comm_kl, 2)."</td><td class='text-center align-middle'>kL</td><td class='text-end align-middle'>".number_format(($rates_p1['comm_water_kl'] ?? 0), 4)."</td><td class='text-end align-middle'>".number_format($comm_w_amt, 2)."</td></tr>";
                    $comm_water_subtotal += $comm_w_amt;
                    $slip_totals['water_comm'] += $comm_w_amt;
                    $slip_totals['comm_kl'] += $comm_kl;
                    echo "<tr class='table-light text-dark fw-normal'><td colspan='4'>Subtotal - Common Area Charges</td><td class='text-end'>".number_format($comm_water_subtotal, 2)."</td></tr>";
                }

                $additional_subtotal = 0;
                if (stripos($municipality, 'Rustenburg') !== false && $has_sewer) {
                    echo "<tr class='table-secondary text-dark fw-normal'><td colspan='5'>Additional / Back Charges</td></tr>";
                    $sewer_desc = "Basic Charge &mdash; {$tenant['tenant_property']} - Commercial Sewer";
                    
                    if ($show_separate_water_p2) {
                        $a_amt1 = ($rates_p1['sewer_basic'] ?? 0) * $w_units_1;
                        $additional_subtotal += $a_amt1;
                        $slip_totals['sewer_additional'] += $a_amt1;
                        echo "<tr><td>{$sewer_desc} — {$d1_str}, {$days_w1}/{$total_w_days} days</td><td class='text-end'>".number_format($w_units_1, 2)."</td><td class='text-center'>kL</td><td class='text-end'>".number_format(($rates_p1['sewer_basic'] ?? 0), 4)."</td><td class='text-end'>".number_format($a_amt1, 2)."</td></tr>";
                        
                        $a_amt2 = ($rates_p2['sewer_basic'] ?? 0) * $w_units_2;
                        $additional_subtotal += $a_amt2;
                        $slip_totals['sewer_additional'] += $a_amt2;
                        echo "<tr><td>{$sewer_desc} — {$d2_str}, {$days_w2}/{$total_w_days} days</td><td class='text-end'>".number_format($w_units_2, 2)."</td><td class='text-center'>kL</td><td class='text-end'>".number_format(($rates_p2['sewer_basic'] ?? 0), 4)."</td><td class='text-end'>".number_format($a_amt2, 2)."</td></tr>";
                    } else {
                        echo "<tr><td>{$sewer_desc}</td><td class='text-end'>1.00</td><td class='text-center'>kL</td><td class='text-end'>".number_format(($rates_p1['sewer_basic'] ?? 0), 4)."</td><td class='text-end'>".number_format(($rates_p1['sewer_basic'] ?? 0), 2)."</td></tr>";
                        $additional_subtotal += ($rates_p1['sewer_basic'] ?? 0);
                        $slip_totals['sewer_additional'] += ($rates_p1['sewer_basic'] ?? 0);
                    }
                    echo "<tr class='table-light text-dark fw-normal'><td colspan='4'>Subtotal - Additional / Back Charges</td><td class='text-end'>".number_format($additional_subtotal, 2)."</td></tr>";
                }
                
                $water_sewer_grand_total = $water_service_subtotal + $sewer_subtotal + $comm_water_subtotal + $additional_subtotal;
                // On an electricity-only slip the water charges are not billed here
                if (($slip_part ?? 'all') !== 'electricity') {
                    $grand_total += $water_sewer_grand_total;
                }
                $slip_totals['water_total'] = $water_sewer_grand_total;
                ?>
                <tr class="table-dark text-white fw-normal" style="background-color: #1a365d;">
                    <td colspan="4" class="text-uppercase fw-normal" style="background-color: #1a365d;">Water & Sewer Subtotal</td>
                    <td class="text-end fw-normal" style="background-color: #1a365d;">R <?php echo number_format($water_sewer_grand_total, 2); ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <?php if ($show_water_graph && !empty($agg_daily_water_graph) && $slip_render_mode !== 'data'): ?>
    <div class="mb-4 border rounded p-3 bg-light shadow-sm d-print-block page-break-inside-avoid">
        <h6 class="text-uppercase text-dark mb-2 text-center pb-1 border-bottom border-secondary fw-normal">Daily Water Consumption</h6>
        <?php if ($slip_render_mode === 'bulk'): ?>
        <div style="height: 120px; width: 100%;">
            <canvas id="waterUsageChart_<?php echo htmlspecialchars($slip_chart_key); ?>" style="width:100%; height:100%;"></canvas>
        </div>
        <?php else: ?>
        <canvas id="waterUsageChart" height="100"></canvas>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php
    $water_section_html = ob_get_clean();
    if (empty($water_meters) && abs($water_sewer_grand_total ?? 0) < 0.005) {
        $water_section_html = '';
        $has_any_water_section = false;
    }
}
?>

<?php echo lumCompanyHeaderHtml(); // Company details: Configurations -> Company Details ?>

<div class="border p-2 mb-3 shadow-sm bg-light info-box">
    <div class="row">
        <div class="col-6">
            <table class="table table-borderless table-sm mb-0">
                <tbody>
                    <tr><th class="text-muted text-nowrap fw-normal" style="width: 35%;">Premises:</th><td class="text-dark"><?php echo htmlspecialchars($tenant['tenant_property'] ?? ''); ?></td></tr>
                    <tr><th class="text-muted text-nowrap fw-normal">Account No:</th><td class="text-dark"><?php echo htmlspecialchars($property_account_number); ?></td></tr>
                    <tr><th class="text-muted text-nowrap fw-normal">Tenant:</th><td class="text-dark"><?php echo htmlspecialchars($tenant['tenant_name'] ?? ''); ?></td></tr>
                    <tr><th class="text-muted text-nowrap fw-normal">Shop No:</th><td class="text-dark"><?php echo htmlspecialchars($tenant['tenant_shop'] ?? ''); ?></td></tr>
                </tbody>
            </table>
        </div>
        <div class="col-6">
            <table class="table table-borderless table-sm mb-0">
                <tbody>
                    <tr><th class="text-muted text-nowrap fw-normal" style="width: 35%;">Billing Period:</th><td class="text-dark"><?php echo date('F Y', strtotime($end_date)); ?></td></tr>
                    
                    <?php if ($has_any_elec_section): ?>
                    <tr><th class="text-muted text-nowrap fw-normal">Elec Period:</th><td class="text-dark"><?php echo date('d/m/Y', strtotime($start_date)); ?> - <?php echo date('d/m/Y', strtotime(empty($end_date_2) ? $end_date : $end_date_2)); ?></td></tr>
                    <?php endif; ?>
                    
                    <?php if ($has_any_water_section): ?>
                    <tr><th class="text-muted text-nowrap fw-normal">Water Period:</th><td class="text-dark"><?php echo date('d/m/Y', strtotime($water_start_date)); ?> - <?php echo date('d/m/Y', strtotime(empty($water_end_date_2) ? $water_end_date : $water_end_date_2)); ?></td></tr>
                    <?php endif; ?>

                    <?php if ($has_any_elec_section): ?>
                    <tr><th class="text-muted text-nowrap fw-normal">Electricity Tariff:</th><td class="text-dark"><?php echo htmlspecialchars($elec_tariff); ?></td></tr>
                    <?php endif; ?>
                    
                    <?php if ($has_any_elec_section && $show_amps): ?>
                    <tr><th class="text-muted text-nowrap fw-normal">Capacity:</th><td class="text-dark"><?php echo number_format((float)($tenant['tenant_amps'] ?? 0), 4); ?> Amps</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php 
// Electrical section
if ($has_any_elec_section): 
    
    $elec_results = [];
    $gen_runtime_used = false; // True when any meter's generator kWh came from the run-time calculation
    $agg_kwh1 = 0; $agg_peak1 = 0; $agg_std1 = 0; $agg_off1 = 0; $agg_gen1 = 0;
    $agg_kwh2 = 0; $agg_peak2 = 0; $agg_std2 = 0; $agg_off2 = 0; $agg_gen2 = 0;
    $agg_kva = 0;

    $t_amps = floatval($tenant['tenant_amps'] ?? 0);

    if (!empty($valid_elec_meters)) {
        foreach($valid_elec_meters as $meter_id => $ct_ratio) {
            
            // Resolve timelines (returns 1 record for normal periods, 2 for crossovers)
            $timelines_p1 = resolveMeterTimelines($tenant_db_conn, $obis_db_conn, $tenant, $meter_id, $start_date, $end_date, $tenant_obis_code);
            $rdg1 = ['open' => 0, 'close' => 0, 'kwh' => 0, 'peak' => 0, 'std' => 0, 'off' => 0, 'kva' => 0, 'daily' => [], 'applied_ct' => $ct_ratio, 'splits' => []];
            $is_first = true;
            foreach ($timelines_p1 as $timeline) {
                $split_rdg = getSlipElecReadings(
                    $obis_db_conn, $manual_db_conn, $timeline['serial'], $timeline['start'], $timeline['end'], 
                    $tenant_obis_code, $tou_algorithm, $public_holidays, $season_1, 
                    $ct_ratio, $t_amps, $manual_override_col, 
                    $use_estimated_readings, $timeline['occ_start'], $timeline['occ_end'], $tenant_db_conn, $tenant, ($elec_meter_slots[$meter_id] ?? 1)
                );
                if ($split_rdg) {
                    $split_rdg['serial'] = $timeline['serial'];
                    $rdg1['splits'][] = $split_rdg;
                    if ($is_first) { $rdg1['open'] = $split_rdg['open']; $is_first = false; }
                    $rdg1['close'] = $split_rdg['close'];
                    $rdg1['kwh'] += $split_rdg['kwh'];
                    $rdg1['peak'] += $split_rdg['peak'];
                    $rdg1['std'] += $split_rdg['std'];
                    $rdg1['off'] += $split_rdg['off'];
                    $rdg1['kva'] = max($rdg1['kva'], $split_rdg['kva']);
                    $rdg1['applied_ct'] = $split_rdg['applied_ct'];
                    if (!empty($split_rdg['ct_step'])) { $rdg1['ct_step'] = $split_rdg['ct_step']; $rdg1['ct_step_mode'] = $split_rdg['ct_step_mode']; $rdg1['ct_setting'] = $split_rdg['ct_setting']; }
                    if (!empty($split_rdg['daily'])) {
                        foreach ($split_rdg['daily'] as $d => $v) {
                            if (!isset($rdg1['daily'][$d])) $rdg1['daily'][$d] = ['total'=>0,'peak'=>0,'std'=>0,'off'=>0];
                            $rdg1['daily'][$d]['total'] += $v['total'];
                            $rdg1['daily'][$d]['peak'] += $v['peak'];
                            $rdg1['daily'][$d]['std'] += $v['std'];
                            $rdg1['daily'][$d]['off'] += $v['off'];
                        }
                    }
                }
            }

            $rdg2 = null;
            if ($has_period_2) {
                $timelines_p2 = resolveMeterTimelines($tenant_db_conn, $obis_db_conn, $tenant, $meter_id, $start_date_2, $end_date_2, $tenant_obis_code);
                $rdg2 = ['open' => 0, 'close' => 0, 'kwh' => 0, 'peak' => 0, 'std' => 0, 'off' => 0, 'kva' => 0, 'daily' => [], 'applied_ct' => $ct_ratio, 'splits' => []];
                $is_first = true;
                foreach ($timelines_p2 as $timeline) {
                    $split_rdg = getSlipElecReadings(
                        $obis_db_conn, $manual_db_conn, $timeline['serial'], $timeline['start'], $timeline['end'], 
                        $tenant_obis_code, $tou_algorithm, $public_holidays, $season_2, 
                        $ct_ratio, $t_amps, $manual_override_col, 
                        $use_estimated_readings, $timeline['occ_start'], $timeline['occ_end'], $tenant_db_conn, $tenant, ($elec_meter_slots[$meter_id] ?? 1)
                    );
                    if ($split_rdg) {
                        $split_rdg['serial'] = $timeline['serial'];
                        $rdg2['splits'][] = $split_rdg;
                        if ($is_first) { $rdg2['open'] = $split_rdg['open']; $is_first = false; }
                        $rdg2['close'] = $split_rdg['close'];
                        $rdg2['kwh'] += $split_rdg['kwh'];
                        $rdg2['peak'] += $split_rdg['peak'];
                        $rdg2['std'] += $split_rdg['std'];
                        $rdg2['off'] += $split_rdg['off'];
                        $rdg2['kva'] = max($rdg2['kva'], $split_rdg['kva']);
                        $rdg2['applied_ct'] = $split_rdg['applied_ct'];
                        if (!empty($split_rdg['ct_step'])) { $rdg2['ct_step'] = $split_rdg['ct_step']; $rdg2['ct_step_mode'] = $split_rdg['ct_step_mode']; $rdg2['ct_setting'] = $split_rdg['ct_setting']; }
                        if (!empty($split_rdg['daily'])) {
                            foreach ($split_rdg['daily'] as $d => $v) {
                                if (!isset($rdg2['daily'][$d])) $rdg2['daily'][$d] = ['total'=>0,'peak'=>0,'std'=>0,'off'=>0];
                                $rdg2['daily'][$d]['total'] += $v['total'];
                                $rdg2['daily'][$d]['peak'] += $v['peak'];
                                $rdg2['daily'][$d]['std'] += $v['std'];
                                $rdg2['daily'][$d]['off'] += $v['off'];
                            }
                        }
                    }
                }
            }

            $rdg_gen1 = null;
            $rdg_gen2 = null;

            $meter_gen_runtime = false;
            if ($has_generator) {
                // Generator (T2) source order:
                // 1. Manual T2 extract (when selected); never replaced by estimated readings.
                // 2. No complete manual T2 readings: run-time calculation when selected, or when a manual T2
                //    extract was selected for the report.
                // 3. Otherwise the 1.1.1.8.2 meter reading (estimated readings may fill gaps).
                $gen_manual_complete = false;
                if ($manual_override_col_t2 !== 'none') {
                    $gen_manual_complete = true;
                    $timelines_gen1 = resolveMeterTimelines($tenant_db_conn, $obis_db_conn, $tenant, $meter_id, $start_date, $end_date, '1.1.1.8.2');
                    $rdg_gen1 = ['open' => 0, 'close' => 0, 'kwh' => 0, 'peak' => 0, 'std' => 0, 'off' => 0, 'kva' => 0, 'daily' => [], 'splits' => []];
                    $is_first = true;
                    foreach ($timelines_gen1 as $timeline) {
                        $split_rdg = getSlipElecReadings(
                            $obis_db_conn, $manual_db_conn, $timeline['serial'], $timeline['start'], $timeline['end'], 
                            '1.1.1.8.2', 'None', $public_holidays, $season_1, 
                            $ct_ratio, $t_amps, $manual_override_col_t2, 
                            false, $timeline['occ_start'], $timeline['occ_end'], $tenant_db_conn, $tenant, ($elec_meter_slots[$meter_id] ?? 1)
                        );
                        if (($split_rdg['source'] ?? '') !== 'manual') $gen_manual_complete = false;
                        if ($split_rdg) {
                            $split_rdg['serial'] = $timeline['serial'];
                            $rdg_gen1['splits'][] = $split_rdg;
                            if ($is_first) { $rdg_gen1['open'] = $split_rdg['open']; $is_first = false; }
                            $rdg_gen1['close'] = $split_rdg['close'];
                            $rdg_gen1['kwh'] += $split_rdg['kwh'];
                        }
                    }

                    if ($has_period_2) {
                        $timelines_gen2 = resolveMeterTimelines($tenant_db_conn, $obis_db_conn, $tenant, $meter_id, $start_date_2, $end_date_2, '1.1.1.8.2');
                        $rdg_gen2 = ['open' => 0, 'close' => 0, 'kwh' => 0, 'peak' => 0, 'std' => 0, 'off' => 0, 'kva' => 0, 'daily' => [], 'splits' => []];
                        $is_first = true;
                        foreach ($timelines_gen2 as $timeline) {
                            $split_rdg = getSlipElecReadings(
                                $obis_db_conn, $manual_db_conn, $timeline['serial'], $timeline['start'], $timeline['end'], 
                                '1.1.1.8.2', 'None', $public_holidays, $season_2, 
                                $ct_ratio, $t_amps, $manual_override_col_t2, 
                                false, $timeline['occ_start'], $timeline['occ_end'], $tenant_db_conn, $tenant, ($elec_meter_slots[$meter_id] ?? 1)
                            );
                            if (($split_rdg['source'] ?? '') !== 'manual') $gen_manual_complete = false;
                            if ($split_rdg) {
                                $split_rdg['serial'] = $timeline['serial'];
                                $rdg_gen2['splits'][] = $split_rdg;
                                if ($is_first) { $rdg_gen2['open'] = $split_rdg['open']; $is_first = false; }
                                $rdg_gen2['close'] = $split_rdg['close'];
                                $rdg_gen2['kwh'] += $split_rdg['kwh'];
                            }
                        }
                    }
                    if (!$gen_manual_complete) {
                        $rdg_gen1 = null;
                        $rdg_gen2 = null;
                    }
                }

                if (!$gen_manual_complete) {
                    if ($gen_runtime_available) {
                        $meter_gen_runtime = true;
                        $gen_runtime_used = true;
                        $timelines_gen1 = resolveMeterTimelines($tenant_db_conn, $obis_db_conn, $tenant, $meter_id, $gen_start_date, $gen_end_date, $t2_base_obis);
                        $base_rdg1 = ['open' => 0, 'close' => 0, 'kwh' => 0];
                        foreach ($timelines_gen1 as $timeline) {
                            $split_rdg = getSlipElecReadings($obis_db_conn, $manual_db_conn, $timeline['serial'], $timeline['start'], $timeline['end'], $t2_base_obis, 'None', $public_holidays, $season_1, $ct_ratio, $t_amps, 'none', $use_estimated_readings, $timeline['occ_start'], $timeline['occ_end'], $tenant_db_conn, $tenant, ($elec_meter_slots[$meter_id] ?? 1));
                            if ($split_rdg) {
                                $base_rdg1['kwh'] += $split_rdg['kwh'];
                            }
                        }
                        $days1 = max(1, (new DateTime($gen_start_date))->diff(new DateTime($gen_end_date))->days + 1);
                        $daily_avg1 = ($base_rdg1['kwh'] ?? 0) / $days1;
                        $gen_kwh1 = ($daily_avg1 / 24) * $gen_hours_1;
                        $rdg_gen1 = ['open' => 0, 'close' => $gen_kwh1, 'kwh' => $gen_kwh1, 'peak' => 0, 'std' => 0, 'off' => 0, 'splits' => []];

                        if ($has_period_2) {
                            $timelines_gen2 = resolveMeterTimelines($tenant_db_conn, $obis_db_conn, $tenant, $meter_id, $start_date_2, $end_date_2, $t2_base_obis);
                            $base_rdg2 = ['open' => 0, 'close' => 0, 'kwh' => 0];
                            foreach ($timelines_gen2 as $timeline) {
                                $split_rdg = getSlipElecReadings($obis_db_conn, $manual_db_conn, $timeline['serial'], $timeline['start'], $timeline['end'], $t2_base_obis, 'None', $public_holidays, $season_2, $ct_ratio, $t_amps, 'none', $use_estimated_readings, $timeline['occ_start'], $timeline['occ_end'], $tenant_db_conn, $tenant, ($elec_meter_slots[$meter_id] ?? 1));
                                if ($split_rdg) {
                                    $base_rdg2['kwh'] += $split_rdg['kwh'];
                                }
                            }
                            $days2 = max(1, (new DateTime($start_date_2))->diff(new DateTime($end_date_2))->days + 1);
                            $daily_avg2 = ($base_rdg2['kwh'] ?? 0) / $days2;
                            $gen_kwh2 = ($daily_avg2 / 24) * $gen_hours_2;
                            $rdg_gen2 = ['open' => 0, 'close' => $gen_kwh2, 'kwh' => $gen_kwh2, 'peak' => 0, 'std' => 0, 'off' => 0, 'splits' => []];
                        }
                    } else {
                        $timelines_gen1 = resolveMeterTimelines($tenant_db_conn, $obis_db_conn, $tenant, $meter_id, $gen_start_date, $gen_end_date, '1.1.1.8.2');
                        $rdg_gen1 = ['open' => 0, 'close' => 0, 'kwh' => 0, 'peak' => 0, 'std' => 0, 'off' => 0, 'kva' => 0, 'daily' => [], 'splits' => []];
                        $is_first = true;
                        foreach ($timelines_gen1 as $timeline) {
                            $split_rdg = getSlipElecReadings(
                                $obis_db_conn, $manual_db_conn, $timeline['serial'], $timeline['start'], $timeline['end'], 
                                '1.1.1.8.2', 'None', $public_holidays, $season_1, 
                                $ct_ratio, $t_amps, 'none', 
                                $use_estimated_readings, $timeline['occ_start'], $timeline['occ_end'], $tenant_db_conn, $tenant, ($elec_meter_slots[$meter_id] ?? 1)
                            );
                            if ($split_rdg) {
                                $split_rdg['serial'] = $timeline['serial'];
                                $rdg_gen1['splits'][] = $split_rdg;
                                if ($is_first) { $rdg_gen1['open'] = $split_rdg['open']; $is_first = false; }
                                $rdg_gen1['close'] = $split_rdg['close'];
                                $rdg_gen1['kwh'] += $split_rdg['kwh'];
                            }
                        }

                        if ($has_period_2) {
                            $timelines_gen2 = resolveMeterTimelines($tenant_db_conn, $obis_db_conn, $tenant, $meter_id, $start_date_2, $end_date_2, '1.1.1.8.2');
                            $rdg_gen2 = ['open' => 0, 'close' => 0, 'kwh' => 0, 'peak' => 0, 'std' => 0, 'off' => 0, 'kva' => 0, 'daily' => [], 'splits' => []];
                            $is_first = true;
                            foreach ($timelines_gen2 as $timeline) {
                                $split_rdg = getSlipElecReadings(
                                    $obis_db_conn, $manual_db_conn, $timeline['serial'], $timeline['start'], $timeline['end'], 
                                    '1.1.1.8.2', 'None', $public_holidays, $season_2, 
                                    $ct_ratio, $t_amps, 'none', 
                                    $use_estimated_readings, $timeline['occ_start'], $timeline['occ_end'], $tenant_db_conn, $tenant, ($elec_meter_slots[$meter_id] ?? 1)
                                );
                                if ($split_rdg) {
                                    $split_rdg['serial'] = $timeline['serial'];
                                    $rdg_gen2['splits'][] = $split_rdg;
                                    if ($is_first) { $rdg_gen2['open'] = $split_rdg['open']; $is_first = false; }
                                    $rdg_gen2['close'] = $split_rdg['close'];
                                    $rdg_gen2['kwh'] += $split_rdg['kwh'];
                                }
                            }
                        }
                    }
                }
            }
            
            // Meter change-over: a Period 2 reading that repeats Period 1 is shown and billed once
            if ($has_period_2) {
                $rdg2 = lumSlipDropRepeatedSplits($rdg1, $rdg2);
                if ($has_generator && empty($meter_gen_runtime)) {
                    $rdg_gen2 = lumSlipDropRepeatedSplits($rdg_gen1, $rdg_gen2);
                }
            }

            // Single meter: the period kWh must add up to the overall register difference
            $distinct_serials = [];
            $all_raw_splits = array_merge($rdg1['splits'] ?? [], $has_period_2 ? ($rdg2['splits'] ?? []) : []);
            foreach ($all_raw_splits as $spl) {
                $s = trim((string)$spl['serial']);
                if (!in_array($s, $distinct_serials)) $distinct_serials[] = $s;
            }
            $has_e_crossover = count($distinct_serials) > 1;

            if (!$has_e_crossover) {
                // Overall difference (the boundary between the periods is skipped)
                $e1_open = isset($rdg1['open']) ? floatval($rdg1['open']) : 0;
                $e_close = ($has_period_2 && isset($rdg2['close']) && $rdg2['close'] > 0) ? floatval($rdg2['close']) : (isset($rdg1['close']) ? floatval($rdg1['close']) : 0);

                $e_overall_diff = max(0, $e_close - $e1_open);
                $e_sum_kwh = ($rdg1['kwh'] ?? 0) + (($has_period_2 && $rdg2) ? $rdg2['kwh'] : 0);

                // Parts that do not add up to the whole: prorate by days
                if ($e_overall_diff > 0 && abs($e_overall_diff - $e_sum_kwh) > 0.01) {
                    $d1 = max(1, (new DateTime($start_date))->diff(new DateTime($end_date))->days + 1);
                    $d2 = $has_period_2 ? max(1, (new DateTime($start_date_2))->diff(new DateTime($end_date_2))->days + 1) : 0;
                    $tot_d = $d1 + $d2;
                    
                    $rdg1['kwh'] = $e_overall_diff * ($d1 / $tot_d);
                    if ($has_period_2 && $rdg2) {
                        $rdg2['kwh'] = $e_overall_diff - $rdg1['kwh'];
                    }
                }
            }

            // Same for the generator (T2)
            $has_g_crossover = false;
            if ($has_generator && $rdg_gen1) {
                $g_distinct_serials = [];
                $g_all_raw_splits = array_merge($rdg_gen1['splits'] ?? [], $has_period_2 ? ($rdg_gen2['splits'] ?? []) : []);
                foreach ($g_all_raw_splits as $spl) {
                    $s = trim((string)$spl['serial']);
                    if (!in_array($s, $g_distinct_serials)) $g_distinct_serials[] = $s;
                }
                $has_g_crossover = count($g_distinct_serials) > 1;
                
                if (!$has_g_crossover) {
                    $g1_open = isset($rdg_gen1['open']) ? floatval($rdg_gen1['open']) : 0;
                    $g_close = ($has_period_2 && isset($rdg_gen2['close']) && $rdg_gen2['close'] > 0) ? floatval($rdg_gen2['close']) : (isset($rdg_gen1['close']) ? floatval($rdg_gen1['close']) : 0);
                    
                    $g_overall_diff = max(0, $g_close - $g1_open);
                    $g_sum_kwh = ($rdg_gen1['kwh'] ?? 0) + (($has_period_2 && $rdg_gen2) ? $rdg_gen2['kwh'] : 0);

                    if ($g_overall_diff > 0 && abs($g_overall_diff - $g_sum_kwh) > 0.01) {
                        $d1 = max(1, (new DateTime($gen_start_date))->diff(new DateTime($gen_end_date))->days + 1);
                        $d2 = $has_period_2 ? max(1, (new DateTime($start_date_2))->diff(new DateTime($end_date_2))->days + 1) : 0;
                        $tot_d = $d1 + $d2;
                        
                        $rdg_gen1['kwh'] = $g_overall_diff * ($d1 / $tot_d);
                        if ($has_period_2 && $rdg_gen2) {
                            $rdg_gen2['kwh'] = $g_overall_diff - $rdg_gen1['kwh'];
                        }
                    }
                }
            }

            if ($rdg1 && $has_generator && $tenant_obis_code === '1.1.1.8.0') {
                $gen1_val = $rdg_gen1['kwh'] ?? 0;
                if ($rdg1['kwh'] >= $gen1_val) {
                    $rdg1['kwh'] -= $gen1_val;
                    $rdg1['close'] -= $gen1_val; 
                }
            }
            
            if ($rdg2 && $has_generator && $tenant_obis_code === '1.1.1.8.0') {
                $gen2_val = $rdg_gen2['kwh'] ?? 0;
                if ($rdg2['kwh'] >= $gen2_val) {
                    $rdg2['kwh'] -= $gen2_val;
                    $rdg2['close'] -= $gen2_val; 
                }
            }

            if ($rdg1) {
                $agg_kwh1 += $rdg1['kwh'];
                $agg_peak1 += $rdg1['peak'];
                $agg_std1 += $rdg1['std'];
                $agg_off1 += $rdg1['off'];
                $agg_kva += $rdg1['kva'];
                if ($has_generator) $agg_gen1 += $rdg_gen1['kwh'];
                
                if (!empty($rdg1['daily'])) {
                    foreach ($rdg1['daily'] as $d => $v) {
                        if (!isset($agg_daily_graph[$d])) $agg_daily_graph[$d] = ['total'=>0,'peak'=>0,'std'=>0,'off'=>0];
                        $agg_daily_graph[$d]['total'] += $v['total'];
                        $agg_daily_graph[$d]['peak'] += $v['peak'];
                        $agg_daily_graph[$d]['std'] += $v['std'];
                        $agg_daily_graph[$d]['off'] += $v['off'];
                    }
                }
            }
            if ($rdg2) {
                $agg_kwh2 += $rdg2['kwh'];
                $agg_peak2 += $rdg2['peak'];
                $agg_std2 += $rdg2['std'];
                $agg_off2 += $rdg2['off'];
                if ($has_generator) $agg_gen2 += $rdg_gen2['kwh'];
                
                if (!empty($rdg2['daily'])) {
                    foreach ($rdg2['daily'] as $d => $v) {
                        if (!isset($agg_daily_graph[$d])) $agg_daily_graph[$d] = ['total'=>0,'peak'=>0,'std'=>0,'off'=>0];
                        $agg_daily_graph[$d]['total'] += $v['total'];
                        $agg_daily_graph[$d]['peak'] += $v['peak'];
                        $agg_daily_graph[$d]['std'] += $v['std'];
                        $agg_daily_graph[$d]['off'] += $v['off'];
                    }
                }
            }

            $elec_results[] = [
                'id' => $meter_id,
                'rdg1' => $rdg1,
                'rdg2' => $rdg2,
                'gen1' => $rdg_gen1,
                'gen2' => $rdg_gen2,
                'total_kwh' => ($rdg1['kwh'] ?? 0) + ($rdg2['kwh'] ?? 0),
                'total_gen' => ($rdg_gen1['kwh'] ?? 0) + ($rdg_gen2['kwh'] ?? 0),
                'applied_ct' => $rdg1['applied_ct'] ?? $ct_ratio,
                'ct_step' => $rdg1['ct_step'] ?? ($rdg2['ct_step'] ?? null),
                'ct_step_mode' => $rdg1['ct_step_mode'] ?? ($rdg2['ct_step_mode'] ?? null),
                'ct_setting' => $ct_ratio,
                'gen_runtime' => $meter_gen_runtime
            ];
        }
    }

    if ($tou_algorithm !== 'None') {
        $t_sum1 = $agg_peak1 + $agg_std1 + $agg_off1;
        if ($t_sum1 > 0 && abs($t_sum1 - $agg_kwh1) > 0.001) {
            $rat1 = $agg_kwh1 / $t_sum1;
            $agg_peak1 = round($agg_peak1 * $rat1, 2);
            $agg_std1 = round($agg_std1 * $rat1, 2);
            $agg_off1 = round($agg_kwh1 - $agg_peak1 - $agg_std1, 2);
        }
        if ($has_period_2) {
            $t_sum2 = $agg_peak2 + $agg_std2 + $agg_off2;
            if ($t_sum2 > 0 && abs($t_sum2 - $agg_kwh2) > 0.001) {
                $rat2 = $agg_kwh2 / $t_sum2;
                $agg_peak2 = round($agg_peak2 * $rat2, 2);
                $agg_std2 = round($agg_std2 * $rat2, 2);
                $agg_off2 = round($agg_kwh2 - $agg_peak2 - $agg_std2, 2);
            }
        }
    }

    if ($has_period_2 && !$show_separate_p2_charges) {
        $agg_kwh1 += $agg_kwh2;
        $agg_peak1 += $agg_peak2;
        $agg_std1 += $agg_std2;
        $agg_off1 += $agg_off2;
    }

    $slip_totals['kwh'] = $agg_kwh1 + ($show_separate_p2_charges ? $agg_kwh2 : 0);

    $r_peak = ($agg_kwh1 > 0) ? ($agg_peak1 / $agg_kwh1) : 0;
    $r_std = ($agg_kwh1 > 0) ? ($agg_std1 / $agg_kwh1) : 0;
    $r_off = ($agg_kwh1 > 0) ? ($agg_off1 / $agg_kwh1) : 0;

    $total_gen_all = $agg_gen1 + $agg_gen2;
    $show_gen_slip = $has_generator; 
    $elec_subtotal = 0;
?>
<?php if (($slip_part ?? 'all') !== 'all'): ?>
<div class="alert alert-secondary py-1 px-3 mb-3 fs-6">
    This slip covers <strong><?php echo $slip_part === 'water' ? 'water and sewer' : 'electricity'; ?></strong> only.
</div>
<?php endif; ?>

<?php if (($slip_part ?? 'all') !== 'water'): // left out of a water-only slip ?>
<div class="mb-4">
    <div class="d-flex justify-content-between align-items-end mb-2">
        <h4 class="border-bottom border-secondary pb-1 text-dark text-uppercase fw-normal m-0 w-100">Electricity</h4>
        <?php if ($using_manual_override): ?>
            <span class="badge bg-warning text-dark ms-2 fw-normal" style="font-size: 0.75rem;">Manual Override</span>
        <?php endif; ?>
    </div>
    
    <?php foreach($elec_results as $res): ?>
    <div class="table-responsive mb-2">
        <table class="table table-bordered table-sm align-middle">
            <thead class="table-dark">
                <tr>
                    <th colspan="<?php echo $show_gen_slip ? '4' : '3'; ?>" class="text-uppercase align-middle fw-normal">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>ELECTRICITY — METER READINGS <?php echo (!empty($res['id'])) ? '('.htmlspecialchars($res['id']).')' : ''; ?> <?php if(isset($res['applied_ct']) && $res['applied_ct'] > 1.0) echo '(CT: '.number_format($res['applied_ct'], 2).')'; ?></span>
                        </div>
                        <?php if (!empty($res['ct_step'])): ?>
                        <div class="small fw-normal text-white-50" style="text-transform: none;">
                            <?php if ($res['ct_step_mode'] === 'before'): ?>
                                Meter records multiplied kWh since <?php echo date('d/m/Y H:i', strtotime($res['ct_step'])); ?> &mdash; CT <?php echo number_format($res['ct_setting'], 2); ?> not applied again.
                            <?php else: ?>
                                CT <?php echo number_format($res['ct_setting'], 2); ?> applied up to <?php echo date('d/m/Y H:i', strtotime($res['ct_step'])); ?> only (meter records multiplied kWh after this).
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </th>
                </tr>
                <tr class="table-secondary text-dark fw-normal">
                    <th class="fw-normal">Description</th>
                    <th class="text-end fw-normal">T1 - Normal (kWh)</th>
                    <?php if ($show_gen_slip): ?><th class="text-end fw-normal">T2 - Emergency (kWh)</th><?php endif; ?>
                    <th class="text-end fw-normal">kVA</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $t1_splits = array_merge($res['rdg1']['splits'] ?? [], $has_period_2 ? ($res['rdg2']['splits'] ?? []) : []);
                $t2_splits = array_merge($res['gen1']['splits'] ?? [], $has_period_2 ? ($res['gen2']['splits'] ?? []) : []);
                
                // Consolidate splits if there is no physical meter crossover
                $meter_has_e_crossover = (isset($res['rdg1']['splits']) && count($res['rdg1']['splits']) > 1) || ($has_period_2 && isset($res['rdg2']['splits']) && count($res['rdg2']['splits']) > 1);

                if (!$meter_has_e_crossover && !empty($t1_splits)) {
                    $e_close = ($has_period_2 && isset($res['rdg2']['close']) && $res['rdg2']['close'] > 0) ? floatval($res['rdg2']['close']) : (isset($res['rdg1']['close']) ? floatval($res['rdg1']['close']) : 0);
                    $t1_splits = [[
                        'serial' => $t1_splits[0]['serial'] ?? '',
                        'open' => isset($res['rdg1']['open']) ? floatval($res['rdg1']['open']) : 0,
                        'close' => $e_close
                    ]];
                }

                // Apply the same consolidation to the Generator (T2)
                $meter_has_g_crossover = (isset($res['gen1']['splits']) && count($res['gen1']['splits']) > 1) || ($has_period_2 && isset($res['gen2']['splits']) && count($res['gen2']['splits']) > 1);

                if ($show_gen_slip && !$meter_has_g_crossover && !empty($t2_splits)) {
                    $g_close = ($has_period_2 && isset($res['gen2']['close']) && $res['gen2']['close'] > 0) ? floatval($res['gen2']['close']) : (isset($res['gen1']['close']) ? floatval($res['gen1']['close']) : 0);
                    $t2_splits = [[
                        'serial' => $t2_splits[0]['serial'] ?? '',
                        'open' => isset($res['gen1']['open']) ? floatval($res['gen1']['open']) : 0,
                        'close' => $g_close
                    ]];
                }

                $max_splits = max(count($t1_splits), count($t2_splits));
                if ($max_splits === 0) $max_splits = 1;

                for ($i = 0; $i < $max_splits; $i++) {
                    $s1 = $t1_splits[$i] ?? null;
                    $s2 = $t2_splits[$i] ?? null;
                    
                    $serial_label = "";
                    if ($s1 && isset($s1['serial']) && count($t1_splits) > 1) {
                        $serial_label = " (Meter: " . htmlspecialchars($s1['serial']) . ")";
                    } elseif ($s2 && isset($s2['serial']) && count($t2_splits) > 1) {
                        $serial_label = " (Meter: " . htmlspecialchars($s2['serial']) . ")";
                    }

                    $s1_open = $s1 ? number_format($s1['open'], 2, '.', '') : '0.00';
                    $s1_close = $s1 ? number_format($s1['close'], 2, '.', '') : '0.00';
                    
                    $s2_open = '-';
                    $s2_close = '-';
                    if ($show_gen_slip) {
                        if (empty($res['gen_runtime'])) {
                            $s2_open = $s2 ? number_format($s2['open'], 2, '.', '') : '0.00';
                            $s2_close = $s2 ? number_format($s2['close'], 2, '.', '') : '0.00';
                        }
                    }

                    echo "<tr>";
                    echo "<td>Opening Reading" . $serial_label . "</td>";
                    echo "<td class='text-end'>" . $s1_open . "</td>";
                    if ($show_gen_slip) echo "<td class='text-end'>" . $s2_open . "</td>";
                    echo "<td class='text-end'>0.00</td>";
                    echo "</tr>";

                    echo "<tr>";
                    echo "<td>Closing Reading" . $serial_label . "</td>";
                    echo "<td class='text-end'>" . $s1_close . "</td>";
                    if ($show_gen_slip) echo "<td class='text-end'>" . $s2_close . "</td>";
                    echo "<td class='text-end'>0.00</td>";
                    echo "</tr>";
                }
                ?>
                <tr class="table-light text-dark fw-normal">
                    <td>Total Consumption</td>
                    <td class="text-end"><?php echo number_format($res['total_kwh'] ?? 0, 2, '.', ''); ?> kWh</td>
                    <?php if ($show_gen_slip): ?><td class="text-end"><?php echo number_format($res['total_gen'] ?? 0, 2, '.', ''); ?> kWh</td><?php endif; ?>
                    <td class="text-end"><?php echo number_format($res['rdg1']['kva'] ?? 0, 2, '.', ''); ?> kVA</td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>

    <div class="table-responsive mb-3">
        <table class="table table-bordered table-sm align-middle">
            <thead class="table-dark">
                <tr><th colspan="5" class="text-uppercase fw-normal">ELECTRICITY — CHARGE BREAKDOWN</th></tr>
                <tr class="table-secondary text-dark fw-normal">
                    <th width="45%" class="fw-normal">Description</th>
                    <th class="text-end fw-normal">Units</th>
                    <th class="text-center fw-normal">Unit</th>
                    <th class="text-end fw-normal">Rate (R/unit)</th>
                    <th class="text-end fw-normal">Amount (R)</th>
                </tr>
            </thead>
            <tbody>
                <tr class="text-muted">
                    <td>Tariff applied: <?php echo htmlspecialchars($elec_tariff); ?></td>
                    <td class="text-end">0.00</td><td></td><td class="text-end">-</td><td class="text-end">-</td>
                </tr>
                <?php if ($show_gen_slip): ?>
                <tr class="text-muted">
                    <td>Generator tariff applied: <?php echo htmlspecialchars($gen_tariff); ?></td>
                    <td class="text-end">0.00</td><td></td><td class="text-end">-</td><td class="text-end">-</td>
                </tr>
                <?php endif; ?>
                
                <?php
                $dt_s1 = new DateTime($start_date);
                $dt_e1 = new DateTime($end_date);
                $days_p1 = max(1, $dt_s1->diff($dt_e1)->days + 1);
                
                if ($has_period_2) {
                    $dt_s2 = new DateTime($start_date_2);
                    $dt_e2 = new DateTime($end_date_2);
                    $days_p2 = max(1, $dt_s2->diff($dt_e2)->days + 1);
                    $total_days = $days_p1 + $days_p2;
                } else {
                    $total_days = $days_p1;
                }
                
                if ($show_separate_p2_charges) {
                    $basic_units_1 = round($days_p1 / $total_days, 4);
                    $basic_units_2 = round($days_p2 / $total_days, 4);
                    $str_s1 = $dt_s1->format('d M');
                    $str_e1 = $dt_e1->format('d M Y');
                    $str_s2 = $dt_s2->format('d M');
                    $str_e2 = $dt_e2->format('d M Y');
                    
                    $basic_desc_1 = "Basic Charge &mdash; {$str_s1}&ndash;{$str_e1}, {$days_p1}/{$total_days} days &mdash; {$elec_tariff}";
                    $basic_desc_2 = "Basic Charge &mdash; {$str_s2}&ndash;{$str_e2}, {$days_p2}/{$total_days} days &mdash; {$elec_tariff}";
                } else {
                    $basic_units_1 = 1.00;
                    if ($has_period_2) {
                        $str_s1 = $dt_s1->format('d M');
                        $str_e2 = $dt_e2->format('d M Y');
                        $basic_desc_1 = "Basic Charge &mdash; {$str_s1}&ndash;{$str_e2}, {$total_days} days &mdash; {$elec_tariff}";
                    } else {
                        $basic_desc_1 = "Basic Charge &mdash; {$elec_tariff}";
                    }
                }
                
                $calculated_basic_1 = 0;
                $energy_subtotal = 0;
                if ($pays_elec_basic) {
                    $calculated_basic_1 = ($rates_p1['basic'] ?? 0) * $basic_units_1;
                    $energy_subtotal += $calculated_basic_1;
                    $slip_totals['basic'] += $calculated_basic_1;
                    echo "<tr><td>{$basic_desc_1}</td><td class='text-end'>".number_format($basic_units_1, 2)."</td><td></td><td class='text-end'>".number_format(($rates_p1['basic'] ?? 0), 4)."</td><td class='text-end'>".number_format($calculated_basic_1, 2)."</td></tr>";
                }

                if ($tou_algorithm !== 'None') {
                    if ($pays_elec_unit) {
                        $p_amt = $agg_peak1 * ($rates_p1['kwh_peak'] ?? 0);
                        $s_amt = $agg_std1 * ($rates_p1['kwh_std'] ?? 0);
                        $o_amt = $agg_off1 * ($rates_p1['kwh_off'] ?? 0);
                        $energy_subtotal += ($p_amt + $s_amt + $o_amt);
                        $slip_totals['energy'] += ($p_amt + $s_amt + $o_amt);
                        
                        echo "<tr><td>Peak ({$season_1} Demand) — {$elec_tariff}</td><td class='text-end'>".number_format($agg_peak1, 2)."</td><td class='text-center'>kWh</td><td class='text-end'>".number_format(($rates_p1['kwh_peak'] ?? 0), 4)."</td><td class='text-end'>".number_format($p_amt, 2)."</td></tr>";
                        echo "<tr><td>Standard ({$season_1} Demand) — {$elec_tariff}</td><td class='text-end'>".number_format($agg_std1, 2)."</td><td class='text-center'>kWh</td><td class='text-end'>".number_format(($rates_p1['kwh_std'] ?? 0), 4)."</td><td class='text-end'>".number_format($s_amt, 2)."</td></tr>";
                        echo "<tr><td>Off Peak ({$season_1} Demand) — {$elec_tariff}</td><td class='text-end'>".number_format($agg_off1, 2)."</td><td class='text-center'>kWh</td><td class='text-end'>".number_format(($rates_p1['kwh_off'] ?? 0), 4)."</td><td class='text-end'>".number_format($o_amt, 2)."</td></tr>";
                    }
                } else {
                    if ($pays_elec_unit) {
                        $e_amt1 = $agg_kwh1 * ($rates_p1['kwh_std'] ?? 0);
                        $energy_subtotal += $e_amt1;
                        $slip_totals['energy'] += $e_amt1;
                        
                        if ($show_separate_p2_charges) {
                            $unit_desc1 = "Energy charge per kWh &mdash; {$str_s1}&ndash;{$str_e1}, {$days_p1}/{$total_days} days &mdash; {$elec_tariff}";
                        } else {
                            if ($has_period_2) {
                                $unit_desc1 = "Energy charge per kWh &mdash; {$str_s1}&ndash;{$str_e2}, {$total_days} days &mdash; {$elec_tariff}";
                            } else {
                                $unit_desc1 = (stripos($elec_tariff, 'Three Phase Conventional') !== false) ? $elec_tariff : "{$elec_tariff} - kWh ({$season_1} Demand)";
                                if (stripos($elec_tariff, 'George - General Consumers') !== false || stripos($municipality, 'George') !== false) {
                                    $unit_desc1 = "Energy Charges &mdash; George - General Consumers";
                                }
                            }
                        }
                        echo "<tr><td>{$unit_desc1}</td><td class='text-end'>".number_format($agg_kwh1, 2)."</td><td class='text-center'>kWh</td><td class='text-end'>".number_format(($rates_p1['kwh_std'] ?? 0), 4)."</td><td class='text-end'>".number_format($e_amt1, 2)."</td></tr>";
                    }
                }

                if ($show_separate_p2_charges) {
                    $switch_over_text = 'High Demand Season / Tariff Switch Over';
                    echo "<tr class='table-secondary text-dark fw-normal'><td colspan='5' class='text-uppercase'>{$switch_over_text}</td></tr>";
                    
                    $calculated_basic_2 = 0;
                    if ($pays_elec_basic) {
                        $calculated_basic_2 = ($rates_p2['basic'] ?? 0) * $basic_units_2;
                        $energy_subtotal += $calculated_basic_2;
                        $slip_totals['basic'] += $calculated_basic_2;
                        echo "<tr><td>{$basic_desc_2}</td><td class='text-end'>".number_format($basic_units_2, 2)."</td><td></td><td class='text-end'>".number_format(($rates_p2['basic'] ?? 0), 4)."</td><td class='text-end'>".number_format($calculated_basic_2, 2)."</td></tr>";
                    }

                    if ($tou_algorithm !== 'None') {
                        if ($pays_elec_unit) {
                            $p_amt2 = $agg_peak2 * ($rates_p2['kwh_peak'] ?? 0);
                            $s_amt2 = $agg_std2 * ($rates_p2['kwh_std'] ?? 0);
                            $o_amt2 = $agg_off2 * ($rates_p2['kwh_off'] ?? 0);
                            $energy_subtotal += ($p_amt2 + $s_amt2 + $o_amt2);
                            $slip_totals['energy'] += ($p_amt2 + $s_amt2 + $o_amt2);
                            
                            echo "<tr><td>Peak ({$season_2} Demand) — {$elec_tariff}</td><td class='text-end'>".number_format($agg_peak2, 2)."</td><td class='text-center'>kWh</td><td class='text-end'>".number_format(($rates_p2['kwh_peak'] ?? 0), 4)."</td><td class='text-end'>".number_format($p_amt2, 2)."</td></tr>";
                            echo "<tr><td>Standard ({$season_2} Demand) — {$elec_tariff}</td><td class='text-end'>".number_format($agg_std2, 2)."</td><td class='text-center'>kWh</td><td class='text-end'>".number_format(($rates_p2['kwh_std'] ?? 0), 4)."</td><td class='text-end'>".number_format($s_amt2, 2)."</td></tr>";
                            echo "<tr><td>Off Peak ({$season_2} Demand) — {$elec_tariff}</td><td class='text-end'>".number_format($agg_off2, 2)."</td><td class='text-center'>kWh</td><td class='text-end'>".number_format(($rates_p2['kwh_off'] ?? 0), 4)."</td><td class='text-end'>".number_format($o_amt2, 2)."</td></tr>";
                        }
                    } else {
                        if ($pays_elec_unit) {
                            $e_amt2 = $agg_kwh2 * ($rates_p2['kwh_std'] ?? 0);
                            $energy_subtotal += $e_amt2;
                            $slip_totals['energy'] += $e_amt2;
                            $unit_desc2 = "Energy charge per kWh &mdash; {$str_s2}&ndash;{$str_e2}, {$days_p2}/{$total_days} days &mdash; {$elec_tariff}";
                            echo "<tr><td>{$unit_desc2}</td><td class='text-end'>".number_format($agg_kwh2, 2)."</td><td class='text-center'>kWh</td><td class='text-end'>".number_format(($rates_p2['kwh_std'] ?? 0), 4)."</td><td class='text-end'>".number_format($e_amt2, 2)."</td></tr>";
                        }
                    }
                }

                $gen_amt = 0;
                if ($show_gen_slip && $pays_gen_unit) {
                    $gen_amt1 = $agg_gen1 * ($rates_p1['generator'] ?? 0);
                    $gen_amt2 = $has_period_2 ? ($agg_gen2 * ($rates_p2['generator'] ?? 0)) : 0;
                    $gen_amt = $gen_amt1 + $gen_amt2;
                    $energy_subtotal += $gen_amt;
                    $slip_totals['generator'] += $gen_amt;
                    
                    $avg_gen_rate = ($total_gen_all > 0) ? ($gen_amt / $total_gen_all) : ($rates_p1['generator'] ?? 0);
                    $gen_desc = $gen_runtime_used
                        ? "Generator (Run Time: " . ($gen_hours_1 + $gen_hours_2) . " hrs) &mdash; {$gen_tariff}"
                        : "Generator / Emergency Supply &mdash; {$gen_tariff}";

                    echo "<tr><td>{$gen_desc}</td><td class='text-end'>".number_format($total_gen_all, 2)."</td><td class='text-center'>kWh</td><td class='text-end'>".number_format($avg_gen_rate, 4)."</td><td class='text-end'>".number_format($gen_amt, 2)."</td></tr>";
                }

                $elec_subtotal = $energy_subtotal;
                echo "<tr class='table-light text-dark fw-normal'><td colspan='4'>Subtotal - Energy & Service Charges</td><td class='text-end'>".number_format($energy_subtotal, 2)."</td></tr>";

                $is_ekur_tou_or_c = ((stripos($municipality, 'Ekhurhuleni') !== false || stripos($municipality, 'Ekurhuleni') !== false) && (stripos($elec_tariff_calc, 'TOU') !== false || stripos($elec_tariff_calc, 'Tariff C') !== false));
                $is_george_bulk_tou_1 = (strcasecmp(trim($elec_tariff_calc), 'George - Bulk TOU (Medium Voltage)') === 0);
                $is_george_bulk_tou_2 = (strcasecmp(trim($elec_tariff_calc), 'George - Bulk TOU 2 (Medium Voltage)') === 0);
                $has_kva_demand = (stripos($elec_tariff_calc, 'TOU') !== false || $is_george_bulk_tou_2 || stripos($elec_tariff_calc, 'Tariff C') !== false || stripos($elec_tariff_calc, 'Bulk Supply and Rural') !== false || stripos($elec_tariff_calc, 'Low Voltage Demand') !== false || stripos($elec_tariff_calc, 'LV Electricity') !== false || stripos($elec_tariff_calc, 'LV') !== false || stripos($elec_tariff_calc, 'Industrial') !== false || $is_plett_elec);

                if ($is_george_bulk_tou_1) {
                    $has_kva_demand = false; 
                }

                if ($pays_demand_unit) {
                    if ($has_kva_demand && (($rates_p1['kva_demand'] ?? 0) > 0 || ($rates_p1['kva_network'] ?? 0) > 0 || $is_george_bulk_tou_2 || ($show_separate_p2_charges && (($rates_p2['kva_demand'] ?? 0) > 0 || ($rates_p2['kva_network'] ?? 0) > 0)))) {
                        $demand_subtotal = 0;
                        echo "<tr class='table-secondary text-dark fw-normal'><td colspan='5'>Demand & Capacity Charges (kVA)</td></tr>";

                        if ($show_separate_p2_charges) {
                            $kva_u1 = $agg_kva * ($days_p1 / $total_days);
                            $kva_u2 = $agg_kva * ($days_p2 / $total_days);

                            if (($rates_p1['kva_demand'] ?? 0) > 0 || $is_george_bulk_tou_2) {
                                $amt_d1 = $kva_u1 * ($rates_p1['kva_demand'] ?? 0);
                                $demand_subtotal += $amt_d1;
                                $slip_totals['demand'] += $amt_d1;
                                
                                $desc_d1 = $is_plett_elec ? "Network Demand Charge (NDC) per kVA ({$season_1} Demand) &mdash; {$str_s1}&ndash;{$str_e1}, {$days_p1}/{$total_days} days &mdash; {$elec_tariff}" : "Demand Charges ({$season_1} Demand) &mdash; {$str_s1}&ndash;{$str_e1}, {$days_p1}/{$total_days} days &mdash; {$elec_tariff}";
                                if ($is_george_bulk_tou_2) $desc_d1 = "Demand Charge (block) TOU2 (R/kVA/month) ({$season_1} Demand) &mdash; {$str_s1}&ndash;{$str_e1}, {$days_p1}/{$total_days} days &mdash; {$elec_tariff}";
                                echo "<tr><td>{$desc_d1}</td><td class='text-end'>".number_format($kva_u1, 2)."</td><td class='text-center'>kVA</td><td class='text-end'>".number_format(($rates_p1['kva_demand'] ?? 0), 4)."</td><td class='text-end'>".number_format($amt_d1, 2)."</td></tr>";
                            }

                            if (($rates_p1['kva_network'] ?? 0) > 0 || $is_george_bulk_tou_2) {
                                $amt_n1 = $kva_u1 * ($rates_p1['kva_network'] ?? 0);
                                $demand_subtotal += $amt_n1;
                                $slip_totals['network'] += $amt_n1;
                                
                                $desc_n1 = $is_plett_elec ? "Network Capacity Charge (NCC) per kVA ({$season_1} Demand) &mdash; {$str_s1}&ndash;{$str_e1}, {$days_p1}/{$total_days} days &mdash; {$elec_tariff}" : "Network Access Charge ({$season_1} Demand) &mdash; {$str_s1}&ndash;{$str_e1}, {$days_p1}/{$total_days} days &mdash; {$elec_tariff}";
                                if ($is_george_bulk_tou_2) $desc_n1 = "Access Charge TOU2A (R/kVA/month) ({$season_1} Demand) &mdash; {$str_s1}&ndash;{$str_e1}, {$days_p1}/{$total_days} days &mdash; {$elec_tariff}";
                                echo "<tr><td>{$desc_n1}</td><td class='text-end'>".number_format($kva_u1, 2)."</td><td class='text-center'>kVA</td><td class='text-end'>".number_format(($rates_p1['kva_network'] ?? 0), 4)."</td><td class='text-end'>".number_format($amt_n1, 2)."</td></tr>";
                            }

                            if (($rates_p2['kva_demand'] ?? 0) > 0 || $is_george_bulk_tou_2) {
                                $amt_d2 = $kva_u2 * ($rates_p2['kva_demand'] ?? 0);
                                $demand_subtotal += $amt_d2;
                                $slip_totals['demand'] += $amt_d2;
                                
                                $desc_d2 = $is_plett_elec ? "Network Demand Charge (NDC) per kVA ({$season_2} Demand) &mdash; {$str_s2}&ndash;{$str_e2}, {$days_p2}/{$total_days} days &mdash; {$elec_tariff}" : "Demand Charges ({$season_2} Demand) &mdash; {$str_s2}&ndash;{$str_e2}, {$days_p2}/{$total_days} days &mdash; {$elec_tariff}";
                                if ($is_george_bulk_tou_2) $desc_d2 = "Demand Charge (block) TOU2 (R/kVA/month) ({$season_2} Demand) &mdash; {$str_s2}&ndash;{$str_e2}, {$days_p2}/{$total_days} days &mdash; {$elec_tariff}";
                                echo "<tr><td>{$desc_d2}</td><td class='text-end'>".number_format($kva_u2, 2)."</td><td class='text-center'>kVA</td><td class='text-end'>".number_format(($rates_p2['kva_demand'] ?? 0), 4)."</td><td class='text-end'>".number_format($amt_d2, 2)."</td></tr>";
                            }

                            if (($rates_p2['kva_network'] ?? 0) > 0 || $is_george_bulk_tou_2) {
                                $amt_n2 = $kva_u2 * ($rates_p2['kva_network'] ?? 0);
                                $demand_subtotal += $amt_n2;
                                $slip_totals['network'] += $amt_n2;
                                
                                $desc_n2 = $is_plett_elec ? "Network Capacity Charge (NCC) per kVA ({$season_2} Demand) &mdash; {$str_s2}&ndash;{$str_e2}, {$days_p2}/{$total_days} days &mdash; {$elec_tariff}" : "Network Access Charge ({$season_2} Demand) &mdash; {$str_s2}&ndash;{$str_e2}, {$days_p2}/{$total_days} days &mdash; {$elec_tariff}";
                                if ($is_george_bulk_tou_2) $desc_n2 = "Access Charge TOU2A (R/kVA/month) ({$season_2} Demand) &mdash; {$str_s2}&ndash;{$str_e2}, {$days_p2}/{$total_days} days &mdash; {$elec_tariff}";
                                echo "<tr><td>{$desc_n2}</td><td class='text-end'>".number_format($kva_u2, 2)."</td><td class='text-center'>kVA</td><td class='text-end'>".number_format(($rates_p2['kva_network'] ?? 0), 4)."</td><td class='text-end'>".number_format($amt_n2, 2)."</td></tr>";
                            }

                        } else {
                            if (($rates_p1['kva_demand'] ?? 0) > 0 || $is_george_bulk_tou_2) {
                                $amt_d = $agg_kva * ($rates_p1['kva_demand'] ?? 0);
                                $demand_subtotal += $amt_d;
                                $slip_totals['demand'] += $amt_d;
                                
                                $demand_desc = $is_plett_elec ? "Network Demand Charge (NDC) per kVA ({$season_1} Demand) &mdash; {$elec_tariff}" : "Demand Charges ({$season_1} Demand) &mdash; {$elec_tariff}";
                                if ($has_period_2) {
                                    $str_s1 = $dt_s1->format('d M');
                                    $str_e2 = $dt_e2->format('d M Y');
                                    $demand_desc = $is_plett_elec ? "Network Demand Charge (NDC) per kVA ({$season_1} Demand) &mdash; {$str_s1}&ndash;{$str_e2}, {$total_days} days &mdash; {$elec_tariff}" : "Demand Charges ({$season_1} Demand) &mdash; {$str_s1}&ndash;{$str_e2}, {$total_days} days &mdash; {$elec_tariff}";
                                }
                                if ($is_george_bulk_tou_2) $demand_desc = "Demand Charge (block) TOU2 (R/kVA/month) ({$season_1} Demand) &mdash; {$elec_tariff}";
                                
                                echo "<tr><td>{$demand_desc}</td><td class='text-end'>".number_format($agg_kva, 2)."</td><td class='text-center'>kVA</td><td class='text-end'>".number_format(($rates_p1['kva_demand'] ?? 0), 4)."</td><td class='text-end'>".number_format($amt_d, 2)."</td></tr>";
                            }

                            if (($rates_p1['kva_network'] ?? 0) > 0 || $is_george_bulk_tou_2) {
                                $amt_n = $agg_kva * ($rates_p1['kva_network'] ?? 0);
                                $demand_subtotal += $amt_n;
                                $slip_totals['network'] += $amt_n;
                                
                                $nac_desc = $is_plett_elec ? "Network Capacity Charge (NCC) per kVA ({$season_1} Demand) &mdash; {$elec_tariff}" : "Network Access Charge ({$season_1} Demand) &mdash; {$elec_tariff}";
                                if ($has_period_2) {
                                    $str_s1 = $dt_s1->format('d M');
                                    $str_e2 = $dt_e2->format('d M Y');
                                    $nac_desc = $is_plett_elec ? "Network Capacity Charge (NCC) per kVA ({$season_1} Demand) &mdash; {$str_s1}&ndash;{$str_e2}, {$total_days} days &mdash; {$elec_tariff}" : "Network Access Charge ({$season_1} Demand) &mdash; {$str_s1}&ndash;{$str_e2}, {$total_days} days &mdash; {$elec_tariff}";
                                }
                                if ($is_george_bulk_tou_2) $desc_n = "Access Charge TOU2A (R/kVA/month) ({$season_1} Demand) &mdash; {$elec_tariff}";
                                
                                echo "<tr><td>{$nac_desc}</td><td class='text-end'>".number_format($agg_kva, 2)."</td><td class='text-center'>kVA</td><td class='text-end'>".number_format(($rates_p1['kva_network'] ?? 0), 4)."</td><td class='text-end'>".number_format($amt_n, 2)."</td></tr>";
                            }
                        }

                        $elec_subtotal += $demand_subtotal;
                        echo "<tr class='table-light text-dark fw-normal'><td colspan='4'>Subtotal - Demand & Capacity Charges</td><td class='text-end'>".number_format($demand_subtotal, 2)."</td></tr>";

                    } elseif (stripos($elec_tariff_calc, 'Tariff B') !== false || stripos($elec_tariff_calc, 'Tariff A') !== false || stripos($elec_tariff_calc, 'Industrial Small') !== false || stripos($elec_tariff_calc, 'George - General Consumers') !== false || (stripos($municipality, 'George') !== false && stripos($elec_tariff_calc, 'General Consumers') !== false)) {
                        $amps = floatval($tenant['tenant_amps'] ?? 0);
                        $a_amt = $amps * ($rates_p1['amps'] ?? 0);
                        
                        if ($a_amt > 0 || ($rates_p1['amps'] ?? 0) > 0 || $amps > 0 || stripos($elec_tariff_calc, 'George - General Consumers') !== false || stripos($municipality, 'George') !== false) {
                            echo "<tr class='table-secondary text-dark fw-normal'><td colspan='5'>Demand & Capacity Charges (kVA)</td></tr>";
                            $elec_subtotal += $a_amt;
                            $slip_totals['capacity'] += $a_amt;
                            
                            $cap_label = "{$elec_tariff} - Capacity Charge ({$season_1} Demand)";
                            if (stripos($elec_tariff_calc, 'George - General Consumers') !== false || stripos($municipality, 'George') !== false) {
                                $cap_label = "Capacity Charges &mdash; George - General Consumers";
                            }
                            
                            echo "<tr><td>{$cap_label}</td><td class='text-end'>".number_format($amps, 2)."</td><td class='text-center'>Amps</td><td class='text-end'>".number_format(($rates_p1['amps'] ?? 0), 4)."</td><td class='text-end'>".number_format($a_amt, 2)."</td></tr>";
                            echo "<tr class='table-light text-dark fw-normal'><td colspan='4'>Subtotal - Demand & Capacity Charges</td><td class='text-end'>".number_format($a_amt, 2)."</td></tr>";
                        }
                    }
                }

                if ($pays_elec_comm && !$remove_elec_comm) {
                    $comm_kwh = $raw_comm_elec_kwh;
                    if ($elec_comm_discount > 0) {
                        $comm_kwh = $comm_kwh * (1 - ($elec_comm_discount / 100));
                    }
                    
                    $comm_amt = $comm_kwh * ($rates_p1['comm_elec_kwh'] ?? 0);
                    $elec_subtotal += $comm_amt;
                    $slip_totals['elec_comm'] += $comm_amt;
                    $slip_totals['comm_kwh'] += $comm_kwh;
                    
                    echo "<tr class='table-secondary text-dark fw-normal'><td colspan='5'>Common Area Contributions</td></tr>";
                    
                    $c_desc = "Common Area Electricity Contribution";
                    if (stripos($municipality, 'Mkhondo') !== false) $c_desc = "Common Area Generator Electricity Contribution";
                    
                    echo "<tr><td>{$c_desc}</td><td class='text-end align-middle'>".number_format($comm_kwh, 2)."</td><td class='text-center align-middle'>kWh</td><td class='text-end align-middle'>".number_format(($rates_p1['comm_elec_kwh'] ?? 0), 4)."</td><td class='text-end align-middle'>".number_format($comm_amt, 2)."</td></tr>";
                    echo "<tr class='table-light text-dark fw-normal'><td colspan='4'>Subtotal - Common Area Charges</td><td class='text-end'>".number_format($comm_amt, 2)."</td></tr>";
                }

                if (!empty($property_settings['charges_shared_nac']) && isset($tenant['pays_shared_nac']) && $tenant['pays_shared_nac'] === 'Yes') {
                    $tenant_comm_perc = floatval($tenant['tenant_comm_area']);
                    $tenant_nac_kva = $shared_nac_leftover_kva * ($tenant_comm_perc / 100);
                    
                    // Shared network access charge rate
                    $nac_rate = $rates_p1['rustenburg_shared_network_access_charge'] ?? 0;
                    $nac_amt = $tenant_nac_kva * $nac_rate;
                    $elec_subtotal += $nac_amt;
                    $slip_totals['shared_nac'] += $nac_amt;
                    
                    echo "<tr><td>Network Access Charge (Pro Rata Share of Total Expense)</td><td class='text-end align-middle'>".number_format($tenant_nac_kva, 2)."</td><td class='text-center align-middle'>kVA</td><td class='text-end align-middle'>".number_format($nac_rate, 4)."</td><td class='text-end align-middle'>".number_format($nac_amt, 2)."</td></tr>";
                    echo "<tr class='table-light text-dark fw-normal'><td colspan='4'>Subtotal - Shared Network Access Charge</td><td class='text-end'>".number_format($nac_amt, 2)."</td></tr>";
                }

                // On a water-only slip the electricity charges are not billed here
                if (($slip_part ?? 'all') !== 'water') {
                    $grand_total += $elec_subtotal;
                }
                $slip_totals['elec_total'] = $elec_subtotal;
                ?>
                <tr class="table-dark text-white fw-normal">
                    <td colspan="4" class="text-uppercase fw-normal" style="background-color: #212529; color: #ffffff;">ELECTRICITY SUBTOTAL</td>
                    <td class="text-end fw-normal" style="background-color: #212529; color: #ffffff;">R <?php echo number_format($elec_subtotal, 2); ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <?php if ($show_graph && !empty($agg_daily_graph) && $slip_render_mode !== 'data'): ?>
    <div class="mb-4 border rounded p-3 bg-light shadow-sm d-print-block page-break-inside-avoid">
        <h6 class="text-uppercase text-dark mb-2 text-center pb-1 border-bottom border-secondary fw-normal">Daily Electrical Consumption</h6>
        <?php if ($slip_render_mode === 'bulk'): ?>
        <div style="height: 120px; width: 100%;">
            <canvas id="elecUsageChart_<?php echo htmlspecialchars($slip_chart_key); ?>" style="width:100%; height:100%;"></canvas>
        </div>
        <?php else: ?>
        <canvas id="elecUsageChart" height="100"></canvas>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; // end of the electricity part ?>

<?php if (($slip_part ?? 'all') !== 'electricity') echo $water_section_html; ?>
<?php 
// Refuse recovery section
$refuse_charge = floatval($tenant['tenant_council_refuse_charge'] ?? 0);
$is_plett_tariff = (stripos($elec_tariff, 'Plett') !== false || stripos($water_tariff, 'Bitou') !== false || stripos($sewer_tariff, 'Bitou') !== false || $municipality === 'Plettenberg Bay');

if (!empty($property_settings['charges_refuse']) && $is_plett_tariff && $refuse_charge > 0 && ($slip_part ?? 'all') !== 'water'): 
    $grand_total += $refuse_charge;
    $slip_totals['refuse'] += $refuse_charge;
?>
<div class="table-responsive mb-3">
    <table class="table table-bordered table-sm align-middle">
        <thead class="table-dark" style="background-color: #1a365d;">
            <tr><th colspan="5" class="text-uppercase fw-normal" style="background-color: #1a365d;">REFUSE RECOVERY</th></tr>
        </thead>
        <tbody>
            <tr class="text-dark fw-normal" style="background-color: #f8f9fa;">
                <td colspan="5">Refuse Recovery</td>
            </tr>
            <tr>
                <td width="45%">Council refuse (pro-rata)</td>
                <td class="text-end">1</td>
                <td class="text-center">item</td>
                <td class="text-end"><?php echo number_format($refuse_charge, 2, '.', ''); ?></td>
                <td class="text-end"><?php echo number_format($refuse_charge, 2, '.', ''); ?></td>
            </tr>
            <tr class="table-light text-dark fw-normal">
                <td colspan="4">Subtotal - Refuse Charges</td>
                <td class="text-end">R <?php echo number_format($refuse_charge, 2); ?></td>
            </tr>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php
// Back billing and adjustments billed in this month (approved or posted)
$slip_adjustments = [];
$slip_adjustments_total = 0.0;
if (is_readable('/var/www/Lynx/Reporting/back-billing-engine.php')) {
    require_once('/var/www/Lynx/Reporting/back-billing-engine.php');
    if (function_exists('lumAdjApprovedForTenant')) {
        $slip_adjustments = lumAdjApprovedForTenant(lumJournalDb(), $property_name, $tenant['tenant_id'] ?? 0,
                                                    $slip_bill_month ?? $report_month, $slip_bill_year ?? $report_year);
        foreach ($slip_adjustments as $slip_adj) {
            $slip_adjustments_total += (float)$slip_adj['total'];
        }
    }
}
$slip_adjustments_total = round($slip_adjustments_total, 2);
$grand_total += $slip_adjustments_total;
$slip_totals['adjustments'] = $slip_adjustments_total;

if (!empty($slip_adjustments)):
?>
<div class="table-responsive mb-3 page-break-inside-avoid">
    <table class="table table-bordered table-sm align-middle">
        <thead class="table-dark">
            <tr><th colspan="4" class="text-uppercase fw-normal">BACK BILLING &amp; ADJUSTMENTS</th></tr>
        </thead>
        <tbody>
            <tr class="text-dark fw-normal" style="background-color: #f8f9fa;">
                <td style="width: 16%;">Reference</td>
                <td>Description</td>
                <td style="width: 16%;">Period Corrected</td>
                <td class="text-end" style="width: 16%;">Amount (R)</td>
            </tr>
            <?php foreach ($slip_adjustments as $slip_adj): $slip_adj_amt = (float)$slip_adj['total']; ?>
            <tr>
                <td><?php echo htmlspecialchars(lumAdjNumber($slip_adj['adjustment_id'])); ?><br><small class="text-muted"><?php echo $slip_adj_amt < 0 ? 'Credit' : 'Debit'; ?></small></td>
                <td>
                    <?php echo htmlspecialchars(LUM_ADJ_REASONS[$slip_adj['reason']] ?? $slip_adj['reason']); ?>
                    <?php if (!empty($slip_adj['description'])): ?><br><small class="text-muted"><?php echo htmlspecialchars($slip_adj['description']); ?></small><?php endif; ?>
                </td>
                <td>
                    <?php echo htmlspecialchars(lumAdjMonthLabel($slip_adj['original_month'], $slip_adj['original_year'])); ?>
                    <?php if (!empty($slip_adj['original_period_start']) && !empty($slip_adj['original_period_end'])): ?>
                        <br><small class="text-muted"><?php echo date('d/m/Y', strtotime($slip_adj['original_period_start'])); ?> - <?php echo date('d/m/Y', strtotime($slip_adj['original_period_end'])); ?></small>
                    <?php endif; ?>
                </td>
                <td class="text-end"><?php echo number_format($slip_adj_amt, 2, '.', ' '); ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="table-light text-dark fw-normal">
                <td colspan="3">Subtotal - Adjustments<?php echo $slip_adjustments_total < 0 ? ' (credit)' : ''; ?></td>
                <td class="text-end">R <?php echo number_format($slip_adjustments_total, 2); ?></td>
            </tr>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="d-flex flex-column flex-sm-row justify-content-between align-items-center bg-dark text-white p-3 px-4 shadow-sm page-break-inside-avoid mt-4 mb-2">
    <div class="w-100">
        <div class="d-flex justify-content-between align-items-center">
            <h4 class="mb-0 text-uppercase tracking-wide text-white fw-normal">Total Amount Payable</h4>
            <h3 class="mb-0 text-white fw-normal">R <?php echo number_format($grand_total, 2); ?></h3>
        </div>
    </div>
</div>

<?php
// What this slip billed on: every meter used, the dates it applied to this tenant and its
// CT ratio, so a wrong meter or a wrong ratio is visible on the slip rather than only in
// the total. Anything on the tenant that no assignment covers is said plainly.
if (!empty($lum_meter_gaps)):
?>
<div class="alert alert-warning py-2 px-3 mt-3 page-break-inside-avoid" role="alert">
    <strong>Not everything on this tenant was billed</strong>
    <ul class="mb-0 mt-1 fs-6">
        <?php foreach ($lum_meter_gaps as $lum_gap): ?>
        <li><?php echo htmlspecialchars($lum_gap); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="text-center text-muted small mt-3 px-md-5 d-print-none pb-2 page-break-inside-avoid">
    <p class="mb-0"><?php echo lumCompanyContactNoteHtml('your most recent consumption slip received'); ?></p>
</div>

<?php
$slip_totals['total'] = $grand_total;

// How this tenant was billed (recorded in the financial journal)
$slip_billing = [
    'elec_obis'          => $tenant_obis_code,
    'is_tou'             => !empty($is_tou_tenant),
    'tou_algorithm'      => $tou_algorithm,
    'municipality'       => $municipality,
    'season_1'           => $season_1,
    'season_2'           => $has_period_2 ? $season_2 : null,
    'estimated_readings' => !empty($use_estimated_readings),
    'occupancy_status'   => $occ_status ?? null,
    'occupancy_override' => !empty($use_occ_override),
    'manual_col_t1'      => $manual_override_col,
    'manual_col_t2'      => $manual_override_col_t2,
    'gen_runtime_hours'  => (!empty($has_any_elec_section) && !empty($gen_runtime_used)) ? round((float)$gen_hours_1 + (float)$gen_hours_2, 2) : null,
    'meters'             => lumSlipBillingSources(!empty($has_any_elec_section) ? ($elec_results ?? []) : [], !empty($has_generator), $manual_override_col, $manual_override_col_t2),
];

// Bulk mode: chart data for the page-level chart script
if ($slip_render_mode === 'bulk') {
    if ($show_graph && !empty($agg_daily_graph)) {
        ksort($agg_daily_graph);
        $glabels = []; $gdata_tot = []; $gdata_p = []; $gdata_s = []; $gdata_o = [];
        foreach ($agg_daily_graph as $d => $v) {
            $glabels[] = date('d M', strtotime($d));
            $gdata_tot[] = round($v['total'], 2);
            $gdata_p[] = round($v['peak'], 2);
            $gdata_s[] = round($v['std'], 2);
            $gdata_o[] = round($v['off'], 2);
        }
        $all_chart_data[$slip_chart_key] = [
            'isTOU' => ($tou_algorithm !== 'None'),
            'labels' => $glabels, 'total' => $gdata_tot, 'peak' => $gdata_p, 'std' => $gdata_s, 'off' => $gdata_o
        ];
    }
    if ($show_water_graph && !empty($agg_daily_water_graph)) {
        ksort($agg_daily_water_graph);
        $wlabels = []; $wdata_tot = [];
        foreach ($agg_daily_water_graph as $d => $v) {
            $wlabels[] = date('d M', strtotime($d));
            $wdata_tot[] = round($v, 2);
        }
        $all_water_chart_data[$slip_chart_key] = ['labels' => $wlabels, 'total' => $wdata_tot];
    }
}
?>
<?php if ($slip_render_mode === 'single' && $show_graph && !empty($agg_daily_graph)): 
    ksort($agg_daily_graph);
    $glabels = []; $gdata_tot = []; $gdata_p = []; $gdata_s = []; $gdata_o = [];
    foreach ($agg_daily_graph as $d => $v) {
        $glabels[] = date('d M', strtotime($d));
        $gdata_tot[] = round($v['total'], 2);
        $gdata_p[] = round($v['peak'], 2);
        $gdata_s[] = round($v['std'], 2);
        $gdata_o[] = round($v['off'], 2);
    }
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ" crossorigin="anonymous"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('elecUsageChart').getContext('2d');
        const isTOU = <?php echo ($tou_algorithm !== 'None') ? 'true' : 'false'; ?>;
        let chartData;
        let chartOptions;
        if (isTOU) {
            chartData = {
                labels: <?php echo json_encode($glabels); ?>,
                datasets: [
                    { label: 'Off-Peak (kWh)', data: <?php echo json_encode($gdata_o); ?>, backgroundColor: '#198754' },
                    { label: 'Standard (kWh)', data: <?php echo json_encode($gdata_s); ?>, backgroundColor: '#ffc107' },
                    { label: 'Peak (kWh)', data: <?php echo json_encode($gdata_p); ?>, backgroundColor: '#dc3545' }
                ]
            };
            chartOptions = { responsive: true, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } }, plugins: { legend: { position: 'bottom' } }, animation: false };
        } else {
            chartData = {
                labels: <?php echo json_encode($glabels); ?>,
                datasets: [{ label: 'Total Consumption (kWh)', data: <?php echo json_encode($gdata_tot); ?>, backgroundColor: '#0d6efd' }]
            };
            chartOptions = { responsive: true, scales: { y: { beginAtZero: true } }, plugins: { legend: { position: 'bottom' } }, animation: false };
        }
        new Chart(ctx, { type: 'bar', data: chartData, options: chartOptions });
    });
</script>
<?php endif; ?>

<?php if ($slip_render_mode === 'single' && $show_water_graph && !empty($agg_daily_water_graph)): 
    ksort($agg_daily_water_graph);
    $wlabels = []; $wdata_tot = [];
    foreach ($agg_daily_water_graph as $d => $v) {
        $wlabels[] = date('d M', strtotime($d));
        $wdata_tot[] = round($v, 2);
    }
?>
<?php if (!$show_graph): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ" crossorigin="anonymous"></script>
<?php endif; ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const ctxW = document.getElementById('waterUsageChart');
        if (ctxW) {
            new Chart(ctxW.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($wlabels); ?>,
                    datasets: [{ label: 'Total Water Usage (kL)', data: <?php echo json_encode($wdata_tot); ?>, backgroundColor: '#0dcaf0' }]
                },
                options: { responsive: true, scales: { y: { beginAtZero: true } }, plugins: { legend: { position: 'bottom' } }, animation: false }
            });
        }
    });
</script>
<?php endif; ?>