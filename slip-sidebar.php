<?php
// Library file: only other pages may include it, it can never be opened directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(403); exit('Forbidden'); }
?>
<div class="sidebar no-print d-print-none" id="sidebar">
    <form id="slip_form_final">
        <input type="hidden" name="tenant_id" value="<?php echo htmlspecialchars($tenant_id); ?>">
        <input type="hidden" name="return_search" value="<?php echo htmlspecialchars($return_search); ?>">
        <?php if ($return_to === 'financial'): ?>
        <!-- Keeps the way back to the financial report when the slip is recalculated -->
        <input type="hidden" name="return_to" value="financial">
        <input type="hidden" name="return_query" value="<?php echo htmlspecialchars($return_query); ?>">
        <?php endif; ?>
        <input type="hidden" name="save_settings_flag" value="1">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
        
        <div class="mb-4 pb-2 border-bottom border-secondary d-flex align-items-center">
            <a href="<?php echo htmlspecialchars($back_url); ?>" id="lumSlipBack" class="btn btn-sm btn-outline-light py-0 me-3" title="<?php echo htmlspecialchars($back_title); ?>"><i class="bi bi-arrow-left"></i> Back</a>
            <h5 class="mb-0 text-white fs-6 fw-normal">Consumption Slip Settings</h5>
        </div>

        <?php if ($return_to === 'financial'): ?>
        <div class="sidebar-section shadow-sm" style="border-color: #0dcaf0;">
            <div class="small text-info mb-1"><i class="bi bi-link-45deg me-1"></i>Linked to the Financial Report</div>
            <?php if (!lum_can('consumption_slips', 'edit')): ?>
                <div class="small text-warning">You can view this slip, but your changes to this tenant's settings are not saved.</div>
            <?php endif; ?>
            <?php if (!empty($lum_report_settings_msg)): ?>
                <div class="small text-success mt-2"><?php echo htmlspecialchars($lum_report_settings_msg); ?></div>
            <?php endif; ?>
            <?php if (!empty($lum_report_settings)): ?>
                <div class="small text-white mt-2 mb-1">This tenant's own settings in the report:</div>
                <ul class="small text-warning mb-2 ps-3">
                    <?php foreach ($lum_report_settings as $lum_k => $lum_v): ?>
                        <li><?php echo htmlspecialchars(lumTenantSettingLabel($lum_k) . ': ' . lumTenantSettingValue($lum_k, $lum_v)); ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php if (lum_can('consumption_slips', 'edit')): ?>
                    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($lum_return_report_params ?? [], ['tenant_id' => $tenant_id, 'return_to' => 'financial', 'return_query' => $return_query, 'use_report_settings' => 1]))); ?>"
                       class="btn btn-sm btn-outline-info w-100" id="lumUseReportSettings">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Use report settings for this tenant
                    </a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($billing_cycles)): ?>
        <div class="sidebar-section shadow-sm">
            <h6 class="text-white pb-1 border-bottom border-secondary fw-normal mb-3" style="font-size: 0.9rem;">
                Billing Cycle
            </h6>
            <div class="mb-2">
                <?php
                $current_cycle_val = '';
                if (!empty($start_date)) {
                    $cycle_end = !empty($end_date_2) ? $end_date_2 : $end_date;
                    $clean_start = date('Y-m-d', strtotime($start_date));
                    $clean_end = date('Y-m-d', strtotime($cycle_end));
                    $current_cycle_val = $clean_start . '_TO_' . $clean_end;
                }

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
                        $c_start = date('Y-m-d', strtotime($cycle['period_start_date']));
                        $c_end = date('Y-m-d', strtotime($cycle['period_end_date']));
                        $opt_val = $c_start . '_TO_' . $c_end;
                        
                        $is_selected = ($opt_val === $current_cycle_val) ? 'selected' : '';
                        
                        $lbl = htmlspecialchars($cycle['billing_month_label']);
                        // Pad the month name so the date ranges line up
                        $pad_length = max(0, 22 - strlen($lbl));
                        $padding = str_repeat('&nbsp;', $pad_length);
                        
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
                <label class="form-label small mb-1 text-white">Property Main OBIS Code</label>
                <select name="obis_code" class="form-select form-select-sm">
                    <option value="1.1.1.8.1" <?php echo $global_obis_code == '1.1.1.8.1' ? 'selected' : ''; ?>>1.1.1.8.1 (Tariff 1)</option>
                    <option value="1.1.1.8.0" <?php echo $global_obis_code == '1.1.1.8.0' ? 'selected' : ''; ?>>1.1.1.8.0 (Total)</option>
                </select>
            </div>
            
            <div class="mb-3">
                <label class="form-label small mb-1 text-white">Tenant Billing OBIS Code</label>
                <?php if ($is_tou_tenant): ?>
                    <input type="hidden" name="tenant_billing_obis" value="1.1.1.8.0">
                    <select class="form-select form-select-sm" disabled>
                        <option value="1.1.1.8.0" selected>1.1.1.8.0 (Total - Time Of Use)</option>
                    </select>
                    <div class="text-white-50 mt-1" style="font-size: 0.7rem;">Time Of Use tenants are always billed on 1.1.1.8.0.</div>
                <?php else: ?>
                <select name="tenant_billing_obis" class="form-select form-select-sm">
                    <option value="1.1.1.8.1" <?php echo $tenant_obis_code == '1.1.1.8.1' ? 'selected' : ''; ?>>1.1.1.8.1 (Tariff 1 - Standard)</option>
                    <option value="1.1.1.8.0" <?php echo $tenant_obis_code == '1.1.1.8.0' ? 'selected' : ''; ?>>1.1.1.8.0 (Total)</option>
                </select>
                <?php endif; ?>
            </div>

            <div class="form-check mb-2 border-top border-secondary pt-3 mt-3">
                <?php $est_can_run = ($occ_status === 'valid' || !empty($estimate_time_ranges)); ?>
                <input class="form-check-input" type="checkbox" id="use_estimated_readings" name="use_estimated_readings" value="1" 
                    <?php echo ($use_estimated_readings_requested && $est_can_run) ? 'checked' : ''; ?>
                    <?php echo !$est_can_run ? 'disabled' : ''; ?>>
                <label class="form-check-label small text-white" for="use_estimated_readings" style="opacity: 1;">Use Estimated Electrical Readings</label>
                <?php if ($occ_status === 'missing'): ?>
                    <div class="text-danger mt-1 fw-bold" style="font-size: 0.75rem;">Tenant Occupancy Dates have not been set.</div>
                <?php elseif ($occ_status === 'expired'): ?>
                    <div class="text-danger mt-1 fw-bold" style="font-size: 0.75rem;">Tenant Occupancy Dates expired on <?php echo date('d/m/Y', strtotime($tenant['tenant_occupancy_end_date'])); ?>.</div>
                <?php endif; ?>
            </div>

            <!-- Occupancy override: estimate time ranges for tenants with missing or expired occupancy dates -->
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="use_estimate_time_ranges" name="use_estimate_time_ranges" value="1"
                    <?php echo ($use_estimate_time_ranges_requested && !empty($estimate_time_ranges)) ? 'checked' : ''; ?>
                    <?php echo empty($estimate_time_ranges) ? 'disabled' : ''; ?>>
                <label class="form-check-label small text-white" for="use_estimate_time_ranges" style="opacity: 1;">Override Occupancy Dates (Estimate Time Ranges)</label>
            </div>

            <!-- Which part of the slip: tenants who want water and electricity separately are
                 billed on one record and given two documents -->
            <div class="mb-2">
                <label class="form-label small text-white mb-1" for="part">Slip covers</label>
                <select class="form-select form-select-sm bg-dark text-white border-secondary" id="part" name="part">
                    <option value="all" <?php echo ($slip_part === 'all') ? 'selected' : ''; ?>>Everything</option>
                    <option value="electricity" <?php echo ($slip_part === 'electricity') ? 'selected' : ''; ?>>Electricity only</option>
                    <option value="water" <?php echo ($slip_part === 'water') ? 'selected' : ''; ?>>Water and sewer only</option>
                </select>
                <?php if (!empty($tenant['slip_layout']) && $tenant['slip_layout'] === 'separate' && $slip_part === 'all'): ?>
                    <div class="small text-warning mt-1">This tenant is set to separate slips.</div>
                <?php endif; ?>
            </div>

            <?php
            // Does each estimate time range actually cover the period being billed? A window
            // left on last month's dates gives the estimate nothing to work with, and nothing
            // on the slip would show it, so it is said plainly here.
            $est_period_start = $start_date . ' 00:00:00';
            $est_period_end   = (!empty($end_date_2) ? $end_date_2 : $end_date) . ' 23:59:59';
            $est_not_covering = [];
            foreach ($estimate_time_ranges as $est_code => $est_win) {
                if (strtotime($est_win['start']) > strtotime($est_period_start)
                    || strtotime($est_win['end']) < strtotime($est_period_end)) {
                    $est_not_covering[$est_code] = $est_win;
                }
            }
            if (!empty($est_not_covering)):
            ?>
            <div class="alert alert-warning py-2 px-2 mb-2 small" role="alert">
                <i class="bi bi-exclamation-triangle me-1"></i>
                <strong>The estimate window does not cover this billing period.</strong>
                <div class="mt-1">
                    Billing <?php echo htmlspecialchars(substr($est_period_start, 0, 10)); ?>
                    to <?php echo htmlspecialchars(substr($est_period_end, 0, 10)); ?>, but:
                    <ul class="mb-1 ps-3">
                    <?php foreach ($est_not_covering as $est_code => $est_win): ?>
                        <li><?php echo htmlspecialchars($est_code); ?>:
                            <?php echo htmlspecialchars(substr($est_win['start'], 0, 16)); ?>
                            to <?php echo htmlspecialchars(substr($est_win['end'], 0, 16)); ?></li>
                    <?php endforeach; ?>
                    </ul>
                </div>
                <?php if (function_exists('lum_can') && lum_can('configs', 'edit')): ?>
                <button type="submit" form="lum_estimate_range_form" class="btn btn-sm btn-warning py-0 px-2 mt-1">Set the windows to this billing period</button>
                <?php else: ?>
                <div class="mt-1">An administrator can correct this under Configurations.</div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Linked electrical meters (current and historical, as used by the estimate) -->
            <?php
            $linked_meter_groups = [];
            $has_historical_meter = false;
            for ($slot_no = 1; $slot_no <= 3; $slot_no++) {
                if (empty($tenant['tenant_electricalMeter_0' . $slot_no])) continue;
                $slot_history = getTenantElecMeterHistory($tenant_db_conn, $tenant, $slot_no);
                $linked_meter_groups[$slot_no] = $slot_history;
                foreach ($slot_history as $h) {
                    if (empty($h['is_current'])) $has_historical_meter = true;
                }
            }
            ?>
            <div class="mt-3 p-2 bg-dark border border-secondary rounded">
                <label class="form-label small mb-1 text-white-50">Linked Electrical Meters:</label>
                <?php if (!$has_historical_meter): ?>
                    <div class="text-info fw-bold small">No historical meters</div>
                <?php else: ?>
                    <?php foreach ($linked_meter_groups as $slot_no => $slot_history): ?>
                        <?php if (count($linked_meter_groups) > 1): ?>
                            <div class="text-white-50 mt-1" style="font-size: 0.7rem;">Meter <?php echo $slot_no; ?></div>
                        <?php endif; ?>
                        <?php foreach ($slot_history as $h): ?>
                            <div class="d-flex justify-content-between align-items-center" style="font-size: 0.75rem;">
                                <span class="<?php echo !empty($h['is_current']) ? 'text-white' : 'text-info'; ?> fw-bold">
                                    <?php echo htmlspecialchars($h['serial']); ?>
                                    <?php if (abs($h['ct'] - 1.0) > 0.001): ?><span class="text-white-50 fw-normal">(CT <?php echo number_format($h['ct'], 2); ?>)</span><?php endif; ?>
                                </span>
                                <span class="text-white-50">
                                    <?php echo !empty($h['start']) ? date('d/m/Y', strtotime($h['start'])) : 'Earliest data'; ?>
                                    &ndash;
                                    <?php echo !empty($h['display_end']) ? date('d/m/Y', strtotime($h['display_end'])) : 'Present'; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    <div class="text-white-50 mt-1" style="font-size: 0.65rem;">All meters above are combined when estimating.</div>
                <?php endif; ?>
            </div>

            <?php if (!empty($available_manual_columns)): ?>
            <div class="mb-3 mt-3">
                <label class="form-label small mb-1 text-white">Manual Billing Override (Grid / T1)</label>
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
            
            <div class="mb-3">
                <label class="form-label small mb-1 text-white">Manual Billing Override (Gen / T2)</label>
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
            
            <?php if ($is_tou_tenant): ?>
            <div class="mb-1 mt-3">
                <label class="form-label small mb-1 text-white">TOU Algorithm</label>
                <select name="tou_algorithm" class="form-select form-select-sm">
                    <?php foreach (lumTouAlgorithmList() as $tou_opt): ?>
                    <option value="<?php echo htmlspecialchars($tou_opt); ?>" <?php echo $tou_algorithm === $tou_opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($tou_opt); ?></option>
                    <?php endforeach; ?>
                    <option value="None" <?php echo $tou_algorithm == 'None' ? 'selected' : ''; ?>>None (Flat)</option>
                </select>
            </div>
            <?php else: ?>
                <input type="hidden" name="tou_algorithm" value="None">
            <?php endif; ?>
        </div>

        <div class="sidebar-section shadow-sm">
            <h6 class="text-white pb-1 border-bottom border-secondary fw-normal mb-3" style="font-size: 0.9rem;">Electrical Period 1</h6>
            <div class="row g-2 mb-2">
                <div class="col-6">
                    <label class="form-label small mb-1 text-white">Start</label>
                    <input type="date" name="start_date" id="start_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($start_date); ?>" required>
                </div>
                <div class="col-6">
                    <label class="form-label small mb-1 text-white">End</label>
                    <input type="date" name="end_date" id="end_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($end_date); ?>" required>
                </div>
            </div>
        </div>

        <div class="sidebar-section shadow-sm">
            <h6 class="text-white pb-1 border-bottom border-secondary fw-normal mb-3" style="font-size: 0.9rem;">Electrical Period 2</h6>
            <div class="row g-2 mb-2">
                <div class="col-6">
                    <label class="form-label small mb-1 text-white">Start</label>
                    <input type="date" name="start_date_2" id="start_date_2" class="form-control form-control-sm" value="<?php echo htmlspecialchars($start_date_2); ?>">
                </div>
                <div class="col-6">
                    <label class="form-label small mb-1 text-white">End</label>
                    <input type="date" name="end_date_2" id="end_date_2" class="form-control form-control-sm" value="<?php echo htmlspecialchars($end_date_2); ?>">
                </div>
            </div>
        </div>

        <div class="sidebar-section shadow-sm">
            <h6 class="text-white pb-1 border-bottom border-secondary fw-normal mb-3" style="font-size: 0.9rem;">Water Period 1</h6>
            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label small mb-1 text-white">Start</label>
                    <input type="date" name="water_start_date" id="water_start_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($water_start_date); ?>" required>
                </div>
                <div class="col-6">
                    <label class="form-label small mb-1 text-white">End</label>
                    <input type="date" name="water_end_date" id="water_end_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($water_end_date); ?>" required>
                </div>
            </div>
        </div>
        
        <div class="sidebar-section shadow-sm">
            <h6 class="text-white pb-1 border-bottom border-secondary fw-normal mb-3" style="font-size: 0.9rem;">Water Period 2</h6>
            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label small mb-1 text-white">Start</label>
                    <input type="date" name="water_start_date_2" id="water_start_date_2" class="form-control form-control-sm" value="<?php echo htmlspecialchars($water_start_date_2); ?>">
                </div>
                <div class="col-6">
                    <label class="form-label small mb-1 text-white">End</label>
                    <input type="date" name="water_end_date_2" id="water_end_date_2" class="form-control form-control-sm" value="<?php echo htmlspecialchars($water_end_date_2); ?>">
                </div>
            </div>
        </div>

        <?php if ($has_generator): ?>
        <div class="sidebar-section shadow-sm">
            <h6 class="text-white pb-1 border-bottom border-secondary fw-normal mb-3" style="font-size: 0.9rem;">Generator (T2) Settings</h6>
            <div class="mb-3">
                <label class="form-label small mb-1 text-white">Calculation Method</label>
                <select name="t2_method" id="t2_method" class="form-select form-select-sm" onchange="document.getElementById('runtime-opts').style.display = this.value === 'runtime' ? 'block' : 'none';">
                    <option value="1.1.1.8.2" <?php echo $t2_method == '1.1.1.8.2' ? 'selected' : ''; ?>>OBIS 1.1.1.8.2 Reading</option>
                    <option value="runtime" <?php echo $t2_method == 'runtime' ? 'selected' : ''; ?>>Run Time Calculation</option>
                </select>
            </div>
            <div id="runtime-opts" style="display: <?php echo ($t2_method == 'runtime' || $manual_override_col_t2 !== 'none') ? 'block' : 'none'; ?>;">
                <div class="mb-3">
                    <label class="form-label small mb-1 text-white">Daily Avg Base OBIS</label>
                    <select name="t2_base_obis" id="t2_base_obis" class="form-select form-select-sm">
                        <option value="1.1.1.8.0" <?php echo $t2_base_obis == '1.1.1.8.0' ? 'selected' : ''; ?>>1.1.1.8.0 (Total)</option>
                        <option value="1.1.1.8.1" <?php echo $t2_base_obis == '1.1.1.8.1' ? 'selected' : ''; ?>>1.1.1.8.1 (Tariff 1)</option>
                    </select>
                </div>
                <div class="row g-2 mb-1">
                    <div class="col-6">
                        <label class="form-label small mb-1 text-white">Start Date</label>
                        <input type="date" name="gen_start_date" id="gen_start_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($gen_runtime_start); ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-1 text-white">End Date</label>
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

        <?php if ($pays_elec_comm): ?>
        <div class="sidebar-section shadow-sm">
            <h6 class="text-white pb-1 border-bottom border-secondary fw-normal mb-3" style="font-size: 0.9rem;">Elec Common Area Base</h6>
            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label small mb-1 text-white">Start Date</label>
                    <input type="date" name="comm_start_date" id="comm_start_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($comm_start_date); ?>">
                </div>
                <div class="col-6">
                    <label class="form-label small mb-1 text-white">End Date</label>
                    <input type="date" name="comm_end_date" id="comm_end_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($comm_end_date); ?>">
                </div>
                
                <?php 
                // Manual total only for properties without their own common area calculation (property billing settings)
                $sidebar_prop_settings = $property_settings ?? lumPropertySettings($tenant['tenant_property'] ?? '');
                if ($sidebar_prop_settings['elec_common_area_method'] === 'manual'): 
                ?>
                <div class="col-12 mt-2">
                    <label class="form-label small mb-1 text-white">Prop Total Base (kWh)</label>
                    <input type="number" step="0.01" name="manual_prop_elec_comm" id="manual_prop_elec_comm" class="form-control form-control-sm" value="<?php echo htmlspecialchars($manual_prop_elec_comm); ?>">
                </div>
                <?php endif; ?>

                <div class="col-12 mt-2">
                    <label class="form-label small mb-1 text-white">Reduction Discount (%)</label>
                    <div class="input-group input-group-sm">
                        <input type="number" step="0.01" name="elec_comm_discount" id="elec_comm_discount" class="form-control" value="<?php echo htmlspecialchars($elec_comm_discount); ?>">
                        <span class="input-group-text">%</span>
                    </div>
                </div>
            </div>
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="remove_elec_comm" name="remove_elec_comm" value="1" <?php echo $remove_elec_comm ? 'checked' : ''; ?>>
                <label class="form-check-label small text-white" for="remove_elec_comm">Remove from Slip</label>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($pays_water_comm): ?>
        <div class="sidebar-section shadow-sm">
            <h6 class="text-white pb-1 border-bottom border-secondary fw-normal mb-3" style="font-size: 0.9rem;">Water Common Area Base</h6>
            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label small mb-1 text-white">Start Date</label>
                    <input type="date" name="water_comm_start_date" id="water_comm_start_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($water_start_date); ?>">
                </div>
                <div class="col-6">
                    <label class="form-label small mb-1 text-white">End Date</label>
                    <input type="date" name="water_comm_end_date" id="water_comm_end_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($water_end_date); ?>">
                </div>
                
                <?php 
                $sidebar_prop_settings = $property_settings ?? lumPropertySettings($tenant['tenant_property'] ?? '');
                $is_auto_water = ($sidebar_prop_settings['water_common_area_method'] !== 'manual');
                if (!$is_auto_water): 
                ?>
                <div class="col-12 mt-2">
                    <label class="form-label small mb-1 text-white">Prop Total Base (kL)</label>
                    <input type="number" step="0.01" name="manual_prop_water_comm" id="manual_prop_water_comm" class="form-control form-control-sm" value="<?php echo htmlspecialchars($manual_prop_water_comm); ?>">
                </div>
                <?php endif; ?>

                <div class="col-12 mt-2">
                    <label class="form-label small mb-1 text-white">Reduction Discount (%)</label>
                    <div class="input-group input-group-sm">
                        <input type="number" step="0.01" name="water_comm_discount" id="water_comm_discount" class="form-control" value="<?php echo htmlspecialchars($water_comm_discount); ?>">
                        <span class="input-group-text">%</span>
                    </div>
                </div>
            </div>
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="remove_water_comm" name="remove_water_comm" value="1" <?php echo $remove_water_comm ? 'checked' : ''; ?>>
                <label class="form-check-label small text-white" for="remove_water_comm">Remove from Slip</label>
            </div>
        </div>
        <?php endif; ?>

        <div class="sidebar-section shadow-sm">
            <h6 class="text-white pb-1 border-bottom border-secondary fw-normal mb-3" style="font-size: 0.9rem;">Graphs</h6>
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="show_graph" name="show_graph" value="1" <?php echo $show_graph ? 'checked' : ''; ?>>
                <label class="form-check-label small text-white" for="show_graph">Add Electrical Usage Graph</label>
            </div>
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="show_water_graph" name="show_water_graph" value="1" <?php echo $show_water_graph ? 'checked' : ''; ?>>
                <label class="form-check-label small text-white" for="show_water_graph">Add Water Usage Graph</label>
            </div>
        </div>

        <div class="d-flex gap-2 mt-4 mb-4">
            <button type="button" onclick="window.print()" class="btn btn-danger btn-sm w-100"><i class="bi bi-printer me-1"></i> Print / PDF</button>
            <a href="?<?php echo htmlspecialchars(http_build_query(array_filter(['tenant_id' => $tenant_id, 'return_search' => $return_search, 'return_to' => $return_to, 'return_query' => $return_query], function ($v) { return (string)$v !== ''; }))); ?>" class="btn btn-secondary btn-sm w-100"><i class="bi bi-arrow-counterclockwise me-1"></i> Slip Reset</a>
            <?php if ($tou_algorithm !== 'None' && !empty($elec_meters)): ?>
                <?php $first_meter = reset($elec_meters); ?>
                <a href="tou-analysis-report.php?meter_id=<?php echo urlencode($first_meter); ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode(empty($end_date_2) ? $end_date : $end_date_2); ?>&obis_code=<?php echo urlencode($tenant_obis_code); ?>&tou_algorithm=<?php echo urlencode($tou_algorithm); ?>&tenant_id=<?php echo urlencode($tenant_id); ?>" target="_blank" class="btn btn-info btn-sm text-dark w-100"><i class="bi bi-clipboard-data me-1"></i> TOU Math Analysis</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if (!empty($est_not_covering) && function_exists('lum_can') && lum_can('configs', 'edit')): ?>
    <form method="POST" action="https://lynx-um.co.za/Configs/estimate-range-set.php" id="lum_estimate_range_form" class="d-none">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
        <input type="hidden" name="period_start" value="<?php echo htmlspecialchars(substr($est_period_start, 0, 10)); ?>">
        <input type="hidden" name="period_end" value="<?php echo htmlspecialchars(substr($est_period_end, 0, 10)); ?>">
        <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? ''); ?>">
    </form>
    <?php endif; ?>
