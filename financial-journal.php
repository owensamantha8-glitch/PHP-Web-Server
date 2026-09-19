<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once __DIR__ . '/bootstrap.php';
lum_page('financial_reports', 'view');

// =========================================================================
// LYNX UTILITY MANAGEMENT - FINANCIAL JOURNAL HISTORY
// Location: /var/www/Lynx/Reporting/financial-journal.php
// List of journaled financial reports, the stored report per journal,
// CSV export, and voiding of journals.
// =========================================================================

lum_use('journal', 'audit');
if (is_readable(LUM_ROOT . LUM_LIBRARIES['back_billing'])) lum_use('back_billing'); // Back billing adjustments (optional until installed)

$jdb = lumJournalDb();
$allowed_props = lum_allowed_properties(); // null = all properties
$can_void = lum_can('financial_reports', 'delete');
$can_edit = lum_can('financial_reports', 'edit');
$can_back_bill = function_exists('lumAdjForJournal') && lum_can('back_billing', 'edit');
$can_view_back_bill = function_exists('lumAdjForJournal') && lum_can('back_billing', 'view');
// Back to the financial report exactly as it was last generated
$report_back_url = 'financial-reporting-overview.php' . (!empty($_SESSION['lum_last_financial_report']) ? '?' . $_SESSION['lum_last_financial_report'] : '');
$message = $_SESSION['journal_page_message'] ?? null;
unset($_SESSION['journal_page_message']);

$lum_audit_label = function ($j) {
    return $j['property'] . ' ' . sprintf('%02d/%04d', $j['billing_month'], $j['billing_year']);
};

// --- Edit a journal's note ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_notes') {
    lum_require_csrf();
    lum_require_access('financial_reports', 'edit');
    $edit_id = (int)($_POST['journal_id'] ?? 0);
    $j = lumJournalGet($jdb, $edit_id);
    if ($j) {
        lum_require_property($j['journal']['property']);
        $new_notes = substr(trim((string)($_POST['notes'] ?? '')), 0, 255);
        $old_notes = lumJournalUpdateNotes($jdb, $edit_id, $new_notes);
        if ($old_notes !== false) {
            if (function_exists('lum_audit_log')) {
                lum_audit_log('UPDATE', 'financial_journal', $edit_id, $lum_audit_label($j['journal']), $j['journal']['property'],
                              ['notes' => $old_notes], ['notes' => $new_notes], 'Journal note edited');
            }
            $_SESSION['journal_page_message'] = ['type' => 'success', 'text' => 'The note on Journal #' . $edit_id . ' was saved.'];
        } else {
            $_SESSION['journal_page_message'] = ['type' => 'danger', 'text' => 'The note could not be saved.'];
        }
    }
    header('Location: financial-journal.php?id=' . $edit_id);
    exit();
}

// --- Permanently delete a voided journal ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    lum_require_csrf();
    lum_require_access('financial_reports', 'delete');
    $del_id = (int)($_POST['journal_id'] ?? 0);
    $j = lumJournalGet($jdb, $del_id);
    if ($j) {
        lum_require_property($j['journal']['property']);
        if ($j['journal']['status'] !== 'Voided') {
            $_SESSION['journal_page_message'] = ['type' => 'warning', 'text' => 'Only voided journals can be deleted. Void Journal #' . $del_id . ' first.'];
            header('Location: financial-journal.php?id=' . $del_id);
            exit();
        }
        if (lumJournalDelete($jdb, $del_id)) {
            if (function_exists('lum_audit_log')) {
                // The audit entry keeps a summary of what was deleted
                $jj = $j['journal'];
                lum_audit_log('DELETE', 'financial_journal', $del_id, $lum_audit_label($jj), $jj['property'], [
                    'billing_month' => sprintf('%02d/%04d', $jj['billing_month'], $jj['billing_year']),
                    'period' => $jj['period_start'] . ' to ' . (!empty($jj['period_2_end']) ? $jj['period_2_end'] : $jj['period_end']),
                    'tenant_lines' => count($j['lines']),
                    'total_elec' => $jj['total_elec'], 'total_water' => $jj['total_water'],
                    'total_refuse' => $jj['total_refuse'], 'grand_total' => $jj['grand_total'],
                    'journaled_by' => $jj['created_by_name'], 'journaled_at' => $jj['created_at'],
                    'notes' => $jj['notes'],
                ], null, 'Voided journal permanently deleted');
            }
            $_SESSION['journal_page_message'] = ['type' => 'success', 'text' => 'Journal #' . $del_id . ' was permanently deleted.'];
            header('Location: financial-journal.php?property=' . urlencode($j['journal']['property']) . '&status=');
            exit();
        }
        $_SESSION['journal_page_message'] = ['type' => 'danger', 'text' => 'Journal #' . $del_id . ' could not be deleted.'];
    }
    header('Location: financial-journal.php?id=' . $del_id);
    exit();
}

