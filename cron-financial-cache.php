<?php
// /var/www/Lynx/Reporting/cron-financial-cache.php
// =========================================================================
// LYNX UTILITY MANAGEMENT - DASHBOARD FINANCIAL TOTALS (cron job)
// -------------------------------------------------------------------------
// Last month's electricity and grand totals per property, for the dashboard:
//  - a journaled month: the totals of the active journal (what was billed),
//  - otherwise: calculated exactly like the financial report with its default
//    settings (shared slip engine, tenants' own report settings, generator run
//    time, back billing adjustments).
// Stored in sys_db_information.lum_dashboard_financials (created here if missing; see dashboard-financials-setup.sql).
// Nothing is written inside the web root: the old /var/www/Lynx/Reporting/global_financials.json is deleted.
// Run from cron, e.g.:  15 2 * * *  php /var/www/Lynx/Reporting/cron-financial-cache.php
// =========================================================================

// This script may only run from the command line (cron). Block all web requests.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

// Shared settings, connections and engines (see /var/www/Lynx/bootstrap.php; no login check from the command line)
require_once __DIR__ . '/bootstrap.php';
ini_set('memory_limit', '2048M');
set_time_limit(3600);

lum_connect('tenants', 'obis', 'manual', 'tariffs');
lum_use('slips', 'journal'); // The slip engine also loads reporting-engine.php
if (is_readable(LUM_ROOT . LUM_LIBRARIES['back_billing'])) lum_use('back_billing'); // Back billing adjustments on the slips (optional until installed)

$cron_log = function ($text) { error_log('LUM cron-financial-cache: ' . $text); };
$cron_started = microtime(true);

// Last month (the financial report's default period)
$cron_start = date('Y-m-d', strtotime('first day of last month'));
$cron_end = date('Y-m-d', strtotime('last day of last month'));
$cron_month = (int)date('n', strtotime($cron_end));
$cron_year = (int)date('Y', strtotime($cron_end));

// Properties: the property register only
$cron_props_db = lumDbConn('sys_db_properties');
if (!$cron_props_db) {
    $cron_log('property register not available');
    exit(1);
}
$cron_properties = $cron_props_db->query("SELECT Property FROM lum_properties ORDER BY Property ASC")->fetchAll(PDO::FETCH_COLUMN);

$cron_jdb = lumJournalDb();
$cron_tenant_stmt = $tenant_db_conn->prepare("SELECT * FROM lum_tenants WHERE tenant_property = :prop ORDER BY tenant_shop ASC");
$cron_results = [];

foreach ($cron_properties as $cron_property) {
    try {
        // --- A journaled month: the journal's totals ---
        $cron_journal = $cron_jdb ? lumJournalActive($cron_jdb, $cron_property, $cron_month, $cron_year) : null;
        if ($cron_journal) {
            $cron_results[$cron_property] = [
                'total_elec' => round((float)$cron_journal['total_elec'], 2), 'grand_total' => round((float)$cron_journal['grand_total'], 2),
                'tenant_count' => (int)$cron_journal['tenant_count'], 'source' => 'journal', 'journal_id' => (int)$cron_journal['journal_id'],
                'period_start' => $cron_journal['period_start'], 'period_end' => $cron_journal['period_2_end'] ?: $cron_journal['period_end'],
            ];
            continue;
        }

        // --- Otherwise: exactly the financial report's calculation (default report settings) ---
        $_REQUEST = [];
        $property_name = $cron_property;
        $lum_default_start = $cron_start;
        $lum_default_end = $cron_end;
        require LUM_SLIP_DIR . '/slip-context.php';

        $cron_tenant_stmt->execute(['prop' => $cron_property]);
        $tenants = $cron_tenant_stmt->fetchAll(PDO::FETCH_ASSOC);

        $tenant_report_settings = lumTenantReportSettingsLoad($tenant_db_conn, array_column($tenants, 'tenant_id'), $report_month, $report_year);
        $lum_gen_cache = null;
        $slip_bill_month = $report_month;
        $slip_bill_year = $report_year;

        $cron_elec = 0.0;
        $cron_water = 0.0;
        $cron_refuse = 0.0;
        $cron_adjust = 0.0;
        foreach ($tenants as $tenant) {
            require LUM_SLIP_DIR . '/slip-tenant-settings-apply.php';
            require LUM_SLIP_DIR . '/slip-tenant.php';

            $show_graph = false;
            $show_water_graph = false;
            $slip_render_mode = 'data';
            $slip_chart_key = $tenant['tenant_id'];
            ob_start();
            try {
                include LUM_SLIP_DIR . '/slip-render.php';
            } finally {
                ob_end_clean();
            }
            unset($slip_render_mode, $slip_chart_key);
            $t = $slip_totals;

            require LUM_SLIP_DIR . '/slip-tenant-settings-restore.php';

            // Same totals as the financial report
            $cron_elec += $t['energy'] + $t['basic'] + $t['network'] + $t['shared_nac'] + $t['capacity'] + $t['demand'] + $t['generator'] + $t['elec_comm'];
            $cron_water += $t['water_basic'] + $t['water_usage'] + $t['sewer_basic'] + $t['sewer_usage'] + $t['sewer_additional'] + $t['water_comm'] + $t['sewer_comm'];
            $cron_refuse += $t['refuse'];
            $cron_adjust += (float)($t['adjustments'] ?? 0);
        }

        $cron_results[$cron_property] = [
            'total_elec' => round($cron_elec, 2), 'grand_total' => round($cron_elec + $cron_water + $cron_refuse + $cron_adjust, 2),
            'tenant_count' => count($tenants), 'source' => 'live', 'journal_id' => null,
            'period_start' => $start_date, 'period_end' => $has_period_2 ? $end_date_2 : $end_date,
        ];
    } catch (\Throwable $e) {
        $cron_log('property ' . $cron_property . ' skipped: ' . $e->getMessage());
        while (ob_get_level() > 0) ob_end_clean();
    }
}

