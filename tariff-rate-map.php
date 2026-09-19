<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once '/var/www/Lynx/bootstrap.php';
lum_page('tariffs', 'edit'); // Tariff setup screen: only for users who may change tariffs
@set_time_limit(300);

// =========================================================================
// LYNX UTILITY MANAGEMENT - TARIFF RATE MAP
// Location: /var/www/Lynx/Tarrifs/tariff-rate-map.php (next to view-tariffs.php)
// Which municipal tariff column each rate is read from. Filled and checked
// against the billing engine's old tariff-name rules.
// =========================================================================

lum_connect('tariffs', 'tenants');
lum_use('slips', 'audit'); // The slip engine also loads reporting-engine.php

$can_edit = lum_can('tariffs', 'edit');
$can_delete = lum_can('tariffs', 'delete');
$user_name = substr((string)($_SESSION['user_name'] ?? 'system'), 0, 50);
$types = ['municipality' => 'Municipality (generator, water, sewer, common areas)', 'electricity' => 'Electricity tariffs', 'water' => 'Water tariffs', 'sewer' => 'Sewer tariffs'];
$type_keys = [
    'municipality' => array_merge(LUM_RATE_KEYS_MUNICIPALITY, LUM_RATE_KEYS_WATER, LUM_RATE_KEYS_SEWER, ['is_tiered_water']),
    'electricity'  => LUM_RATE_KEYS_ELEC,
    'water'        => array_merge(LUM_RATE_KEYS_WATER, ['is_tiered_water']),
    'sewer'        => LUM_RATE_KEYS_SEWER,
];
$ledger_cols = lumRateLedgerColumns();

$message = $_SESSION['rm_message'] ?? null;
unset($_SESSION['rm_message']);
$flash = function ($type, $text) { $_SESSION['rm_message'] = ['type' => $type, 'text' => $text]; };
$audit = function ($action, $label, $before, $after, $note = null) {
    if (function_exists('lum_audit_log')) lum_audit_log($action, 'tariff_rate_map', 0, $label, null, $before, $after, $note);
};

$table_ok = false;
try { $tariff_db_conn->query("SELECT 1 FROM lum_tariff_rate_map LIMIT 1"); $table_ok = true; } catch (\Throwable $e) {}

// =========================================================================
// TARIFF COMBINATIONS IN USE (worked out per tenant exactly as the slips do)
// =========================================================================
$combos = [];
try {
    $props = [];
    $prop_db = lumSysDb('sys_db_properties');
    if ($prop_db) {
        foreach ($prop_db->query("SELECT Property, municipality, electrical_billing_type FROM lum_properties") as $p) $props[$p['Property']] = $p;
    }
    $rows = $tenant_db_conn->query("SELECT tenant_property, tenant_electrical_tariff_charge, tenant_water_tariff_charge, tenant_water_sewer_tariff_charge FROM lum_tenants")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $t) {
        $prop = (string)$t['tenant_property'];
        $pr = $props[$prop] ?? ['municipality' => '', 'electrical_billing_type' => ''];
        $water = (string)($t['tenant_water_tariff_charge'] ?? '');
        $sewer = (string)($t['tenant_water_sewer_tariff_charge'] ?? '');
        list($elec, $calc) = lumSlipResolveElecTariff((string)($t['tenant_electrical_tariff_charge'] ?? ''), $prop, (string)$pr['electrical_billing_type']);
        $muni = lumSlipResolveMunicipality((string)$pr['municipality'], $prop, $elec, $water, $sewer);
        $key = $calc . '|' . $muni . '|' . $water . '|' . $sewer;
        if (!isset($combos[$key])) $combos[$key] = ['elec' => $calc, 'muni' => $muni, 'water' => $water, 'sewer' => $sewer, 'tenants' => 0, 'properties' => []];
        $combos[$key]['tenants']++;
        $combos[$key]['properties'][$prop] = true;
    }
} catch (\Throwable $e) {
    error_log('LUM rate map: tenant combinations not read: ' . $e->getMessage());
}
$combos = array_values($combos);

// Existing map entries: "type|lower name|municipality" => rows
$entries = [];
if ($table_ok) {
    $st = $tariff_db_conn->query("SELECT * FROM lum_tariff_rate_map ORDER BY FIELD(map_type, 'municipality', 'electricity', 'water', 'sewer'), municipality, tariff_name, rate_key");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $k = $r['map_type'] . '|' . lumLower(trim($r['tariff_name'])) . '|' . $r['municipality'];
        if (!isset($entries[$k])) $entries[$k] = ['type' => $r['map_type'], 'name' => $r['tariff_name'], 'municipality' => $r['municipality'], 'rows' => []];
        $entries[$k]['rows'][] = $r;
    }
}

