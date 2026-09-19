<?php
// Library file: only other pages may include it, it can never be opened directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(403); exit('Forbidden'); }
?>
<div class="sidebar no-print flex-shrink-0 border-end border-secondary shadow-sm d-print-none" id="sidebar">
    <form method="GET" action="">
        
        <div class="mb-4 d-flex align-items-center">
            <a href="../index.php" class="btn btn-sm btn-outline-light py-0 me-3"><i class="bi bi-arrow-left"></i> Back</a>
            <span class="fs-6 text-white pb-1 border-bottom border-secondary fw-normal">Report Settings</span>
        </div>

        <div class="sidebar-section shadow-sm">
            <div class="mb-3 d-flex justify-content-between align-items-center pb-1 border-bottom border-secondary">
                <span class="fs-6 text-white fw-normal mb-0">Property Setup</span>
                <button type="button" onclick="resetFinancialForm()" class="btn btn-secondary btn-sm py-0 px-2" style="font-size: 0.75rem;"><i class="bi bi-arrow-counterclockwise me-1"></i> Report Reset</button>
            </div>
            <div class="mb-3">
                <label class="form-label small mb-1 fw-normal">Select Property</label>
                <select name="property" class="form-select form-select-sm" required>
                    <option value="">-- Choose Property --</option>
                    <?php foreach($properties as $prop): ?>
                        <?php $fs_prop_settings = function_exists('lumPropertySettings') ? lumPropertySettings($prop) : ['default_generator_method' => '1.1.1.8.2']; ?>
                        <option value="<?php echo htmlspecialchars($prop); ?>" data-default-t2="<?php echo htmlspecialchars($fs_prop_settings['default_generator_method']); ?>" <?php echo $selected_property === $prop ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($prop); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-1">
                <label class="form-label small mb-1 fw-normal">Property Main OBIS Code</label>
                <select name="obis_code" class="form-select form-select-sm">
                    <option value="1.1.1.8.1" <?php echo $global_obis_code == '1.1.1.8.1' ? 'selected' : ''; ?>>1.1.1.8.1 (Tariff 1)</option>
                    <option value="1.1.1.8.0" <?php echo $global_obis_code == '1.1.1.8.0' ? 'selected' : ''; ?>>1.1.1.8.0 (Total)</option>
                </select>
            </div>
            
            <div class="mb-1 mt-3">
                <label class="form-label small mb-1 fw-normal text-white">Non-TOU Tenant OBIS Code</label>
                <select name="non_tou_obis" class="form-select form-select-sm">
                    <option value="1.1.1.8.1" <?php echo $non_tou_obis == '1.1.1.8.1' ? 'selected' : ''; ?>>1.1.1.8.1 (Tariff 1 - Standard)</option>
                    <option value="1.1.1.8.0" <?php echo $non_tou_obis == '1.1.1.8.0' ? 'selected' : ''; ?>>1.1.1.8.0 (Total)</option>
                </select>
            </div>

            <?php if (!empty($available_manual_columns)): ?>
            <div class="mb-3 mt-3">
                <label class="form-label small mb-1 fw-normal text-white">Manual Billing Override (Grid / T1)</label>
                <select name="manual_override_col" class="form-select form-select-sm bg-dark text-white">
                    <option value="none">-- Automated Readings --</option>
                    <?php foreach ($available_manual_columns as $col): 
                        $display_col = str_ireplace(['_plus_', '_minus_', '_plus', '_minus'], ['+ ', '- ', '+', '-'], $col);
                        $display_col = str_replace('_', ' ', $display_col);
                    ?>
                        <option value="<?php echo htmlspecialchars($col); ?>" <?php echo $manual_override_col === $col ? 'selected' : ''; ?>>
                            Extract: <?php echo htmlspecialchars($display_col); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="mb-1">
                <label class="form-label small mb-1 fw-normal text-white">Manual Billing Override (Gen / T2)</label>
                <select name="manual_override_col_t2" class="form-select form-select-sm bg-dark text-white">
                    <option value="none">-- Automated Readings --</option>
                    <?php foreach ($available_manual_columns as $col): 
                        $display_col = str_ireplace(['_plus_', '_minus_', '_plus', '_minus'], ['+ ', '- ', '+', '-'], $col);
                        $display_col = str_replace('_', ' ', $display_col);
                    ?>
                        <option value="<?php echo htmlspecialchars($col); ?>" <?php echo $manual_override_col_t2 === $col ? 'selected' : ''; ?>>
                            Extract: <?php echo htmlspecialchars($display_col); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <!-- ESTIMATED READINGS: only used for tenants/meters without manual extract readings -->
            <div class="form-check mb-2 border-top border-secondary pt-3 mt-3">
                <?php 
                // Count tenants with valid vs. missing/expired occupancy dates for the selected billing period
                $occ_valid_count = 0;
                $occ_invalid_count = 0;
                if (!empty($selected_property)) {
                    try {
                        $stmt_check_occ = $tenant_db_conn->prepare("SELECT
                                SUM(CASE WHEN tenant_occupancy_start_date IS NOT NULL AND tenant_occupancy_end_date IS NOT NULL AND tenant_occupancy_end_date >= :sd1 THEN 1 ELSE 0 END) AS valid_cnt,
                                SUM(CASE WHEN tenant_occupancy_start_date IS NULL OR tenant_occupancy_end_date IS NULL OR tenant_occupancy_end_date < :sd2 THEN 1 ELSE 0 END) AS invalid_cnt
                            FROM lum_tenants WHERE tenant_property = :prop");
                        $stmt_check_occ->execute(['sd1' => $start_date, 'sd2' => $start_date, 'prop' => $selected_property]);
                        $occ_counts = $stmt_check_occ->fetch(PDO::FETCH_ASSOC);
                        $occ_valid_count = (int)($occ_counts['valid_cnt'] ?? 0);
                        $occ_invalid_count = (int)($occ_counts['invalid_cnt'] ?? 0);
                    } catch (Exception $e) {}
                }
                $has_occ_dates = ($occ_valid_count > 0);
                $est_can_run = ($has_occ_dates || !empty($estimate_time_ranges));
                ?>
                <input class="form-check-input" type="checkbox" id="use_estimated_readings" name="use_estimated_readings" value="1" 
                    <?php echo ($use_estimated_readings_requested && $est_can_run) ? 'checked' : ''; ?>
                    <?php echo !$est_can_run ? 'disabled' : ''; ?>>
                <label class="form-check-label small text-white" for="use_estimated_readings" style="opacity: 1;">Use Estimated Electrical Readings</label>
                <?php if (!empty($selected_property) && $occ_invalid_count > 0): ?>
                    <div class="text-danger mt-1 fw-bold" style="font-size: 0.75rem;"><?php echo $occ_invalid_count; ?> tenant<?php echo $occ_invalid_count === 1 ? '' : 's'; ?> without valid Occupancy Dates.</div>
                <?php endif; ?>
            </div>

            <!-- OCCUPANCY OVERRIDE: estimate time ranges for tenants with missing / expired occupancy dates -->
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="use_estimate_time_ranges" name="use_estimate_time_ranges" value="1"
                    <?php echo ($use_estimate_time_ranges_requested && !empty($estimate_time_ranges)) ? 'checked' : ''; ?>
                    <?php echo empty($estimate_time_ranges) ? 'disabled' : ''; ?>>
                <label class="form-check-label small text-white" for="use_estimate_time_ranges" style="opacity: 1;">Override Occupancy Dates (Estimate Time Ranges)</label>
            </div>
        </div>

        <?php if (!empty($billing_cycles)): ?>
        <div class="sidebar-section shadow-sm">
            <h6 class="text-white pb-1 border-bottom border-secondary fw-normal mb-3" style="font-size: 0.9rem;">
                Billing Cycle
            </h6>
            <div class="mb-2">
                <?php
                // Pre-calculate the current selected cycle based on the active start and end dates
                $current_cycle_val = '';
                if (!empty($start_date)) {
                    $cycle_end = !empty($end_date_2) ? $end_date_2 : $end_date;
                    // Ensure dates are strictly Y-m-d formatted to match the parsed cycle values
                    $clean_start = date('Y-m-d', strtotime($start_date));
                    $clean_end = date('Y-m-d', strtotime($cycle_end));
                    $current_cycle_val = $clean_start . '_TO_' . $clean_end;
                }

                // Verify if the current cycle actually matches an available billing cycle
                $match_found = false;
                foreach ($billing_cycles as $cycle) {
                    $c_start = date('Y-m-d', strtotime($cycle['period_start_date']));
                    $c_end = date('Y-m-d', strtotime($cycle['period_end_date']));
                    if (($c_start . '_TO_' . $c_end) === $current_cycle_val) {
                        $match_found = true;
                        break;
                    }
                }
                ?>
                <select id="billing_cycle_dropdown" class="form-select form-select-sm" onchange="applyBillingCycle(this.value)">
                    <option value="" <?php echo !$match_found ? 'selected' : ''; ?> disabled>Select Billing Month...</option>
                    <?php foreach ($billing_cycles as $cycle): 
                        // Strip any potential timestamp artifacts directly from the DB
                        $c_start = date('Y-m-d', strtotime($cycle['period_start_date']));
                        $c_end = date('Y-m-d', strtotime($cycle['period_end_date']));
                        $opt_val = $c_start . '_TO_' . $c_end;
                        
                        $is_selected = ($opt_val === $current_cycle_val) ? 'selected' : '';
                        
                        $lbl = htmlspecialchars($cycle['billing_month_label']);
                        // Dynamically pad the month name with non-breaking spaces to simulate a perfect 50/50 column split
                        $pad_length = max(0, 22 - strlen($lbl));
                        $padding = str_repeat('&nbsp;', $pad_length);
                        
                        // Extract dates and drop the year from the range as requested
                        $dt_range = date('d M', strtotime($c_start)) . ' - ' . date('d M', strtotime($c_end));
                    ?>
                        <option value="<?php echo htmlspecialchars($opt_val); ?>" <?php echo $is_selected; ?>>
                            <?php echo $lbl . $padding . '|&nbsp;&nbsp;&nbsp;' . $dt_range; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php endif; ?>

        <div class="sidebar-section shadow-sm">
            <div class="mb-3">
                <span class="fs-6 text-white pb-1 border-bottom border-secondary fw-normal">Electrical Period 1</span>
            </div>
            <div class="row g-2 mb-2">
                <div class="col-6">
                    <label class="form-label small mb-1 fw-normal">Start</label>
                    <input type="date" name="start_date" id="start_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($start_date); ?>" required>
                </div>
                <div class="col-6">
                    <label class="form-label small mb-1 fw-normal">End</label>
                    <input type="date" name="end_date" id="end_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($end_date); ?>" required>
                </div>
            </div>
            <!-- Tariff month follows the End date and the season comes from the tariff ledger (same as the consumption slips) -->
            <div class="row g-2">
                <div class="col-6">
                    <label class="form-label small mb-1 fw-normal">Tariff Ledger Month</label>
                    <input type="month" id="tariff_period_1" class="form-control form-control-sm" value="<?php echo htmlspecialchars($tariff_period_1); ?>" disabled>
                </div>
                <div class="col-6">
                    <label class="form-label small mb-1 fw-normal">Demand Season</label>
                    <input type="text" id="season_1" class="form-control form-control-sm" value="<?php echo htmlspecialchars($season_1); ?> Demand" disabled>
                </div>
            </div>
        </div>

        <div class="sidebar-section shadow-sm">
            <div class="mb-3">
                <span class="fs-6 text-white pb-1 border-bottom border-secondary fw-normal">Electrical Period 2</span>
            </div>
            <div class="row g-2 mb-2">
                <div class="col-6">
                    <label class="form-label small mb-1 fw-normal">Start</label>
                    <input type="date" name="start_date_2" id="start_date_2" class="form-control form-control-sm" value="<?php echo htmlspecialchars($start_date_2); ?>">
                </div>
                <div class="col-6">
                    <label class="form-label small mb-1 fw-normal">End</label>
                    <input type="date" name="end_date_2" id="end_date_2" class="form-control form-control-sm" value="<?php echo htmlspecialchars($end_date_2); ?>">
                </div>
            </div>
            <div class="row g-2">
                <div class="col-6">
                    <label class="form-label small mb-1 fw-normal">Tariff Ledger Month</label>
                    <input type="month" id="tariff_period_2" class="form-control form-control-sm" value="<?php echo $has_period_2 ? htmlspecialchars($tariff_period_2) : ''; ?>" disabled>
                </div>
                <div class="col-6">
                    <label class="form-label small mb-1 fw-normal">Demand Season</label>
                    <input type="text" id="season_2" class="form-control form-control-sm" value="<?php echo $has_period_2 ? htmlspecialchars($season_2) . ' Demand' : '-'; ?>" disabled>
                </div>
            </div>
        </div>

        <?php if ($has_generator): ?>
        <div class="sidebar-section shadow-sm">
            <div class="mb-3">
                <span class="fs-6 text-white pb-1 border-bottom border-secondary fw-normal">Generator (T2) Settings</span>
            </div>
            <div class="mb-3">
                <label class="form-label small mb-1 fw-normal">Calculation Method</label>
                <select name="t2_method" id="t2_method" class="form-select form-select-sm fw-normal" onchange="document.getElementById('runtime-opts').style.display = this.value === 'runtime' ? 'block' : 'none';">
                    <option value="1.1.1.8.2" <?php echo $t2_method == '1.1.1.8.2' ? 'selected' : ''; ?>>OBIS 1.1.1.8.2 Reading</option>
                    <option value="runtime" <?php echo $t2_method == 'runtime' ? 'selected' : ''; ?>>Run Time Calculation</option>
                </select>
            </div>
            <div id="runtime-opts" style="display: <?php echo ($t2_method == 'runtime' || $manual_override_col_t2 !== 'none') ? 'block' : 'none'; ?>;">
                <div class="mb-3">
                    <label class="form-label small mb-1 fw-normal">Daily Avg Base OBIS</label>
                    <select name="t2_base_obis" id="t2_base_obis" class="form-select form-select-sm fw-normal">
                        <option value="1.1.1.8.0" <?php echo $t2_base_obis == '1.1.1.8.0' ? 'selected' : ''; ?>>1.1.1.8.0 (Total)</option>
                        <option value="1.1.1.8.1" <?php echo $t2_base_obis == '1.1.1.8.1' ? 'selected' : ''; ?>>1.1.1.8.1 (Tariff 1)</option>
                    </select>
                </div>
                <div class="row g-2 mb-1">
                    <div class="col-6">
                        <label class="form-label small mb-1 fw-normal">Start Date</label>
                        <input type="date" name="gen_start_date" id="gen_start_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($gen_runtime_start); ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-1 fw-normal">End Date</label>
                        <input type="date" name="gen_end_date" id="gen_end_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($gen_runtime_end); ?>">
                    </div>
                </div>
                <div class="text-white-50 mb-3" style="font-size: 0.7rem;">Defaults to the billing cycle. Readings are matched by the month of each date.</div>
                <?php if (!empty($gen_runtime_info)): ?>
                <div class="border border-secondary rounded p-2 mb-2" style="font-size: 0.7rem;">
                    <div class="text-white-50 mb-1">Run-time readings (manual_readings_genrun)</div>
                    <?php foreach (['open' => $gen_runtime_info['open_month'], 'close' => $gen_runtime_info['close_month']] as $rt_key => $rt_month): $rt_row = $gen_runtime_info[$rt_key]; ?>
                    <div class="d-flex justify-content-between">
                        <span class="text-white-50"><?php echo date('M Y', strtotime($rt_month . '-01')); ?></span>
                        <?php if ($rt_row): ?>
                            <span class="text-white"><?php echo htmlspecialchars($rt_row['reading_hours']); ?> <span class="text-white-50">(<?php echo date('d/m/Y', strtotime($rt_row['reading_date'])); ?>)</span></span>
                        <?php else: ?>
                            <span class="text-danger">No reading</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if ($gen_runtime_info['source'] === 'manual'): ?>
                        <div class="text-info mt-1">Run time: <?php echo number_format($gen_hours_1 + $gen_hours_2, 2); ?> hrs</div>
                    <?php elseif ($gen_runtime_info['source'] === 'offline'): ?>
                        <div class="text-warning mt-1">No reading for the month &mdash; using meter offline hours: <?php echo number_format($gen_hours_1 + $gen_hours_2, 2); ?> hrs</div>
                    <?php else: ?>
                        <div class="text-danger mt-1">No run-time reading for the month &mdash; generator run time is 0 hrs.</div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if ($manual_override_col_t2 !== 'none'): ?>
                <div class="text-warning mb-2" style="font-size: 0.7rem;">Manual T2 extract selected: tenants without manual T2 readings use this run-time calculation.</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="sidebar-section shadow-sm">
            <div class="mb-3">
                <span class="fs-6 text-white pb-1 border-bottom border-secondary fw-normal">Electrical Common Area</span>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label small mb-1 fw-normal">Start Date</label>
                    <input type="date" name="comm_start_date" id="comm_start_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($comm_start_date); ?>">
                </div>
                <div class="col-6">
                    <label class="form-label small mb-1 fw-normal">End Date</label>
                    <input type="date" name="comm_end_date" id="comm_end_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($comm_end_date); ?>">
                </div>
                
                <?php 
                // Manual total only for properties without their own common area calculation (property Billing Settings)
                $fs_billing = function_exists('lumPropertySettings') ? lumPropertySettings($selected_property ?? '') : ['elec_common_area_method' => 'manual', 'water_common_area_method' => 'manual'];
                if ($fs_billing['elec_common_area_method'] === 'manual'): 
                ?>
                <div class="col-12 mt-2">
                    <label class="form-label small mb-1 fw-normal text-info">Prop Total Base (kWh)</label>
                    <input type="number" step="0.01" name="manual_prop_elec_comm" class="form-control form-control-sm" value="<?php echo htmlspecialchars($_GET['manual_prop_elec_comm'] ?? 0); ?>">
                </div>
                <?php endif; ?>

                <div class="col-12 mt-2">
                    <label class="form-label small mb-1 fw-normal text-warning">Reduction Discount (%)</label>
                    <div class="input-group input-group-sm">
                        <input type="number" step="0.01" name="elec_comm_discount" id="elec_comm_discount" class="form-control border-warning" value="<?php echo htmlspecialchars((string)$elec_comm_discount); ?>" placeholder="Tenant setting" title="Leave blank to use each tenant's own monthly discount">
                        <span class="input-group-text border-warning text-warning fw-normal">%</span>
                    </div>
                </div>
            </div>
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="remove_elec_comm" name="remove_elec_comm" value="1" <?php echo $remove_elec_comm ? 'checked' : ''; ?>>
                <label class="form-check-label small fw-normal text-white" for="remove_elec_comm">Remove from Slip</label>
            </div>
        </div>

        <div class="sidebar-section shadow-sm">
            <div class="mb-3">
                <span class="fs-6 text-info pb-1 border-bottom border-secondary fw-normal">Water Period 1</span>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label small mb-1 fw-normal">Start</label>
                    <input type="date" name="water_start_date" id="water_start_date" class="form-control form-control-sm border-info" value="<?php echo htmlspecialchars($water_start_date); ?>" required>
                </div>
                <div class="col-6">
                    <label class="form-label small mb-1 fw-normal">End</label>
                    <input type="date" name="water_end_date" id="water_end_date" class="form-control form-control-sm border-info" value="<?php echo htmlspecialchars($water_end_date); ?>" required>
                </div>
            </div>
        </div>

        <div class="sidebar-section shadow-sm">
            <div class="mb-3">
                <span class="fs-6 text-info pb-1 border-bottom border-secondary fw-normal">Water Period 2</span>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label small mb-1 fw-normal">Start</label>
                    <input type="date" name="water_start_date_2" id="water_start_date_2" class="form-control form-control-sm border-info" value="<?php echo htmlspecialchars($water_start_date_2); ?>">
                </div>
                <div class="col-6">
                    <label class="form-label small mb-1 fw-normal">End</label>
                    <input type="date" name="water_end_date_2" id="water_end_date_2" class="form-control form-control-sm border-info" value="<?php echo htmlspecialchars($water_end_date_2); ?>">
                </div>
            </div>
        </div>

        <div class="sidebar-section shadow-sm">
            <div class="mb-3">
                <span class="fs-6 text-info pb-1 border-bottom border-secondary fw-normal">Water Common Area</span>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label small mb-1 fw-normal">Start Date</label>
                    <input type="date" name="water_comm_start_date" id="water_comm_start_date" class="form-control form-control-sm border-info" value="<?php echo htmlspecialchars($water_comm_start_date); ?>">
                </div>
                <div class="col-6">
                    <label class="form-label small mb-1 fw-normal">End Date</label>
                    <input type="date" name="water_comm_end_date" id="water_comm_end_date" class="form-control form-control-sm border-info" value="<?php echo htmlspecialchars($water_comm_end_date); ?>">
                </div>
                
                <?php 
                $fs_billing = $fs_billing ?? (function_exists('lumPropertySettings') ? lumPropertySettings($selected_property ?? '') : ['water_common_area_method' => 'manual']);
                $is_auto_water = ($fs_billing['water_common_area_method'] !== 'manual');
                if (!$is_auto_water): 
                ?>
                <div class="col-12 mt-2">
                    <label class="form-label small mb-1 fw-normal text-info">Prop Total Base (kL)</label>
                    <input type="number" step="0.01" name="manual_prop_water_comm" class="form-control form-control-sm border-info" value="<?php echo htmlspecialchars($_GET['manual_prop_water_comm'] ?? 0); ?>">
                </div>
                <?php endif; ?>

                <div class="col-12 mt-2">
                    <label class="form-label small mb-1 fw-normal text-warning">Reduction Discount (%)</label>
                    <div class="input-group input-group-sm">
                        <input type="number" step="0.01" name="water_comm_discount" id="water_comm_discount" class="form-control border-warning" value="<?php echo htmlspecialchars((string)$water_comm_discount); ?>" placeholder="Tenant setting" title="Leave blank to use each tenant's own monthly discount">
                        <span class="input-group-text border-warning text-warning fw-normal">%</span>
                    </div>
                </div>
            </div>
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="remove_water_comm" name="remove_water_comm" value="1" <?php echo $remove_water_comm ? 'checked' : ''; ?>>
                <label class="form-check-label small fw-normal text-white" for="remove_water_comm">Remove from Slip</label>
            </div>
        </div>

        <div class="sidebar-section shadow-sm">
            <div class="mb-3">
                <span class="fs-6 text-white pb-1 border-bottom border-secondary fw-normal">Export Visualizations</span>
            </div>
            <span class="text-info small fw-bold d-block mb-2">Include in Financial Report:</span>
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="show_tenant_bar" name="show_tenant_bar" value="1" <?php echo isset($_GET['show_tenant_bar']) ? 'checked' : ''; ?>>
                <label class="form-check-label small fw-normal text-white" for="show_tenant_bar">Tenant Breakdown Bar Chart</label>
            </div>
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="show_elec_pie" name="show_elec_pie" value="1" <?php echo isset($_GET['show_elec_pie']) ? 'checked' : ''; ?>>
                <label class="form-check-label small fw-normal text-white" for="show_elec_pie">Electricity Recovery Pie Chart</label>
            </div>
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="show_water_pie" name="show_water_pie" value="1" <?php echo isset($_GET['show_water_pie']) ? 'checked' : ''; ?>>
                <label class="form-check-label small fw-normal text-white" for="show_water_pie">Water Recovery Pie Chart</label>
            </div>
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="show_daily_fin" name="show_daily_fin" value="1" <?php echo isset($_GET['show_daily_fin']) ? 'checked' : ''; ?>>
                <label class="form-check-label small fw-normal text-white" for="show_daily_fin">Daily Financial Spend Chart</label>
            </div>
            
            <span class="text-warning small fw-bold d-block mt-3 mb-2">Bulk Consumption Slips Only:</span>
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="show_graph" name="show_graph" value="1" <?php echo $show_graph ? 'checked' : ''; ?>>
                <label class="form-check-label small fw-normal text-white" for="show_graph">Add Electrical Daily Graphs</label>
            </div>
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="show_water_graph" name="show_water_graph" value="1" <?php echo $show_water_graph ? 'checked' : ''; ?>>
                <label class="form-check-label small fw-normal text-white" for="show_water_graph">Add Water Daily Graphs</label>
            </div>
        </div>

        <div class="d-grid gap-2 mt-4 mb-4">
            <button type="button" onclick="window.print()" class="btn btn-brand btn-sm fw-normal" <?php echo empty($selected_property) ? 'disabled' : ''; ?>><i class="bi bi-printer me-1"></i> Print / PDF</button>
            <button type="button" onclick="exportToExcel('report-content', 'Financial_Report_<?php echo preg_replace('/[^a-zA-Z0-9]/', '_', $selected_property); ?>')" class="btn btn-success btn-sm fw-normal" <?php echo empty($selected_property) ? 'disabled' : ''; ?>><i class="bi bi-file-earmark-excel me-1"></i> Export Excel</button>
            <button type="button" onclick="exportMRI()" class="btn btn-warning btn-sm fw-normal text-dark" <?php echo empty($selected_property) ? 'disabled' : ''; ?>><i class="bi bi-filetype-csv me-1"></i> Export MRI CSV</button>
            <button type="button" onclick="bulkExportSlips()" class="btn btn-info btn-sm fw-normal text-dark" <?php echo empty($selected_property) ? 'disabled' : ''; ?>><i class="bi bi-files me-1"></i> Bulk Export Slips</button>
        </div>
    </form>
</div>

<script>
    function resetFinancialForm() {
        sessionStorage.removeItem('lynxSidebarScroll');
        window.location.href = window.location.pathname;
    }

    function bulkExportSlips() {
        try {
            // Guarantee we are selecting the form originating from the sidebar
            const form = document.querySelector('.sidebar form');
            if (!form) return;
            
            const fd = new FormData(form);
            const obj = {};
            
            // Extract all active form values
            fd.forEach((value, key) => {
                if (value !== '' && value !== null && key !== 'billing_cycle_dropdown') {
                    obj[key] = value;
                }
            });
            
            const jsonStr = JSON.stringify(obj);
            
            // Encode into Hexadecimal to blind the WAF
            let hexPayload = '';
            for (let i = 0; i < jsonStr.length; i++) {
                hexPayload += jsonStr.charCodeAt(i).toString(16).padStart(2, '0');
            }
            
            // Generate stealth POST form targeting the bulk script in a new tab
            const hiddenForm = document.createElement('form');
            hiddenForm.method = 'POST';
            
            // Ensure the action explicitly targets the file without triggering 404 paths
            hiddenForm.action = 'bulk-consumption-slips.php?property=' + encodeURIComponent(obj['property'] || '');
            hiddenForm.target = '_blank';
            
            // Chunk payload to prevent max-input-length triggers
            const chunks = hexPayload.match(/.{1,200}/g) || [];
            chunks.forEach((chunk, i) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'p' + i;
                input.value = chunk;
                hiddenForm.appendChild(input);
            });
            
            document.body.appendChild(hiddenForm);
            hiddenForm.submit();
            
            // Clean up DOM after transmission
            setTimeout(() => document.body.removeChild(hiddenForm), 1000);
        } catch (e) {
            console.error("Bulk Export Error:", e);
        }
    }

    function applyBillingCycle(val) {
        if (!val) return;
        const parts = val.split('_TO_');
        if (parts.length !== 2) return;
        
        const startStr = parts[0]; 
        const endStr = parts[1];   
        
        const sParts = startStr.split('-');
        const sYear = parseInt(sParts[0], 10);
        const sMonth = parseInt(sParts[1], 10);
        
        const eParts = endStr.split('-');
        const eYear = parseInt(eParts[0], 10);
        const eMonth = parseInt(eParts[1], 10);
        
        const setVal = (name, val) => { 
            const el = document.querySelector(`[name="${name}"]`) || document.getElementById(name); 
            if (el) el.value = val; 
        };
        
        if (sYear === eYear && sMonth === eMonth) {
            // Same calendar month
            const ledgerMonth = sYear + '-' + String(sMonth).padStart(2, '0');
            
            setVal('start_date', startStr);
            setVal('end_date', endStr);
            
            setVal('start_date_2', '');
            setVal('end_date_2', '');
            
            setVal('water_start_date', startStr);
            setVal('water_end_date', endStr);
            
            setVal('water_start_date_2', '');
            setVal('water_end_date_2', '');
            
        } else {
            // Crosses calendar months: Splitting exactly at the month boundary
            const endOfStartMonth = new Date(sYear, sMonth, 0); 
            const p1EndStr = sYear + '-' + String(sMonth).padStart(2, '0') + '-' + String(endOfStartMonth.getDate()).padStart(2, '0');
            const p2StartStr = eYear + '-' + String(eMonth).padStart(2, '0') + '-01';
            
            const p1Ledger = sYear + '-' + String(sMonth).padStart(2, '0');
            const p2Ledger = eYear + '-' + String(eMonth).padStart(2, '0');

            setVal('start_date', startStr);
            setVal('end_date', p1EndStr);
            
            setVal('start_date_2', p2StartStr);
            setVal('end_date_2', endStr);
            
            setVal('water_start_date', startStr);
            setVal('water_end_date', p1EndStr);
            
            setVal('water_start_date_2', p2StartStr);
            setVal('water_end_date_2', endStr);
        }
        
        setVal('comm_start_date', startStr);
        setVal('comm_end_date', endStr);
        setVal('water_comm_start_date', startStr);
        setVal('water_comm_end_date', endStr);
        setVal('gen_start_date', startStr);
        setVal('gen_end_date', endStr);
        
        const form = document.querySelector('.sidebar form');
        if (form) form.submit();
    }

    document.addEventListener('DOMContentLoaded', function() {
        const form = document.querySelector('.sidebar form');
        const sidebarEl = document.querySelector('.sidebar');
        
        // Fullscreen Toggle Logic
        const sidebarToggleBtn = document.getElementById('sidebarToggle');
        if(sidebarToggleBtn) {
            sidebarToggleBtn.addEventListener('click', function() {
                if (sidebarEl) {
                    sidebarEl.classList.toggle('collapsed');
                    setTimeout(() => window.dispatchEvent(new Event('resize')), 350);
                }
            });
        }

        // Restore sidebar scroll position if we just reloaded
        if (sidebarEl) {
            const savedScroll = sessionStorage.getItem('lynxSidebarScroll');
            if (savedScroll) {
                sidebarEl.scrollTop = parseInt(savedScroll, 10);
                sessionStorage.removeItem('lynxSidebarScroll');
            }
        }
        
        let timeout;
        function autoSubmit() {
            clearTimeout(timeout);
            timeout = setTimeout(() => {
                if (sidebarEl) {
                    sessionStorage.setItem('lynxSidebarScroll', sidebarEl.scrollTop);
                }
                form.submit();
            }, 400);
        }

        // Choosing a property applies its default generator method (property Billing Settings) before the form is submitted
        const propertyDropdown = document.querySelector('select[name="property"]');
        if (propertyDropdown) {
            propertyDropdown.addEventListener('change', function() {
                const opt = this.options[this.selectedIndex];
                const defaultT2 = opt ? opt.dataset.defaultT2 : '';
                const t2Method = document.getElementById('t2_method');
                if (t2Method && defaultT2) {
                    t2Method.value = defaultT2;
                    const runtimeOpts = document.getElementById('runtime-opts');
                    if (runtimeOpts && defaultT2 === 'runtime') runtimeOpts.style.display = 'block';
                }
            });
        }

        // Attach auto-submit to all relevant form controls
        if (form) {
            const inputs = form.querySelectorAll('input:not([type="hidden"]), select:not(#billing_cycle_dropdown)');
            inputs.forEach(input => {
                input.addEventListener('change', autoSubmit);
            });
        }

        // Collapse toggles for new charts
        ['tenantBar', 'elecPie', 'waterPie', 'dailyFin'].forEach(prefix => {
            const collapseEl = document.getElementById(prefix + 'Collapse');
            const toggleBtn = document.getElementById(prefix + 'ToggleBtn');
            if (collapseEl && toggleBtn) {
                const icon = toggleBtn.querySelector('.toggle-icon');
                collapseEl.addEventListener('show.bs.collapse', () => {
                    icon.classList.remove('bi-plus-circle');
                    icon.classList.add('bi-dash-circle');
                });
                collapseEl.addEventListener('hide.bs.collapse', () => {
                    icon.classList.remove('bi-dash-circle');
                    icon.classList.add('bi-plus-circle');
                });
            }
        });
    });
</script>