</div>

<script>
    <?php if ($return_to === 'financial'): ?>
    <?php if (!empty($lum_slip_submitted) || !empty($lum_report_settings_msg)): ?>
    // Remember (for this tab) that the report needs recalculating when we go back
    sessionStorage.setItem('lumSlipChangedReport', '1');
    <?php endif; ?>
    // Opened from the financial report in its own tab: Back closes this tab and returns to the report.
    // If the report tab is gone, or the browser does not allow closing, Back opens the report here instead.
    (function () {
        const backBtn = document.getElementById('lumSlipBack');
        if (!backBtn) return;
        backBtn.addEventListener('click', function (e) {
            let reportTab = null;
            try {
                if (window.opener && !window.opener.closed
                    && window.opener.location.pathname.indexOf('financial-reporting-overview.php') !== -1) {
                    reportTab = window.opener;
                }
            } catch (err) {
                reportTab = null; // The opener is not one of our pages
            }
            if (!reportTab) return; // Normal link: load the report in this tab

            e.preventDefault();
            // Settings were changed on this slip: reload the report with the synced settings
            if (sessionStorage.getItem('lumSlipChangedReport') === '1') {
                try { reportTab.location.href = backBtn.href; } catch (err) {}
            }
            try { reportTab.focus(); } catch (err) {}
            window.close();
            // Still open after a moment? Then the browser refused to close it: show the report here
            const backUrl = backBtn.href;
            setTimeout(function () { if (!window.closed) window.location.href = backUrl; }, 400);
        });
    })();
    <?php endif; ?>

    function transmitData() {
        try {
            const form = document.getElementById('slip_form_final');
            if (!form) return;
            
            const sidebarEl = document.querySelector('.sidebar');
            const mainContentEl = document.querySelector('.main-content');
            if (sidebarEl) sessionStorage.setItem('lynxSidebarScroll', sidebarEl.scrollTop);
            if (mainContentEl) sessionStorage.setItem('lynxMainScroll', mainContentEl.scrollTop);
            
            const fd = new FormData(form);
            const obj = {};
            
            fd.forEach((value, key) => {
                if (value !== '' && value !== null && key !== 'billing_cycle_dropdown') {
                    obj[key] = value;
                }
            });
            
            const jsonStr = JSON.stringify(obj);
            
            let hexPayload = '';
            for (let i = 0; i < jsonStr.length; i++) {
                hexPayload += jsonStr.charCodeAt(i).toString(16).padStart(2, '0');
            }
            
            const hiddenForm = document.createElement('form');
            hiddenForm.method = 'POST';
            hiddenForm.action = window.location.pathname + '?tenant_id=' + encodeURIComponent(obj['tenant_id'] || '') + '&return_search=' + encodeURIComponent(obj['return_search'] || '');
            
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
        } catch (e) {
            console.error("Transmission Error:", e);
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('slip_form_final');
        const sidebarEl = document.querySelector('.sidebar');
        const mainContentEl = document.querySelector('.main-content');
        
        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                transmitData();
            });
        }

        if (sidebarEl) {
            const savedScroll = sessionStorage.getItem('lynxSidebarScroll');
            if (savedScroll) {
                sidebarEl.scrollTop = parseInt(savedScroll, 10);
                sessionStorage.removeItem('lynxSidebarScroll');
            }
        }

        if (mainContentEl) {
            const savedMainScroll = sessionStorage.getItem('lynxMainScroll');
            if (savedMainScroll) {
                mainContentEl.scrollTop = parseInt(savedMainScroll, 10);
                sessionStorage.removeItem('lynxMainScroll');
            }
        }
        
        let timeout;
        function autoUpdate() {
            clearTimeout(timeout);
            timeout = setTimeout(() => {
                transmitData();
            }, 400);
        }

        if (form) {
            const inputs = form.querySelectorAll('input:not([type="hidden"]):not(#billing_cycle_dropdown), select:not(#billing_cycle_dropdown)');
            inputs.forEach(input => {
                input.addEventListener('change', autoUpdate);
            });
        }

        const elecStart = document.querySelector('[name="start_date"]');
        const elecEnd = document.querySelector('[name="end_date"]');

        if (elecStart) {
            elecStart.addEventListener('change', function() {
                const genStart = document.querySelector('[name="gen_start_date"]');
                if (genStart) genStart.value = this.value;
            });
        }

        // Run-time End Date follows the end of the billing cycle (Period 2 end when it is filled in)
        const elecEnd2 = document.querySelector('[name="end_date_2"]');
        const syncGenEnd = function() {
            const genEnd = document.querySelector('[name="gen_end_date"]');
            if (!genEnd) return;
            genEnd.value = (elecEnd2 && elecEnd2.value) ? elecEnd2.value : (elecEnd ? elecEnd.value : genEnd.value);
        };
        if (elecEnd) elecEnd.addEventListener('change', syncGenEnd);
        if (elecEnd2) elecEnd2.addEventListener('change', syncGenEnd);
    });

    // A billing cycle spanning two months is split into Period 1 (to month end) and Period 2 (from the 1st)
    function applyBillingCycle(val) {
        try {
            if (!val) return;
            const parts = val.split('_TO_');
            if (parts.length !== 2) return;
            
            const startStr = parts[0].trim(); 
            const endStr = parts[1].trim();   
            
            const sParts = startStr.split('-');
            const sYear = parseInt(sParts[0], 10);
            const sMonth = parseInt(sParts[1], 10);
            
            const eParts = endStr.split('-');
            const eYear = parseInt(eParts[0], 10);
            const eMonth = parseInt(eParts[1], 10);
            
            const setVal = (name, val) => { 
                const el = document.querySelector(`[name="${name}"]`); 
                if (el) el.value = val; 
            };
            
            if (sYear === eYear && sMonth === eMonth) {
                setVal('start_date', startStr);
                setVal('end_date', endStr);
                setVal('start_date_2', '');
                setVal('end_date_2', '');
                setVal('water_start_date', startStr);
                setVal('water_end_date', endStr);
                setVal('water_start_date_2', '');
                setVal('water_end_date_2', '');
            } else {
                const endOfStartMonth = new Date(sYear, sMonth, 0); 
                const lastDay = String(endOfStartMonth.getDate()).padStart(2, '0');
                const p1EndStr = `${sYear}-${String(sMonth).padStart(2, '0')}-${lastDay}`;
                const p2StartStr = `${eYear}-${String(eMonth).padStart(2, '0')}-01`;

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
            
            setTimeout(transmitData, 50); 
            
        } catch (e) {
            console.error("Billing Cycle processing failed: ", e);
            // Fall back to a plain form submit
            const form = document.getElementById('slip_form_final');
            if (form) form.submit();
        }
    }
</script>