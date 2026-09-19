<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once __DIR__ . '/bootstrap.php';
lum_page('tariffs', 'edit'); // Tariff setup screen: only for users who may change tariffs

// =========================================================================
// LYNX UTILITY MANAGEMENT - TIME OF USE PERIODS
// Location: /var/www/Lynx/Tarrifs/tou-periods.php (next to view-tariffs.php)
// Peak / standard / off-peak hours, holiday rule and season of each TOU algorithm.
// =========================================================================

lum_use('reporting', 'audit'); // Reporting engine: lumTouConfig() and the built-in periods

$can_edit = lum_can('tariffs', 'edit');
$can_delete = lum_can('tariffs', 'delete');
$user_name = substr((string)($_SESSION['user_name'] ?? 'system'), 0, 50);
$pdo = lumTouDb();
$seasons = ['high' => 'High season', 'low' => 'Low season'];
$day_types = ['weekday' => 'Weekday', 'saturday' => 'Saturday', 'sunday' => 'Sunday'];
$holiday_rules = ['saturday' => 'Billed as a Saturday', 'sunday' => 'Billed as a Sunday', 'weekday' => 'Billed as a normal weekday'];
$season_sources = [
    'months' => 'Calendar months (the high season months below)',
    'ledger' => 'Tariff ledger (demand season of the municipal tariff month)',
    'none'   => 'No seasons (the low season hours always apply)',
];
$month_names = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

$message = $_SESSION['tou_message'] ?? null;
unset($_SESSION['tou_message']);
$flash = function ($type, $text) { $_SESSION['tou_message'] = ['type' => $type, 'text' => $text]; };

// Are the tables installed?
$table_ok = false;
if ($pdo) {
    try {
        $pdo->query("SELECT 1 FROM lum_tou_algorithms LIMIT 1");
        $pdo->query("SELECT 1 FROM lum_tou_bands LIMIT 1");
        $table_ok = true;
    } catch (\Throwable $e) {
        $table_ok = false;
    }
}