// --- Void a journal ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'void') {
    lum_require_csrf();
    lum_require_access('financial_reports', 'delete');
    $void_id = (int)($_POST['journal_id'] ?? 0);
    $j = lumJournalGet($jdb, $void_id);
    if ($j) {
        lum_require_property($j['journal']['property']);
        if (lumJournalVoid($jdb, $void_id, (string)($_POST['reason'] ?? ''))) {
            if (function_exists('lum_audit_log')) {
                lum_audit_log('UPDATE', 'financial_journal', $void_id, $lum_audit_label($j['journal']),
                              $j['journal']['property'], ['status' => $j['journal']['status']], ['status' => 'Voided'],
                              'Journal voided' . (trim((string)($_POST['reason'] ?? '')) !== '' ? ': ' . trim((string)$_POST['reason']) : ''));
            }
            // Back billing adjustments posted with this journal are billed again when the month is journaled again
            $released = function_exists('lumAdjUnpostJournal') ? lumAdjUnpostJournal($jdb, $void_id) : [];
            foreach ($released as $rel_id) {
                if (function_exists('lum_audit_log')) {
                    lum_audit_log('UPDATE', 'back_billing', $rel_id, lumAdjNumber($rel_id), $j['journal']['property'],
                                  ['status' => 'Posted', 'posted_journal_id' => $void_id], ['status' => 'Approved'], 'Journal #' . $void_id . ' voided');
                }
            }
            $_SESSION['journal_page_message'] = ['type' => 'success', 'text' => 'Journal #' . $void_id . ' has been voided.'
                . ($released ? ' ' . count($released) . ' posted adjustment(s) are approved again and will be billed when the month is journaled again.' : '')];
        } else {
            $_SESSION['journal_page_message'] = ['type' => 'warning', 'text' => 'Journal #' . $void_id . ' could not be voided (it may already be voided).'];
        }
    }
    header('Location: financial-journal.php?id=' . $void_id);
    exit();
}

// --- One journal ---
$view_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$view = null;
if ($view_id > 0) {
    $view = lumJournalGet($jdb, $view_id);
    if ($view) {
        lum_require_property($view['journal']['property']);
    }
}

// Manual readings columns used to bill a tenant line: ['elec' => 'A14', 'gen' => '...'] ('' = not used).
// Saved on the line (financial-journal-upgrade-2.sql); older lines: from the saved billing detail.
function lumJournalLineManualCols(array $l, $detail = null) {
    $elec = trim((string)($l['manual_col_elec'] ?? ''));
    $gen = trim((string)($l['manual_col_gen'] ?? ''));
    if (!array_key_exists('manual_col_elec', $l) || ($elec === '' && $gen === '')) {
        if ($detail === null) $detail = json_decode((string)($l['billing_detail'] ?? ''), true) ?: [];
        $e = []; $g = [];
        foreach ((array)($detail['meters'] ?? []) as $m) {
            if (!empty($m['elec_manual_col'])) $e[] = $m['elec_manual_col'];
            elseif (strpos((string)($m['elec_source'] ?? ''), 'Manual extract: ') === 0) $e[] = substr($m['elec_source'], 16);
            if (!empty($m['gen_manual_col'])) $g[] = $m['gen_manual_col'];
            elseif (strpos((string)($m['gen_source'] ?? ''), 'Manual extract: ') === 0) $g[] = substr($m['gen_source'], 16);
        }
        if ($elec === '') $elec = implode(', ', array_unique($e));
        if ($gen === '') $gen = implode(', ', array_unique($g));
    }
    return ['elec' => $elec, 'gen' => $gen];
}

// --- CSV export of one journal ---
if ($view && ($_GET['export'] ?? '') === 'csv') {
    $j = $view['journal'];
    $safe = function ($v) {
        $v = (string)$v;
        return (preg_match('/^[=+\-@\t\r]/', $v)) ? "'" . $v : $v; // Spreadsheet formula protection for text
    };
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Journal_' . (int)$j['journal_id'] . '_' . preg_replace('/[^A-Za-z0-9]+/', '_', $j['property']) . '_' . sprintf('%04d-%02d', $j['billing_year'], $j['billing_month']) . '.csv"');
    $out = fopen('php://output', 'w');
    $head = ['Journal ID', 'Property', 'Billing Month', 'Status', 'Tenant ID', 'Tenant', 'Tenant Code', 'Shop',
             'Period Start', 'Period End', 'Period 2 Start', 'Period 2 End', 'Water Start', 'Water End', 'Water 2 Start', 'Water 2 End',
             'Own Billing Cycle', 'Municipality',
             'Electrical Tariff', 'Electrical OBIS', 'TOU', 'TOU Algorithm', 'Generator Method', 'Generator Run Time (h)',
             'Manual Readings Column (Grid/T1)', 'Manual Readings Column (Gen/T2)',
             'Electrical Meters', 'Water Meters', 'Estimated Readings', 'Occupancy'];
    foreach (LUM_JOURNAL_QUANTITIES as $k) $head[] = LUM_JOURNAL_LABELS[$k];
    foreach (LUM_JOURNAL_AMOUNTS as $k) $head[] = LUM_JOURNAL_LABELS[$k];
    $head[] = 'Own Tenant Settings';
    fputcsv($out, $head);
    foreach ($view['lines'] as $l) {
        $settings = json_decode((string)$l['tenant_settings'], true) ?: [];
        $line_manual = lumJournalLineManualCols($l);
        $settings_txt = [];
        foreach ($settings as $k => $v) $settings_txt[] = lumTenantSettingLabelSafe($k) . ': ' . lumTenantSettingValueSafe($k, $v);
        $row = [$j['journal_id'], $safe($j['property']), sprintf('%02d/%04d', $j['billing_month'], $j['billing_year']), $j['status'],
                $l['tenant_id'], $safe($l['tenant_name']), $safe($l['tenant_code']), $safe($l['tenant_shop']),
                $l['period_start'] ?? $j['period_start'], $l['period_end'] ?? $j['period_end'],
                array_key_exists('period_start', $l) ? $l['period_2_start'] : $j['period_2_start'],
                array_key_exists('period_start', $l) ? $l['period_2_end'] : $j['period_2_end'],
                array_key_exists('period_start', $l) ? $l['water_start'] : $j['water_start'],
                array_key_exists('period_start', $l) ? $l['water_end'] : $j['water_end'],
                array_key_exists('period_start', $l) ? $l['water_2_start'] : $j['water_2_start'],
                array_key_exists('period_start', $l) ? $l['water_2_end'] : $j['water_2_end'],
                !empty($l['own_billing_cycle']) ? 'Yes' : 'No', $safe($l['municipality']),
                $safe($l['elec_tariff']), $l['elec_obis'], $l['is_tou'] ? 'Yes' : 'No', $l['tou_algorithm'], $safe($l['gen_method']),
                $l['gen_runtime_hours'], $safe($line_manual['elec']), $safe($line_manual['gen']),
                $safe($l['elec_meters']), $safe($l['water_meters']), $l['estimated_readings'] ? 'Yes' : 'No', $l['occupancy_status']];
        foreach (LUM_JOURNAL_QUANTITIES as $k) $row[] = $l[$k];
        foreach (LUM_JOURNAL_AMOUNTS as $k) $row[] = $l[$k];
        $row[] = $safe(implode('; ', $settings_txt));
        fputcsv($out, $row);
    }
    fclose($out);
    exit();
}

