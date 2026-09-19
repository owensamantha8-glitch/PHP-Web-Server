<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once __DIR__ . '/bootstrap.php';
lum_page('tariffs', 'edit'); // Tariff setup screen: only for users who may change tariffs

// =========================================================================
// LYNX UTILITY MANAGEMENT - TARIFF CATALOG
// Location: /var/www/Lynx/Tarrifs/tariff-catalog.php (next to view-tariffs.php)
// How each tariff name used on the tenants behaves in the billing engine.
// =========================================================================

lum_connect('tariffs', 'tenants');
lum_use('reporting', 'audit'); // Reporting engine: catalog functions and the old name rules

$can_edit = lum_can('tariffs', 'edit');
$can_delete = lum_can('tariffs', 'delete');
$user_name = substr((string)($_SESSION['user_name'] ?? 'system'), 0, 50);
$services = LUM_TARIFF_SERVICES;
$tenant_columns = [
    'electricity' => 'tenant_electrical_tariff_charge',
    'water'       => 'tenant_water_tariff_charge',
    'sewer'       => 'tenant_water_sewer_tariff_charge',
    'generator'   => 'tenant_generator_tariff_charge',
    'elec_common' => 'tenant_electrical_commArea_charge',
    'water_common'=> 'tenant_water_commArea_charge',
];
// The standard tariffs of the tenant forms (listed as "not in the catalog" until they are added)
$standard_tariffs = [];
if (is_readable(LUM_ROOT . LUM_LIBRARIES['tenant_forms'])) {
    lum_use('tenant_forms');
    if (defined('LUM_TENANT_FORM_STANDARD_TARIFFS')) {
        foreach (LUM_TENANT_FORM_STANDARD_TARIFFS as $std_service => $std_groups) {
            foreach ($std_groups as $std_names) foreach ($std_names as $std_name) $standard_tariffs[$std_service][] = $std_name;
        }
    }
}
$fields = ['service', 'tariff_name', 'municipality', 'not_applicable', 'is_tou', 'tou_algorithm', 'shows_amps', 'display_name', 'calc_name', 'active', 'notes'];

$message = $_SESSION['tc_message'] ?? null;
unset($_SESSION['tc_message']);
$flash = function ($type, $text) { $_SESSION['tc_message'] = ['type' => $type, 'text' => $text]; };

// Is the table installed?
$table_ok = true;
try {
    $tariff_db_conn->query("SELECT 1 FROM lum_tariff_catalog LIMIT 1");
} catch (\Throwable $e) {
    $table_ok = false;
}