// Algorithms used by the tariff catalog (cannot be deleted)
$used_by_catalog = function ($key) {
    $n = 0;
    foreach ((lumTariffCatalog(true)['electricity'] ?? []) as $row) {
        if ((string)$row['tou_algorithm'] === $key) $n++;
    }
    return $n;
};
$snapshot = function ($key) use ($pdo) {
    $st = $pdo->prepare("SELECT * FROM lum_tou_algorithms WHERE algorithm = ?");
    $st->execute([$key]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    unset($row['updated_at'], $row['updated_by_name']);
    $st = $pdo->prepare("SELECT season, day_type, hours FROM lum_tou_bands WHERE algorithm = ? ORDER BY season, day_type");
    $st->execute([$key]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) $row['hours_' . $b['season'] . '_' . $b['day_type']] = $b['hours'];
    return $row;
};
$audit = function ($action, $key, $before, $after, $note = null) {
    if (!function_exists('lum_audit_log')) return;
    lum_audit_log($action, 'tou_periods', crc32($key) & 0x7fffffff, 'TOU periods: ' . $key, null, $before, $after, $note);
};

// =========================================================================
// ACTIONS
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $table_ok) {
    lum_require_csrf();
    $action = (string)($_POST['action'] ?? '');
    $key = (string)($_POST['algorithm'] ?? '');
    $back = 'tou-periods.php?algo=' . rawurlencode($key);
    try {
        $config = lumTouConfig(true);

        if ($action === 'save') {
            lum_require_access('tariffs', 'edit');
            $hours = is_array($_POST['hours'] ?? null) ? $_POST['hours'] : [];
            $months = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['months'] ?? [])), function ($m) { return $m >= 1 && $m <= 12; })));
            sort($months);
            $row = [
                'label'              => substr(trim((string)($_POST['label'] ?? '')), 0, 100),
                'holiday_rule'       => isset($holiday_rules[$_POST['holiday_rule'] ?? '']) ? $_POST['holiday_rule'] : 'saturday',
                'season_source'      => isset($season_sources[$_POST['season_source'] ?? '']) ? $_POST['season_source'] : 'months',
                'high_season_months' => implode(',', $months),
                'season_ledger'      => isset(LUM_TOU_SEASON_LEDGERS[$_POST['season_ledger'] ?? '']) ? $_POST['season_ledger'] : null,
                'default_for'        => substr(trim((string)($_POST['default_for'] ?? '')), 0, 255),
                'aliases'            => substr(trim((string)($_POST['aliases'] ?? '')), 0, 255),
                'sort_order'         => max(0, min(999, (int)($_POST['sort_order'] ?? 10))),
                'active'             => !empty($_POST['active']) ? 1 : 0,
            ];
            $error = '';
            if (!isset($config[$key])) $error = 'The algorithm was not found.';
            elseif ($row['label'] === '') $error = 'Please enter a name.';
            elseif ($row['season_source'] === 'ledger' && $row['season_ledger'] === null) $error = 'Please choose the tariff ledger the season is read from.';
            foreach ($seasons as $sk => $sl) {
                foreach ($day_types as $dk => $dl) {
                    if ($error === '' && !preg_match('/^[PSO]{24}$/', (string)($hours[$sk][$dk] ?? ''))) $error = 'The hours of ' . $sl . ' / ' . $dl . ' are not valid.';
                }
            }
            if ($error !== '') {
                $flash('warning', $error);
            } else {
                $before = $snapshot($key);
                $pdo->beginTransaction();
                $sets = implode(', ', array_map(function ($c) { return "`$c` = ?"; }, array_keys($row)));
                $pdo->prepare("UPDATE lum_tou_algorithms SET $sets, updated_by_name = ? WHERE algorithm = ?")
                    ->execute(array_merge(array_values($row), [$user_name, $key]));
                $ins = $pdo->prepare("REPLACE INTO lum_tou_bands (algorithm, season, day_type, hours) VALUES (?, ?, ?, ?)");
                foreach ($seasons as $sk => $sl) {
                    foreach ($day_types as $dk => $dl) $ins->execute([$key, $sk, $dk, $hours[$sk][$dk]]);
                }
                $pdo->commit();
                $after = $snapshot($key);
                if ($before != $after) $audit('UPDATE', $key, $before, $after);
                $flash('success', 'Saved the TOU periods of ' . $key . '.');
            }
        } elseif ($action === 'add') {
            lum_require_access('tariffs', 'edit');
            $new = trim((string)($_POST['new_algorithm'] ?? ''));
            $copy = (string)($_POST['copy_from'] ?? '');
            if (!preg_match('/^[A-Za-z][A-Za-z0-9 _-]{1,29}$/', $new) || strcasecmp($new, 'None') === 0) {
                $flash('warning', 'Please enter a name of 2 to 30 letters, digits, spaces, dashes or underscores (not "None").');
            } elseif (isset($config[$new]) || lumTouResolve($new) !== null) {
                $flash('warning', '"' . $new . '" is already a TOU algorithm name.');
            } elseif (!isset($config[$copy])) {
                $flash('warning', 'Please choose the algorithm to copy.');
            } else {
                $src = $config[$copy];
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO lum_tou_algorithms (algorithm, label, holiday_rule, season_source, high_season_months, default_for, aliases, season_ledger, sort_order, active, updated_by_name)
                               VALUES (?, ?, ?, ?, ?, '', '', ?, ?, 1, ?)")
                    ->execute([$new, $new, $src['holiday_rule'], $src['season_source'], $src['high_season_months'], $src['season_ledger'] ?: null, (int)$src['sort_order'] + 1, $user_name]);
                $ins = $pdo->prepare("INSERT INTO lum_tou_bands (algorithm, season, day_type, hours) VALUES (?, ?, ?, ?)");
                foreach ($seasons as $sk => $sl) {
                    foreach ($day_types as $dk => $dl) $ins->execute([$new, $sk, $dk, $src['bands'][$sk][$dk]]);
                }
                $pdo->commit();
                $audit('INSERT', $new, null, $snapshot($new), 'Copied from ' . $copy);
                $flash('success', 'Added ' . $new . ' (copied from ' . $copy . '). Adjust its hours and save.');
                $back = 'tou-periods.php?algo=' . rawurlencode($new);
            }
        } elseif ($action === 'delete') {
            lum_require_access('tariffs', 'delete');
            if (isset(LUM_TOU_DEFAULTS[$key])) {
                $flash('warning', 'The standard algorithms cannot be deleted. Set them to inactive instead.');
            } elseif (!isset($config[$key])) {
                $flash('warning', 'The algorithm was not found.');
            } elseif (($n = $used_by_catalog($key)) > 0) {
                $flash('warning', $key . ' is used by ' . $n . ' tariff(s) in the tariff catalog and cannot be deleted.');
            } else {
                $before = $snapshot($key);
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM lum_tou_bands WHERE algorithm = ?")->execute([$key]);
                $pdo->prepare("DELETE FROM lum_tou_algorithms WHERE algorithm = ?")->execute([$key]);
                $pdo->commit();
                $audit('DELETE', $key, $before, null);
                $flash('success', 'Deleted ' . $key . '.');
                $back = 'tou-periods.php';
            }
        }
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('LUM TOU periods save failed: ' . $e->getMessage());
        $flash('danger', 'The change could not be saved. Please try again or contact the system administrator.');
    }
    header('Location: ' . $back);
    exit();
}