// --- Store the totals (database only) ---
$cron_saved = false;
$cron_info_db = lumSysDb('sys_db_information');
if ($cron_info_db) {
    try {
        // Same definition as dashboard-financials-setup.sql. Only created when it does not exist yet: the website's
        // database user may not create tables, so the CREATE is not even tried once the table is there.
        $cron_has_table = (bool)$cron_info_db->query("SHOW TABLES LIKE 'lum_dashboard_financials'")->fetchColumn();
        if (!$cron_has_table) $cron_info_db->exec("CREATE TABLE IF NOT EXISTS lum_dashboard_financials (
              property       VARCHAR(50)   NOT NULL,
              period_year    SMALLINT UNSIGNED NOT NULL,
              period_month   TINYINT UNSIGNED NOT NULL,
              period_start   DATE NULL,
              period_end     DATE NULL,
              total_elec     DECIMAL(16,2) NOT NULL DEFAULT 0,
              grand_total    DECIMAL(16,2) NOT NULL DEFAULT 0,
              tenant_count   INT UNSIGNED  NOT NULL DEFAULT 0,
              source         VARCHAR(10)   NOT NULL DEFAULT 'live',
              journal_id     INT UNSIGNED  NULL,
              calculated_at  DATETIME      NOT NULL,
              PRIMARY KEY (property, period_year, period_month),
              KEY idx_period (period_year, period_month)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) {
        $cron_log('could not create lum_dashboard_financials (run dashboard-financials-setup.sql): ' . $e->getMessage());
    }
    try {
        $cron_info_db->beginTransaction();
        $cron_up = $cron_info_db->prepare("INSERT INTO lum_dashboard_financials
                (property, period_year, period_month, period_start, period_end, total_elec, grand_total, tenant_count, source, journal_id, calculated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE period_start = VALUES(period_start), period_end = VALUES(period_end), total_elec = VALUES(total_elec),
                    grand_total = VALUES(grand_total), tenant_count = VALUES(tenant_count), source = VALUES(source),
                    journal_id = VALUES(journal_id), calculated_at = VALUES(calculated_at)");
        foreach ($cron_results as $cron_prop => $cron_r) {
            $cron_up->execute([$cron_prop, $cron_year, $cron_month, $cron_r['period_start'], $cron_r['period_end'], $cron_r['total_elec'],
                               $cron_r['grand_total'], $cron_r['tenant_count'], $cron_r['source'], $cron_r['journal_id']]);
        }
        $cron_info_db->commit();
        $cron_saved = true;
    } catch (\Throwable $e) {
        if ($cron_info_db->inTransaction()) $cron_info_db->rollBack();
        $cron_log('totals NOT saved: ' . $e->getMessage());
    }
} else {
    $cron_log('totals NOT saved: sys_db_information is not available');
}

// The old cache file sat inside the web root (readable by anyone with the address): never written again, always removed
$cron_old_file = lum_resolve_path('Reporting/global_financials.json', false);
if (is_string($cron_old_file) && is_file($cron_old_file) && !@unlink($cron_old_file)) {
    $cron_log('could not delete ' . $cron_old_file . ' - please delete it by hand');
}

$cron_journaled = count(array_filter($cron_results, function ($r) { return $r['source'] === 'journal'; }));
$cron_log(sprintf('%d properties (%d journaled) for %02d/%04d in %.1f s%s', count($cron_results), $cron_journaled, $cron_month, $cron_year,
                  microtime(true) - $cron_started, $cron_saved ? '' : ' - NOT SAVED'));
exit($cron_saved ? 0 : 1); // A failed save shows as an error in cron