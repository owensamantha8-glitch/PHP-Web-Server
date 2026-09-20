<?php
// Library file: only other pages may include it, it can never be opened directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(403); exit('Forbidden'); }
// =========================================================================
// LYNX UTILITY MANAGEMENT - BACK BILLING ENGINE (STEP 1)
// Location: /var/www/Lynx/Reporting/back-billing-engine.php
// -------------------------------------------------------------------------
// Adjustments correct a tenant's billing after a report was journaled:
//   positive difference = back billing (tenant pays more)
//   negative difference = refund (tenant is paid back)
// A journaled report is never changed: the adjustment is billed in the next
// open billing month, or (optional) in the original month, which is then
// journaled again.
// Status: Draft -> Approved -> Posted (step 4, when the posting month is journaled); Voided.
// =========================================================================

require_once(__DIR__ . '/financial-journal-engine.php'); // lumJournalDb(), lumJournalActive()

if (!defined('LUM_BACKBILL_ENGINE')) {
    define('LUM_BACKBILL_ENGINE', true);

    // Charges that can be adjusted (same keys as the journal lines), grouped for the totals
    define('LUM_ADJ_CHARGES', [
        'energy'           => ['Energy Charge',            'elec'],
        'basic'            => ['Basic Charge',             'elec'],
        'demand'           => ['Demand Charge',            'elec'],
        'network'          => ['Network Access Charge',    'elec'],
        'capacity'         => ['Capacity Charge',          'elec'],
        'generator'        => ['Generator Charge',         'elec'],
        'elec_comm'        => ['Electrical Common Area',   'elec'],
        'shared_nac'       => ['Shared Network Access',    'elec'],
        'water_basic'      => ['Water Basic Charge',       'water'],
        'water_usage'      => ['Water Usage',              'water'],
        'sewer_basic'      => ['Sewer Basic Charge',       'water'],
        'sewer_usage'      => ['Sewer Usage',              'water'],
        'sewer_additional' => ['Sewer Additional',         'water'],
        'water_comm'       => ['Water Common Area',        'water'],
        'sewer_comm'       => ['Sewer Common Area',        'water'],
        'refuse'           => ['Refuse',                   'refuse'],
    ]);

    define('LUM_ADJ_REASONS', [
        'missing_readings' => 'Missing readings',
        'incorrect_units'  => 'Incorrect units billed',
        'incorrect_tariff' => 'Incorrect tariff applied',
        'other'            => 'Other',
    ]);

    define('LUM_ADJ_STATUSES', ['Draft', 'Approved', 'Posted', 'Voided']);

    function lumAdjNumber($id) {
        return 'ADJ-' . str_pad((string)(int)$id, 6, '0', STR_PAD_LEFT);
    }

    function lumAdjMonthLabel($month, $year) {
        return date('F Y', mktime(0, 0, 0, (int)$month, 1, (int)$year));
    }

    function lumAdjUser() {
        return [
            'id'   => isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
            'name' => substr((string)($_SESSION['user_name'] ?? 'system'), 0, 50),
        ];
    }

    // First billing month AFTER the given month that has no active journal for the property
    function lumAdjNextOpenMonth($pdo, $property, $month, $year) {
        $m = (int)$month;
        $y = (int)$year;
        for ($i = 0; $i < 60; $i++) {
            $m++;
            if ($m > 12) { $m = 1; $y++; }
            if (!lumJournalActive($pdo, $property, $m, $y)) {
                return [$m, $y];
            }
        }
        return [$m, $y];
    }

    // Posting month for an option: 'same' = the original month, 'next' = next open month
    function lumAdjPostingMonth($pdo, $option, $property, $orig_month, $orig_year) {
        if ($option === 'same') return [(int)$orig_month, (int)$orig_year];
        return lumAdjNextOpenMonth($pdo, $property, $orig_month, $orig_year);
    }

    // Clean the charge lines from a form: [charge_key => ['original' => ?, 'corrected' => ?, 'difference' => float]]
    // When both original and corrected are given, the difference is always corrected - original.
    function lumAdjCleanLines(array $input) {
        $num = function ($v) {
            $v = str_replace([' ', ','], ['', '.'], trim((string)$v));
            return ($v === '' || !is_numeric($v)) ? null : round((float)$v, 2);
        };
        $lines = [];
        foreach (LUM_ADJ_CHARGES as $key => $def) {
            $row = $input[$key] ?? [];
            $orig = $num($row['original'] ?? '');
            $corr = $num($row['corrected'] ?? '');
            $diff = $num($row['difference'] ?? '');
            if ($orig !== null && $corr !== null) {
                $diff = round($corr - $orig, 2);
            }
            if ($diff === null) $diff = 0.0;
            // Nothing entered, or only zeros: not stored
            if (abs($diff) < 0.005 && abs((float)($orig ?? 0)) < 0.005 && abs((float)($corr ?? 0)) < 0.005) continue;
            $lines[$key] = ['original' => $orig, 'corrected' => $corr, 'difference' => $diff];
        }
        return $lines;
    }

    function lumAdjTotals(array $lines) {
        $t = ['elec' => 0.0, 'water' => 0.0, 'refuse' => 0.0, 'total' => 0.0];
        foreach ($lines as $key => $l) {
            $group = LUM_ADJ_CHARGES[$key][1] ?? null;
            if ($group === null) continue;
            $t[$group] += (float)$l['difference'];
            $t['total'] += (float)$l['difference'];
        }
        return array_map(function ($v) { return round($v, 2); }, $t);
    }

    // Create ($id = null) or update a Draft. Returns the adjustment_id; throws on failure.
    function lumAdjSave($pdo, array $data, array $lines, $id = null) {
        if (!$pdo) throw new RuntimeException('The journal database is not available.');
        $user = lumAdjUser();
        $totals = lumAdjTotals($lines);
        $dec = function ($v) { return ($v === null || $v === '') ? null : round((float)$v, 3); };

        $fields = [
            'property' => $data['property'], 'tenant_id' => (int)$data['tenant_id'],
            'tenant_name' => substr((string)$data['tenant_name'], 0, 50), 'tenant_code' => substr((string)$data['tenant_code'], 0, 50),
            'tenant_shop' => substr((string)$data['tenant_shop'], 0, 10),
            'adjustment_type' => $data['adjustment_type'] ?? 'manual',
            'reason' => $data['reason'], 'description' => ($data['description'] ?? '') !== '' ? substr($data['description'], 0, 500) : null,
            'original_journal_id' => $data['original_journal_id'] ?: null, 'original_line_id' => $data['original_line_id'] ?: null,
            'original_month' => (int)$data['original_month'], 'original_year' => (int)$data['original_year'],
            'original_period_start' => $data['original_period_start'] ?: null, 'original_period_end' => $data['original_period_end'] ?: null,
            'posting_option' => $data['posting_option'], 'posting_month' => (int)$data['posting_month'], 'posting_year' => (int)$data['posting_year'],
            'total_elec' => $totals['elec'], 'total_water' => $totals['water'], 'total_refuse' => $totals['refuse'], 'total' => $totals['total'],
            'kwh_original' => $dec($data['kwh_original'] ?? null), 'kwh_corrected' => $dec($data['kwh_corrected'] ?? null),
            'kl_original' => $dec($data['kl_original'] ?? null), 'kl_corrected' => $dec($data['kl_corrected'] ?? null),
            'reverses_adjustment_id' => !empty($data['reverses_adjustment_id']) ? (int)$data['reverses_adjustment_id'] : null,
            'recalc_settings' => !empty($data['recalc_settings']) ? json_encode($data['recalc_settings'], JSON_UNESCAPED_UNICODE) : null,
        ];

        $pdo->beginTransaction();
        try {
            if ($id === null) {
                $fields['status'] = 'Draft';
                $fields['created_by_user_id'] = $user['id'];
                $fields['created_by_name'] = $user['name'];
                $cols = array_keys($fields);
                $st = $pdo->prepare("INSERT INTO lum_adjustments (" . implode(', ', $cols) . ") VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")");
                $st->execute(array_values($fields));
                $id = (int)$pdo->lastInsertId();
            } else {
                $fields['updated_by_name'] = $user['name'];
                $sets = implode(', ', array_map(function ($c) { return "$c = ?"; }, array_keys($fields)));
                $st = $pdo->prepare("UPDATE lum_adjustments SET $sets, updated_at = NOW() WHERE adjustment_id = ? AND status = 'Draft'");
                $st->execute(array_merge(array_values($fields), [(int)$id]));
                if ($st->rowCount() === 0) throw new RuntimeException('Only draft adjustments can be changed.');
                $pdo->prepare("DELETE FROM lum_adjustment_lines WHERE adjustment_id = ?")->execute([(int)$id]);
            }

            $ins = $pdo->prepare("INSERT INTO lum_adjustment_lines (adjustment_id, charge_key, original_amount, corrected_amount, difference) VALUES (?, ?, ?, ?, ?)");
            foreach ($lines as $key => $l) {
                $ins->execute([(int)$id, $key, $l['original'], $l['corrected'], round((float)$l['difference'], 2)]);
            }
            $pdo->commit();
            return (int)$id;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    // ['adjustment' => row, 'lines' => [charge_key => row]] or null
    function lumAdjGet($pdo, $id) {
        if (!$pdo) return null;
        try {
            $st = $pdo->prepare("SELECT * FROM lum_adjustments WHERE adjustment_id = ?");
            $st->execute([(int)$id]);
            $adj = $st->fetch(PDO::FETCH_ASSOC);
            if (!$adj) return null;
            $st = $pdo->prepare("SELECT * FROM lum_adjustment_lines WHERE adjustment_id = ?");
            $st->execute([(int)$id]);
            $lines = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) $lines[$l['charge_key']] = $l;
            // Keep the standard charge order
            $ordered = [];
            foreach (array_keys(LUM_ADJ_CHARGES) as $k) if (isset($lines[$k])) $ordered[$k] = $lines[$k];
            return ['adjustment' => $adj, 'lines' => $ordered];
        } catch (\Throwable $e) {
            error_log('LUM back billing read failed: ' . $e->getMessage());
            return null;
        }
    }

    // $filters: property, status, posting ('YYYY-MM'), search, allowed_properties (null = all)
    function lumAdjList($pdo, array $filters, $limit = 300) {
        if (!$pdo) return [];
        $sql = "SELECT * FROM lum_adjustments WHERE 1=1";
        $p = [];
        if (!empty($filters['property'])) { $sql .= " AND property = ?"; $p[] = $filters['property']; }
        if (!empty($filters['status'])) { $sql .= " AND status = ?"; $p[] = $filters['status']; }
        if (!empty($filters['posting']) && preg_match('/^(\d{4})-(\d{2})$/', $filters['posting'], $m)) {
            $sql .= " AND posting_year = ? AND posting_month = ?"; $p[] = (int)$m[1]; $p[] = (int)$m[2];
        }
        if (!empty($filters['search'])) {
            $sql .= " AND (tenant_name LIKE ? OR tenant_code LIKE ? OR tenant_shop LIKE ? OR description LIKE ?)";
            $like = '%' . $filters['search'] . '%';
            array_push($p, $like, $like, $like, $like);
        }
        if (isset($filters['allowed_properties']) && is_array($filters['allowed_properties'])) {
            if (empty($filters['allowed_properties'])) return [];
            $sql .= " AND property IN (" . implode(',', array_fill(0, count($filters['allowed_properties']), '?')) . ")";
            $p = array_merge($p, array_values($filters['allowed_properties']));
        }
        $sql .= " ORDER BY adjustment_id DESC LIMIT " . (int)$limit;
        try {
            $st = $pdo->prepare($sql);
            $st->execute($p);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('LUM back billing list failed: ' . $e->getMessage());
            return [];
        }
    }

    // Approve a Draft. A 'next' posting month is checked again (that month may have been journaled meanwhile).
    // Returns [true, message] or [false, message].
    function lumAdjApprove($pdo, $id) {
        $adj = lumAdjGet($pdo, $id);
        if (!$adj) return [false, 'Adjustment not found.'];
        $a = $adj['adjustment'];
        if ($a['status'] !== 'Draft') return [false, 'Only draft adjustments can be approved.'];
        if (abs((float)$a['total']) < 0.005) return [false, 'The adjustment amount is R 0.00.'];

        list($pm, $py) = lumAdjPostingMonth($pdo, $a['posting_option'], $a['property'], $a['original_month'], $a['original_year']);
        $user = lumAdjUser();
        try {
            $st = $pdo->prepare("UPDATE lum_adjustments SET status = 'Approved', posting_month = ?, posting_year = ?,
                                 approved_by_user_id = ?, approved_by_name = ?, approved_at = NOW()
                                 WHERE adjustment_id = ? AND status = 'Draft'");
            $st->execute([$pm, $py, $user['id'], $user['name'], (int)$id]);
            if ($st->rowCount() === 0) return [false, 'The adjustment could not be approved.'];
            $moved = ((int)$a['posting_month'] !== $pm || (int)$a['posting_year'] !== $py);
            return [true, 'Approved for ' . lumAdjMonthLabel($pm, $py) . ($moved ? ' (moved: the planned month has been journaled).' : '.')];
        } catch (\Throwable $e) {
            error_log('LUM back billing approve failed: ' . $e->getMessage());
            return [false, 'The adjustment could not be approved.'];
        }
    }

    // Void a Draft or Approved adjustment (posted adjustments are corrected with a reversing adjustment)
    function lumAdjVoid($pdo, $id, $reason) {
        $user = lumAdjUser();
        try {
            $st = $pdo->prepare("UPDATE lum_adjustments SET status = 'Voided', voided_by_name = ?, voided_at = NOW(), void_reason = ?
                                 WHERE adjustment_id = ? AND status IN ('Draft', 'Approved')");
            $st->execute([$user['name'], substr(trim((string)$reason), 0, 255), (int)$id]);
            return $st->rowCount() > 0;
        } catch (\Throwable $e) {
            error_log('LUM back billing void failed: ' . $e->getMessage());
            return false;
        }
    }

    // Journals of a property that contain a tenant (newest first), with that tenant's line
    function lumAdjTenantJournals($pdo, $property, $tenant_id) {
        if (!$pdo) return [];
        try {
            $st = $pdo->prepare("SELECT j.journal_id, j.billing_month, j.billing_year, j.billing_label, j.status, j.period_start, j.period_end,
                                        j.period_2_end, l.line_id
                                 FROM lum_journal j
                                 JOIN lum_journal_lines l ON l.journal_id = j.journal_id AND l.tenant_id = ?
                                 WHERE j.property = ? AND j.status IN ('Active', 'Superseded')
                                 ORDER BY j.billing_year DESC, j.billing_month DESC, j.journal_id DESC LIMIT 48");
            $st->execute([(int)$tenant_id, $property]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('LUM back billing journal lookup failed: ' . $e->getMessage());
            return [];
        }
    }

    // One journal line (with its journal header)
    function lumAdjJournalLine($pdo, $line_id) {
        if (!$pdo) return null;
        try {
            $st = $pdo->prepare("SELECT l.*, j.property, j.billing_month, j.billing_year, j.status AS journal_status,
                                        j.period_start AS j_period_start, j.period_end AS j_period_end,
                                        j.period_2_start AS j_period_2_start, j.period_2_end AS j_period_2_end,
                                        j.water_start AS j_water_start, j.water_end AS j_water_end,
                                        j.water_2_start AS j_water_2_start, j.water_2_end AS j_water_2_end,
                                        j.report_settings AS j_report_settings
                                 FROM lum_journal_lines l JOIN lum_journal j ON j.journal_id = l.journal_id
                                 WHERE l.line_id = ?");
            $st->execute([(int)$line_id]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) {
            error_log('LUM back billing journal line read failed: ' . $e->getMessage());
            return null;
        }
    }

    // =====================================================================
    // STEP 2 - RECALCULATION
    // =====================================================================
    define('LUM_ADJ_DATE_KEYS', ['start_date', 'end_date', 'start_date_2', 'end_date_2',
                                 'water_start_date', 'water_end_date', 'water_start_date_2', 'water_end_date_2']);

    // The billing period a journal line was billed on (its own cycle, or the journal's)
    function lumAdjLinePeriod(array $line) {
        $has_cols = array_key_exists('period_start', $line) && !empty($line['period_start']);
        $src = $has_cols
            ? [$line['period_start'], $line['period_end'], $line['period_2_start'], $line['period_2_end'],
               $line['water_start'], $line['water_end'], $line['water_2_start'], $line['water_2_end']]
            : [$line['j_period_start'], $line['j_period_end'], $line['j_period_2_start'], $line['j_period_2_end'],
               $line['j_water_start'], $line['j_water_end'], $line['j_water_2_start'], $line['j_water_2_end']];
        return array_combine(LUM_ADJ_DATE_KEYS, array_map(function ($v) { return (string)($v ?? ''); }, $src));
    }

    // Clean the correction choices from a form. $tariff_options: ['elec' => [...], 'water' => [...], 'sewer' => [...]]
    function lumAdjCleanCorrections(array $in, array $tariff_options) {
        $c = [];
        foreach (['elec' => 'elec_tariff', 'water' => 'water_tariff', 'sewer' => 'sewer_tariff'] as $grp => $key) {
            $v = trim((string)($in[$key] ?? ''));
            $c[$key] = ($v !== '' && in_array($v, $tariff_options[$grp] ?? [], true)) ? $v : '';
        }
        $c['billing_obis'] = in_array($in['billing_obis'] ?? '', ['1.1.1.8.0', '1.1.1.8.1'], true) ? $in['billing_obis'] : '';
        $c['t2_method'] = in_array($in['t2_method'] ?? '', ['1.1.1.8.2', 'runtime'], true) ? $in['t2_method'] : '';
        $c['estimated'] = in_array($in['estimated'] ?? '', ['on', 'off'], true) ? $in['estimated'] : '';
        foreach (LUM_ADJ_DATE_KEYS as $k) {
            $v = trim((string)($in[$k] ?? ''));
            $c[$k] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
        }
        foreach (['kwh', 'kl'] as $k) {
            $v = str_replace([' ', ','], ['', '.'], trim((string)($in[$k] ?? '')));
            $c[$k] = ($v !== '' && is_numeric($v) && (float)$v >= 0) ? (string)round((float)$v, 3) : '';
        }
        return $c;
    }

    // Is the correction set valid? Returns an error message or ''.
    function lumAdjCorrectionsError(array $c) {
        if ($c['start_date'] === '' || $c['end_date'] === '') return 'The billing period start and end dates are required.';
        if (strtotime($c['end_date']) < strtotime($c['start_date'])) return 'The billing period ends before it starts.';
        if (($c['start_date_2'] === '') !== ($c['end_date_2'] === '')) return 'Enter both Period 2 dates, or neither.';
        if (($c['water_start_date_2'] === '') !== ($c['water_end_date_2'] === '')) return 'Enter both water Period 2 dates, or neither.';
        return '';
    }

    // Distinct tariff names used by tenants (for the correction dropdowns)
    function lumAdjTariffOptions($tenant_pdo) {
        $out = ['elec' => [], 'water' => [], 'sewer' => []];
        $cols = ['elec' => 'tenant_electrical_tariff_charge', 'water' => 'tenant_water_tariff_charge', 'sewer' => 'tenant_water_sewer_tariff_charge'];
        foreach ($cols as $grp => $col) {
            try {
                $out[$grp] = $tenant_pdo->query("SELECT DISTINCT `$col` FROM lum_tenants WHERE `$col` IS NOT NULL AND `$col` <> '' ORDER BY `$col`")->fetchAll(PDO::FETCH_COLUMN);
            } catch (\Throwable $e) {}
        }
        return $out;
    }

    // Re-run the slip engine for a journal line with corrections.
    // $line: lumAdjJournalLine() row, $tenant_row: current lum_tenants row, $c: lumAdjCleanCorrections() result.
    // Returns ['totals', 'billing', 'period', 'tariffs', 'obis', 'seasons'] - the corrected bill.
    function lumAdjRecalculate(array $line, array $tenant_row, array $c) {
        global $tenant_db_conn, $obis_db_conn, $manual_db_conn, $tariff_db_conn;
        require_once lum_resolve_path('/slip-engine.php');

        // 1. The report exactly as it was journaled ...
        $req = json_decode((string)($line['j_report_settings'] ?? ''), true);
        if (!is_array($req)) $req = [];
        unset($req['show_tenant_bar'], $req['show_elec_pie'], $req['show_water_pie'], $req['show_daily_fin'], $req['show_graph'], $req['show_water_graph']);
        // ... with this tenant's own settings at the time
        $own = json_decode((string)($line['tenant_settings'] ?? ''), true);
        foreach ((is_array($own) ? $own : []) as $k => $v) {
            if (!is_scalar($v)) continue;
            if ((string)$v === '') unset($req[$k]); else $req[$k] = (string)$v;
        }
        // ... and the billing OBIS / TOU algorithm the tenant was billed on
        if (in_array($line['elec_obis'] ?? '', ['1.1.1.8.0', '1.1.1.8.1'], true)) $req['tenant_billing_obis'] = $line['elec_obis'];
        if (!empty($line['is_tou']) && !empty($line['tou_algorithm'])) $req['tou_algorithm'] = $line['tou_algorithm'];

        // 2. The corrections
        foreach (LUM_ADJ_DATE_KEYS as $k) {
            if ($c[$k] === '') unset($req[$k]); else $req[$k] = $c[$k];
        }
        if ($c['billing_obis'] !== '') $req['tenant_billing_obis'] = $c['billing_obis'];
        if ($c['t2_method'] !== '') $req['t2_method'] = $c['t2_method'];
        if ($c['estimated'] === 'on') $req['use_estimated_readings'] = '1';
        if ($c['estimated'] === 'off') unset($req['use_estimated_readings']);

        $tenant = $tenant_row;
        if ($c['elec_tariff'] !== '') $tenant['tenant_electrical_tariff_charge'] = $c['elec_tariff'];
        if ($c['water_tariff'] !== '') $tenant['tenant_water_tariff_charge'] = $c['water_tariff'];
        if ($c['sewer_tariff'] !== '') $tenant['tenant_water_sewer_tariff_charge'] = $c['sewer_tariff'];

        // 3. Bill the tenant (in this function's own scope, so nothing leaks into the page)
        $saved_request = $_REQUEST;
        $_REQUEST = $req;
        try {
            $property_name = (string)$tenant['tenant_property'];
            $lum_default_start = $c['start_date'];
            $lum_default_end = $c['end_date'];
            require LUM_SLIP_DIR . '/slip-context.php';
            require LUM_SLIP_DIR . '/slip-tenant.php';

            $show_graph = false;
            $show_water_graph = false;
            $slip_render_mode = 'data';
            $slip_chart_key = $tenant['tenant_id'];
            $slip_units_override = ['kwh' => $c['kwh'], 'kl' => $c['kl']];
            ob_start();
            try {
                include LUM_SLIP_DIR . '/slip-render.php';
            } finally {
                ob_end_clean();
            }

            return [
                'totals'  => $slip_totals,
                'billing' => $slip_billing ?? [],
                'period'  => [
                    'start_date' => $start_date, 'end_date' => $end_date,
                    'start_date_2' => $has_period_2 ? $start_date_2 : '', 'end_date_2' => $has_period_2 ? $end_date_2 : '',
                    'water_start_date' => $water_start_date, 'water_end_date' => $water_end_date,
                    'water_start_date_2' => $has_water_period_2 ? $water_start_date_2 : '', 'water_end_date_2' => $has_water_period_2 ? $water_end_date_2 : '',
                ],
                'tariffs' => ['elec' => $elec_tariff, 'water' => $water_tariff, 'sewer' => $sewer_tariff],
                'obis'    => $tenant_obis_code,
                'seasons' => [$season_1, $has_period_2 ? $season_2 : null],
            ];
        } finally {
            $_REQUEST = $saved_request;
        }
    }

    // =====================================================================
    // STEP 3 - ADJUSTMENTS ON SLIPS AND REPORTS
    // =====================================================================
    // Approved and posted adjustments billed to a tenant in a billing month.
    // All adjustments of the property and month are read once per request.
    function lumAdjApprovedForTenant($pdo, $property, $tenant_id, $month, $year) {
        static $cache = [];
        if (!$pdo || (string)$property === '') return [];
        $key = $property . '|' . (int)$month . '|' . (int)$year;
        if (!isset($cache[$key])) {
            $cache[$key] = [];
            try {
                $st = $pdo->prepare("SELECT * FROM lum_adjustments WHERE property = ? AND posting_month = ? AND posting_year = ?
                                     AND status IN ('Approved', 'Posted') ORDER BY adjustment_id");
                $st->execute([$property, (int)$month, (int)$year]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $cache[$key][(int)$r['tenant_id']][] = $r;
                }
            } catch (\Throwable $e) {
                error_log('LUM back billing: adjustments not available: ' . $e->getMessage());
            }
        }
        return $cache[$key][(int)$tenant_id] ?? [];
    }

    // The adjustments included in a journal become Posted (journaling the month again moves them to the new journal)
    function lumAdjMarkPosted($pdo, array $ids, $journal_id) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$pdo || empty($ids)) return 0;
        try {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("UPDATE lum_adjustments SET status = 'Posted', posted_journal_id = ?, posted_at = NOW()
                                 WHERE adjustment_id IN ($in) AND status IN ('Approved', 'Posted')");
            $st->execute(array_merge([(int)$journal_id], $ids));
            return $st->rowCount();
        } catch (\Throwable $e) {
            error_log('LUM back billing: could not mark adjustments posted: ' . $e->getMessage());
            return 0;
        }
    }

    // A voided journal releases its adjustments again (Posted -> Approved). Returns the adjustment ids.
    function lumAdjUnpostJournal($pdo, $journal_id) {
        if (!$pdo) return [];
        try {
            $st = $pdo->prepare("SELECT adjustment_id FROM lum_adjustments WHERE posted_journal_id = ? AND status = 'Posted'");
            $st->execute([(int)$journal_id]);
            $ids = $st->fetchAll(PDO::FETCH_COLUMN);
            if ($ids) {
                $pdo->prepare("UPDATE lum_adjustments SET status = 'Approved', posted_journal_id = NULL, posted_at = NULL
                               WHERE posted_journal_id = ? AND status = 'Posted'")->execute([(int)$journal_id]);
            }
            return array_map('intval', $ids);
        } catch (\Throwable $e) {
            error_log('LUM back billing: could not release adjustments: ' . $e->getMessage());
            return [];
        }
    }

    // Adjustments that correct a journal (for the journal viewer)
    function lumAdjForJournal($pdo, $journal_id) {
        if (!$pdo) return [];
        try {
            $st = $pdo->prepare("SELECT * FROM lum_adjustments WHERE original_journal_id = ? AND status <> 'Voided' ORDER BY adjustment_id");
            $st->execute([(int)$journal_id]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }
}