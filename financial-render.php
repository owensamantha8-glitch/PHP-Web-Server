<?php
// Library file: only other pages may include it, it can never be opened directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(403); exit('Forbidden'); }
?>
<?php if (empty($selected_property)): ?>
    <div class="d-flex align-items-center justify-content-center h-100 text-muted">
        <div class="text-center">
            <i class="bi bi-file-earmark-bar-graph fs-1" style="font-size: 3rem;"></i>
            <h4 class="mt-3">Financial Reporting Overview</h4>
            <p>Select a property from the sidebar to generate the aggregated recovery report.</p>
        </div>
    </div>
<?php else: ?>

<?php if (!empty($is_submitted)): ?>
<!-- FINANCIAL JOURNAL (not part of the printed report) -->
<div class="d-print-none border shadow-sm bg-white p-3 mb-3 mx-auto" style="max-width: 100%;">
    <?php if (!empty($journal_message['text'])): ?>
        <div class="alert alert-<?php echo htmlspecialchars($journal_message['type'] ?? 'info'); ?> py-2 small mb-3"><?php echo htmlspecialchars($journal_message['text']); ?></div>
    <?php endif; ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <div class="fw-bold text-dark"><i class="bi bi-journal-bookmark-fill me-2"></i>Financial Journal &mdash; billing month <?php echo htmlspecialchars(sprintf('%02d/%04d', $report_month, $report_year)); ?></div>
            <div class="small text-muted">
                <?php if (!empty($journal_active)): ?>
                    Journaled on <?php echo htmlspecialchars(date('d M Y H:i', strtotime($journal_active['created_at']))); ?>
                    by <?php echo htmlspecialchars($journal_active['created_by_name']); ?>
                    (Journal #<?php echo (int)$journal_active['journal_id']; ?>, Grand Total R <?php echo number_format((float)$journal_active['grand_total'], 2); ?>).
                    <a href="financial-journal.php?id=<?php echo (int)$journal_active['journal_id']; ?>">View journal</a>
                    <?php if (abs((float)$journal_active['grand_total'] - (float)$grand_total) > 0.009): ?>
                        <br><span class="text-danger">This report now differs from the journal (R <?php echo number_format((float)$grand_total - (float)$journal_active['grand_total'], 2); ?>).</span>
                    <?php endif; ?>
                <?php elseif (empty($journal_db)): ?>
                    <span class="text-danger">The journal database is not available (run financial-journal-setup.sql).</span>
                <?php else: ?>
                    This report has not been journaled yet.
                <?php endif; ?>
                <?php $lum_own = count(array_filter(array_column($tenant_rows, 'settings'))); ?>
                <?php $lum_own_cycle = count(array_filter(array_column(array_column($tenant_rows, 'period'), 'own'))); ?>
                <?php if ($lum_own > 0): ?>
                    <br><?php echo $lum_own; ?> tenant(s) use their own settings chosen on their consumption slip (marked below)<?php echo $lum_own_cycle > 0 ? ', ' . $lum_own_cycle . ' of them with their own billing cycle' : ''; ?>.
                <?php endif; ?>
            </div>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <?php if (lum_can('back_billing', 'view') && is_readable(__DIR__ . '/back-billing.php')): ?>
                <a href="back-billing.php?property=<?php echo urlencode($selected_property); ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left-right me-1"></i>Back Billing</a>
            <?php endif; ?>
            <a href="financial-journal.php?property=<?php echo urlencode($selected_property); ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-clock-history me-1"></i>Journal History</a>
            <?php if (!empty($journal_can_create) && !empty($journal_db) && !empty($tenant_rows)): ?>
            <form method="POST" action="financial-reporting-overview.php?<?php echo htmlspecialchars(http_build_query($_GET)); ?>" class="d-flex gap-2 m-0"
                  onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(!empty($journal_active)
                      ? 'Journal this report? Journal #' . $journal_active['journal_id'] . ' for this billing month will be marked as superseded.'
                      : 'Journal this report? All values shown will be stored in the financial journal.'), ENT_QUOTES); ?>);">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="journal_action" value="create">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="text" name="journal_notes" class="form-control form-control-sm" maxlength="255" placeholder="Note (optional)" style="width: 200px;">
                <button type="submit" class="btn btn-dark btn-sm text-nowrap"><i class="bi bi-journal-plus me-1"></i><?php echo !empty($journal_active) ? 'Journal Again' : 'Journal this Report'; ?></button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="p-4 p-md-5 border shadow-sm report-container" id="report-content">
    
    <div class="d-flex justify-content-between align-items-end mb-4 border-bottom pb-3">
        <div>
            <h2 class="fw-bold text-dark mb-1">Financial Recovery Overview</h2>
            <h5 class="text-muted mb-0"><?php echo htmlspecialchars($selected_property); ?></h5>
        </div>
        <div class="text-end">
            <span class="badge bg-secondary mb-2" style="font-size: 0.9rem;">
                <?php echo date('d M Y', strtotime($start_date)); ?> to <?php echo date('d M Y', strtotime(empty($end_date_2) ? $end_date : $end_date_2)); ?>
            </span>
            <div class="text-muted small fw-bold">Tenants Billed: <?php echo $tenant_count; ?></div>
        </div>
    </div>

    <h5 class="fw-bold text-dark mb-3">Tenant Breakdown</h5>
    <div class="table-responsive mb-5">
        <table class="table table-striped table-bordered table-sm align-middle" style="font-size: 0.85rem;">
            <thead class="table-dark">
                <tr>
                    <th>Tenant</th>
                    <th>Shop</th>
                    <th>Elec Tariff</th>
                    <th class="text-end">Elec (kWh)</th>
                    <th class="text-end">Total Elec (R)</th>
                    <th>Water Tariff</th>
                    <th class="text-end">Water (kL)</th>
                    <th class="text-end">Total Water (R)</th>
                    <th class="text-end" title="Common area charges already included in Total Elec and Total Water">Incl. Comm Area (R)</th>
                    <th class="text-end">Refuse (R)</th>
                    <th class="text-end" title="Approved back billing (+) and refunds (-) billed in this month">Adjustments (R)</th>
                    <th class="text-end">Grand Total (R)</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Open any tenant's single consumption slip with exactly the same report settings
                $slip_link_params = $_GET;
                unset($slip_link_params['property'], $slip_link_params['show_tenant_bar'], $slip_link_params['show_elec_pie'], $slip_link_params['show_water_pie'], $slip_link_params['show_daily_fin']);
                $slip_link_base = lum_app_url('/Tenant%20Management/Tenant%20Consumption%20Slips/view-consumption-slip.php');
                // The slip's Back button returns to this report with exactly the same settings
                $slip_link_params['return_to'] = 'financial';
                $slip_link_params['return_query'] = http_build_query($_GET);
                $sum_elec_kwh = 0; $sum_elec_r = 0; $sum_water_kl = 0; $sum_water_r = 0; $sum_comm_r = 0; $sum_refuse_r = 0; $sum_adj_r = 0; $sum_total_r = 0;
                ?>
                <?php foreach ($tenant_rows as $row):
                    $sum_elec_kwh += $row['elec_kwh']; $sum_elec_r += $row['elec_r'];
                    $sum_water_kl += $row['water_kl']; $sum_water_r += $row['water_r'];
                    $sum_comm_r += $row['comm_r']; $sum_refuse_r += $row['refuse_r']; $sum_adj_r += ($row['adj_r'] ?? 0); $sum_total_r += $row['total_r'];
                    $slip_link = $slip_link_base . '?' . http_build_query(array_merge(['tenant_id' => $row['tenant_id'] ?? '', 'return_search' => ''], $slip_link_params));
                ?>
                <tr>
                    <td class="fw-semibold text-dark">
                        <?php echo htmlspecialchars($row['name']); ?>
                        <?php if (!empty($row['settings'])):
                            $lum_tip = [];
                            foreach ($row['settings'] as $lum_k => $lum_v) { $lum_tip[] = lumTenantSettingLabel($lum_k) . ': ' . lumTenantSettingValue($lum_k, $lum_v); } ?>
                            <span class="badge bg-info text-dark ms-1 d-print-none" title="<?php echo htmlspecialchars("Own settings from the consumption slip:\n" . implode("\n", $lum_tip)); ?>"><i class="bi bi-sliders"></i></span>
                        <?php endif; ?>
                        <?php if (!empty($row['tenant_id'])): ?>
                        <a href="<?php echo htmlspecialchars($slip_link); ?>" target="_blank" rel="opener" class="text-decoration-none ms-1 d-print-none lum-slip-link" title="Open this tenant's consumption slip in a new tab (Back closes it and returns to this report)"><i class="bi bi-box-arrow-up-right small"></i></a>
                        <?php endif; ?>
                        <?php if (!empty($row['period']['own'])): $lum_p = $row['period']; ?>
                            <div class="small fw-normal text-primary" title="This tenant has its own billing cycle within the property billing cycle">
                                <i class="bi bi-calendar-range me-1"></i><?php echo htmlspecialchars(date('d M', strtotime($lum_p['start'])) . ' - ' . date('d M Y', strtotime($lum_p['end']))); ?>
                                <?php if ($lum_p['start_2'] !== ''): ?> &amp; <?php echo htmlspecialchars(date('d M', strtotime($lum_p['start_2'])) . ' - ' . date('d M Y', strtotime($lum_p['end_2']))); ?><?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($row['shop']); ?></td>
                    <td class="text-muted"><?php echo htmlspecialchars($row['elec_tariff']); ?></td>
                    <td class="text-end"><?php echo number_format($row['elec_kwh'], 2); ?></td>
                    <td class="text-end text-success fw-semibold"><?php echo number_format($row['elec_r'], 2); ?></td>
                    <td class="text-muted"><?php echo htmlspecialchars($row['water_tariff']); ?></td>
                    <td class="text-end"><?php echo number_format($row['water_kl'], 2); ?></td>
                    <td class="text-end text-info fw-semibold"><?php echo number_format($row['water_r'], 2); ?></td>
                    <td class="text-end text-secondary fw-semibold"><?php echo number_format($row['comm_r'], 2); ?></td>
                    <td class="text-end text-secondary fw-semibold"><?php echo number_format($row['refuse_r'], 2); ?></td>
                    <td class="text-end fw-semibold <?php echo ($row['adj_r'] ?? 0) < 0 ? 'text-success' : (($row['adj_r'] ?? 0) > 0 ? 'text-danger' : 'text-secondary'); ?>"
                        title="<?php echo htmlspecialchars(implode(', ', array_map(function ($a) { return lumAdjNumber($a['adjustment_id']) . ' ' . number_format($a['total'], 2); }, $row['adjustments'] ?? []))); ?>">
                        <?php echo number_format($row['adj_r'] ?? 0, 2); ?>
                    </td>
                    <td class="text-end fw-bold text-dark bg-light"><?php echo number_format($row['total_r'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
                
                <?php if (empty($tenant_rows)): ?>
                <tr>
                    <td colspan="12" class="text-center text-muted py-3">No tenants found for this property.</td>
                </tr>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($tenant_rows)): ?>
            <tfoot class="table-light">
                <tr class="fw-bold">
                    <td colspan="3">Totals</td>
                    <td class="text-end"><?php echo number_format($sum_elec_kwh, 2); ?></td>
                    <td class="text-end"><?php echo number_format($sum_elec_r, 2); ?></td>
                    <td></td>
                    <td class="text-end"><?php echo number_format($sum_water_kl, 2); ?></td>
                    <td class="text-end"><?php echo number_format($sum_water_r, 2); ?></td>
                    <td class="text-end"><?php echo number_format($sum_comm_r, 2); ?></td>
                    <td class="text-end"><?php echo number_format($sum_refuse_r, 2); ?></td>
                    <td class="text-end"><?php echo number_format($sum_adj_r, 2); ?></td>
                    <td class="text-end"><?php echo number_format($sum_total_r, 2); ?></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>

    <div class="row g-4 mt-2">
        <div class="col-md-6">
            <h5 class="fw-bold text-dark border-bottom border-2 pb-2 mb-3">Electricity — Recovery Totals</h5>
            <div class="table-responsive">
                <table class="table table-bordered table-sm table-data">
                    <tbody>
                        <tr><th>Energy Charge (R)</th><td>R <?php echo number_format($accum_energy_charge, 2); ?></td></tr>
                        <tr><th>Basic Charge (R)</th><td>R <?php echo number_format($accum_basic_charge, 2); ?></td></tr>
                        <tr><th>Network Access (R)</th><td>R <?php echo number_format($accum_nac, 2); ?></td></tr>
                        <tr><th>Capacity Charge (R)</th><td>R <?php echo number_format($accum_capacity, 2); ?></td></tr>
                        <tr><th>Demand Charge (R)</th><td>R <?php echo number_format($accum_demand, 2); ?></td></tr>
                        <tr><th>Generator Charge (R)</th><td>R <?php echo number_format($accum_gen_charge, 2); ?></td></tr>
                        <tr><th>Generator Common Area Charge (R)</th><td>R <?php echo number_format($accum_elec_comm_charge, 2); ?></td></tr>
                        <tr class="table-light"><th>Total Electricity (R)</th><td class="fw-bold">R <?php echo number_format($total_electricity_r, 2); ?></td></tr>
                        
                        <tr><th>Usage (kWh)</th><td><?php echo number_format($accum_usage_kwh, 2); ?></td></tr>
                        <tr><th>kWh Common Allocation</th><td><?php echo number_format($accum_comm_kwh, 2); ?></td></tr>
                        <tr class="table-light"><th>Total kWh Recovered</th><td class="fw-bold"><?php echo number_format($total_kwh_recovered, 2); ?></td></tr>
                        <tr><th>Units not recovered (kWh)</th><td class="text-danger fw-semibold"><?php echo number_format($units_not_recovered, 2); ?></td></tr>
                        
                        <tr><th>Recovered R/kWh</th><td>R <?php echo number_format($recovered_r_kwh, 2); ?></td></tr>
                        <tr><th>Recovered kWh</th><td><?php echo number_format($total_kwh_recovered, 2); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="col-md-6">
            <h5 class="fw-bold text-dark border-bottom border-2 pb-2 mb-3">Water & Sewer — Recovery Totals</h5>
            <div class="table-responsive">
                <table class="table table-bordered table-sm table-data">
                    <tbody>
                        <tr><th>Water Basic Charge (R)</th><td>R <?php echo number_format($accum_water_basic, 2); ?></td></tr>
                        <tr><th>Water Charge (R)</th><td>R <?php echo number_format($accum_water_charge, 2); ?></td></tr>
                        <tr><th>Sewer Charge (R)</th><td>R <?php echo number_format($accum_sewer_charge, 2); ?></td></tr>
                        <tr><th>Sewer Common Area Charge (R)</th><td>R <?php echo number_format($accum_water_sewer_comm_charge, 2); ?></td></tr>
                        <tr class="table-light"><th>Total Water & Sewer (R)</th><td class="fw-bold">R <?php echo number_format($total_water_sewer_r, 2); ?></td></tr>
                        
                        <tr><th>Recovered kL (own + common)</th><td><?php echo number_format($total_kl_recovered, 2); ?></td></tr>
                        <tr><th>Recovered R/kL</th><td>R <?php echo number_format($recovered_r_kl, 2); ?></td></tr>
                        <tr><th>Recovered kL</th><td><?php echo number_format($total_kl_recovered, 2); ?></td></tr>
                        <tr><th>Units not recovered (kL)</th><td class="text-danger fw-semibold"><?php echo number_format($water_units_not_recovered, 2); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Grand Total -->
    <div class="mt-4 border-top pt-3">
        <table class="table table-bordered table-sm table-data m-0">
            <tbody>
                <?php if ($accum_refuse_charge > 0): ?>
                <tr><th class="text-dark fs-6 align-middle bg-light" style="background-color: #f8f9fa;">Total Refuse Recovery</th><td class="fw-bold fs-6 align-middle text-dark bg-light" style="background-color: #f8f9fa;">R <?php echo number_format($accum_refuse_charge, 2); ?></td></tr>
                <?php endif; ?>
                <?php if (abs($accum_adjustments ?? 0) >= 0.005): ?>
                <tr><th class="text-dark fs-6 align-middle bg-light" style="background-color: #f8f9fa;">Back Billing &amp; Refunds (<?php echo count($report_adjustments ?? []); ?> adjustment<?php echo count($report_adjustments ?? []) === 1 ? '' : 's'; ?>)</th><td class="fw-bold fs-6 align-middle bg-light <?php echo $accum_adjustments < 0 ? 'text-success' : 'text-danger'; ?>" style="background-color: #f8f9fa;">R <?php echo number_format($accum_adjustments, 2); ?></td></tr>
                <?php endif; ?>
                <tr><th class="text-dark fs-5 align-middle bg-light" style="background-color: #f8f9fa;">Grand Total Billed</th><td class="fw-bold fs-4 align-middle text-dark bg-light" style="background-color: #f8f9fa;">R <?php echo number_format($grand_total, 2); ?></td></tr>
            </tbody>
        </table>
    </div>

    <!-- NEW VISUALIZATION SECTIONS -->
    <div class="mt-5 <?php echo isset($_GET['show_tenant_bar']) ? 'd-print-block collapse-print-force' : 'd-print-none'; ?>">
        <div class="d-flex justify-content-between align-items-center border-bottom border-secondary pb-2 mb-3 print-hide-toggle" data-bs-toggle="collapse" data-bs-target="#tenantBarCollapse" style="cursor: pointer;" id="tenantBarToggleBtn">
            <h5 class="fw-bold text-dark mb-0 fs-5"><i class="bi bi-bar-chart-fill me-2 text-primary"></i> Tenant Billing Breakdown</h5>
            <i class="bi bi-plus-circle fs-5 text-dark toggle-icon" style="transition: transform 0.2s;"></i>
        </div>
        <div class="collapse <?php echo isset($_GET['show_tenant_bar']) ? 'collapse-print-force' : ''; ?>" id="tenantBarCollapse">
            <div class="card border border-secondary p-4 bg-light shadow-sm text-center">
                <h6 class="fw-bold text-dark mb-3 d-none d-print-block">Tenant Billing Breakdown</h6>
                <div style="height: 350px; width: 100%;">
                    <canvas id="tenantBarChart" data-chart='<?php echo htmlspecialchars(json_encode($tenant_bar_data), ENT_QUOTES, 'UTF-8'); ?>'></canvas>
                </div>
            </div>
        </div>
    </div>
    
    <div class="mt-4 <?php echo isset($_GET['show_elec_pie']) ? 'd-print-block collapse-print-force' : 'd-print-none'; ?>">
        <div class="d-flex justify-content-between align-items-center border-bottom border-secondary pb-2 mb-3 print-hide-toggle" data-bs-toggle="collapse" data-bs-target="#elecPieCollapse" style="cursor: pointer;" id="elecPieToggleBtn">
            <h5 class="fw-bold text-dark mb-0 fs-5"><i class="bi bi-pie-chart-fill text-danger me-2"></i> Electricity Recovery Visualization</h5>
            <i class="bi bi-plus-circle fs-5 text-dark toggle-icon" style="transition: transform 0.2s;"></i>
        </div>
        <div class="collapse <?php echo isset($_GET['show_elec_pie']) ? 'collapse-print-force' : ''; ?>" id="elecPieCollapse">
            <div class="card border border-secondary p-4 bg-light shadow-sm text-center">
                <h6 class="fw-bold text-dark mb-3 d-none d-print-block">Electricity Recovery Visualization</h6>
                <div style="height: 350px; width: 100%; display: flex; justify-content: center;">
                    <canvas id="elecPieChart" data-chart='<?php echo htmlspecialchars(json_encode($elec_pie_data), ENT_QUOTES, 'UTF-8'); ?>'></canvas>
                </div>
            </div>
        </div>
    </div>
    
    <div class="mt-4 <?php echo isset($_GET['show_water_pie']) ? 'd-print-block collapse-print-force' : 'd-print-none'; ?>">
        <div class="d-flex justify-content-between align-items-center border-bottom border-secondary pb-2 mb-3 print-hide-toggle" data-bs-toggle="collapse" data-bs-target="#waterPieCollapse" style="cursor: pointer;" id="waterPieToggleBtn">
            <h5 class="fw-bold text-dark mb-0 fs-5"><i class="bi bi-pie-chart-fill text-info me-2"></i> Water & Sewer Recovery Visualization</h5>
            <i class="bi bi-plus-circle fs-5 text-dark toggle-icon" style="transition: transform 0.2s;"></i>
        </div>
        <div class="collapse <?php echo isset($_GET['show_water_pie']) ? 'collapse-print-force' : ''; ?>" id="waterPieCollapse">
            <div class="card border border-secondary p-4 bg-light shadow-sm text-center">
                <h6 class="fw-bold text-dark mb-3 d-none d-print-block">Water & Sewer Recovery Visualization</h6>
                <div style="height: 350px; width: 100%; display: flex; justify-content: center;">
                    <canvas id="waterPieChart" data-chart='<?php echo htmlspecialchars(json_encode($water_pie_data), ENT_QUOTES, 'UTF-8'); ?>'></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-4 <?php echo isset($_GET['show_daily_fin']) ? 'd-print-block collapse-print-force' : 'd-print-none'; ?>">
        <div class="d-flex justify-content-between align-items-center border-bottom border-secondary pb-2 mb-3 print-hide-toggle" data-bs-toggle="collapse" data-bs-target="#dailyFinCollapse" style="cursor: pointer;" id="dailyFinToggleBtn">
            <h5 class="fw-bold text-dark mb-0 fs-5"><i class="bi bi-graph-up-arrow text-success me-2"></i> Daily Financial Spend Visualization</h5>
            <i class="bi bi-plus-circle fs-5 text-dark toggle-icon" style="transition: transform 0.2s;"></i>
        </div>
        <div class="collapse <?php echo isset($_GET['show_daily_fin']) ? 'collapse-print-force' : ''; ?>" id="dailyFinCollapse">
            <div class="card border border-secondary p-4 bg-light shadow-sm text-center">
                <h6 class="fw-bold text-dark mb-3 d-none d-print-block">Daily Financial Spend Visualization</h6>
                <div style="height: 400px; width: 100%;">
                    <canvas id="dailyFinChart" data-chart='<?php echo htmlspecialchars($daily_fin_json, ENT_QUOTES, 'UTF-8'); ?>'></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Legacy Slip Graph Renders -->
    <div class="<?php echo ($show_graph) ? 'd-print-block collapse-print-force' : 'd-print-none'; ?>" id="legacyElecContainer">
        <?php if ($show_graph && !empty($agg_daily_graph) && array_sum(array_column($agg_daily_graph, 'total')) > 0): ?>
        <?php 
            ksort($agg_daily_graph);
            $c_labels = []; $c_tot = []; $c_p = []; $c_s = []; $c_o = [];
            foreach ($agg_daily_graph as $d => $v) {
                $c_labels[] = date('d M', strtotime($d));
                $c_tot[] = round($v['total'], 2);
                $c_p[] = round($v['peak'], 2);
                $c_s[] = round($v['std'], 2);
                $c_o[] = round($v['off'], 2);
            }
            $chart_json = json_encode([
                'isTOU' => ($tou_algorithm !== 'None'),
                'labels' => $c_labels,
                'total' => $c_tot, 'peak' => $c_p, 'std' => $c_s, 'off' => $c_o
            ]);
        ?>
        <div class="mt-5 border p-4 bg-light shadow-sm d-print-block page-break-inside-avoid text-center">
            <div class="text-center mb-3"><span class="fw-bold text-dark text-uppercase fs-5 border-bottom border-secondary pb-1">Property Total Daily Electrical Consumption</span></div>
            <canvas class="elec-usage-chart" data-chart='<?php echo htmlspecialchars($chart_json, ENT_QUOTES, 'UTF-8'); ?>' height="100"></canvas>
        </div>
        <?php endif; ?>
    </div>

    <div class="<?php echo ($show_water_graph) ? 'd-print-block collapse-print-force' : 'd-print-none'; ?>" id="legacyWaterContainer">
        <?php if ($show_water_graph && !empty($agg_daily_water_graph) && array_sum($agg_daily_water_graph) > 0): ?>
        <?php 
            ksort($agg_daily_water_graph);
            $w_labels = []; $w_tot = [];
            foreach ($agg_daily_water_graph as $d => $v) {
                $w_labels[] = date('d M', strtotime($d));
                $w_tot[] = round($v, 2);
            }
            $w_chart_json = json_encode([
                'labels' => $w_labels,
                'total' => $w_tot
            ]);
        ?>
        <div class="mt-5 border p-4 bg-light shadow-sm d-print-block page-break-inside-avoid text-center">
            <div class="text-center mb-3"><span class="fw-bold text-dark text-uppercase fs-5 border-bottom border-secondary pb-1">Property Total Daily Water Consumption</span></div>
            <canvas class="water-usage-chart" data-chart='<?php echo htmlspecialchars($w_chart_json, ENT_QUOTES, 'UTF-8'); ?>' height="100"></canvas>
        </div>
        <?php endif; ?>
    </div>

</div>

<script>
    // Tenant slips open in a new tab through a script: browsers only let a page close tabs that a
    // script opened, so the slip's Back button can close its tab and return to this report.
    document.addEventListener('click', function (e) {
        const link = e.target.closest ? e.target.closest('a.lum-slip-link') : null;
        if (!link || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
        e.preventDefault();
        const tab = window.open(link.href, '_blank');
        if (!tab) {
            window.location.href = link.href; // Pop-ups blocked: open the slip in this tab instead
        }
    });

    // --- EXACT DATE BOUNDARY SPLITTING ---
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
        
        function getSeason(m) {
            return [6, 7, 8].includes(m) ? 'High' : 'Low';
        }
        
        const setVal = (name, val) => { 
            const el = document.querySelector(`[name="${name}"]`) || document.getElementById(name); 
            if (el) el.value = val; 
        };
        
        if (sYear === eYear && sMonth === eMonth) {
            const ledgerMonth = sYear + '-' + String(sMonth).padStart(2, '0');
            const season = getSeason(sMonth);
            
            setVal('start_date', startStr);
            setVal('end_date', endStr);
            setVal('tariff_period_1', ledgerMonth);
            setVal('season_1', season);
            
            setVal('start_date_2', '');
            setVal('end_date_2', '');
            
            setVal('water_start_date', startStr);
            setVal('water_end_date', endStr);
            
            setVal('water_start_date_2', '');
            setVal('water_end_date_2', '');
            
        } else {
            const endOfStartMonth = new Date(sYear, sMonth, 0); 
            const p1EndStr = sYear + '-' + String(sMonth).padStart(2, '0') + '-' + String(endOfStartMonth.getDate()).padStart(2, '0');
            const p2StartStr = eYear + '-' + String(eMonth).padStart(2, '0') + '-01';
            
            const p1Ledger = sYear + '-' + String(sMonth).padStart(2, '0');
            const p2Ledger = eYear + '-' + String(eMonth).padStart(2, '0');

            setVal('start_date', startStr);
            setVal('end_date', p1EndStr);
            setVal('tariff_period_1', p1Ledger);
            setVal('season_1', getSeason(sMonth));
            
            setVal('start_date_2', p2StartStr);
            setVal('end_date_2', endStr);
            setVal('tariff_period_2', p2Ledger);
            setVal('season_2', getSeason(eMonth));
            
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
        const sidebarEl = document.querySelector('.sidebar');
        if (sidebarEl) sessionStorage.setItem('lynxSidebarScroll', sidebarEl.scrollTop);
        form.submit();
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

        // --- Advanced Chart Renderers ---
        
        // 1. Tenant Bar Chart
        const tBarEl = document.getElementById('tenantBarChart');
        if (tBarEl) {
            const tData = JSON.parse(tBarEl.getAttribute('data-chart'));
            new Chart(tBarEl.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: tData.labels,
                    datasets: [{
                        label: 'Total Billed (R)',
                        data: tData.values,
                        backgroundColor: '#0d6efd',
                        borderRadius: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true } }
                }
            });
        }

        // 2. Electricity Pie Chart
        const ePieEl = document.getElementById('elecPieChart');
        if (ePieEl) {
            const eData = JSON.parse(ePieEl.getAttribute('data-chart'));
            new Chart(ePieEl.getContext('2d'), {
                type: 'pie',
                data: {
                    labels: eData.labels,
                    datasets: [{
                        data: eData.values,
                        backgroundColor: ['#0d6efd', '#fd7e14', '#6c757d', '#20c997', '#dc3545', '#ffc107', '#198754']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'right' } }
                }
            });
        }

        // 3. Water Pie Chart
        const wPieEl = document.getElementById('waterPieChart');
        if (wPieEl) {
            const wData = JSON.parse(wPieEl.getAttribute('data-chart'));
            new Chart(wPieEl.getContext('2d'), {
                type: 'pie',
                data: {
                    labels: wData.labels,
                    datasets: [{
                        data: wData.values,
                        backgroundColor: ['#0dcaf0', '#0d6efd', '#6c757d', '#198754']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'right' } }
                }
            });
        }

        // 4. Daily Financial Spend Chart
        const dfEl = document.getElementById('dailyFinChart');
        if (dfEl) {
            const dfData = JSON.parse(dfEl.getAttribute('data-chart'));
            new Chart(dfEl.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: dfData.labels,
                    datasets: [
                        { 
                            label: 'Combined Total (R)', 
                            data: dfData.total, 
                            type: 'line', 
                            borderColor: '#dc3545', 
                            backgroundColor: '#dc3545', 
                            borderWidth: 2, 
                            fill: false, 
                            tension: 0.3, 
                            pointRadius: 3,
                            order: 1 
                        },
                        { 
                            label: 'Water & Sewer (R)', 
                            data: dfData.water, 
                            backgroundColor: '#0dcaf0',
                            order: 2
                        },
                        { 
                            label: 'Electricity (R)', 
                            data: dfData.elec, 
                            backgroundColor: '#ffc107',
                            order: 3
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { 
                        x: { stacked: true },
                        y: { stacked: true, beginAtZero: true }
                    },
                    plugins: { legend: { position: 'bottom' } }
                }
            });
        }

        // Legacy Slip Graph Renders
        document.querySelectorAll('.elec-usage-chart').forEach(canvas => {
            const data = JSON.parse(canvas.getAttribute('data-chart'));
            const ctx = canvas.getContext('2d');
            let chartData, chartOptions;

            if (data.isTOU) {
                chartData = {
                    labels: data.labels,
                    datasets: [
                        { label: 'Off-Peak (kWh)', data: data.off, backgroundColor: '#198754' },
                        { label: 'Standard (kWh)', data: data.std, backgroundColor: '#ffc107' },
                        { label: 'Peak (kWh)', data: data.peak, backgroundColor: '#dc3545' }
                    ]
                };
                chartOptions = { responsive: true, maintainAspectRatio: false, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } }, plugins: { legend: { position: 'bottom' } }, animation: false };
            } else {
                chartData = {
                    labels: data.labels,
                    datasets: [{ label: 'Total Consumption (kWh)', data: data.total, backgroundColor: '#0d6efd' }]
                };
                chartOptions = { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } }, plugins: { legend: { position: 'bottom' } }, animation: false };
            }
            new Chart(ctx, { type: 'bar', data: chartData, options: chartOptions });
        });

        document.querySelectorAll('.water-usage-chart').forEach(canvas => {
            const data = JSON.parse(canvas.getAttribute('data-chart'));
            const ctx = canvas.getContext('2d');
            const chartData = {
                labels: data.labels,
                datasets: [{ label: 'Total Water Usage (kL)', data: data.total, backgroundColor: '#0dcaf0' }]
            };
            const chartOptions = { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } }, plugins: { legend: { position: 'bottom' } }, animation: false };
            new Chart(ctx, { type: 'bar', data: chartData, options: chartOptions });
        });
    });

    function bulkExportSlips() {
        const form = document.querySelector('form');
        const urlParams = new URLSearchParams(new FormData(form)).toString();
        window.open('bulk-consumption-slips.php?' + urlParams, '_blank');
    }

    // --- UPGRADED CANVAS-TO-EXCEL SNAPSHOT LOGIC ---
    function exportToExcel(divId, filename) {
        let originalReport = document.getElementById(divId);
        
        // Step 1: Create a perfect clone of the report so we don't break the live UI
        let reportClone = originalReport.cloneNode(true);
        
        // ---------------------------------------------------------------------------------
        // EXACT DATA REPLACEMENT LOGIC FOR EXCEL
        // ---------------------------------------------------------------------------------

        const showTenantBar = document.getElementById('show_tenant_bar').checked;
        const showElecPie = document.getElementById('show_elec_pie').checked;
        const showWaterPie = document.getElementById('show_water_pie').checked;
        const showDailyFin = document.getElementById('show_daily_fin').checked;
        const showGraph = document.getElementById('show_graph').checked;
        const showWaterGraph = document.getElementById('show_water_graph').checked;

        // 1. Tenant Bar Chart logic
        let tenantBarWrapper = reportClone.querySelector('#tenantBarCollapse');
        if (tenantBarWrapper) {
            if (!showTenantBar) {
                tenantBarWrapper.parentNode.remove();
            } else {
                let canvas = tenantBarWrapper.querySelector('#tenantBarChart');
                if (canvas && canvas.hasAttribute('data-chart')) {
                    let data = JSON.parse(canvas.getAttribute('data-chart'));
                    let tableHtml = '<br><h3>Tenant Billing Breakdown Data (Insert Chart Here)</h3><table border="1"><thead><tr><th style="background-color:#f2f2f2;">Tenant & Shop</th><th style="background-color:#f2f2f2;text-align:right;">Total Billed (R)</th></tr></thead><tbody>';
                    for (let i = 0; i < data.labels.length; i++) {
                        tableHtml += `<tr><td>${data.labels[i]}</td><td style="text-align:right; mso-number-format:\'0.00\';">${data.values[i]}</td></tr>`;
                    }
                    tableHtml += '</tbody></table>';
                    canvas.parentNode.innerHTML = tableHtml;
                }
            }
        }

        // 2. Electricity Pie Chart logic
        let elecPieWrapper = reportClone.querySelector('#elecPieCollapse');
        if (elecPieWrapper) {
            if (!showElecPie) {
                elecPieWrapper.parentNode.remove();
            } else {
                let canvas = elecPieWrapper.querySelector('#elecPieChart');
                if (canvas && canvas.hasAttribute('data-chart')) {
                    let data = JSON.parse(canvas.getAttribute('data-chart'));
                    let tableHtml = '<br><h3>Electricity Recovery Split Data (Insert Chart Here)</h3><table border="1"><thead><tr><th style="background-color:#f2f2f2;">Category</th><th style="background-color:#f2f2f2;text-align:right;">Amount (R)</th></tr></thead><tbody>';
                    for (let i = 0; i < data.labels.length; i++) {
                        tableHtml += `<tr><td>${data.labels[i]}</td><td style="text-align:right; mso-number-format:\'0.00\';">${data.values[i]}</td></tr>`;
                    }
                    tableHtml += '</tbody></table>';
                    canvas.parentNode.innerHTML = tableHtml;
                }
            }
        }

        // 3. Water Pie Chart logic
        let waterPieWrapper = reportClone.querySelector('#waterPieCollapse');
        if (waterPieWrapper) {
            if (!showWaterPie) {
                waterPieWrapper.parentNode.remove();
            } else {
                let canvas = waterPieWrapper.querySelector('#waterPieChart');
                if (canvas && canvas.hasAttribute('data-chart')) {
                    let data = JSON.parse(canvas.getAttribute('data-chart'));
                    let tableHtml = '<br><h3>Water & Sewer Recovery Split Data (Insert Chart Here)</h3><table border="1"><thead><tr><th style="background-color:#f2f2f2;">Category</th><th style="background-color:#f2f2f2;text-align:right;">Amount (R)</th></tr></thead><tbody>';
                    for (let i = 0; i < data.labels.length; i++) {
                        tableHtml += `<tr><td>${data.labels[i]}</td><td style="text-align:right; mso-number-format:\'0.00\';">${data.values[i]}</td></tr>`;
                    }
                    tableHtml += '</tbody></table>';
                    canvas.parentNode.innerHTML = tableHtml;
                }
            }
        }

        // 4. Daily Financial Spend Graph logic
        let dailyFinWrapper = reportClone.querySelector('#dailyFinCollapse');
        if (dailyFinWrapper) {
            if (!showDailyFin) {
                dailyFinWrapper.parentNode.remove();
            } else {
                let canvas = dailyFinWrapper.querySelector('#dailyFinChart');
                if (canvas && canvas.hasAttribute('data-chart')) {
                    let data = JSON.parse(canvas.getAttribute('data-chart'));
                    let tableHtml = '<br><h3>Daily Financial Spend Data (Insert Chart Here)</h3><table border="1"><thead><tr><th style="background-color:#f2f2f2;">Date</th><th style="background-color:#f2f2f2;text-align:right;">Electricity (R)</th><th style="background-color:#f2f2f2;text-align:right;">Water & Sewer (R)</th><th style="background-color:#f2f2f2;text-align:right;">Combined Total (R)</th></tr></thead><tbody>';
                    for (let i = 0; i < data.labels.length; i++) {
                        tableHtml += `<tr><td>${data.labels[i]}</td><td style="text-align:right; mso-number-format:\'0.00\';">${data.elec[i]}</td><td style="text-align:right; mso-number-format:\'0.00\';">${data.water[i]}</td><td style="text-align:right; mso-number-format:\'0.00\';">${data.total[i]}</td></tr>`;
                    }
                    tableHtml += '</tbody></table>';
                    canvas.parentNode.innerHTML = tableHtml;
                }
            }
        }

        // Legacy Slip Graphs
        let legacyElecContainer = reportClone.querySelector('#legacyElecContainer');
        if (legacyElecContainer) {
            if (!showGraph) {
                legacyElecContainer.remove();
            } else {
                let canvas = legacyElecContainer.querySelector('.elec-usage-chart');
                if (canvas && canvas.hasAttribute('data-chart')) {
                    let data = JSON.parse(canvas.getAttribute('data-chart'));
                    let tableHtml = '<br><h3>Daily Electrical Consumption Data (Insert Chart Here)</h3><table border="1"><thead><tr><th style="background-color:#f2f2f2;">Date</th>';
                    
                    if (data.isTOU) {
                        tableHtml += '<th style="background-color:#f2f2f2;text-align:right;">Peak (kWh)</th><th style="background-color:#f2f2f2;text-align:right;">Standard (kWh)</th><th style="background-color:#f2f2f2;text-align:right;">Off-Peak (kWh)</th><th style="background-color:#f2f2f2;text-align:right;">Total (kWh)</th></tr></thead><tbody>';
                        for (let i = 0; i < data.labels.length; i++) {
                            tableHtml += `<tr><td>${data.labels[i]}</td><td style="text-align:right; mso-number-format:\'0.00\';">${data.peak[i]}</td><td style="text-align:right; mso-number-format:\'0.00\';">${data.std[i]}</td><td style="text-align:right; mso-number-format:\'0.00\';">${data.off[i]}</td><td style="text-align:right; mso-number-format:\'0.00\';">${data.total[i]}</td></tr>`;
                        }
                    } else {
                        tableHtml += '<th style="background-color:#f2f2f2;text-align:right;">Total (kWh)</th></tr></thead><tbody>';
                        for (let i = 0; i < data.labels.length; i++) {
                            tableHtml += `<tr><td>${data.labels[i]}</td><td style="text-align:right; mso-number-format:\'0.00\';">${data.total[i]}</td></tr>`;
                        }
                    }
                    tableHtml += '</tbody></table>';
                    canvas.parentNode.innerHTML = tableHtml;
                }
            }
        }

        let legacyWaterContainer = reportClone.querySelector('#legacyWaterContainer');
        if (legacyWaterContainer) {
            if (!showWaterGraph) {
                legacyWaterContainer.remove();
            } else {
                let canvas = legacyWaterContainer.querySelector('.water-usage-chart');
                if (canvas && canvas.hasAttribute('data-chart')) {
                    let data = JSON.parse(canvas.getAttribute('data-chart'));
                    let tableHtml = '<br><h3>Daily Water Consumption Data (Insert Chart Here)</h3><table border="1"><thead><tr><th style="background-color:#f2f2f2;">Date</th><th style="background-color:#f2f2f2;text-align:right;">Total (kL)</th></tr></thead><tbody>';
                    for (let i = 0; i < data.labels.length; i++) {
                        tableHtml += `<tr><td>${data.labels[i]}</td><td style="text-align:right; mso-number-format:\'0.00\';">${data.total[i]}</td></tr>`;
                    }
                    tableHtml += '</tbody></table>';
                    canvas.parentNode.innerHTML = tableHtml;
                }
            }
        }

        // Step 2: Force border attribute on ALL tables existing in the clone to ensure Excel renders rigid black gridlines.
        let allTables = reportClone.querySelectorAll('table');
        allTables.forEach(t => {
            t.setAttribute('border', '1');
            t.style.border = '1px solid black';
            t.style.borderCollapse = 'collapse';
        });

        // Remove internal UI toggles from the final clean export sheet
        let toggleIcons = reportClone.querySelectorAll('.print-hide-toggle');
        toggleIcons.forEach(icon => icon.remove());
        
        // Step 3: Package the modified HTML into the Excel MIME standard WITH GRIDLINES
        let reportHtml = reportClone.innerHTML;
        let template = `
        <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
        <head>
            <meta charset="UTF-8">
            <!--[if gte mso 9]>
            <xml>
                <x:ExcelWorkbook>
                    <x:ExcelWorksheets>
                        <x:ExcelWorksheet>
                            <x:Name>Financial Report</x:Name>
                            <x:WorksheetOptions>
                                <x:DisplayGridlines/>
                            </x:WorksheetOptions>
                        </x:ExcelWorksheet>
                    </x:ExcelWorksheets>
                </x:ExcelWorkbook>
            </xml>
            <![endif]-->
            <style>
        table { border-collapse: collapse; width: 100%; margin-bottom: 20px; font-family: sans-serif; }
        th, td { border: 1px solid #000000; padding: 6px; text-align: left; vertical-align: middle; }
        th { background-color: #f2f2f2; font-weight: bold; }
        .text-end { text-align: right; }
        .text-center { text-align: center; }
        h2, h3, h4, h5, h6 { font-family: sans-serif; margin-bottom: 10px; color: #000000; }
    </style>
        </head>
        <body>${reportHtml}</body>
        </html>`;
        
        let blob = new Blob([template], { type: 'application/vnd.ms-excel' });
        let url = URL.createObjectURL(blob);
        let a = document.createElement('a');
        a.href = url;
        a.download = filename + '.xls';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }

    function exportMRI() {
        const mriData = <?php echo json_encode($mri_data ?? ['EL00'=>[], 'GE00'=>[], 'WT00'=>[], 'SE00'=>[], 'RF00'=>[]]); ?>;
        const propertyName = "<?php echo preg_replace('/[^a-zA-Z0-9]/', '_', $selected_property); ?>";
        const reportMonth = "<?php echo date('M_Y', strtotime(empty($end_date_2) ? $end_date : $end_date_2)); ?>";

        let delay = 0;
        let filesGenerated = 0;
        
        for (const [service, rows] of Object.entries(mriData)) {
            if (rows.length === 0) continue;
            
            filesGenerated++;
            setTimeout(() => {
                let csvContent = rows.join("\r\n");
                let blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                let url = URL.createObjectURL(blob);
                let a = document.createElement('a');
                a.href = url;
                a.download = `MDA_${service}_${propertyName}_${reportMonth}.csv`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            }, delay);
            
            delay += 500; 
        }
        
        if (filesGenerated === 0) {
            alert("No utility data available to export for this property.");
        }
    }
</script>
<?php endif; ?>