// Setting names without loading the slip engine
function lumTenantSettingLabelSafe($key) {
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

function lumTenantSettingValueSafe($key, $value) {
    if (in_array($key, ['start_date', 'end_date', 'start_date_2', 'end_date_2', 'water_start_date', 'water_end_date', 'water_start_date_2', 'water_end_date_2'], true)) {
        return ((string)$value === '') ? 'None' : date('d M Y', strtotime((string)$value));
    }
    if (in_array($key, ['use_estimated_readings', 'use_estimate_time_ranges', 'remove_elec_comm', 'remove_water_comm'], true)) return ((string)$value === '1') ? 'On' : 'Off';
    if ($key === 't2_method') return ($value === 'runtime') ? 'Run Time Calculation' : 'OBIS 1.1.1.8.2 Reading';
    if (($key === 'manual_override_col' || $key === 'manual_override_col_t2') && ($value === 'none' || $value === '')) return 'None';
    return (string)$value;
}

// --- List filters ---
$f_property = (string)($_GET['property'] ?? '');
$f_year = (string)($_GET['year'] ?? '');
$f_status = (string)($_GET['status'] ?? 'Active');
if (!in_array($f_status, ['', 'Active', 'Superseded', 'Voided'], true)) $f_status = 'Active';
if ($f_property !== '' && !lum_can_access_property($f_property)) $f_property = '';
if ($f_year !== '' && !ctype_digit($f_year)) $f_year = '';

$journals = $view ? [] : lumJournalList($jdb, [
    'property' => $f_property, 'year' => $f_year, 'status' => $f_status, 'allowed_properties' => $allowed_props,
]);

// Properties that have journals (for the filter)
$journal_properties = [];
if ($jdb) {
    try {
        // Properties come from the property register only
        $jp_db = lum_db('properties');
        $journal_properties = $jp_db ? $jp_db->query("SELECT Property FROM lum_properties ORDER BY Property")->fetchAll(PDO::FETCH_COLUMN) : [];
        if ($allowed_props !== null) $journal_properties = array_values(array_intersect($journal_properties, $allowed_props));
    } catch (\Throwable $e) {}
}

$status_badge = function ($status) {
    $cls = ['Active' => 'bg-success', 'Superseded' => 'bg-secondary', 'Voided' => 'bg-danger'][$status] ?? 'bg-secondary';
    return "<span class='badge {$cls}'>" . htmlspecialchars($status) . "</span>";
};
$money = function ($v) { return number_format((float)$v, 2); };
$month_name = function ($m, $y) { return date('F Y', mktime(0, 0, 0, (int)$m, 1, (int)$y)); };
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Journal - Lynx Utility Management</title>
    <?php include LUM_ROOT . '/Layout/head-assets.php'; ?>
    <style>
        * { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background-color: #e2e8f0; }
        .navbar { background-color: #000000; border-bottom: 1px solid #333333; }
        .action-bar { background-color: #0a0a0a; border-bottom: 1px solid #333; padding: 12px 0; }
        .journal-card { background: #fff; border: 1px solid #d0d7de; }
        .table-sm th, .table-sm td { padding: 0.35rem 0.5rem; vertical-align: middle; }
        .table thead th { white-space: nowrap; }
        .detail-row td { background: #f8f9fa !important; }
        @media print {
            .d-print-none, .navbar, .action-bar { display: none !important; }
        body { background: #fff; }
        .journal-card { border: none; }
        }
    </style>
</head>
<body>

<?php include LUM_ROOT . '/Layout/top-navbar.php'; ?>

<div class="action-bar d-print-none">
    <div class="container-fluid px-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="mb-0 text-white fw-normal"><i class="bi bi-journal-bookmark-fill me-2"></i>Financial Journal</h5>
        <div class="d-flex gap-2">
            <?php if ($view): ?>
                <a href="financial-journal.php?property=<?php echo urlencode($view['journal']['property']); ?>" class="btn btn-outline-light btn-sm"><i class="bi bi-list-ul me-1"></i>All Journals</a>
            <?php endif; ?>
            <?php if ($can_view_back_bill): ?>
                <a href="back-billing.php<?php echo $view ? '?property=' . urlencode($view['journal']['property']) : ''; ?>" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left-right me-1"></i>Back Billing</a>
            <?php endif; ?>
            <a href="<?php echo htmlspecialchars($report_back_url); ?>" class="btn btn-outline-light btn-sm" title="Back to the financial report with its last settings"><i class="bi bi-arrow-left me-1"></i>Financial Report</a>
        </div>
    </div>
</div>

<div class="container-fluid px-4 py-4">

    <?php if (!$jdb): ?>
        <div class="alert alert-danger">The journal database is not available. Please run <strong>financial-journal-setup.sql</strong>.</div>
    <?php endif; ?>

    <?php if (!empty($message['text'])): ?>
        <div class="alert alert-<?php echo htmlspecialchars($message['type'] ?? 'info'); ?>"><?php echo htmlspecialchars($message['text']); ?></div>
    <?php endif; ?>

    <?php if ($view_id > 0 && !$view): ?>
        <div class="alert alert-warning">Journal #<?php echo (int)$view_id; ?> was not found.</div>
    <?php endif; ?>

    <?php if ($view):
        $j = $view['journal'];
        $lines = $view['lines'];
        $summary = json_decode((string)$j['property_summary'], true) ?: [];
        $settings = json_decode((string)$j['report_settings'], true) ?: [];
        $report_link = 'financial-reporting-overview.php?' . http_build_query($settings);
    ?>
    <!-- ============================ ONE JOURNAL ============================ -->
    <div class="journal-card p-4 mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 border-bottom pb-3 mb-3">
            <div>
                <h3 class="fw-bold mb-1">Journal #<?php echo (int)$j['journal_id']; ?> <?php echo $status_badge($j['status']); ?></h3>
                <h5 class="text-muted mb-1"><?php echo htmlspecialchars($j['property']); ?> &mdash; <?php echo htmlspecialchars($month_name($j['billing_month'], $j['billing_year'])); ?>
                    <?php if (!empty($j['billing_label'])): ?><span class="small">(<?php echo htmlspecialchars($j['billing_label']); ?>)</span><?php endif; ?>
                </h5>
                <div class="small text-muted">
                    Electrical: <?php echo date('d M Y', strtotime($j['period_start'])); ?> &ndash; <?php echo date('d M Y', strtotime($j['period_end'])); ?>
                    <?php if (!empty($j['period_2_start'])): ?> and <?php echo date('d M Y', strtotime($j['period_2_start'])); ?> &ndash; <?php echo date('d M Y', strtotime($j['period_2_end'])); ?><?php endif; ?>
                    <?php if (!empty($j['water_start'])): ?>
                        &middot; Water: <?php echo date('d M Y', strtotime($j['water_start'])); ?> &ndash; <?php echo date('d M Y', strtotime($j['water_end'])); ?>
                        <?php if (!empty($j['water_2_start'])): ?> and <?php echo date('d M Y', strtotime($j['water_2_start'])); ?> &ndash; <?php echo date('d M Y', strtotime($j['water_2_end'])); ?><?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="small text-muted">
                    Journaled <?php echo htmlspecialchars(date('d M Y H:i', strtotime($j['created_at']))); ?> by <?php echo htmlspecialchars($j['created_by_name']); ?>
                    <?php if ($j['status'] !== 'Active' && !empty($j['status_changed_at'])): ?>
                        &middot; <?php echo htmlspecialchars($j['status']); ?> <?php echo htmlspecialchars(date('d M Y H:i', strtotime($j['status_changed_at']))); ?> by <?php echo htmlspecialchars((string)$j['status_changed_by']); ?>
                        <?php if (!empty($j['superseded_by'])): ?> (replaced by <a href="financial-journal.php?id=<?php echo (int)$j['superseded_by']; ?>">Journal #<?php echo (int)$j['superseded_by']; ?></a>)<?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php if (!empty($j['notes'])): ?><div class="small mt-1"><i class="bi bi-chat-left-text me-1"></i><?php echo htmlspecialchars($j['notes']); ?></div><?php endif; ?>
                <?php if ($can_edit): ?>
                <form method="POST" class="d-flex gap-1 mt-2 d-print-none" style="max-width: 520px;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="edit_notes">
                    <input type="hidden" name="journal_id" value="<?php echo (int)$j['journal_id']; ?>">
                    <input type="text" name="notes" class="form-control form-control-sm" maxlength="255" value="<?php echo htmlspecialchars((string)$j['notes']); ?>" placeholder="Journal note">
                    <button type="submit" class="btn btn-outline-secondary btn-sm text-nowrap"><i class="bi bi-pencil me-1"></i>Save Note</button>
                </form>
                <?php endif; ?>
            </div>
            <div class="d-flex flex-wrap gap-2 d-print-none">
                <a href="financial-journal.php?id=<?php echo (int)$j['journal_id']; ?>&amp;export=csv" class="btn btn-success btn-sm"><i class="bi bi-filetype-csv me-1"></i>Export CSV</a>
                <button type="button" onclick="window.print()" class="btn btn-outline-dark btn-sm"><i class="bi bi-printer me-1"></i>Print</button>
                <a href="<?php echo htmlspecialchars($report_link); ?>" class="btn btn-outline-primary btn-sm" title="Recalculate the live report with the settings used for this journal"><i class="bi bi-arrow-repeat me-1"></i>Open Live Report</a>
                <?php if ($can_void && $j['status'] === 'Voided'): ?>
                <form method="POST" class="m-0" onsubmit="return confirm('Permanently delete Journal #<?php echo (int)$j['journal_id']; ?> and all its tenant lines? This cannot be undone. A summary is kept in the audit log.');">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="journal_id" value="<?php echo (int)$j['journal_id']; ?>">
                    <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-trash3 me-1"></i>Delete Journal</button>
                </form>
                <?php endif; ?>
                <?php if ($can_void && $j['status'] !== 'Voided'): ?>
                <form method="POST" class="d-flex gap-1 m-0" onsubmit="return confirm('Void Journal #<?php echo (int)$j['journal_id']; ?>? It stays on record but is no longer active.');">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="void">
                    <input type="hidden" name="journal_id" value="<?php echo (int)$j['journal_id']; ?>">
                    <input type="text" name="reason" class="form-control form-control-sm" placeholder="Reason" maxlength="100" style="width: 150px;">
                    <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-x-octagon me-1"></i>Void</button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Totals -->
        <div class="row g-3 mb-4">
            <?php foreach ([['Total Electricity', $j['total_elec'], number_format((float)$j['total_kwh'], 2) . ' kWh'],
                            ['Total Water & Sewer', $j['total_water'], number_format((float)$j['total_kl'], 2) . ' kL'],
                            ['Refuse', $j['total_refuse'], ''],
                            ['Grand Total', $j['grand_total'], (int)$j['tenant_count'] . ' tenants']] as $card): ?>
            <div class="col-md-3">
                <div class="border p-3 h-100 <?php echo $card[0] === 'Grand Total' ? 'bg-dark text-white' : 'bg-light'; ?>">
                    <div class="small <?php echo $card[0] === 'Grand Total' ? 'text-white-50' : 'text-muted'; ?>"><?php echo $card[0]; ?></div>
                    <div class="fs-4 fw-bold">R <?php echo $money($card[1]); ?></div>
                    <div class="small <?php echo $card[0] === 'Grand Total' ? 'text-white-50' : 'text-muted'; ?>"><?php echo $card[2]; ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($summary)): $e = $summary['electricity'] ?? []; $w = $summary['water'] ?? []; $g = $summary['generator'] ?? []; ?>
        <div class="row g-3 mb-4 small">
            <div class="col-lg-4">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-dark"><tr><th colspan="2">Electricity</th></tr></thead>
                    <tbody>
                        <tr><td>Energy Charge</td><td class="text-end">R <?php echo $money($e['energy'] ?? 0); ?></td></tr>
                        <tr><td>Basic Charge</td><td class="text-end">R <?php echo $money($e['basic'] ?? 0); ?></td></tr>
                        <tr><td>Network Access</td><td class="text-end">R <?php echo $money($e['network_access'] ?? 0); ?></td></tr>
                        <tr><td>Capacity</td><td class="text-end">R <?php echo $money($e['capacity'] ?? 0); ?></td></tr>
                        <tr><td>Demand</td><td class="text-end">R <?php echo $money($e['demand'] ?? 0); ?></td></tr>
                        <tr><td>Generator</td><td class="text-end">R <?php echo $money($e['generator'] ?? 0); ?></td></tr>
                        <tr><td>Common Area</td><td class="text-end">R <?php echo $money($e['common_area'] ?? 0); ?></td></tr>
                        <tr><td>Property common area / billed</td><td class="text-end"><?php echo number_format((float)($e['property_common_area_kwh'] ?? 0), 2); ?> / <?php echo number_format((float)($e['common_area_kwh_billed'] ?? 0), 2); ?> kWh</td></tr>
                        <tr><td>Recovered R/kWh</td><td class="text-end">R <?php echo number_format((float)($e['recovered_r_per_kwh'] ?? 0), 4); ?></td></tr>
                    </tbody>
                </table>
            </div>
            <div class="col-lg-4">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-dark"><tr><th colspan="2">Water &amp; Sewer</th></tr></thead>
                    <tbody>
                        <tr><td>Water Basic</td><td class="text-end">R <?php echo $money($w['basic'] ?? 0); ?></td></tr>
                        <tr><td>Water Usage</td><td class="text-end">R <?php echo $money($w['usage'] ?? 0); ?></td></tr>
                        <tr><td>Sewer</td><td class="text-end">R <?php echo $money($w['sewer'] ?? 0); ?></td></tr>
                        <tr><td>Common Area</td><td class="text-end">R <?php echo $money($w['common_area'] ?? 0); ?></td></tr>
                        <tr><td>Property common area / billed</td><td class="text-end"><?php echo number_format((float)($w['property_common_area_kl'] ?? 0), 2); ?> / <?php echo number_format((float)($w['common_area_kl_billed'] ?? 0), 2); ?> kL</td></tr>
                        <tr><td>Recovered R/kL</td><td class="text-end">R <?php echo number_format((float)($w['recovered_r_per_kl'] ?? 0), 4); ?></td></tr>
                    </tbody>
                </table>
            </div>
            <div class="col-lg-4">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-dark"><tr><th colspan="2">Report Settings Used</th></tr></thead>
                    <tbody>
                        <tr><td>Property OBIS (common areas)</td><td class="text-end"><?php echo htmlspecialchars((string)($summary['property_obis'] ?? '')); ?></td></tr>
                        <tr><td>Default OBIS (non-TOU tenants)</td><td class="text-end"><?php echo htmlspecialchars((string)($summary['non_tou_obis'] ?? '')); ?></td></tr>
                        <tr><td>Generator method</td><td class="text-end"><?php echo (($g['method'] ?? '') === 'runtime') ? 'Run Time Calculation' : 'OBIS 1.1.1.8.2 Reading'; ?></td></tr>
                        <?php if (!empty($g['runtime'])): ?>
                        <tr><td>Generator run time</td><td class="text-end"><?php echo isset($g['runtime']['hours']) ? number_format((float)$g['runtime']['hours'], 2) . ' h' : 'No readings'; ?> (<?php echo htmlspecialchars((string)($g['runtime']['source'] ?? '')); ?>)</td></tr>
                        <?php endif; ?>
                        <tr><td>Manual extract Grid/T1 &middot; Gen/T2</td><td class="text-end"><?php echo htmlspecialchars((string)($summary['manual_col_t1'] ?? 'none')); ?> &middot; <?php echo htmlspecialchars((string)($g['manual_col_t2'] ?? 'none')); ?></td></tr>
                        <tr><td>Estimated readings</td><td class="text-end"><?php echo !empty($summary['estimated_readings']) ? 'On' : 'Off'; ?><?php echo !empty($summary['estimate_time_ranges']) ? ' (with estimate time ranges)' : ''; ?></td></tr>
                        <tr><td>Tenants with own settings</td><td class="text-end"><?php echo (int)($summary['tenants_with_own_settings'] ?? 0); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Tenant lines -->
        <?php $j_adjustments = $can_view_back_bill ? lumAdjForJournal($jdb, $j['journal_id']) : []; ?>
        <?php if (!empty($j_adjustments)): ?>
        <div class="border rounded p-3 mb-4" style="background: #fff8e1;">
            <div class="fw-bold mb-2"><i class="bi bi-arrow-left-right me-1"></i>Adjusted later (back billing / refunds for this month)</div>
            <table class="table table-sm mb-0 bg-transparent" style="font-size: 0.85rem;">
                <thead><tr><th>No.</th><th>Tenant</th><th>Reason</th><th>Billed in</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
                <tbody>
                <?php $adj_net = 0; foreach ($j_adjustments as $ja): if ($ja['status'] !== 'Draft') $adj_net += (float)$ja['total']; ?>
                    <tr>
                        <td><a href="back-billing.php?id=<?php echo (int)$ja['adjustment_id']; ?>"><?php echo lumAdjNumber($ja['adjustment_id']); ?></a></td>
                        <td><?php echo htmlspecialchars((string)$ja['tenant_name']); ?></td>
                        <td><?php echo htmlspecialchars(LUM_ADJ_REASONS[$ja['reason']] ?? $ja['reason']); ?></td>
                        <td><?php echo htmlspecialchars(date('M Y', mktime(0, 0, 0, $ja['posting_month'], 1, $ja['posting_year']))); ?><?php echo $ja['posting_option'] === 'same' ? ' (this month)' : ''; ?></td>
                        <td class="text-end <?php echo (float)$ja['total'] < 0 ? 'text-success' : 'text-danger'; ?>"><?php echo ((float)$ja['total'] < 0 ? '- R ' : 'R ') . number_format(abs((float)$ja['total']), 2); ?></td>
                        <td><?php echo htmlspecialchars($ja['status']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="small text-muted mt-1">Approved and posted adjustments total <?php echo ($adj_net < 0 ? '- R ' : 'R ') . number_format(abs($adj_net), 2); ?>. This journal itself is not changed.</div>
        </div>
        <?php endif; ?>

        <h5 class="fw-bold">Tenant Breakdown</h5>
        <div class="table-responsive">
            <table class="table table-striped table-bordered table-sm" style="font-size: 0.8rem;">
                <thead class="table-dark">
                    <tr>
                        <th>Tenant</th><th>Shop</th><th>Billing Period</th><th>Elec Tariff</th><th>OBIS</th><th>Generator</th>
                        <th class="text-end">kWh</th><th class="text-end">Basic (R)</th><th class="text-end">Energy (R)</th>
                        <th class="text-end">Demand (R)</th><th class="text-end">Network (R)</th><th class="text-end">Capacity (R)</th>
                        <th class="text-end">Generator (R)</th><th class="text-end">Comm Area (R)</th><th class="text-end">Total Elec (R)</th>
                        <th class="text-end">Water kL</th><th class="text-end">Total Water (R)</th><th class="text-end">Refuse (R)</th>
                        <th class="text-end">Grand Total (R)</th><th class="d-print-none"></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $sum = array_fill_keys(array_merge(LUM_JOURNAL_QUANTITIES, LUM_JOURNAL_AMOUNTS), 0);
                foreach ($lines as $l):
                    foreach ($sum as $k => $v) $sum[$k] += (float)$l[$k];
                    $detail = json_decode((string)$l['billing_detail'], true) ?: [];
                    $own = json_decode((string)$l['tenant_settings'], true) ?: [];
                    $line_manual = lumJournalLineManualCols($l, $detail);
                ?>
                    <tr>
                        <td class="fw-semibold">
                            <?php echo htmlspecialchars((string)$l['tenant_name']); ?>
                            <?php if ($own): ?><span class="badge bg-info text-dark" title="Own settings from the consumption slip"><i class="bi bi-sliders"></i></span><?php endif; ?>
                            <?php if ($l['estimated_readings']): ?><span class="badge bg-warning text-dark" title="Estimated readings allowed">Est</span><?php endif; ?>
                            <div class="small text-muted"><?php echo htmlspecialchars((string)$l['tenant_code']); ?></div>
                        </td>
                        <td><?php echo htmlspecialchars((string)$l['tenant_shop']); ?></td>
                        <?php
                        // Tenant billing cycle (journals made before upgrade 1 only have the property cycle)
                        $lp_start = $l['period_start'] ?? null ?: $j['period_start'];
                        $lp_end = $l['period_end'] ?? null ?: $j['period_end'];
                        $lp_start2 = array_key_exists('period_start', $l) ? ($l['period_2_start'] ?? '') : ($j['period_2_start'] ?? '');
                        $lp_end2 = array_key_exists('period_start', $l) ? ($l['period_2_end'] ?? '') : ($j['period_2_end'] ?? '');
                        $lp_own = !empty($l['own_billing_cycle']);
                        ?>
                        <td class="small text-nowrap <?php echo $lp_own ? 'text-primary fw-semibold' : ''; ?>" <?php echo $lp_own ? 'title="Own billing cycle within the property billing cycle"' : ''; ?>>
                            <?php echo date('d M', strtotime($lp_start)); ?> &ndash; <?php echo date('d M Y', strtotime($lp_end)); ?>
                            <?php if (!empty($lp_start2)): ?><br><?php echo date('d M', strtotime($lp_start2)); ?> &ndash; <?php echo date('d M Y', strtotime($lp_end2)); ?><?php endif; ?>
                            <?php if ($lp_own): ?><i class="bi bi-calendar-range ms-1"></i><?php endif; ?>
                        </td>
                        <td class="text-muted"><?php echo htmlspecialchars((string)$l['elec_tariff']); ?></td>
                        <td class="text-nowrap"><?php echo htmlspecialchars((string)$l['elec_obis']); ?><?php echo $l['is_tou'] ? ' <span class="badge bg-dark">TOU</span>' : ''; ?>
                            <?php if ($line_manual['elec'] !== ''): ?><br><span class="badge bg-secondary" title="Manual readings column used for the electricity readings">Manual <?php echo htmlspecialchars($line_manual['elec']); ?></span><?php endif; ?>
                        </td>
                        <td class="small"><?php echo htmlspecialchars((string)$l['gen_method']); ?><?php echo ($l['gen_runtime_hours'] !== null) ? '<br><span class="text-muted">' . number_format((float)$l['gen_runtime_hours'], 2) . ' h</span>' : ''; ?></td>
                        <td class="text-end"><?php echo number_format((float)$l['kwh'], 2); ?></td>
                        <td class="text-end"><?php echo $money($l['basic']); ?></td>
                        <td class="text-end"><?php echo $money($l['energy']); ?></td>
                        <td class="text-end"><?php echo $money($l['demand']); ?></td>
                        <td class="text-end"><?php echo $money((float)$l['network'] + (float)$l['shared_nac']); ?></td>
                        <td class="text-end"><?php echo $money($l['capacity']); ?></td>
                        <td class="text-end"><?php echo $money($l['generator']); ?></td>
                        <td class="text-end"><?php echo $money($l['elec_comm']); ?></td>
                        <td class="text-end fw-bold"><?php echo $money($l['elec_total']); ?></td>
                        <td class="text-end"><?php echo number_format((float)$l['water_kl'], 2); ?></td>
                        <td class="text-end fw-bold"><?php echo $money($l['water_total']); ?></td>
                        <td class="text-end"><?php echo $money($l['refuse']); ?></td>
                        <td class="text-end fw-bold"><?php echo $money($l['total']); ?></td>
                        <td class="d-print-none text-center">
                            <button type="button" class="btn btn-link btn-sm p-0" data-bs-toggle="collapse" data-bs-target="#line<?php echo (int)$l['line_id']; ?>" title="How this tenant was billed"><i class="bi bi-info-circle"></i></button>
                            <?php if ($can_back_bill && $j['status'] !== 'Voided'): ?>
                                <a href="back-billing.php?action=recalc&amp;line_id=<?php echo (int)$l['line_id']; ?>" class="btn btn-link btn-sm p-0 ms-1 text-danger" title="Back bill or refund this tenant for this month (recalculate)"><i class="bi bi-arrow-left-right"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr class="collapse detail-row" id="line<?php echo (int)$l['line_id']; ?>">
                        <td colspan="20">
                            <div class="row small g-3">
                                <div class="col-lg-7">
                                    <strong>Meters</strong>
                                    <?php if (!empty($detail['meters'])): ?>
                                    <table class="table table-sm table-bordered bg-white mb-1 mt-1">
                                        <thead><tr><th>Serial</th><th>CT</th><th>Applied CT</th><th class="text-end">kWh</th><th>Electrical source</th><th class="text-end">Gen kWh</th><th>Generator source</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($detail['meters'] as $m): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string)$m['serial']); ?></td>
                                                <td><?php echo number_format((float)$m['ct_setting'], 2); ?></td>
                                                <td><?php echo number_format((float)$m['applied_ct'], 2); ?></td>
                                                <td class="text-end"><?php echo number_format((float)$m['kwh'], 2); ?></td>
                                                <td><?php echo htmlspecialchars((string)$m['elec_source']); ?></td>
                                                <td class="text-end"><?php echo $m['gen_source'] !== null ? number_format((float)$m['gen_kwh'], 2) : '-'; ?></td>
                                                <td><?php echo htmlspecialchars((string)($m['gen_source'] ?? '-')); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    <?php else: ?>
                                        <div class="text-muted">No electrical meters.</div>
                                    <?php endif; ?>
                                    <div>Water meters: <?php echo htmlspecialchars((string)($l['water_meters'] ?: 'none')); ?></div>
                                    <?php if (!empty($l['water_start'])): ?>
                                        <div>Water period: <?php echo date('d M Y', strtotime($l['water_start'])); ?> &ndash; <?php echo date('d M Y', strtotime($l['water_end'])); ?>
                                        <?php if (!empty($l['water_2_start'])): ?> and <?php echo date('d M Y', strtotime($l['water_2_start'])); ?> &ndash; <?php echo date('d M Y', strtotime($l['water_2_end'])); ?><?php endif; ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-lg-5">
                                    <div>Municipality: <?php echo htmlspecialchars((string)$l['municipality']); ?> &middot; Season: <?php echo htmlspecialchars((string)$l['season_1']); ?><?php echo !empty($l['season_2']) ? ' / ' . htmlspecialchars((string)$l['season_2']) : ''; ?></div>
                                    <div>Water tariff: <?php echo htmlspecialchars((string)$l['water_tariff']); ?> &middot; Sewer tariff: <?php echo htmlspecialchars((string)$l['sewer_tariff']); ?></div>
                                    <?php if ($l['is_tou']): ?><div>TOU algorithm: <?php echo htmlspecialchars((string)$l['tou_algorithm']); ?></div><?php endif; ?>
                                    <div>Occupancy: <?php echo htmlspecialchars((string)$l['occupancy_status']); ?></div>
                                    <div>Water: basic R <?php echo $money($l['water_basic']); ?>, usage R <?php echo $money($l['water_usage']); ?>, sewer basic R <?php echo $money($l['sewer_basic']); ?>, sewer usage R <?php echo $money($l['sewer_usage']); ?>, sewer additional R <?php echo $money($l['sewer_additional']); ?>, common area R <?php echo $money((float)$l['water_comm'] + (float)$l['sewer_comm']); ?> (<?php echo number_format((float)$l['comm_kl'], 2); ?> kL)</div>
                                    <?php if ($own): ?>
                                        <div class="mt-2"><strong>Own settings from the consumption slip</strong>
                                            <ul class="mb-0 ps-3">
                                            <?php foreach ($own as $k => $v): ?>
                                                <li><?php echo htmlspecialchars(lumTenantSettingLabelSafe($k) . ': ' . lumTenantSettingValueSafe($k, $v)); ?></li>
                                            <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-dark">
                    <tr>
                        <th colspan="6">Totals</th>
                        <th class="text-end"><?php echo number_format($sum['kwh'], 2); ?></th>
                        <th class="text-end"><?php echo $money($sum['basic']); ?></th>
                        <th class="text-end"><?php echo $money($sum['energy']); ?></th>
                        <th class="text-end"><?php echo $money($sum['demand']); ?></th>
                        <th class="text-end"><?php echo $money($sum['network'] + $sum['shared_nac']); ?></th>
                        <th class="text-end"><?php echo $money($sum['capacity']); ?></th>
                        <th class="text-end"><?php echo $money($sum['generator']); ?></th>
                        <th class="text-end"><?php echo $money($sum['elec_comm']); ?></th>
                        <th class="text-end"><?php echo $money($sum['elec_total']); ?></th>
                        <th class="text-end"><?php echo number_format($sum['water_kl'], 2); ?></th>
                        <th class="text-end"><?php echo $money($sum['water_total']); ?></th>
                        <th class="text-end"><?php echo $money($sum['refuse']); ?></th>
                        <th class="text-end"><?php echo $money($sum['total']); ?></th>
                        <th class="d-print-none"></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <?php else: ?>
    <!-- ============================ JOURNAL LIST ============================ -->
    <div class="journal-card p-3 mb-3 d-print-none">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small mb-1">Property</label>
                <select name="property" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All properties</option>
                    <?php foreach ($journal_properties as $p): ?>
                        <option value="<?php echo htmlspecialchars($p); ?>" <?php echo $f_property === $p ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Year</label>
                <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All years</option>
                    <?php for ($y = (int)date('Y') + 1; $y >= 2020; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $f_year === (string)$y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach (['Active' => 'Active', 'Superseded' => 'Superseded', 'Voided' => 'Voided', '' => 'All'] as $val => $lbl): ?>
                        <option value="<?php echo $val; ?>" <?php echo $f_status === $val ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>

    <div class="journal-card">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle" style="font-size: 0.85rem;">
                <thead class="table-dark">
                    <tr>
                        <th>#</th><th>Property</th><th>Billing Month</th><th>Periods</th><th class="text-end">Tenants</th>
                        <th class="text-end">Total Elec (R)</th><th class="text-end">Total Water (R)</th><th class="text-end">Refuse (R)</th>
                        <th class="text-end">Grand Total (R)</th><th>Status</th><th>Journaled</th><th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($journals)): ?>
                    <tr><td colspan="12" class="text-center text-muted py-4">No journals found.</td></tr>
                <?php endif; ?>
                <?php foreach ($journals as $j): ?>
                    <tr>
                        <td><?php echo (int)$j['journal_id']; ?></td>
                        <td class="fw-semibold"><?php echo htmlspecialchars($j['property']); ?></td>
                        <td><?php echo htmlspecialchars($month_name($j['billing_month'], $j['billing_year'])); ?>
                            <?php if (!empty($j['billing_label'])): ?><div class="small text-muted"><?php echo htmlspecialchars($j['billing_label']); ?></div><?php endif; ?>
                        </td>
                        <td class="small"><?php echo date('d M', strtotime($j['period_start'])); ?> &ndash; <?php echo date('d M Y', strtotime(!empty($j['period_2_end']) ? $j['period_2_end'] : $j['period_end'])); ?></td>
                        <td class="text-end"><?php echo (int)$j['tenant_count']; ?></td>
                        <td class="text-end"><?php echo $money($j['total_elec']); ?></td>
                        <td class="text-end"><?php echo $money($j['total_water']); ?></td>
                        <td class="text-end"><?php echo $money($j['total_refuse']); ?></td>
                        <td class="text-end fw-bold"><?php echo $money($j['grand_total']); ?></td>
                        <td><?php echo $status_badge($j['status']); ?></td>
                        <td class="small"><?php echo htmlspecialchars(date('d M Y H:i', strtotime($j['created_at']))); ?><br><span class="text-muted"><?php echo htmlspecialchars($j['created_by_name']); ?></span></td>
                        <td class="text-nowrap text-end">
                            <a href="financial-journal.php?id=<?php echo (int)$j['journal_id']; ?>" class="btn btn-sm btn-outline-primary" title="View journal"><i class="bi bi-eye"></i></a>
                            <a href="financial-journal.php?id=<?php echo (int)$j['journal_id']; ?>&amp;export=csv" class="btn btn-sm btn-outline-success" title="Export CSV"><i class="bi bi-filetype-csv"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

    <?php include LUM_ROOT . '/Layout/foot-assets.php'; ?>
</body>
</html>