// ---------------- Helpers ----------------
$get_row = function ($id) use ($tariff_db_conn) {
    $st = $tariff_db_conn->prepare("SELECT * FROM lum_tariff_catalog WHERE tariff_id = ?");
    $st->execute([(int)$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
};
$audit_row = function ($row) use ($fields) {
    return $row ? array_intersect_key($row, array_flip($fields)) : null;
};
$audit = function ($action, $row_after, $row_before, $note = null) use ($audit_row) {
    if (!function_exists('lum_audit_log')) return;
    $ref = $row_after ?: $row_before;
    lum_audit_log($action, 'tariff_catalog', (int)$ref['tariff_id'], $ref['service'] . ': ' . $ref['tariff_name'], null,
                  $audit_row($row_before), $audit_row($row_after), $note);
};

// Tenants per tariff: [service => [lower-case name => ['name' => as used, 'count' => n]]]
$usage = [];
try {
    $rows = $tenant_db_conn->query("SELECT " . implode(', ', array_map(function ($c) { return "`$c`"; }, $tenant_columns)) . " FROM lum_tenants")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        foreach ($tenant_columns as $svc => $col) {
            $name = trim((string)($r[$col] ?? ''));
            if ($name === '') continue;
            $key = lumLower($name);
            if (!isset($usage[$svc][$key])) $usage[$svc][$key] = ['name' => $name, 'count' => 0];
            $usage[$svc][$key]['count']++;
        }
    }
} catch (\Throwable $e) {
    error_log('LUM tariff catalog: tenant usage not available: ' . $e->getMessage());
}
foreach ($standard_tariffs as $std_service => $std_names) {
    foreach ($std_names as $std_name) {
        $std_key = lumLower(trim($std_name));
        if (!isset($usage[$std_service][$std_key])) $usage[$std_service][$std_key] = ['name' => trim($std_name), 'count' => 0];
    }
}
$used_by = function ($service, $name) use ($usage) {
    return $usage[$service][lumLower(trim((string)$name))]['count'] ?? 0;
};

// Clean a posted row
$clean = function (array $in) use ($services) {
    $svc = (string)($in['service'] ?? '');
    $row = [
        'service'        => isset($services[$svc]) ? $svc : '',
        'tariff_name'    => trim((string)($in['tariff_name'] ?? '')),
        'municipality'   => in_array($in['municipality'] ?? '', LUM_TARIFF_MUNICIPALITIES, true) ? $in['municipality'] : null,
        'not_applicable' => !empty($in['not_applicable']) ? 1 : 0,
        'is_tou'         => !empty($in['is_tou']) ? 1 : 0,
        'tou_algorithm'  => in_array($in['tou_algorithm'] ?? '', lumTouAlgorithmList(), true) ? $in['tou_algorithm'] : null,
        'shows_amps'     => !empty($in['shows_amps']) ? 1 : 0,
        'display_name'   => trim((string)($in['display_name'] ?? '')) ?: null,
        'calc_name'      => trim((string)($in['calc_name'] ?? '')) ?: null,
        'active'         => !empty($in['active']) ? 1 : 0,
        'notes'          => trim((string)($in['notes'] ?? '')) ?: null,
    ];
    if ($row['service'] !== 'electricity') {
        // Time Of Use, Amps and the calculation name only apply to electricity
        $row['is_tou'] = 0;
        $row['tou_algorithm'] = null;
        $row['shows_amps'] = 0;
        $row['calc_name'] = null;
    }
    if (!$row['is_tou']) $row['tou_algorithm'] = null;
    return $row;
};

// =========================================================================
// ACTIONS
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $table_ok) {
    lum_require_csrf();
    $action = (string)($_POST['action'] ?? '');
    $back = 'tariff-catalog.php' . (!empty($_POST['return_query']) ? '?' . preg_replace('/[^A-Za-z0-9_=&%.+\-]/', '', (string)$_POST['return_query']) : '');
    try {
        if ($action === 'save') {
            lum_require_access('tariffs', 'edit');
            $id = (int)($_POST['tariff_id'] ?? 0);
            $row = $clean($_POST);
            $before = $id ? $get_row($id) : null;

            if ($row['service'] === '' || $row['tariff_name'] === '' || strlen($row['tariff_name']) > 150) {
                $flash('warning', 'Please choose a service and enter a tariff name (up to 150 characters).');
            } elseif (strlen((string)$row['display_name']) > 150 || strlen((string)$row['calc_name']) > 150 || strlen((string)$row['notes']) > 255) {
                $flash('warning', 'The display name, calculation name or notes are too long.');
            } elseif ($id && !$before) {
                $flash('warning', 'The tariff was not found.');
            } elseif ($before && ($before['service'] !== $row['service'] || $before['tariff_name'] !== $row['tariff_name'])
                      && $used_by($before['service'], $before['tariff_name']) > 0) {
                // Tenants store the tariff by name: a used tariff keeps its name and service
                $flash('warning', 'This tariff is used by ' . $used_by($before['service'], $before['tariff_name']) . ' tenant(s), so its name and service cannot be changed. Change the tenants first, or add a new tariff.');
            } else {
                $cols = array_keys($row);
                if ($before) {
                    $sets = implode(', ', array_map(function ($c) { return "`$c` = ?"; }, $cols));
                    $st = $tariff_db_conn->prepare("UPDATE lum_tariff_catalog SET $sets, updated_by_name = ? WHERE tariff_id = ?");
                    $st->execute(array_merge(array_values($row), [$user_name, $id]));
                    $after = $get_row($id);
                    $audit('UPDATE', $after, $before);
                    $flash('success', 'Saved ' . $row['tariff_name'] . '.');
                } else {
                    $st = $tariff_db_conn->prepare("INSERT INTO lum_tariff_catalog (" . implode(', ', array_map(function ($c) { return "`$c`"; }, $cols)) . ", created_by_name)
                                                    VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ", ?)");
                    $st->execute(array_merge(array_values($row), [$user_name]));
                    $after = $get_row((int)$tariff_db_conn->lastInsertId());
                    $audit('INSERT', $after, null);
                    $flash('success', 'Added ' . $row['tariff_name'] . ' to the catalog.');
                }
            }
        } elseif ($action === 'delete') {
            lum_require_access('tariffs', 'delete');
            $before = $get_row((int)($_POST['tariff_id'] ?? 0));
            if (!$before) {
                $flash('warning', 'The tariff was not found.');
            } elseif ($used_by($before['service'], $before['tariff_name']) > 0) {
                $flash('warning', 'This tariff is still used by tenants and cannot be removed. Set it to inactive instead.');
            } else {
                $tariff_db_conn->prepare("DELETE FROM lum_tariff_catalog WHERE tariff_id = ?")->execute([(int)$before['tariff_id']]);
                $audit('DELETE', null, $before);
                $flash('success', 'Removed ' . $before['tariff_name'] . ' from the catalog.');
            }
        } elseif ($action === 'sync') {
            // Add every tariff used by tenants that is not in the catalog yet, set up with the old name rules
            lum_require_access('tariffs', 'edit');
            $existing = lumTariffCatalog(true);
            $st = $tariff_db_conn->prepare("INSERT IGNORE INTO lum_tariff_catalog (service, tariff_name, municipality, not_applicable, is_tou, tou_algorithm,
                                            shows_amps, display_name, calc_name, active, notes, created_by_name)
                                            VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, 1, 'Added from the tenant tariffs', ?)");
            $added = 0;
            foreach ($usage as $svc => $names) {
                foreach ($names as $key => $u) {
                    if (isset($existing[$svc][$key])) continue;
                    $d = lumTariffDerive($u['name'], $svc);
                    $st->execute([$svc, $d['tariff_name'], $d['municipality'], $d['not_applicable'], $d['is_tou'], $d['shows_amps'],
                                  $d['display_name'], $d['calc_name'], $user_name]);
                    if ($st->rowCount() > 0) {
                        $added++;
                        $new = $get_row((int)$tariff_db_conn->lastInsertId());
                        if ($new) $audit('INSERT', $new, null, 'Added from the tenant tariffs');
                    }
                }
            }
            $flash($added ? 'success' : 'info', $added ? "Added $added tariff(s) used by tenants. They are set up exactly as they were billed until now."
                                                       : 'Every tariff used by tenants is already in the catalog.');
        }
    } catch (\Throwable $e) {
        error_log('LUM tariff catalog save failed: ' . $e->getMessage());
        $flash('danger', (strpos($e->getMessage(), 'Duplicate') !== false)
            ? 'That tariff name is already in the catalog for this service.'
            : 'The change could not be saved. Please try again or contact the system administrator.');
    }
    header('Location: ' . $back);
    exit();
}