// =========================================================================
// DATA
// =========================================================================
$config = lumTouConfig(true);
$algo = (string)($_GET['algo'] ?? '');
if (!isset($config[$algo])) $algo = (string)array_key_first($config);
$a = $config[$algo];
$editable = ($can_edit && $table_ok);

// Season check: tariff ledger season vs the calendar months
$season_check = [];
$ledger = $a['season_ledger'];
if ($pdo && isset(LUM_TOU_SEASON_LEDGERS[$ledger])) {
    try {
        $st = $pdo->query("SELECT period_year, period_month, demand_season FROM `$ledger` ORDER BY period_year DESC, period_month DESC LIMIT 36");
        $high_months = array_map('intval', array_filter(array_map('trim', explode(',', $a['high_season_months'])), 'strlen'));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ledger_season = (strcasecmp(trim((string)$r['demand_season']), 'High') === 0) ? 'high' : 'low';
            $months_season = in_array((int)$r['period_month'], $high_months, true) ? 'high' : 'low';
            $season_check[] = ['year' => (int)$r['period_year'], 'month' => (int)$r['period_month'], 'ledger' => $ledger_season,
                               'raw' => (string)$r['demand_season'], 'months' => $months_season];
        }
    } catch (\Throwable $e) {
        error_log('LUM TOU periods: season check failed: ' . $e->getMessage());
    }
}
$mismatches = array_filter($season_check, function ($r) { return $r['ledger'] !== $r['months']; });
$band_names = ['P' => 'Peak', 'S' => 'Standard', 'O' => 'Off-peak'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>TOU Periods - Lynx Utility Management</title>
    <?php include LUM_ROOT . '/Layout/head-assets.php'; ?>
    <style>
        * { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif !important; font-weight: normal !important; text-transform: none !important; border-radius: 0 !important; }
        body { background-color: #121212; color: #fff; padding-bottom: 50px; }
        .action-bar { background-color: #0a0a0a; padding: 15px 20px; border-bottom: 1px solid #333; margin-bottom: 24px; }
        .btn-brand { background-color: #e3000f; color: #fff; border: none; }
        .btn-brand:hover { background-color: #bf000c; color: #fff; }
        .panel { background: linear-gradient(135deg, #222, #2d2d2d); border: 1px solid #333; padding: 20px; }
        .nav-tabs .nav-link { color: #aaa; border: none; padding: 10px 20px; }
        .nav-tabs .nav-link.active { background-color: #1e1e1e; color: #e3000f; border-bottom: 3px solid #e3000f; }
        .form-control, .form-select { background-color: #1a1a1a !important; border: 1px solid #444 !important; color: #fff !important; }
        .form-control:focus, .form-select:focus { border-color: #e3000f !important; box-shadow: 0 0 0 0.25rem rgba(227, 0, 15, 0.25) !important; }
        .form-label { color: #ccc; font-size: 0.85rem; margin-bottom: 0.2rem; }
        .tou-grid { border-collapse: separate; border-spacing: 2px; }
        .tou-grid th { color: #aaa; font-size: 0.72rem; text-align: center; padding: 2px; }
        .tou-grid td.lbl { color: #ddd; font-size: 0.85rem; padding-right: 10px; white-space: nowrap; }
        .tou-cell { width: 30px; height: 30px; text-align: center; font-size: 0.75rem; color: #000; user-select: none; }
        .tou-cell.editable { cursor: pointer; }
        .tou-cell.editable:hover { outline: 2px solid #fff; }
        .tou-P { background-color: #dc3545; color: #fff; }
        .tou-S { background-color: #ffc107; }
        .tou-O { background-color: #198754; color: #fff; }
        .tou-tot { font-size: 0.75rem; color: #aaa; white-space: nowrap; padding-left: 10px; }
        .legend span { display: inline-block; padding: 2px 10px; margin-right: 6px; font-size: 0.8rem; }
        .table-dark td, .table-dark th { background-color: #000 !important; }
    </style>
</head>
<body>

<?php include LUM_ROOT . '/Layout/top-navbar.php'; ?>

<div class="action-bar d-flex flex-wrap justify-content-between align-items-center gap-2 shadow-sm">
    <div class="d-flex align-items-center">
        <a href="view-tariffs.php" class="btn btn-brand btn-sm px-2 shadow-sm me-3"><i class="bi bi-arrow-left"></i> Tariffs</a>
        <span class="fs-6 text-white"><i class="bi bi-clock-history me-2"></i>Time Of Use Periods</span>
    </div>
    <?php if ($editable): ?>
    <form method="POST" class="d-flex align-items-center gap-2 m-0">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="action" value="add">
        <input type="text" name="new_algorithm" class="form-control form-control-sm" style="width: 170px;" placeholder="New algorithm name" required maxlength="30">
        <select name="copy_from" class="form-select form-select-sm" style="width: auto;">
            <?php foreach ($config as $k => $c): ?><option value="<?php echo htmlspecialchars($k); ?>" <?php echo $k === $algo ? 'selected' : ''; ?>>copy of <?php echo htmlspecialchars($k); ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-brand btn-sm px-2 shadow-sm"><i class="bi bi-plus-lg me-1"></i>Add</button>
    </form>
    <?php endif; ?>
</div>

<div class="container-fluid px-4">
    <?php if (!$table_ok): ?>
        <div class="alert alert-warning">The TOU period tables are not installed (run <strong>tou-periods-setup.sql</strong>). The built-in periods below are in use and cannot be changed here.</div>
    <?php endif; ?>
    <?php if (!empty($message['text'])): ?>
        <div class="alert alert-<?php echo htmlspecialchars($message['type']); ?>"><?php echo htmlspecialchars($message['text']); ?></div>
    <?php endif; ?>

    <ul class="nav nav-tabs border-secondary mb-3">
        <?php foreach ($config as $k => $c): ?>
            <li class="nav-item">
                <a class="nav-link <?php echo $k === $algo ? 'active' : ''; ?>" href="tou-periods.php?algo=<?php echo rawurlencode($k); ?>">
                    <?php echo htmlspecialchars($k); ?><?php echo empty($c['active']) ? ' <span class="badge bg-secondary">inactive</span>' : ''; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <form method="POST" id="touForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="algorithm" value="<?php echo htmlspecialchars($algo); ?>">
        <?php $dis = $editable ? '' : 'disabled'; ?>

        <div class="row g-4">
            <!-- Settings -->
            <div class="col-xl-4">
                <div class="panel h-100">
                    <h6 class="mb-3"><?php echo htmlspecialchars($algo); ?> &mdash; Settings</h6>
                    <div class="mb-2">
                        <label class="form-label">Name</label>
                        <input type="text" name="label" class="form-control form-control-sm" maxlength="100" value="<?php echo htmlspecialchars($a['label']); ?>" <?php echo $dis; ?>>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Weekday public holidays</label>
                        <select name="holiday_rule" class="form-select form-select-sm" <?php echo $dis; ?>>
                            <?php foreach ($holiday_rules as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo $a['holiday_rule'] === $k ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Season comes from</label>
                        <select name="season_source" id="seasonSource" class="form-select form-select-sm" <?php echo $dis; ?>>
                            <?php foreach ($season_sources as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo $a['season_source'] === $k ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">High season months</label>
                        <div class="d-flex flex-wrap gap-2">
                            <?php $hm = array_map('intval', array_filter(array_map('trim', explode(',', $a['high_season_months'])), 'strlen')); ?>
                            <?php foreach ($month_names as $mn => $ml): ?>
                                <div class="form-check form-check-inline m-0">
                                    <input class="form-check-input" type="checkbox" name="months[]" value="<?php echo $mn; ?>" id="m<?php echo $mn; ?>" <?php echo in_array($mn, $hm, true) ? 'checked' : ''; ?> <?php echo $dis; ?>>
                                    <label class="form-check-label small" for="m<?php echo $mn; ?>"><?php echo $ml; ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Tariff ledger for the season</label>
                        <select name="season_ledger" class="form-select form-select-sm" <?php echo $dis; ?>>
                            <option value="">-</option>
                            <?php foreach (LUM_TOU_SEASON_LEDGERS as $t => $l): ?><option value="<?php echo $t; ?>" <?php echo $a['season_ledger'] === $t ? 'selected' : ''; ?>><?php echo htmlspecialchars($l); ?> tariffs</option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Default for municipalities</label>
                        <input type="text" name="default_for" class="form-control form-control-sm" maxlength="255" value="<?php echo htmlspecialchars($a['default_for']); ?>" <?php echo $dis; ?>>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Other names</label>
                        <input type="text" name="aliases" class="form-control form-control-sm" maxlength="255" value="<?php echo htmlspecialchars($a['aliases']); ?>" <?php echo $dis; ?>>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label">Order</label>
                            <input type="number" name="sort_order" class="form-control form-control-sm" min="0" max="999" value="<?php echo (int)$a['sort_order']; ?>" <?php echo $dis; ?>>
                        </div>
                        <div class="col-6 d-flex align-items-end">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="active" value="1" id="touActive" <?php echo !empty($a['active']) ? 'checked' : ''; ?> <?php echo $dis; ?>>
                                <label class="form-check-label small" for="touActive">Active (offered on slips)</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Hours -->
            <div class="col-xl-8">
                <div class="panel h-100">
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
                        <h6 class="mb-0">Hours</h6>
                        <div class="legend"><span class="tou-P">P Peak</span><span class="tou-S">S Standard</span><span class="tou-O">O Off-peak</span></div>
                    </div>
                    <div class="table-responsive">
                        <table class="tou-grid">
                            <thead>
                                <tr><th></th><?php for ($h = 0; $h < 24; $h++): ?><th><?php echo str_pad($h, 2, '0', STR_PAD_LEFT); ?></th><?php endfor; ?><th></th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($seasons as $sk => $sl): ?>
                                <?php foreach ($day_types as $dk => $dl): $pattern = $a['bands'][$sk][$dk]; ?>
                                <tr class="season-<?php echo $sk; ?>">
                                    <td class="lbl"><?php echo $sl; ?> &middot; <?php echo $dl; ?></td>
                                    <?php for ($h = 0; $h < 24; $h++): $c = $pattern[$h]; ?>
                                        <td class="tou-cell tou-<?php echo $c; ?><?php echo $editable ? ' editable' : ''; ?>" data-h="<?php echo $h; ?>"
                                            title="<?php echo sprintf('%02d:00-%02d:00 %s', $h, $h + 1, $band_names[$c]); ?>"><?php echo $c; ?></td>
                                    <?php endfor; ?>
                                    <td class="tou-tot-cell"><span class="tou-tot"></span><input type="hidden" name="hours[<?php echo $sk; ?>][<?php echo $dk; ?>]" value="<?php echo htmlspecialchars($pattern); ?>"></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($editable): ?>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <a href="tou-periods.php?algo=<?php echo rawurlencode($algo); ?>" class="btn btn-outline-secondary btn-sm">Undo changes</a>
                        <button type="submit" class="btn btn-brand btn-sm px-3" onclick="return confirm('Save the TOU periods of <?php echo htmlspecialchars(addslashes($algo), ENT_QUOTES); ?>? This changes the peak / standard / off-peak split of every tenant using it.');"><i class="bi bi-save me-1"></i>Save <?php echo htmlspecialchars($algo); ?></button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </form>

    <?php if ($can_delete && $table_ok && !isset(LUM_TOU_DEFAULTS[$algo])): ?>
    <form method="POST" class="mt-2 text-end" onsubmit="return confirm('Delete <?php echo htmlspecialchars(addslashes($algo), ENT_QUOTES); ?>?');">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="algorithm" value="<?php echo htmlspecialchars($algo); ?>">
        <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash3 me-1"></i>Delete <?php echo htmlspecialchars($algo); ?></button>
    </form>
    <?php endif; ?>

    <!-- Season check -->
    <div class="panel mt-4">
        <h6 class="mb-2"><i class="bi bi-calendar2-range me-2"></i>Season check &mdash; <?php echo htmlspecialchars($algo); ?></h6>
        <?php if (empty($season_check)): ?>
            <p class="muted small mb-0">No tariff ledger months to compare<?php echo $ledger ? '' : ' (choose the tariff ledger for the season)'; ?>.</p>
        <?php else: ?>
            <div class="d-flex flex-wrap gap-1">
                <?php foreach (array_reverse($season_check) as $r): $diff = ($r['ledger'] !== $r['months']); ?>
                    <div class="px-2 py-1 small text-center <?php echo $diff ? 'border border-warning' : 'border border-secondary'; ?>" style="min-width: 86px;"
                         title="Ledger: <?php echo htmlspecialchars($r['raw'] ?: 'blank'); ?> / Months rule: <?php echo $r['months']; ?>">
                        <div><?php echo $month_names[$r['month']] . ' ' . $r['year']; ?></div>
                        <div class="<?php echo $diff ? 'text-warning' : 'muted'; ?>">L: <?php echo ucfirst($r['ledger']); ?> &middot; M: <?php echo ucfirst($r['months']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    (function () {
        const order = { P: 'S', S: 'O', O: 'P' };
        const names = { P: 'Peak', S: 'Standard', O: 'Off-peak' };
        function totals(row) {
            const v = row.querySelector('input[type=hidden]').value;
            const n = { P: 0, S: 0, O: 0 };
            for (const ch of v) n[ch]++;
            row.querySelector('.tou-tot').textContent = 'P ' + n.P + 'h · S ' + n.S + 'h · O ' + n.O + 'h';
        }
        document.querySelectorAll('.tou-grid tbody tr').forEach(function (row) {
            totals(row);
            row.querySelectorAll('.tou-cell.editable').forEach(function (cell) {
                cell.addEventListener('click', function () {
                    const input = row.querySelector('input[type=hidden]');
                    const h = parseInt(cell.dataset.h, 10);
                    const next = order[input.value[h]] || 'S';
                    input.value = input.value.substring(0, h) + next + input.value.substring(h + 1);
                    cell.textContent = next;
                    cell.className = 'tou-cell editable tou-' + next;
                    cell.title = String(h).padStart(2, '0') + ':00-' + String(h + 1).padStart(2, '0') + ':00 ' + names[next];
                    totals(row);
                });
            });
        });
    })();
</script>
</body>
</html>