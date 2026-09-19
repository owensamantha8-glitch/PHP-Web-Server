<?php
// Library file: only other pages may include it, it can never be opened directly
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) { http_response_code(403); exit('Forbidden'); }
// =========================================================================
// LYNX UTILITY MANAGEMENT - FINANCIAL JOURNAL ENGINE
// Location: /var/www/Lynx/Reporting/financial-journal-engine.php
// -------------------------------------------------------------------------
// Stores a generated financial report (every tenant's charges and how each
// tenant was billed) in sys_db_financial_journal, so past reports can be
// loaded exactly as they were journaled.
// =========================================================================

if (!defined('LUM_JOURNAL_ENGINE')) {
    define('LUM_JOURNAL_ENGINE', true);

    // Money columns (R) and quantity columns stored for every tenant line
    // (define() is used because 'const' is not allowed inside this if-block)
    if (!defined('LUM_JOURNAL_AMOUNTS')) define('LUM_JOURNAL_AMOUNTS', [
        'basic', 'energy', 'generator', 'demand', 'network', 'capacity', 'elec_comm', 'shared_nac', 'elec_total',
        'water_basic', 'water_usage', 'sewer_basic', 'sewer_usage', 'sewer_comm', 'water_comm', 'sewer_additional', 'water_total',
        'refuse', 'total',
    ]);
    if (!defined('LUM_JOURNAL_QUANTITIES')) define('LUM_JOURNAL_QUANTITIES', ['kwh', 'comm_kwh', 'water_kl', 'comm_kl']);

    // Column headings used by the journal viewer and the CSV export
    if (!defined('LUM_JOURNAL_LABELS')) define('LUM_JOURNAL_LABELS', [
        'kwh' => 'kWh', 'comm_kwh' => 'Comm Area kWh', 'basic' => 'Basic Charge (R)', 'energy' => 'Energy Charge (R)',
        'generator' => 'Generator (R)', 'demand' => 'Demand (R)', 'network' => 'Network Access (R)', 'capacity' => 'Capacity (R)',
        'elec_comm' => 'Elec Comm Area (R)', 'shared_nac' => 'Shared NAC (R)', 'elec_total' => 'Total Elec (R)',
        'water_kl' => 'Water kL', 'comm_kl' => 'Comm Area kL', 'water_basic' => 'Water Basic (R)', 'water_usage' => 'Water Usage (R)',
        'sewer_basic' => 'Sewer Basic (R)', 'sewer_usage' => 'Sewer Usage (R)', 'sewer_comm' => 'Sewer Comm Area (R)',
        'water_comm' => 'Water Comm Area (R)', 'sewer_additional' => 'Sewer Additional (R)', 'water_total' => 'Total Water (R)',
        'refuse' => 'Refuse (R)', 'total' => 'Grand Total (R)',
    ]);

    // Connection to sys_db_financial_journal (shared through lum_db() in /var/www/Lynx/bootstrap.php)
    function lumJournalDb() {
        if (function_exists('lum_db')) {
            $jpdo = lum_db('journal');
            if (!$jpdo) {
                static $jlogged = false;
                if (!$jlogged) error_log('LUM journal: connection failed (has financial-journal-setup.sql been run?)');
                $jlogged = true;
            }
            return $jpdo;
        }
        static $pdo = false;
        if ($pdo !== false) return $pdo;
        $pdo = null;
        $cfg = @parse_ini_file('/var/secure_configs/lynx_db.ini');
        if ($cfg === false) {
            error_log('LUM journal: unable to read /var/secure_configs/lynx_db.ini');
            return null;
        }
        try {
            $pdo = new PDO("mysql:host={$cfg['host']};dbname=sys_db_financial_journal;charset=utf8mb4", $cfg['username'], $cfg['password']);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (\Throwable $e) {
            error_log('LUM journal: connection failed (has financial-journal-setup.sql been run?): ' . $e->getMessage());
            $pdo = null;
        }
        return $pdo;
    }

    // The active journal for a property and billing month (or null)
    function lumJournalActive($pdo, $property, $month, $year) {
        if (!$pdo) return null;
        try {
            $st = $pdo->prepare("SELECT * FROM lum_journal WHERE property = ? AND billing_month = ? AND billing_year = ? AND status = 'Active' ORDER BY journal_id DESC LIMIT 1");
            $st->execute([$property, (int)$month, (int)$year]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) {
            error_log('LUM journal lookup failed: ' . $e->getMessage());
            return null;
        }
    }

    // Are the per-tenant billing cycle columns there? (added by financial-journal-upgrade-1.sql)
    function lumJournalHasPeriodColumns($pdo) {
        static $has = null;
        if ($has !== null) return $has;
        try {
            $has = (bool)$pdo->query("SHOW COLUMNS FROM lum_journal_lines LIKE 'own_billing_cycle'")->fetch();
        } catch (\Throwable $e) {
            $has = false;
        }
        if (!$has) error_log('LUM journal: tenant billing cycle columns missing - run financial-journal-upgrade-1.sql');
        return $has;
    }

    // Are the manual readings column fields there? (added by financial-journal-upgrade-2.sql)
    function lumJournalHasManualColumns($pdo) {
        static $has = null;
        if ($has !== null) return $has;
        try {
            $has = (bool)$pdo->query("SHOW COLUMNS FROM lum_journal_lines LIKE 'manual_col_elec'")->fetch();
        } catch (\Throwable $e) {
            $has = false;
        }
        if (!$has) error_log('LUM journal: manual readings column fields missing - run financial-journal-upgrade-2.sql');
        return $has;
    }

    function lumJournalNullDate($d) {
        $d = trim((string)$d);
        return (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) ? $d : null;
    }

    // Create a journal. Any active journal for the same property and month becomes "Superseded".
    // $header: property, billing_month, billing_year, billing_label, period/water dates, totals, report_settings, property_summary, notes
    // $lines:  one array per tenant (see financial-calculations.php)
    // Returns the new journal_id, or throws on failure (nothing is stored then).
    function lumJournalCreate($pdo, array $header, array $lines) {
        if (!$pdo) throw new RuntimeException('The journal database is not available.');

        $pdo->beginTransaction();
        try {
            $user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
            $user_name = substr((string)($_SESSION['user_name'] ?? 'system'), 0, 50);

            $previous = lumJournalActive($pdo, $header['property'], $header['billing_month'], $header['billing_year']);

            $st = $pdo->prepare("INSERT INTO lum_journal
                (property, billing_month, billing_year, billing_label, period_start, period_end, period_2_start, period_2_end,
                 water_start, water_end, water_2_start, water_2_end, tenant_count,
                 total_kwh, total_comm_kwh, total_kl, total_comm_kl, total_elec, total_water, total_refuse, grand_total,
                 report_settings, property_summary, notes, status, created_by_user_id, created_by_name)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?)");
            $st->execute([
                $header['property'], (int)$header['billing_month'], (int)$header['billing_year'],
                ($header['billing_label'] ?? '') !== '' ? substr($header['billing_label'], 0, 100) : null,
                $header['period_start'], $header['period_end'],
                lumJournalNullDate($header['period_2_start'] ?? ''), lumJournalNullDate($header['period_2_end'] ?? ''),
                lumJournalNullDate($header['water_start'] ?? ''), lumJournalNullDate($header['water_end'] ?? ''),
                lumJournalNullDate($header['water_2_start'] ?? ''), lumJournalNullDate($header['water_2_end'] ?? ''),
                count($lines),
                round((float)$header['total_kwh'], 3), round((float)$header['total_comm_kwh'], 3),
                round((float)$header['total_kl'], 3), round((float)$header['total_comm_kl'], 3),
                round((float)$header['total_elec'], 2), round((float)$header['total_water'], 2),
                round((float)$header['total_refuse'], 2), round((float)$header['grand_total'], 2),
                json_encode($header['report_settings'] ?? [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                json_encode($header['property_summary'] ?? [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                ($header['notes'] ?? '') !== '' ? substr($header['notes'], 0, 255) : null,
                $user_id, $user_name,
            ]);
            $journal_id = (int)$pdo->lastInsertId();

            $cols = array_merge(
                ['journal_id', 'tenant_id', 'tenant_name', 'tenant_code', 'tenant_shop', 'municipality', 'elec_tariff', 'water_tariff',
                 'sewer_tariff', 'season_1', 'season_2', 'elec_obis', 'is_tou', 'tou_algorithm', 'gen_method', 'gen_runtime_hours',
                 'elec_meters', 'water_meters', 'estimated_readings', 'occupancy_status'],
                LUM_JOURNAL_QUANTITIES, LUM_JOURNAL_AMOUNTS, ['tenant_settings', 'billing_detail']
            );
            $with_periods = lumJournalHasPeriodColumns($pdo);
            if ($with_periods) {
                $cols = array_merge($cols, ['period_start', 'period_end', 'period_2_start', 'period_2_end',
                                            'water_start', 'water_end', 'water_2_start', 'water_2_end', 'own_billing_cycle']);
            }
            $with_manual_cols = lumJournalHasManualColumns($pdo);
            if ($with_manual_cols) {
                $cols = array_merge($cols, ['manual_col_elec', 'manual_col_gen']);
            }
            $line_st = $pdo->prepare("INSERT INTO lum_journal_lines (" . implode(', ', $cols) . ") VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")");
            $cut = function ($v, $len) { return ($v === null || $v === '') ? null : substr((string)$v, 0, $len); };

            foreach ($lines as $l) {
                $values = [
                    $journal_id, (int)$l['tenant_id'], $cut($l['tenant_name'] ?? null, 50), $cut($l['tenant_code'] ?? null, 50),
                    $cut($l['tenant_shop'] ?? null, 10), $cut($l['municipality'] ?? null, 50), $cut($l['elec_tariff'] ?? null, 100),
                    $cut($l['water_tariff'] ?? null, 100), $cut($l['sewer_tariff'] ?? null, 100), $cut($l['season_1'] ?? null, 10),
                    $cut($l['season_2'] ?? null, 10), $cut($l['elec_obis'] ?? null, 20), !empty($l['is_tou']) ? 1 : 0,
                    $cut($l['tou_algorithm'] ?? null, 20), $cut($l['gen_method'] ?? null, 150),
                    isset($l['gen_runtime_hours']) ? round((float)$l['gen_runtime_hours'], 2) : null,
                    $cut($l['elec_meters'] ?? null, 255), $cut($l['water_meters'] ?? null, 255),
                    !empty($l['estimated_readings']) ? 1 : 0, $cut($l['occupancy_status'] ?? null, 10),
                ];
                foreach (LUM_JOURNAL_QUANTITIES as $k) $values[] = round((float)($l['totals'][$k] ?? 0), 3);
                foreach (LUM_JOURNAL_AMOUNTS as $k) $values[] = round((float)($l['totals'][$k] ?? 0), 2);
                $values[] = !empty($l['tenant_settings']) ? json_encode($l['tenant_settings'], JSON_UNESCAPED_UNICODE) : null;
                $values[] = json_encode($l['billing_detail'] ?? [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                if ($with_periods) {
                    // The billing cycle this tenant was billed on (its own, or the property's)
                    $p = $l['period'] ?? [];
                    $values[] = lumJournalNullDate($p['start'] ?? $header['period_start']);
                    $values[] = lumJournalNullDate($p['end'] ?? $header['period_end']);
                    $values[] = lumJournalNullDate($p['start_2'] ?? ($header['period_2_start'] ?? ''));
                    $values[] = lumJournalNullDate($p['end_2'] ?? ($header['period_2_end'] ?? ''));
                    $values[] = lumJournalNullDate($p['water_start'] ?? ($header['water_start'] ?? ''));
                    $values[] = lumJournalNullDate($p['water_end'] ?? ($header['water_end'] ?? ''));
                    $values[] = lumJournalNullDate($p['water_start_2'] ?? ($header['water_2_start'] ?? ''));
                    $values[] = lumJournalNullDate($p['water_end_2'] ?? ($header['water_2_end'] ?? ''));
                    $values[] = !empty($p['own']) ? 1 : 0;
                }
                if ($with_manual_cols) {
                    // Manual readings columns used to bill the tenant (e.g. A14)
                    $values[] = $cut($l['manual_col_elec'] ?? null, 100);
                    $values[] = $cut($l['manual_col_gen'] ?? null, 100);
                }
                $line_st->execute($values);
            }

            if ($previous) {
                $up = $pdo->prepare("UPDATE lum_journal SET status = 'Superseded', superseded_by = ?, status_changed_by = ?, status_changed_at = NOW() WHERE journal_id = ?");
                $up->execute([$journal_id, $user_name, (int)$previous['journal_id']]);
            }

            $pdo->commit();
            return $journal_id;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    // Journal list (newest first). $filters: property, year, status ('' = all), allowed_properties (null = all)
    function lumJournalList($pdo, array $filters, $limit = 200) {
        if (!$pdo) return [];
        $sql = "SELECT * FROM lum_journal WHERE 1=1";
        $params = [];
        if (!empty($filters['property'])) { $sql .= " AND property = ?"; $params[] = $filters['property']; }
        if (!empty($filters['year'])) { $sql .= " AND billing_year = ?"; $params[] = (int)$filters['year']; }
        if (!empty($filters['status'])) { $sql .= " AND status = ?"; $params[] = $filters['status']; }
        if (isset($filters['allowed_properties']) && is_array($filters['allowed_properties'])) {
            if (empty($filters['allowed_properties'])) return [];
            $sql .= " AND property IN (" . implode(',', array_fill(0, count($filters['allowed_properties']), '?')) . ")";
            $params = array_merge($params, array_values($filters['allowed_properties']));
        }
        $sql .= " ORDER BY billing_year DESC, billing_month DESC, property ASC, journal_id DESC LIMIT " . (int)$limit;
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('LUM journal list failed: ' . $e->getMessage());
            return [];
        }
    }

    // One journal with its lines: ['journal' => row, 'lines' => rows] or null
    function lumJournalGet($pdo, $journal_id) {
        if (!$pdo) return null;
        try {
            $st = $pdo->prepare("SELECT * FROM lum_journal WHERE journal_id = ?");
            $st->execute([(int)$journal_id]);
            $journal = $st->fetch(PDO::FETCH_ASSOC);
            if (!$journal) return null;
            $st = $pdo->prepare("SELECT * FROM lum_journal_lines WHERE journal_id = ? ORDER BY tenant_shop ASC, tenant_name ASC");
            $st->execute([(int)$journal_id]);
            return ['journal' => $journal, 'lines' => $st->fetchAll(PDO::FETCH_ASSOC)];
        } catch (\Throwable $e) {
            error_log('LUM journal read failed: ' . $e->getMessage());
            return null;
        }
    }

    // Change a journal's note. Returns the previous note, or false on failure.
    function lumJournalUpdateNotes($pdo, $journal_id, $notes) {
        if (!$pdo) return false;
        try {
            $st = $pdo->prepare("SELECT notes FROM lum_journal WHERE journal_id = ?");
            $st->execute([(int)$journal_id]);
            $old = $st->fetchColumn();
            if ($old === false) return false;
            $notes = substr(trim((string)$notes), 0, 255);
            $st = $pdo->prepare("UPDATE lum_journal SET notes = ? WHERE journal_id = ?");
            $st->execute([$notes !== '' ? $notes : null, (int)$journal_id]);
            return (string)$old;
        } catch (\Throwable $e) {
            error_log('LUM journal note update failed: ' . $e->getMessage());
            return false;
        }
    }

    // Permanently delete a VOIDED journal and its lines
    function lumJournalDelete($pdo, $journal_id) {
        if (!$pdo) return false;
        try {
            $st = $pdo->prepare("DELETE FROM lum_journal WHERE journal_id = ? AND status = 'Voided'");
            $st->execute([(int)$journal_id]);
            return $st->rowCount() > 0; // Lines are removed by the foreign key (ON DELETE CASCADE)
        } catch (\Throwable $e) {
            error_log('LUM journal delete failed: ' . $e->getMessage());
            return false;
        }
    }

    // Void a journal (kept for the record, no longer active)
    function lumJournalVoid($pdo, $journal_id, $reason = '') {
        if (!$pdo) return false;
        try {
            $st = $pdo->prepare("UPDATE lum_journal SET status = 'Voided', status_changed_by = ?, status_changed_at = NOW(),
                                 notes = LEFT(CONCAT(COALESCE(notes, ''), CASE WHEN ? = '' THEN '' ELSE CONCAT(' [Voided: ', ?, ']') END), 255)
                                 WHERE journal_id = ? AND status <> 'Voided'");
            $reason = substr(trim((string)$reason), 0, 100);
            $st->execute([substr((string)($_SESSION['user_name'] ?? 'system'), 0, 50), $reason, $reason, (int)$journal_id]);
            return $st->rowCount() > 0;
        } catch (\Throwable $e) {
            error_log('LUM journal void failed: ' . $e->getMessage());
            return false;
        }
    }
}