// =========================================================================
// DATA
// =========================================================================
$f_service = isset($services[$_GET['service'] ?? '']) ? $_GET['service'] : '';
$f_muni = in_array($_GET['municipality'] ?? '', LUM_TARIFF_MUNICIPALITIES, true) ? $_GET['municipality'] : (($_GET['municipality'] ?? '') === '-' ? '-' : '');
$f_search = trim((string)($_GET['search'] ?? ''));
$f_inactive = !empty($_GET['inactive']);
$return_query = http_build_query(array_filter(['service' => $f_service, 'municipality' => $f_muni, 'search' => $f_search, 'inactive' => $f_inactive ? 1 : null]));

$catalog_rows = [];
if ($table_ok) {
    $sql = "SELECT * FROM lum_tariff_catalog WHERE 1=1";
    $p = [];
    if ($f_service !== '') { $sql .= " AND service = ?"; $p[] = $f_service; }
    if ($f_muni === '-') { $sql .= " AND (municipality IS NULL OR municipality = '')"; }
    elseif ($f_muni !== '') { $sql .= " AND municipality = ?"; $p[] = $f_muni; }
    if ($f_search !== '') { $sql .= " AND (tariff_name LIKE ? OR display_name LIKE ? OR notes LIKE ?)"; $like = '%' . $f_search . '%'; array_push($p, $like, $like, $like); }
    if (!$f_inactive) $sql .= " AND active = 1";
    $sql .= " ORDER BY FIELD(service, 'electricity', 'water', 'sewer', 'generator'), municipality, tariff_name";
    $st = $tariff_db_conn->prepare($sql);
    $st->execute($p);
    $catalog_rows = $st->fetchAll(PDO::FETCH_ASSOC);
}