// Insert the rows of built entries (a "-" row marks an entry without rates)
$insert_entry = function (array $e) use ($tariff_db_conn, $user_name) {
    $ins = $tariff_db_conn->prepare("INSERT IGNORE INTO lum_tariff_rate_map (map_type, tariff_name, municipality, rate_key, ledger, column_name, fallback_column, positive_only, created_by_name)
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (empty($e['rows'])) {
        $ins->execute([$e['type'], $e['name'], $e['municipality'], '-', '', '', '', 0, $user_name]);
        return 0;
    }
    foreach ($e['rows'] as $rk => $m) {
        $ins->execute([$e['type'], $e['name'], $e['municipality'], $rk, $m['ledger'], $m['column_name'], $m['fallback_column'], (int)$m['positive_only'], $user_name]);
    }
    return count($e['rows']);
};
$entry_combo = function ($type, $name, $muni) {
    if ($type === 'electricity') return ['elec' => $name, 'muni' => $muni, 'water' => '', 'sewer' => ''];
    if ($type === 'water') return ['elec' => '', 'muni' => $muni, 'water' => $name, 'sewer' => ''];
    if ($type === 'sewer') return ['elec' => '', 'muni' => $muni, 'water' => '', 'sewer' => $name];
    return null;
};
$entry_snapshot = function ($rows) {
    $out = [];
    foreach ($rows as $r) {
        $out[$r['rate_key']] = ($r['rate_key'] === '-') ? 'no rates' : trim($r['ledger'] . '.' . $r['column_name'] . ($r['positive_only'] ? ' (if 0 or less: ' . ($r['fallback_column'] ?: '0') . ')' : ''), '.');
    }
    return $out;
};

// =========================================================================
// ACTIONS
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $table_ok) {
    lum_require_csrf();
    $action = (string)($_POST['action'] ?? '');
    $e_type = (string)($_POST['map_type'] ?? '');
    $e_name = trim((string)($_POST['tariff_name'] ?? ''));
    $e_muni = (string)($_POST['municipality'] ?? '');
    $e_key = $e_type . '|' . lumLower($e_name) . '|' . $e_muni;
    $back = 'tariff-rate-map.php' . ($e_type !== '' && $action !== 'fill' ? '?type=' . rawurlencode($e_type) . '#e' . md5($e_key) : '');
    try {
        if ($action === 'fill') {
            lum_require_access('tariffs', 'edit');
            $errors = [];
            $built = lumRateMapBuild($combos, $errors);
            $added_entries = 0;
            $added_rows = 0;
            $tariff_db_conn->beginTransaction();
            foreach ($built as $k => $e) {
                if (isset($entries[$e['type'] . '|' . lumLower($e['name']) . '|' . $e['municipality']])) continue; // Never change existing entries
                $added_rows += $insert_entry($e);
                $added_entries++;
            }
            $tariff_db_conn->commit();
            if ($added_entries) $audit('INSERT', 'Rate map filled from the current rules', null, ['entries' => $added_entries, 'rates' => $added_rows]);
            $text = $added_entries ? "Added $added_entries map entries ($added_rows rates) from the current rules." : 'Every tariff combination in use is already in the map.';
            if ($errors) $text .= ' Not mapped (kept on the old rules): ' . implode('; ', array_slice(array_unique($errors), 0, 5)) . (count($errors) > 5 ? ' ...' : '');
            $flash($errors ? 'warning' : 'success', $text);

        } elseif ($action === 'reset_entry') {
            lum_require_access('tariffs', 'edit');
            if (!isset($types[$e_type])) throw new RuntimeException('type');
            $errors = [];
            if ($e_type === 'municipality') {
                $built = lumRateMapBuild([], $errors);
            } else {
                $built = lumRateMapBuild([$entry_combo($e_type, $e_name, $e_muni)], $errors);
            }
            $new = null;
            foreach ($built as $e) {
                if ($e['type'] === $e_type && lumLower($e['name']) === lumLower($e_name) && $e['municipality'] === $e_muni) $new = $e;
            }
            if (!$new || $errors) {
                $flash('warning', 'This entry could not be worked out from the current rules' . ($errors ? ': ' . implode('; ', array_slice($errors, 0, 3)) : '.'));
            } else {
                $before = $entry_snapshot($entries[$e_key]['rows'] ?? []);
                $tariff_db_conn->beginTransaction();
                $tariff_db_conn->prepare("DELETE FROM lum_tariff_rate_map WHERE map_type = ? AND tariff_name = ? AND municipality = ?")->execute([$e_type, $e_name, $e_muni]);
                $insert_entry($new);
                $tariff_db_conn->commit();
                $after = [];
                foreach ($new['rows'] as $rk => $m) $after[$rk] = trim($m['ledger'] . '.' . $m['column_name'], '.');
                $audit('UPDATE', "Rate map: $e_type $e_name @ $e_muni", $before, $after ?: ['-' => 'no rates'], 'Reset from the current rules');
                $flash('success', 'The entry was set up again from the current rules.');
            }

        } elseif ($action === 'delete_entry') {
            lum_require_access('tariffs', 'delete');
            $before = $entry_snapshot($entries[$e_key]['rows'] ?? []);
            $tariff_db_conn->prepare("DELETE FROM lum_tariff_rate_map WHERE map_type = ? AND tariff_name = ? AND municipality = ?")->execute([$e_type, $e_name, $e_muni]);
            $audit('DELETE', "Rate map: $e_type $e_name @ $e_muni", $before, null);
            $flash('success', 'The entry was removed. Tenants with this tariff use the old tariff-name rules again.');
            $back = 'tariff-rate-map.php?type=' . rawurlencode($e_type);

        } elseif ($action === 'save_row') {
            lum_require_access('tariffs', 'edit');
            $id = (int)($_POST['id'] ?? 0);
            $rate_key = (string)($_POST['rate_key'] ?? '');
            $ledger = (string)($_POST['ledger'] ?? '');
            $column = trim((string)($_POST['column_name'] ?? ''));
            $fallback = trim((string)($_POST['fallback_column'] ?? ''));
            $positive = !empty($_POST['positive_only']) ? 1 : 0;
            if ($rate_key === 'is_tiered_water') { $ledger = ''; $column = ''; $fallback = ''; $positive = 0; }
            $error = '';
            if (!isset($type_keys[$e_type]) || !in_array($rate_key, $type_keys[$e_type], true)) $error = 'Please choose a rate that belongs to this kind of entry.';
            elseif ($e_type !== 'municipality' && $e_name === '') $error = 'The tariff name is missing.';
            elseif ($rate_key !== 'is_tiered_water' && (!isset(LUM_RATE_LEDGERS[$ledger]) || !in_array($column, $ledger_cols[$ledger] ?? [], true))) $error = 'Please choose a ledger and one of its columns.';
            elseif ($fallback !== '' && !in_array($fallback, $ledger_cols[$ledger] ?? [], true)) $error = 'The fallback column is not a column of that ledger.';
            if ($error !== '') {
                $flash('warning', $error);
            } else {
                $before = $entry_snapshot($entries[$e_key]['rows'] ?? []);
                $tariff_db_conn->beginTransaction();
                if ($id) {
                    $tariff_db_conn->prepare("UPDATE lum_tariff_rate_map SET rate_key = ?, ledger = ?, column_name = ?, fallback_column = ?, positive_only = ?, updated_by_name = ?
                                              WHERE id = ? AND map_type = ? AND tariff_name = ? AND municipality = ?")
                                   ->execute([$rate_key, $ledger, $column, $fallback, $positive, $user_name, $id, $e_type, $e_name, $e_muni]);
                } else {
                    $tariff_db_conn->prepare("INSERT INTO lum_tariff_rate_map (map_type, tariff_name, municipality, rate_key, ledger, column_name, fallback_column, positive_only, created_by_name)
                                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                                   ->execute([$e_type, $e_name, $e_muni, $rate_key, $ledger, $column, $fallback, $positive, $user_name]);
                }
                // An entry with rates no longer needs its "no rates" marker
                $tariff_db_conn->prepare("DELETE FROM lum_tariff_rate_map WHERE map_type = ? AND tariff_name = ? AND municipality = ? AND rate_key = '-'")->execute([$e_type, $e_name, $e_muni]);
                $tariff_db_conn->commit();
                $st = $tariff_db_conn->prepare("SELECT * FROM lum_tariff_rate_map WHERE map_type = ? AND tariff_name = ? AND municipality = ?");
                $st->execute([$e_type, $e_name, $e_muni]);
                $audit($id ? 'UPDATE' : 'INSERT', "Rate map: $e_type $e_name @ $e_muni", $before, $entry_snapshot($st->fetchAll(PDO::FETCH_ASSOC)));
                $flash('success', 'Saved.');
            }

        } elseif ($action === 'delete_row') {
            lum_require_access('tariffs', 'delete');
            $id = (int)($_POST['id'] ?? 0);
            $before = $entry_snapshot($entries[$e_key]['rows'] ?? []);
            $tariff_db_conn->beginTransaction();
            $tariff_db_conn->prepare("DELETE FROM lum_tariff_rate_map WHERE id = ? AND map_type = ? AND tariff_name = ? AND municipality = ?")->execute([$id, $e_type, $e_name, $e_muni]);
            // Keep the entry (with no rates) so it does not fall back to the old rules
            $st = $tariff_db_conn->prepare("SELECT COUNT(*) FROM lum_tariff_rate_map WHERE map_type = ? AND tariff_name = ? AND municipality = ?");
            $st->execute([$e_type, $e_name, $e_muni]);
            if ((int)$st->fetchColumn() === 0) {
                $tariff_db_conn->prepare("INSERT INTO lum_tariff_rate_map (map_type, tariff_name, municipality, rate_key, created_by_name) VALUES (?, ?, ?, '-', ?)")
                               ->execute([$e_type, $e_name, $e_muni, $user_name]);
            }
            $tariff_db_conn->commit();
            $st = $tariff_db_conn->prepare("SELECT * FROM lum_tariff_rate_map WHERE map_type = ? AND tariff_name = ? AND municipality = ?");
            $st->execute([$e_type, $e_name, $e_muni]);
            $audit('UPDATE', "Rate map: $e_type $e_name @ $e_muni", $before, $entry_snapshot($st->fetchAll(PDO::FETCH_ASSOC)), 'Rate removed');
            $flash('success', 'The rate was removed from the entry.');
        }
    } catch (\Throwable $e) {
        if ($tariff_db_conn->inTransaction()) $tariff_db_conn->rollBack();
        error_log('LUM rate map save failed: ' . $e->getMessage());
        $flash('danger', (strpos($e->getMessage(), 'Duplicate') !== false) ? 'That rate is already in this entry.' : 'The change could not be saved. Please try again or contact the system administrator.');
    }
    header('Location: ' . $back);
    exit();
}

// =========================================================================
// STATUS & CHECKS
// =========================================================================
lumRateMap(true);
$not_mapped = [];
foreach ($combos as $i => $c) {
    $combos[$i]['mapped'] = (lumRateMapCalc($c['elec'], $c['muni'], $c['water'], $c['sewer'], []) !== null);
    if (!$combos[$i]['mapped']) $not_mapped[] = $combos[$i];
}

$check = (string)($_GET['check'] ?? '');
$check_result = null;
$check_label = '';
if ($check === 'test' && $table_ok) {
    list($L) = lumRateProbeLedgers();
    $check_result = lumRateMapCompare($combos, $L);
    $check_label = 'with test values (every ledger column its own number)';
} elseif ($check === 'months' && $table_ok) {
    $months_back = max(1, min(120, (int)($_GET['months'] ?? 12)));
    $periods = [];
    foreach (array_keys(LUM_RATE_LEDGERS) as $table) {
        try {
            foreach ($tariff_db_conn->query("SELECT DISTINCT period_year, period_month FROM `$table`") as $p) {
                $periods[sprintf('%04d-%02d', $p['period_year'], $p['period_month'])] = true;
            }
        } catch (\Throwable $e) {}
    }
    krsort($periods);
    $periods = array_slice(array_keys($periods), 0, $months_back);
    $check_result = ['checked' => 0, 'not_mapped' => 0, 'differences' => []];
    foreach ($periods as $period) {
        list($py, $pm) = array_map('intval', explode('-', $period));
        $L = [];
        foreach (array_keys(LUM_RATE_LEDGERS) as $table) {
            try { $L[$table] = fetchMonthlyTariff($tariff_db_conn, $table, $pm, $py); } catch (\Throwable $e) { $L[$table] = []; }
        }
        $res = lumRateMapCompare($combos, $L);
        $check_result['checked'] += $res['checked'];
        $check_result['not_mapped'] += $res['not_mapped'];
        foreach ($res['differences'] as $d) { $d[] = $period; $check_result['differences'][] = $d; }
    }
    $check_label = 'with the real tariff rates of ' . count($periods) . ' month(s)' . ($periods ? ' (' . end($periods) . ' to ' . reset($periods) . ')' : '');
}

$f_type = isset($types[$_GET['type'] ?? '']) ? $_GET['type'] : '';
$combo_users = function ($type, $name, $muni) use ($combos) {
    $n = 0;
    foreach ($combos as $c) {
        $mk = lumRateMapMunicipalityKey($c['muni'], $c['elec']);
        if ($type === 'municipality' && $mk === $muni) $n += $c['tenants'];
        elseif ($type === 'electricity' && lumLower(trim($c['elec'])) === lumLower($name) && $c['muni'] === $muni) $n += $c['tenants'];
        elseif ($type === 'water' && lumLower(trim($c['water'])) === lumLower($name) && $mk === $muni) $n += $c['tenants'];
        elseif ($type === 'sewer' && lumLower(trim($c['sewer'])) === lumLower($name) && $mk === $muni) $n += $c['tenants'];
    }
    return $n;
};
$edit_row = null;
if ($can_edit && !empty($_GET['row'])) {
    foreach ($entries as $e) foreach ($e['rows'] as $r) if ((int)$r['id'] === (int)$_GET['row']) $edit_row = $r;
}
$add_to = ($can_edit && isset($_GET['add'])) ? (string)$_GET['add'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Tariff Rate Map - Lynx Utility Management</title>
    <?php include LUM_ROOT . '/Layout/head-assets.php'; ?>
    <style>
        * { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif !important; font-weight: normal !important; text-transform: none !important; border-radius: 0 !important; }
        body { background-color: #121212; color: #fff; padding-bottom: 50px; }
        .action-bar { background-color: #0a0a0a; padding: 15px 20px; border-bottom: 1px solid #333; margin-bottom: 24px; }
        .btn-brand { background-color: #e3000f; color: #fff; border: none; }
        .btn-brand:hover { background-color: #bf000c; color: #fff; }
        .panel { background: linear-gradient(135deg, #222, #2d2d2d); border: 1px solid #333; padding: 20px; }
        .stat { background: #000; border: 1px solid #333; padding: 14px; }
        .table-container { background-color: #000; border: 1px solid #333; overflow-x: auto; }
        .table { margin-bottom: 0; color: #e0e0e0; }
        .table thead th { background-color: #1a1a1a; color: #aaa; border-bottom: 2px solid #333; font-size: 0.78rem; padding: 7px 10px; white-space: nowrap; }
        .table tbody td { border-bottom: 1px solid #222; padding: 6px 10px; vertical-align: middle; background-color: #000 !important; color: #ccc; font-size: 0.85rem; }
        .form-control, .form-select { background-color: #1a1a1a !important; border: 1px solid #444 !important; color: #fff !important; }
        .form-control:focus, .form-select:focus { border-color: #e3000f !important; box-shadow: 0 0 0 0.25rem rgba(227, 0, 15, 0.25) !important; }
        .form-label { color: #ccc; font-size: 0.82rem; margin-bottom: 0.2rem; }
        .entry-head { background: #1a1a1a; border: 1px solid #333; border-bottom: none; padding: 8px 12px; }
        code { color: #6ee7b7; }
    </style>
</head>
<body>

<?php include LUM_ROOT . '/Layout/top-navbar.php'; ?>

<div class="action-bar d-flex flex-wrap justify-content-between align-items-center gap-2 shadow-sm">
    <div class="d-flex align-items-center">
        <a href="view-tariffs.php" class="btn btn-brand btn-sm px-2 shadow-sm me-3"><i class="bi bi-arrow-left"></i> Tariffs</a>
        <span class="fs-6 text-white"><i class="bi bi-diagram-2 me-2"></i>Tariff Rate Map</span>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($can_edit && $table_ok): ?>
        <form method="POST" class="m-0" onsubmit="return confirm('Add every tariff combination in use that is not in the map yet? Existing entries are not changed.');">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="fill">
            <button type="submit" class="btn btn-brand btn-sm px-2 shadow-sm"><i class="bi bi-magic me-1"></i>Fill from the current rules</button>
        </form>
        <?php endif; ?>
        <?php if ($table_ok): ?>
        <a href="tariff-rate-map.php?check=test" class="btn btn-brand btn-sm px-2 shadow-sm"><i class="bi bi-check2-square me-1"></i>Check (test values)</a>
        <form method="GET" class="d-flex gap-1 m-0">
            <input type="hidden" name="check" value="months">
            <select name="months" class="form-select form-select-sm" style="width: auto;">
                <?php foreach ([12 => 'last 12 months', 24 => 'last 24 months', 36 => 'last 36 months', 120 => 'all months'] as $mv => $ml): ?><option value="<?php echo $mv; ?>"><?php echo $ml; ?></option><?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-brand btn-sm px-2 shadow-sm"><i class="bi bi-calendar-check me-1"></i>Check (real rates)</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="container-fluid px-4">
    <?php if (!$table_ok): ?>
        <div class="alert alert-warning">The rate map table is not installed (run <strong>tariff-rate-map-setup.sql</strong>). Until then every rate is read by the tariff-name rules.</div>
    <?php endif; ?>
    <?php if (!empty($message['text'])): ?><div class="alert alert-<?php echo htmlspecialchars($message['type']); ?>"><?php echo htmlspecialchars($message['text']); ?></div><?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="stat"><div class="small muted">Map entries</div><div class="fs-4"><?php echo count($entries); ?></div></div></div>
        <div class="col-md-3"><div class="stat"><div class="small muted">Tariff combinations in use</div><div class="fs-4"><?php echo count($combos); ?> <span class="fs-6 muted">(<?php echo array_sum(array_column($combos, 'tenants')); ?> tenants)</span></div></div></div>
        <div class="col-md-3"><div class="stat"><div class="small muted">Billed from the map</div><div class="fs-4 text-success"><?php echo count($combos) - count($not_mapped); ?></div></div></div>
        <div class="col-md-3"><div class="stat"><div class="small muted">Still on the old rules</div><div class="fs-4 <?php echo $not_mapped ? 'text-warning' : 'text-success'; ?>"><?php echo count($not_mapped); ?></div></div></div>
    </div>

    <?php if ($check_result !== null): ?>
    <div class="panel mb-4" style="border-color: <?php echo $check_result['differences'] ? '#dc3545' : '#198754'; ?>;">
        <h6 class="mb-2"><i class="bi bi-clipboard-check me-2"></i>Check <?php echo htmlspecialchars($check_label); ?></h6>
        <p class="small mb-2">
            <?php echo (int)$check_result['checked']; ?> calculation(s) compared with the old rules<?php echo $check_result['not_mapped'] ? ', ' . (int)$check_result['not_mapped'] . ' not in the map (still on the old rules)' : ''; ?>.
            <?php if (!$check_result['differences']): ?>
                <span class="text-success"><i class="bi bi-check-circle me-1"></i>The map gives exactly the same rates.</span>
            <?php else: ?>
                <span class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i><?php echo count($check_result['differences']); ?> difference(s). These come from map entries that were changed on purpose, or that need to be reset.</span>
            <?php endif; ?>
        </p>
        <?php if ($check_result['differences']): ?>
        <div class="table-container">
            <table class="table table-sm">
                <thead><tr><th>Month</th><th>Electricity tariff</th><th>Municipality</th><th>Water / sewer</th><th>Rate</th><th class="text-end">Old rules</th><th class="text-end">Map</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($check_result['differences'], 0, 100) as $d): list($c, $k, $ov, $mv) = $d; ?>
                    <tr>
                        <td><?php echo htmlspecialchars($d[4] ?? 'test'); ?></td>
                        <td><?php echo htmlspecialchars($c['elec'] ?: '-'); ?></td>
                        <td><?php echo htmlspecialchars($c['muni'] ?: '-'); ?></td>
                        <td class="small"><?php echo htmlspecialchars(($c['water'] ?: '-') . ' / ' . ($c['sewer'] ?: '-')); ?></td>
                        <td><?php echo htmlspecialchars(LUM_RATE_KEY_LABELS[$k] ?? $k); ?></td>
                        <td class="text-end"><?php echo htmlspecialchars(is_bool($ov) ? ($ov ? 'yes' : 'no') : (string)$ov); ?></td>
                        <td class="text-end text-warning"><?php echo htmlspecialchars(is_bool($mv) ? ($mv ? 'yes' : 'no') : (string)$mv); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($not_mapped): ?>
    <details class="panel mb-4">
        <summary class="small"><i class="bi bi-exclamation-triangle text-warning me-1"></i><?php echo count($not_mapped); ?> tariff combination(s) are still billed by the old rules &mdash; show</summary>
        <div class="table-container mt-3">
            <table class="table table-sm">
                <thead><tr><th>Electricity (billed as)</th><th>Municipality</th><th>Water tariff</th><th>Sewer tariff</th><th class="text-center">Tenants</th><th>Properties</th></tr></thead>
                <tbody>
                <?php foreach ($not_mapped as $c): ?>
                    <tr><td><?php echo htmlspecialchars($c['elec'] ?: '-'); ?></td><td><?php echo htmlspecialchars($c['muni'] ?: '-'); ?></td><td><?php echo htmlspecialchars($c['water'] ?: '-'); ?></td>
                        <td><?php echo htmlspecialchars($c['sewer'] ?: '-'); ?></td><td class="text-center"><?php echo (int)$c['tenants']; ?></td><td class="small"><?php echo htmlspecialchars(implode(', ', array_keys($c['properties']))); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>
    <?php endif; ?>

    <?php if ($edit_row || $add_to !== ''): ?>
    <?php
        if ($edit_row) {
            $fe = ['type' => $edit_row['map_type'], 'name' => $edit_row['tariff_name'], 'municipality' => $edit_row['municipality']];
            $fr = $edit_row;
        } else {
            $fe = $entries[$add_to] ?? null;
            $fr = ['id' => 0, 'rate_key' => '', 'ledger' => '', 'column_name' => '', 'fallback_column' => '', 'positive_only' => 0];
        }
    ?>
    <?php if ($fe): ?>
    <div class="panel mb-4">
        <h6 class="mb-3"><?php echo $edit_row ? 'Edit rate' : 'Add a rate'; ?> &mdash; <?php echo htmlspecialchars($types[$fe['type']] . ': ' . ($fe['name'] !== '' ? $fe['name'] . ' @ ' : '') . $fe['municipality']); ?></h6>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="save_row">
            <input type="hidden" name="id" value="<?php echo (int)$fr['id']; ?>">
            <input type="hidden" name="map_type" value="<?php echo htmlspecialchars($fe['type']); ?>">
            <input type="hidden" name="tariff_name" value="<?php echo htmlspecialchars($fe['name']); ?>">
            <input type="hidden" name="municipality" value="<?php echo htmlspecialchars($fe['municipality']); ?>">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Rate</label>
                    <select name="rate_key" class="form-select form-select-sm" required>
                        <?php foreach ($type_keys[$fe['type']] as $rk): ?><option value="<?php echo $rk; ?>" <?php echo $fr['rate_key'] === $rk ? 'selected' : ''; ?>><?php echo htmlspecialchars(LUM_RATE_KEY_LABELS[$rk] ?? $rk); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tariff ledger</label>
                    <select name="ledger" id="rmLedger" class="form-select form-select-sm">
                        <?php foreach (LUM_RATE_LEDGERS as $t => $l): ?><option value="<?php echo $t; ?>" <?php echo ($fr['ledger'] ?: array_search($fe['municipality'], LUM_RATE_LEDGERS, true)) === $t ? 'selected' : ''; ?>><?php echo htmlspecialchars($l); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Column</label>
                    <input type="text" name="column_name" class="form-control form-control-sm" list="rmCols" value="<?php echo htmlspecialchars($fr['column_name']); ?>" maxlength="100">
                </div>
                <div class="col-md-3">
                    <label class="form-label">If 0 or less, use column</label>
                    <input type="text" name="fallback_column" class="form-control form-control-sm" list="rmCols" value="<?php echo htmlspecialchars($fr['fallback_column']); ?>" maxlength="100" placeholder="blank = 0">
                </div>
                <div class="col-12">
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="positive_only" value="1" id="rmPos" <?php echo !empty($fr['positive_only']) ? 'checked' : ''; ?>>
                        <label class="form-check-label small" for="rmPos">Only use the column when it is above 0 (otherwise the fallback column, or 0)</label></div>
                    <div class="small muted">"Tiered water (switch)" needs no ledger or column.</div>
                </div>
            </div>
            <?php foreach ($ledger_cols as $t => $cols): ?>
                <datalist id="rmCols_<?php echo $t; ?>"><?php foreach ($cols as $c): ?><option value="<?php echo htmlspecialchars($c); ?>"></option><?php endforeach; ?></datalist>
            <?php endforeach; ?>
            <datalist id="rmCols"></datalist>
            <div class="d-flex justify-content-end gap-2 mt-3">
                <a href="tariff-rate-map.php?type=<?php echo rawurlencode($fe['type']); ?>" class="btn btn-outline-secondary btn-sm">Cancel</a>
                <button type="submit" class="btn btn-brand btn-sm px-3"><i class="bi bi-save me-1"></i>Save</button>
            </div>
        </form>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <ul class="nav nav-pills mb-3">
        <li class="nav-item"><a class="nav-link py-1 <?php echo $f_type === '' ? 'active bg-danger' : 'text-secondary'; ?>" href="tariff-rate-map.php">All</a></li>
        <?php foreach ($types as $tk => $tl): ?>
            <li class="nav-item"><a class="nav-link py-1 <?php echo $f_type === $tk ? 'active bg-danger' : 'text-secondary'; ?>" href="tariff-rate-map.php?type=<?php echo $tk; ?>"><?php echo htmlspecialchars($tl); ?></a></li>
        <?php endforeach; ?>
    </ul>

    <?php if (empty($entries)): ?>
        <div class="muted py-4 text-center"><?php echo $table_ok ? 'The map is empty. Use "Fill from the current rules" to start.' : ''; ?></div>
    <?php endif; ?>

    <?php foreach ($entries as $ek => $e): if ($f_type !== '' && $e['type'] !== $f_type) continue; $users = $combo_users($e['type'], $e['name'], $e['municipality']); ?>
    <div class="mb-3" id="e<?php echo md5($ek); ?>">
        <div class="entry-head d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <span class="badge bg-secondary me-2"><?php echo htmlspecialchars(ucfirst($e['type'])); ?></span>
                <span class="text-white"><?php echo htmlspecialchars($e['name'] !== '' ? $e['name'] : $e['municipality']); ?></span>
                <?php if ($e['name'] !== ''): ?><span class="muted"> @ <?php echo htmlspecialchars($e['municipality'] ?: '(no municipality)'); ?></span><?php endif; ?>
                <span class="small muted ms-2"><?php echo $users ? $users . ' tenant(s)' : 'not used by tenants'; ?></span>
            </div>
            <div class="d-flex gap-1">
                <?php if ($can_edit): ?>
                    <a class="btn btn-outline-light btn-sm py-0" href="tariff-rate-map.php?type=<?php echo rawurlencode($e['type']); ?>&amp;add=<?php echo rawurlencode($ek); ?>"><i class="bi bi-plus"></i> Rate</a>
                    <form method="POST" class="m-0" onsubmit="return confirm('Set this entry up again from the current tariff-name rules? Changes made to it are replaced.');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="action" value="reset_entry">
                        <input type="hidden" name="map_type" value="<?php echo htmlspecialchars($e['type']); ?>">
                        <input type="hidden" name="tariff_name" value="<?php echo htmlspecialchars($e['name']); ?>">
                        <input type="hidden" name="municipality" value="<?php echo htmlspecialchars($e['municipality']); ?>">
                        <button type="submit" class="btn btn-outline-warning btn-sm py-0" title="Reset from the current rules"><i class="bi bi-arrow-counterclockwise"></i></button>
                    </form>
                <?php endif; ?>
                <?php if ($can_delete): ?>
                    <form method="POST" class="m-0" onsubmit="return confirm('Remove this entry? Tenants with it are billed by the old tariff-name rules again.');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="action" value="delete_entry">
                        <input type="hidden" name="map_type" value="<?php echo htmlspecialchars($e['type']); ?>">
                        <input type="hidden" name="tariff_name" value="<?php echo htmlspecialchars($e['name']); ?>">
                        <input type="hidden" name="municipality" value="<?php echo htmlspecialchars($e['municipality']); ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm py-0" title="Remove entry"><i class="bi bi-trash3"></i></button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <div class="table-container">
            <table class="table table-sm">
                <tbody>
                <?php foreach ($e['rows'] as $r): ?>
                    <?php if ($r['rate_key'] === '-'): ?>
                        <tr><td colspan="4" class="muted small">No rates (everything 0)</td></tr>
                        <?php continue; endif; ?>
                    <tr>
                        <td style="width: 30%;"><?php echo htmlspecialchars(LUM_RATE_KEY_LABELS[$r['rate_key']] ?? $r['rate_key']); ?></td>
                        <td>
                            <?php if ($r['rate_key'] === 'is_tiered_water'): ?><span class="text-success">on</span>
                            <?php else: ?><span class="muted"><?php echo htmlspecialchars(LUM_RATE_LEDGERS[$r['ledger']] ?? $r['ledger']); ?>:</span> <code><?php echo htmlspecialchars($r['column_name']); ?></code><?php endif; ?>
                        </td>
                        <td class="small">
                            <?php if (!empty($r['positive_only'])): ?>if 0 or less: <?php echo $r['fallback_column'] !== '' ? '<code>' . htmlspecialchars($r['fallback_column']) . '</code>' : '0'; ?><?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap" style="width: 90px;">
                            <?php if ($can_edit): ?><a class="btn btn-sm btn-outline-info py-0" href="tariff-rate-map.php?type=<?php echo rawurlencode($e['type']); ?>&amp;row=<?php echo (int)$r['id']; ?>"><i class="bi bi-pencil"></i></a><?php endif; ?>
                            <?php if ($can_delete): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Remove this rate from the entry (it becomes 0)?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="action" value="delete_row">
                                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                    <input type="hidden" name="map_type" value="<?php echo htmlspecialchars($e['type']); ?>">
                                    <input type="hidden" name="tariff_name" value="<?php echo htmlspecialchars($e['name']); ?>">
                                    <input type="hidden" name="municipality" value="<?php echo htmlspecialchars($e['municipality']); ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-x-lg"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<script>
    // Column suggestions follow the chosen ledger
    (function () {
        const ledger = document.getElementById('rmLedger');
        const target = document.getElementById('rmCols');
        if (!ledger || !target) return;
        function fill() {
            const src = document.getElementById('rmCols_' + ledger.value);
            target.innerHTML = src ? src.innerHTML : '';
        }
        ledger.addEventListener('change', fill);
        fill();
    })();
</script>
</body>
</html>