// Tariffs used by tenants that are not in the catalog
$missing = [];
if ($table_ok) {
    $existing = lumTariffCatalog(true);
    foreach ($usage as $svc => $names) {
        foreach ($names as $key => $u) {
            if (!isset($existing[$svc][$key])) $missing[] = ['service' => $svc] + $u + ['derived' => lumTariffDerive($u['name'], $svc)];
        }
    }
}

// Row being edited / added
$editing = null;
if ($can_edit && $table_ok) {
    if (!empty($_GET['edit'])) {
        $editing = $get_row((int)$_GET['edit']);
    } elseif (!empty($_GET['add'])) {
        $svc = isset($services[$_GET['service_new'] ?? '']) ? $_GET['service_new'] : 'electricity';
        $name = trim((string)($_GET['name'] ?? ''));
        $editing = ['tariff_id' => 0, 'active' => 1, 'notes' => null, 'tou_algorithm' => null] + ($name !== '' ? lumTariffDerive($name, $svc)
                   : ['service' => $svc, 'tariff_name' => '', 'municipality' => null, 'not_applicable' => 0, 'is_tou' => 0, 'shows_amps' => 0, 'display_name' => null, 'calc_name' => null]);
    }
}
$editing_used = $editing && !empty($editing['tariff_id']) ? $used_by($editing['service'], $editing['tariff_name']) : 0;
$yes = function ($v) { return $v ? '<i class="bi bi-check-lg text-success"></i>' : '<span class="text-secondary">-</span>'; };
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Tariff Catalog - Lynx Utility Management</title>
    <?php include LUM_ROOT . '/Layout/head-assets.php'; ?>
    <style>
        * { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif !important; font-weight: normal !important; text-transform: none !important; border-radius: 0 !important; }
        body { background-color: #121212; color: #fff; padding-bottom: 50px; }
        .action-bar { background-color: #0a0a0a; padding: 15px 20px; border-bottom: 1px solid #333; margin-bottom: 24px; }
        .table-container { background-color: #000; border: 1px solid #333; overflow-x: auto; }
        .table { margin-bottom: 0; color: #e0e0e0; }
        .table thead th { background-color: #1a1a1a; color: #fff; border-bottom: 2px solid #333; padding: 10px 12px; white-space: nowrap; }
        .table tbody td { border-bottom: 1px solid #222; padding: 8px 12px; vertical-align: middle; background-color: transparent; color: #ccc; }
        .table tbody tr:hover td { background-color: #111; }
        .btn-brand { background-color: #e3000f; color: #fff; border: none; }
        .btn-brand:hover { background-color: #bf000c; color: #fff; }
        .panel { background: linear-gradient(135deg, #222, #2d2d2d); border: 1px solid #333; padding: 20px; }
        .form-control, .form-select { background-color: #1a1a1a !important; border: 1px solid #444 !important; color: #fff !important; }
        .form-control:focus, .form-select:focus { border-color: #e3000f !important; box-shadow: 0 0 0 0.25rem rgba(227, 0, 15, 0.25) !important; }
        .form-control:disabled { background-color: #111 !important; color: #888 !important; }
        .form-label { color: #ccc; font-size: 0.85rem; margin-bottom: 0.2rem; }
    </style>
</head>
<body>

<?php include LUM_ROOT . '/Layout/top-navbar.php'; ?>

<div class="action-bar d-flex flex-wrap justify-content-between align-items-center gap-2 shadow-sm">
    <div class="d-flex align-items-center">
        <a href="view-tariffs.php" class="btn btn-brand btn-sm px-2 shadow-sm me-3"><i class="bi bi-arrow-left"></i> Tariffs</a>
        <span class="fs-6 text-white"><i class="bi bi-journal-text me-2"></i>Tariff Catalog</span>
    </div>
    <?php if ($can_edit && $table_ok): ?>
        <a href="tariff-catalog.php?add=1<?php echo $return_query ? '&amp;' . htmlspecialchars($return_query) : ''; ?>" class="btn btn-brand btn-sm px-3"><i class="bi bi-plus-lg me-1"></i>Add Tariff</a>
    <?php endif; ?>
</div>

<div class="container-fluid px-4">
    <?php if (!$table_ok): ?>
        <div class="alert alert-danger">The tariff catalog table is not installed. Please run <strong>tariff-catalog-setup.sql</strong>. Until then the billing engine uses the tariff name rules.</div>
    <?php endif; ?>
    <?php if (!empty($message['text'])): ?>
        <div class="alert alert-<?php echo htmlspecialchars($message['type']); ?>"><?php echo htmlspecialchars($message['text']); ?></div>
    <?php endif; ?>

    <?php if ($editing): ?>
    <!-- ======================= ADD / EDIT ======================= -->
    <div class="panel mb-4">
        <h6 class="mb-3"><?php echo !empty($editing['tariff_id']) ? 'Edit Tariff' : 'Add Tariff'; ?></h6>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="tariff_id" value="<?php echo (int)($editing['tariff_id'] ?? 0); ?>">
            <input type="hidden" name="return_query" value="<?php echo htmlspecialchars($return_query); ?>">
            <?php $locked = ($editing_used > 0); ?>
            <?php if ($locked): ?>
                <input type="hidden" name="service" value="<?php echo htmlspecialchars($editing['service']); ?>">
                <input type="hidden" name="tariff_name" value="<?php echo htmlspecialchars($editing['tariff_name']); ?>">
            <?php endif; ?>
            <div class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Service</label>
                    <select name="service" id="tcService" class="form-select form-select-sm" <?php echo $locked ? 'disabled' : ''; ?>>
                        <?php foreach ($services as $k => $lbl): ?>
                            <option value="<?php echo $k; ?>" <?php echo ($editing['service'] ?? '') === $k ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Tariff name (exactly as on the tenants)</label>
                    <input type="text" name="tariff_name" class="form-control form-control-sm" maxlength="150" required value="<?php echo htmlspecialchars((string)$editing['tariff_name']); ?>" <?php echo $locked ? 'disabled' : ''; ?>>
                    <?php if ($locked): ?><div class="small muted">Used by <?php echo $editing_used; ?> tenant(s): the name and service cannot be changed here.</div><?php endif; ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Municipality</label>
                    <select name="municipality" class="form-select form-select-sm">
                        <option value="">Not decided by this tariff</option>
                        <?php foreach (LUM_TARIFF_MUNICIPALITIES as $m): ?>
                            <option value="<?php echo $m; ?>" <?php echo ($editing['municipality'] ?? '') === $m ? 'selected' : ''; ?>><?php echo $m; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end gap-3 flex-wrap">
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="active" value="1" id="tcActive" <?php echo !empty($editing['active']) ? 'checked' : ''; ?>><label class="form-check-label small" for="tcActive">Active</label></div>
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="not_applicable" value="1" id="tcNa" <?php echo !empty($editing['not_applicable']) ? 'checked' : ''; ?>><label class="form-check-label small" for="tcNa">Not applicable</label></div>
                </div>

                <div class="col-md-5">
                    <label class="form-label">Name shown on the slip (blank = tariff name)</label>
                    <input type="text" name="display_name" class="form-control form-control-sm" maxlength="150" value="<?php echo htmlspecialchars((string)$editing['display_name']); ?>">
                </div>
                <div class="col-md-5 elec-only">
                    <label class="form-label">Billed on the rates of (blank = tariff name)</label>
                    <input type="text" name="calc_name" class="form-control form-control-sm" maxlength="150" value="<?php echo htmlspecialchars((string)$editing['calc_name']); ?>">
                </div>

                <div class="col-md-2 elec-only d-flex align-items-end">
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="shows_amps" value="1" id="tcAmps" <?php echo !empty($editing['shows_amps']) ? 'checked' : ''; ?>><label class="form-check-label small" for="tcAmps">Show Amps on slip</label></div>
                </div>
                <div class="col-md-2 elec-only d-flex align-items-end">
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="is_tou" value="1" id="tcTou" <?php echo !empty($editing['is_tou']) ? 'checked' : ''; ?>><label class="form-check-label small" for="tcTou">Time Of Use</label></div>
                </div>
                <div class="col-md-3 elec-only">
                    <label class="form-label">TOU algorithm</label>
                    <select name="tou_algorithm" class="form-select form-select-sm">
                        <option value="">Municipality default</option>
                        <?php foreach (lumTouAlgorithmList() as $a): ?>
                            <option value="<?php echo $a; ?>" <?php echo ($editing['tou_algorithm'] ?? '') === $a ? 'selected' : ''; ?>><?php echo $a; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Notes</label>
                    <input type="text" name="notes" class="form-control form-control-sm" maxlength="255" value="<?php echo htmlspecialchars((string)$editing['notes']); ?>">
                </div>
            </div>
            <div class="small text-warning mt-3">Saving changes how every tenant on this tariff is billed, including recalculations of months that were already journaled.</div>
            <div class="d-flex justify-content-end gap-2 mt-3">
                <a href="tariff-catalog.php<?php echo $return_query ? '?' . htmlspecialchars($return_query) : ''; ?>" class="btn btn-outline-secondary btn-sm">Cancel</a>
                <button type="submit" class="btn btn-brand btn-sm px-3"><i class="bi bi-save me-1"></i>Save</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if (!empty($missing)): ?>
    <!-- ======================= NOT IN THE CATALOG ======================= -->
    <div class="panel mb-4" style="border-color: #6c4f00;">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <div><i class="bi bi-exclamation-triangle text-warning me-2"></i><?php echo count($missing); ?> tariff(s) used by tenants or offered on the tenant forms are not in the catalog yet &mdash; they are billed by the words in their names.</div>
            <?php if ($can_edit): ?>
            <form method="POST" class="m-0" onsubmit="return confirm('Add all <?php echo count($missing); ?> tariffs to the catalog? They are set up exactly as they are billed now.');">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="sync">
                <input type="hidden" name="return_query" value="<?php echo htmlspecialchars($return_query); ?>">
                <button type="submit" class="btn btn-outline-warning btn-sm"><i class="bi bi-download me-1"></i>Add these tariffs</button>
            </form>
            <?php endif; ?>
        </div>
        <div class="table-container">
            <table class="table table-sm">
                <thead><tr><th>Service</th><th>Tariff name</th><th>Tenants</th><th>Read from the name as</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($missing as $m): $d = $m['derived']; ?>
                    <tr>
                        <td><?php echo $services[$m['service']]; ?></td>
                        <td class="text-white"><?php echo htmlspecialchars($m['name']); ?></td>
                        <td><?php echo (int)$m['count']; ?></td>
                        <td class="small">
                            <?php
                            $bits = [];
                            $bits[] = $d['municipality'] ?: 'no municipality';
                            if ($d['not_applicable']) $bits[] = 'not applicable';
                            if ($d['is_tou']) $bits[] = 'Time Of Use';
                            if ($d['shows_amps']) $bits[] = 'shows Amps';
                            if ($d['display_name']) $bits[] = 'shown as "' . $d['display_name'] . '"';
                            if ($d['calc_name']) $bits[] = 'billed as "' . $d['calc_name'] . '"';
                            echo htmlspecialchars(implode(' · ', $bits));
                            ?>
                        </td>
                        <td class="text-end">
                            <?php if ($can_edit): ?>
                                <a class="btn btn-sm btn-outline-light py-0" href="tariff-catalog.php?add=1&amp;service_new=<?php echo urlencode($m['service']); ?>&amp;name=<?php echo urlencode($m['name']); ?>">Add</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ======================= CATALOG ======================= -->
    <form method="GET" class="d-flex flex-wrap gap-2 align-items-end mb-3">
        <div>
            <label class="form-label">Service</label>
            <select name="service" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All</option>
                <?php foreach ($services as $k => $lbl): ?><option value="<?php echo $k; ?>" <?php echo $f_service === $k ? 'selected' : ''; ?>><?php echo $lbl; ?></option><?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label">Municipality</label>
            <select name="municipality" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All</option>
                <?php foreach (LUM_TARIFF_MUNICIPALITIES as $m): ?><option value="<?php echo $m; ?>" <?php echo $f_muni === $m ? 'selected' : ''; ?>><?php echo $m; ?></option><?php endforeach; ?>
                <option value="-" <?php echo $f_muni === '-' ? 'selected' : ''; ?>>None</option>
            </select>
        </div>
        <div>
            <label class="form-label">Search</label>
            <input type="text" name="search" class="form-control form-control-sm" value="<?php echo htmlspecialchars($f_search); ?>" placeholder="Tariff name or notes">
        </div>
        <div class="form-check mb-1 ms-1">
            <input class="form-check-input" type="checkbox" name="inactive" value="1" id="fInactive" <?php echo $f_inactive ? 'checked' : ''; ?> onchange="this.form.submit()">
            <label class="form-check-label small" for="fInactive">Show inactive</label>
        </div>
        <button type="submit" class="btn btn-outline-light btn-sm"><i class="bi bi-search"></i></button>
        <a href="tariff-catalog.php" class="btn btn-outline-secondary btn-sm">Clear</a>
    </form>

    <div class="table-container">
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>Service</th><th>Tariff name</th><th>Municipality</th><th class="text-center">N/A</th><th class="text-center">TOU</th>
                    <th class="text-center">Amps</th><th>Shown as / Billed as</th><th class="text-center">Tenants</th><th class="text-center">Active</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($catalog_rows)): ?>
                <tr><td colspan="10" class="text-center muted py-4"><?php echo $table_ok ? 'No tariffs found.' : 'The catalog is not installed.'; ?></td></tr>
            <?php endif; ?>
            <?php foreach ($catalog_rows as $r): $n_used = $used_by($r['service'], $r['tariff_name']); ?>
                <tr class="<?php echo $r['active'] ? '' : 'opacity-50'; ?>">
                    <td><?php echo $services[$r['service']] ?? htmlspecialchars($r['service']); ?></td>
                    <td class="text-white"><?php echo htmlspecialchars($r['tariff_name']); ?><?php if ($r['notes']): ?><div class="small muted"><?php echo htmlspecialchars($r['notes']); ?></div><?php endif; ?></td>
                    <td><?php echo htmlspecialchars((string)($r['municipality'] ?: '-')); ?></td>
                    <td class="text-center"><?php echo $yes($r['not_applicable']); ?></td>
                    <td class="text-center"><?php echo $r['is_tou'] ? '<span class="badge bg-info text-dark">' . htmlspecialchars($r['tou_algorithm'] ?: 'Default') . '</span>' : '<span class="text-secondary">-</span>'; ?></td>
                    <td class="text-center"><?php echo $yes($r['shows_amps']); ?></td>
                    <td class="small">
                        <?php echo $r['display_name'] ? 'Shown as: ' . htmlspecialchars($r['display_name']) . '<br>' : ''; ?>
                        <?php echo $r['calc_name'] ? 'Billed as: ' . htmlspecialchars($r['calc_name']) : ''; ?>
                    </td>
                    <td class="text-center"><?php echo $n_used ?: '<span class="text-secondary">0</span>'; ?></td>
                    <td class="text-center"><?php echo $yes($r['active']); ?></td>
                    <td class="text-end text-nowrap">
                        <?php if ($can_edit): ?>
                            <a href="tariff-catalog.php?edit=<?php echo (int)$r['tariff_id']; ?><?php echo $return_query ? '&amp;' . htmlspecialchars($return_query) : ''; ?>" class="btn btn-sm btn-outline-info py-0" title="Edit"><i class="bi bi-pencil"></i></a>
                        <?php endif; ?>
                        <?php if ($can_delete && $n_used === 0): ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Remove this tariff from the catalog?');">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="tariff_id" value="<?php echo (int)$r['tariff_id']; ?>">
                                <input type="hidden" name="return_query" value="<?php echo htmlspecialchars($return_query); ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger py-0" title="Remove"><i class="bi bi-trash3"></i></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    // Electricity-only fields
    (function () {
        const svc = document.getElementById('tcService');
        if (!svc) return;
        function toggle() {
            document.querySelectorAll('.elec-only').forEach(function (el) {
                el.style.display = (svc.value === 'electricity') ? '' : 'none';
            });
        }
        svc.addEventListener('change', toggle);
        toggle();
    })();
</script>
</body>
</html>