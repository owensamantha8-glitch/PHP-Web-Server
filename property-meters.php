<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once '/var/www/Lynx/bootstrap.php';
lum_page('configs', 'view');

// =========================================================================
// LYNX UTILITY MANAGEMENT - PROPERTY METERS & DASHBOARD SETTINGS
// Location: /var/www/Lynx/Configs/property-meters.php (next to view-configs.php)
// Which meters the dashboard uses per property, what the dashboard shows,
// and the meter status limits.
// =========================================================================

lum_connect('meters', 'tenants'); // $meter_db_conn, $meter_crud, $tenant_db_conn, $tenant_crud
lum_use('reporting'); // Roles, lists and settings helpers
lum_use('slips'); // Property Billing Settings
// Audit trail (switches itself off if the logger is missing)
lum_use('audit');

$can_edit = lum_can('configs', 'edit');
$can_delete = lum_can('configs', 'delete');
$user_name = substr((string)($_SESSION['user_name'] ?? 'system'), 0, 50);
$roles = LUM_PROPERTY_METER_ROLES;
$colors = array_keys(LUM_PROPERTY_METER_COLORS);
$prop_db = lumSysDb('sys_db_properties');
$info_db = lumSysDb('sys_db_information');

$message = $_SESSION['pm_message'] ?? null;
unset($_SESSION['pm_message']);
$flash = function ($type, $text) { $_SESSION['pm_message'] = ['type' => $type, 'text' => $text]; };
$audit = function ($action, $entity, $id, $label, $property, $before, $after, $note = null) {
    if (function_exists('lum_audit_log')) lum_audit_log($action, $entity, $id, $label, $property, $before, $after, $note);
};

// What is installed?
$meters_ok = false;
try { $meter_db_conn->query("SELECT 1 FROM lum_property_meters LIMIT 1"); $meters_ok = true; } catch (\Throwable $e) {}
$dash_cols_ok = false;
try { $dash_cols_ok = $prop_db && (bool)$prop_db->query("SHOW COLUMNS FROM lum_properties LIKE 'dashboard_has_generator'")->fetch(); } catch (\Throwable $e) {}
$sys_ok = false;
try { $sys_ok = $info_db && $info_db->query("SELECT 1 FROM lum_system_settings LIMIT 1") !== false; } catch (\Throwable $e) {}

// Properties the user may manage
$properties = [];
try {
    // Properties come from the property register only (Configurations)
    if ($prop_db) $properties = $prop_db->query("SELECT Property FROM lum_properties")->fetchAll(PDO::FETCH_COLUMN);
} catch (\Throwable $e) {}
$properties = array_values(array_unique(array_filter(array_map('trim', $properties), 'strlen')));
sort($properties);
$allowed = lum_allowed_properties();
if ($allowed !== null) $properties = array_values(array_intersect($properties, $allowed));

$property = (string)($_GET['property'] ?? $_POST['property'] ?? '');
if (!in_array($property, $properties, true)) $property = $properties[0] ?? '';

// The property's common area calculations (labels), when the billing engine is available
function lumPropertySettingsSafe($property) {
    if (!function_exists('lumPropertySettings') || $property === '') return null;
    $ps = lumPropertySettings($property);
    return [
        'elec'  => defined('LUM_ELEC_COMMON_AREA_METHODS') ? (LUM_ELEC_COMMON_AREA_METHODS[$ps['elec_common_area_method']] ?? $ps['elec_common_area_method']) : $ps['elec_common_area_method'],
        'water' => defined('LUM_WATER_COMMON_AREA_METHODS') ? (LUM_WATER_COMMON_AREA_METHODS[$ps['water_common_area_method']] ?? $ps['water_common_area_method']) : $ps['water_common_area_method'],
    ];
}

$get_meter = function ($id) use ($meter_db_conn) {
    $st = $meter_db_conn->prepare("SELECT * FROM lum_property_meters WHERE id = ?");
    $st->execute([(int)$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
};
$meter_fields = function ($row) {
    return $row ? array_intersect_key($row, array_flip(['property', 'meter_serial', 'role', 'label', 'icon', 'color', 'sort_order', 'active', 'notes'])) : null;
};

// =========================================================================
// ACTIONS
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lum_require_csrf();
    $action = (string)($_POST['action'] ?? '');
    $back = 'property-meters.php?property=' . rawurlencode($property);
    try {
        if ($action === 'save_meter' && $meters_ok) {
            lum_require_access('configs', 'edit');
            lum_require_property($property);
            $id = (int)($_POST['id'] ?? 0);
            $row = [
                'property'     => $property,
                'meter_serial' => preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['meter_serial'] ?? '')),
                'role'         => (string)($_POST['role'] ?? ''),
                'label'        => substr(trim((string)($_POST['label'] ?? '')), 0, 100),
                'icon'         => preg_replace('/[^a-z0-9\-]/', '', strtolower(trim((string)($_POST['icon'] ?? '')))),
                'color'        => in_array($_POST['color'] ?? '', $colors, true) ? $_POST['color'] : '',
                'sort_order'   => max(0, min(999, (int)($_POST['sort_order'] ?? 10))),
                'active'       => !empty($_POST['active']) ? 1 : 0,
                'notes'        => substr(trim((string)($_POST['notes'] ?? '')), 0, 255),
            ];
            $row['icon'] = substr($row['icon'], 0, 40);
            $before = $id ? $get_meter($id) : null;
            if ($row['meter_serial'] === '' || strlen($row['meter_serial']) > 30) {
                $flash('warning', 'Please enter the meter serial number.');
            } elseif (!isset($roles[$row['role']])) {
                $flash('warning', 'Please choose the meter role.');
            } elseif ($id && (!$before || $before['property'] !== $property)) {
                $flash('warning', 'The meter was not found for this property.');
            } else {
                $cols = array_keys($row);
                if ($before) {
                    $sets = implode(', ', array_map(function ($c) { return "`$c` = ?"; }, $cols));
                    $meter_db_conn->prepare("UPDATE lum_property_meters SET $sets, updated_by_name = ? WHERE id = ?")->execute(array_merge(array_values($row), [$user_name, $id]));
                    $audit('UPDATE', 'property_meters', $id, $row['meter_serial'] . ' (' . $row['role'] . ')', $property, $meter_fields($before), $meter_fields($get_meter($id)));
                    $flash('success', 'Saved meter ' . $row['meter_serial'] . '.');
                } else {
                    $meter_db_conn->prepare("INSERT INTO lum_property_meters (" . implode(', ', $cols) . ", created_by_name) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ", ?)")
                                  ->execute(array_merge(array_values($row), [$user_name]));
                    $new_id = (int)$meter_db_conn->lastInsertId();
                    $audit('INSERT', 'property_meters', $new_id, $row['meter_serial'] . ' (' . $row['role'] . ')', $property, null, $meter_fields($get_meter($new_id)));
                    $flash('success', 'Added meter ' . $row['meter_serial'] . ' as ' . $roles[$row['role']] . '.');
                }
            }
        } elseif ($action === 'delete_meter' && $meters_ok) {
            lum_require_access('configs', 'delete');
            lum_require_property($property);
            $before = $get_meter((int)($_POST['id'] ?? 0));
            if ($before && $before['property'] === $property) {
                $meter_db_conn->prepare("DELETE FROM lum_property_meters WHERE id = ?")->execute([(int)$before['id']]);
                $audit('DELETE', 'property_meters', (int)$before['id'], $before['meter_serial'] . ' (' . $before['role'] . ')', $property, $meter_fields($before), null);
                $flash('success', 'Removed meter ' . $before['meter_serial'] . ' (' . ($roles[$before['role']] ?? $before['role']) . ').');
            }
        } elseif ($action === 'save_dashboard' && $dash_cols_ok) {
            lum_require_access('configs', 'edit');
            lum_require_property($property);
            $tenant_obis_col_ok = false;
            try { $tenant_obis_col_ok = (bool)$prop_db->query("SHOW COLUMNS FROM lum_properties LIKE 'dashboard_tenant_obis'")->fetch(); } catch (\Throwable $e) {}
            $st = $prop_db->prepare("SELECT id, dashboard_elec_obis, " . ($tenant_obis_col_ok ? "dashboard_tenant_obis, " : "") . "dashboard_has_generator, dashboard_tenant_cards, dashboard_total_line, dashboard_auto_meters, dashboard_flow_title
                                     FROM lum_properties WHERE Property = ? LIMIT 1");
            $st->execute([$property]);
            $before = $st->fetch(PDO::FETCH_ASSOC);
            if (!$before) {
                $flash('warning', 'This property is not in the property register, so it keeps the standard dashboard settings. Add it on the Property screen first.');
            } else {
                $values = [
                    'dashboard_elec_obis'     => (($_POST['dashboard_elec_obis'] ?? '') === '1.1.1.8.1') ? '1.1.1.8.1' : '1.1.1.8.0',
                    'dashboard_has_generator' => !empty($_POST['dashboard_has_generator']) ? 1 : 0,
                    'dashboard_tenant_cards'  => !empty($_POST['dashboard_tenant_cards']) ? 1 : 0,
                    'dashboard_total_line'    => !empty($_POST['dashboard_total_line']) ? 1 : 0,
                    'dashboard_auto_meters'   => !empty($_POST['dashboard_auto_meters']) ? 1 : 0,
                    'dashboard_flow_title'    => substr(trim((string)($_POST['dashboard_flow_title'] ?? '')), 0, 100),
                ];
                if ($tenant_obis_col_ok) {
                    $values['dashboard_tenant_obis'] = (($_POST['dashboard_tenant_obis'] ?? '') === '1.1.1.8.1') ? '1.1.1.8.1' : '1.1.1.8.0';
                }
                $sets = implode(', ', array_map(function ($c) { return "`$c` = ?"; }, array_keys($values)));
                $prop_db->prepare("UPDATE lum_properties SET $sets WHERE id = ?")->execute(array_merge(array_values($values), [(int)$before['id']]));
                $prop_id = (int)$before['id'];
                unset($before['id']);
                $audit('UPDATE', 'property', $prop_id, $property, $property, $before, $values, 'Dashboard settings');
                $flash('success', 'Saved the dashboard settings of ' . $property . '.');
            }
        } elseif ($action === 'save_system' && $sys_ok) {
            lum_require_access('configs', 'edit');
            $online = (float)str_replace(',', '.', (string)($_POST['meter_online_hours'] ?? ''));
            $lag = (float)str_replace(',', '.', (string)($_POST['meter_lag_hours'] ?? ''));
            if ($online <= 0 || $lag <= $online || $lag > 8760) {
                $flash('warning', 'The "Data Lag" limit must be higher than the "Online" limit (both in hours).');
            } else {
                $before = ['meter_online_hours' => lumSystemSetting('meter_online_hours'), 'meter_lag_hours' => lumSystemSetting('meter_lag_hours')];
                $after = ['meter_online_hours' => rtrim(rtrim(number_format($online, 2, '.', ''), '0'), '.'), 'meter_lag_hours' => rtrim(rtrim(number_format($lag, 2, '.', ''), '0'), '.')];
                $st = $info_db->prepare("INSERT INTO lum_system_settings (setting_key, setting_value, updated_by_name) VALUES (?, ?, ?)
                                         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by_name = VALUES(updated_by_name)");
                foreach ($after as $k => $v) $st->execute([$k, $v, $user_name]);
                if ($before != $after) $audit('UPDATE', 'system_settings', 1, 'Meter status limits', null, $before, $after);
                $flash('success', 'Saved the meter status limits.');
            }
        }
    } catch (\Throwable $e) {
        error_log('LUM property meters save failed: ' . $e->getMessage());
        $flash('danger', (strpos($e->getMessage(), 'Duplicate') !== false)
            ? 'This meter already has that role for this property.'
            : 'The change could not be saved. Please try again or contact the system administrator.');
    }
    header('Location: ' . $back);
    exit();
}

// =========================================================================
// DATA
// =========================================================================
$meters = [];
if ($meters_ok && $property !== '') {
    $st = $meter_db_conn->prepare("SELECT * FROM lum_property_meters WHERE property = ? ORDER BY FIELD(role, 'grid', 'solar', 'elec_check', 'water_main', 'flow_in', 'flow_out', 'ca_elec_main', 'ca_elec_less', 'ca_water_main'), sort_order, id");
    $st->execute([$property]);
    $meters = $st->fetchAll(PDO::FETCH_ASSOC);
} elseif ($property !== '') {
    foreach (lumPropertyMeterRows() as $r) if ($r['property'] === $property) $meters[] = $r + ['id' => 0, 'active' => 1, 'notes' => ''];
}

// Meters in the meter register for this property (suggestions and checks)
$register = [];
try {
    $st = $meter_db_conn->prepare("SELECT meter_serial, meter_type FROM lum_meters WHERE meter_property = ? ORDER BY meter_serial");
    $st->execute([$property]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) $register[trim((string)$m['meter_serial'])] = (string)$m['meter_type'];
} catch (\Throwable $e) {}
$registered_anywhere = function ($serial) use ($meter_db_conn) {
    static $cache = [];
    if (!isset($cache[$serial])) {
        $st = $meter_db_conn->prepare("SELECT meter_property FROM lum_meters WHERE meter_serial = ? LIMIT 1");
        $st->execute([$serial]);
        $cache[$serial] = $st->fetchColumn();
    }
    return $cache[$serial];
};

$dash = lumDashboardSettings($property);
$editing = null;
if ($can_edit && $meters_ok && !empty($_GET['edit'])) {
    $editing = $get_meter((int)$_GET['edit']);
    if ($editing && $editing['property'] !== $property) $editing = null;
}
$form = $editing ?? ['id' => 0, 'meter_serial' => '', 'role' => 'grid', 'label' => '', 'icon' => '', 'color' => '', 'sort_order' => 10, 'active' => 1, 'notes' => ''];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Property Meters - Lynx Utility Management</title>
    <?php include LUM_ROOT . '/Layout/head-assets.php'; ?>
    <style>
        * { border-radius: 0 !important; }
        html { overflow-y: scroll; }
        body { background-color: #121212; color: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .action-bar { background-color: #0a0a0a; border-bottom: 1px solid #333; padding: 10px 0; }
        .panel { background: linear-gradient(135deg, #222, #2d2d2d); border: 1px solid #333; padding: 20px; }
        .table-container { background-color: #000; border: 1px solid #333; overflow-x: auto; }
        .table { margin-bottom: 0; color: #e0e0e0; }
        .table thead th { background-color: #1a1a1a; color: #aaa; border-bottom: 2px solid #333; font-size: 0.8rem; padding: 8px 12px; white-space: nowrap; }
        .table tbody td { border-bottom: 1px solid #222; padding: 8px 12px; vertical-align: middle; background-color: #000 !important; color: #ccc; }
        .form-control, .form-select { background-color: #1a1a1a !important; border: 1px solid #444 !important; color: #fff !important; }
        .form-control:focus, .form-select:focus { border-color: #e3000f !important; box-shadow: 0 0 0 0.25rem rgba(227, 0, 15, 0.25) !important; }
        .form-label { color: #ccc; font-size: 0.85rem; margin-bottom: 0.2rem; }
    </style>
</head>
<body>

<?php include LUM_ROOT . '/Layout/top-navbar.php'; ?>

<?php
$lum_sub_title = 'Property Meters & Dashboard';
$lum_sub_back = 'view-configs.php';
ob_start();
?>
<form method="GET" class="d-flex align-items-center gap-2 m-0">
            <label class="small text-secondary">Property</label>
            <select name="property" class="form-select form-select-sm" style="min-width: 220px;" onchange="this.form.submit()">
                <?php foreach ($properties as $p): ?><option value="<?php echo htmlspecialchars($p); ?>" <?php echo $p === $property ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option><?php endforeach; ?>
            </select>
        </form>
<?php
$lum_sub_filters = ob_get_clean();
include LUM_ROOT . '/Layout/sub-navbar.php';
?>
<div class="container-fluid px-4 py-4">
    <?php if (!$meters_ok || !$dash_cols_ok || !$sys_ok): ?>
        <div class="alert alert-warning">Not everything is installed yet (run <strong>property-meters-setup.sql</strong>). The dashboard uses its built-in lists and settings until then; they are shown here but cannot be changed.</div>
    <?php endif; ?>
    <?php if (!empty($message['text'])): ?><div class="alert alert-<?php echo htmlspecialchars($message['type']); ?>"><?php echo htmlspecialchars($message['text']); ?></div><?php endif; ?>

    <div class="row g-4">
        <!-- Meters -->
        <div class="col-xl-8">
            <div class="table-container mb-3">
                <table class="table table-sm">
                    <thead><tr><th>Role</th><th>Meter</th><th>Label</th><th>Diagram</th><th class="text-center">Order</th><th class="text-center">Active</th><th></th></tr></thead>
                    <tbody>
                    <?php if (empty($meters)): ?>
                        <tr><td colspan="7" class="text-center muted py-4">No meters set up for this property<?php echo $dash['auto_meters'] ? ' (the dashboard takes its meters from the meter register)' : ''; ?>.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($meters as $m): $serial = trim((string)$m['meter_serial']); $reg_prop = isset($register[$serial]) ? $property : $registered_anywhere($serial); ?>
                        <tr class="<?php echo empty($m['active']) ? 'opacity-50' : ''; ?>">
                            <td class="small"><?php echo htmlspecialchars($roles[$m['role']] ?? $m['role']); ?></td>
                            <td class="text-white text-nowrap">
                                <?php echo htmlspecialchars($serial); ?>
                                <?php if ($reg_prop === false): ?>
                                    <i class="bi bi-exclamation-triangle text-warning ms-1" title="Not in the meter register"></i>
                                <?php elseif ($reg_prop !== $property): ?>
                                    <i class="bi bi-info-circle text-info ms-1" title="Registered at <?php echo htmlspecialchars((string)$reg_prop); ?>"></i>
                                <?php endif; ?>
                                <?php if (isset($register[$serial])): ?><div class="small muted"><?php echo htmlspecialchars($register[$serial]); ?></div><?php endif; ?>
                            </td>
                            <td class="small"><?php echo htmlspecialchars((string)$m['label']); ?><?php if (!empty($m['notes'])): ?><div class="muted"><?php echo htmlspecialchars($m['notes']); ?></div><?php endif; ?></td>
                            <td class="small">
                                <?php if (in_array($m['role'], ['flow_in', 'flow_out'], true)): $c = isset(LUM_PROPERTY_METER_COLORS[$m['color']]) ? $m['color'] : ($m['role'] === 'flow_out' ? 'success' : 'info'); ?>
                                    <i class="bi bi-<?php echo htmlspecialchars($m['icon'] ?: ($m['role'] === 'flow_out' ? 'speedometer' : 'droplet')); ?> text-<?php echo $c; ?> fs-5"></i>
                                <?php else: ?><span class="muted">-</span><?php endif; ?>
                            </td>
                            <td class="text-center"><?php echo (int)$m['sort_order']; ?></td>
                            <td class="text-center"><?php echo !empty($m['active']) ? '<i class="bi bi-check-lg text-success"></i>' : '<span class="muted">-</span>'; ?></td>
                            <td class="text-end text-nowrap">
                                <?php if ($can_edit && $meters_ok): ?>
                                    <a href="property-meters.php?property=<?php echo rawurlencode($property); ?>&amp;edit=<?php echo (int)$m['id']; ?>" class="btn btn-sm btn-outline-info py-0" title="Edit"><i class="bi bi-pencil"></i></a>
                                <?php endif; ?>
                                <?php if ($can_delete && $meters_ok): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Remove meter <?php echo htmlspecialchars(addslashes($serial), ENT_QUOTES); ?> (<?php echo htmlspecialchars(addslashes($roles[$m['role']] ?? $m['role']), ENT_QUOTES); ?>) from this property?<?php echo strpos($m['role'], 'ca_') === 0 ? ' This changes the common area billed to tenants.' : ''; ?>');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="action" value="delete_meter">
                                        <input type="hidden" name="property" value="<?php echo htmlspecialchars($property); ?>">
                                        <input type="hidden" name="id" value="<?php echo (int)$m['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger py-0" title="Remove"><i class="bi bi-trash3"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="mb-4"></div>

            <?php if ($can_edit && $meters_ok): ?>
            <div class="panel">
                <h6 class="mb-3"><?php echo $editing ? 'Edit Meter ' . htmlspecialchars($editing['meter_serial']) : 'Add a Meter'; ?></h6>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="save_meter">
                    <input type="hidden" name="property" value="<?php echo htmlspecialchars($property); ?>">
                    <input type="hidden" name="id" value="<?php echo (int)$form['id']; ?>">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Meter serial</label>
                            <input type="text" name="meter_serial" class="form-control form-control-sm" maxlength="30" required list="registerMeters" value="<?php echo htmlspecialchars((string)$form['meter_serial']); ?>">
                            <datalist id="registerMeters">
                                <?php foreach ($register as $rs => $rt): ?><option value="<?php echo htmlspecialchars($rs); ?>"><?php echo htmlspecialchars($rt); ?></option><?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Role</label>
                            <select name="role" id="pmRole" class="form-select form-select-sm">
                                <?php foreach ($roles as $rk => $rl): ?><option value="<?php echo $rk; ?>" <?php echo $form['role'] === $rk ? 'selected' : ''; ?>><?php echo htmlspecialchars($rl); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Order</label>
                            <input type="number" name="sort_order" class="form-control form-control-sm" min="0" max="999" value="<?php echo (int)$form['sort_order']; ?>">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <div class="form-check"><input class="form-check-input" type="checkbox" name="active" value="1" id="pmActive" <?php echo !empty($form['active']) ? 'checked' : ''; ?>><label class="form-check-label small" for="pmActive">Active</label></div>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Label (e.g. Borehole 2 meter (outgoing))</label>
                            <input type="text" name="label" class="form-control form-control-sm" maxlength="100" value="<?php echo htmlspecialchars((string)$form['label']); ?>">
                        </div>
                        <div class="col-md-3 flow-only">
                            <label class="form-label">Diagram icon (<a href="https://icons.getbootstrap.com/" target="_blank" rel="noopener" class="text-info">Bootstrap icon</a> name)</label>
                            <input type="text" name="icon" class="form-control form-control-sm" maxlength="40" placeholder="e.g. building" value="<?php echo htmlspecialchars((string)$form['icon']); ?>">
                        </div>
                        <div class="col-md-2 flow-only">
                            <label class="form-label">Diagram colour</label>
                            <select name="color" class="form-select form-select-sm">
                                <option value="">Default</option>
                                <?php foreach ($colors as $c): ?><option value="<?php echo $c; ?>" <?php echo $form['color'] === $c ? 'selected' : ''; ?>><?php echo ucfirst($c); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <input type="text" name="notes" class="form-control form-control-sm" maxlength="255" value="<?php echo htmlspecialchars((string)$form['notes']); ?>">
                        </div>
                    </div>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <?php if ($editing): ?><a href="property-meters.php?property=<?php echo rawurlencode($property); ?>" class="btn btn-outline-secondary btn-sm">Cancel</a><?php endif; ?>
                        <button type="submit" class="btn btn-danger btn-sm px-3"><i class="bi bi-save me-1"></i><?php echo $editing ? 'Save' : 'Add'; ?></button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <!-- Dashboard settings -->
        <div class="col-xl-4">
            <div class="panel mb-4">
                <h6 class="mb-3">Dashboard &mdash; <?php echo htmlspecialchars($property); ?></h6>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="save_dashboard">
                    <input type="hidden" name="property" value="<?php echo htmlspecialchars($property); ?>">
                    <?php $dd = ($can_edit && $dash_cols_ok) ? '' : 'disabled'; ?>
                    <?php
                        $dash_grid_obis = $dash['grid_obis'] ?? $dash['elec_obis'];
                        $dash_tenant_obis = $dash['tenant_obis'] ?? $dash_grid_obis;
                        $dash_tenant_col_ok = false;
                        try { $dash_tenant_col_ok = $prop_db && (bool)$prop_db->query("SHOW COLUMNS FROM lum_properties LIKE 'dashboard_tenant_obis'")->fetch(); } catch (\Throwable $e) {}
                    ?>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label">OBIS for Grid</label>
                            <select name="dashboard_elec_obis" class="form-select form-select-sm" <?php echo $dd; ?>>
                                <option value="1.1.1.8.0" <?php echo $dash_grid_obis !== '1.1.1.8.1' ? 'selected' : ''; ?>>1.1.1.8.0</option>
                                <option value="1.1.1.8.1" <?php echo $dash_grid_obis === '1.1.1.8.1' ? 'selected' : ''; ?>>1.1.1.8.1</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">OBIS for Tenant Electrical Usage</label>
                            <select name="dashboard_tenant_obis" class="form-select form-select-sm" <?php echo ($dd !== '' || !$dash_tenant_col_ok) ? 'disabled' : ''; ?>>
                                <option value="1.1.1.8.0" <?php echo $dash_tenant_obis !== '1.1.1.8.1' ? 'selected' : ''; ?>>1.1.1.8.0</option>
                                <option value="1.1.1.8.1" <?php echo $dash_tenant_obis === '1.1.1.8.1' ? 'selected' : ''; ?>>1.1.1.8.1</option>
                            </select>
                            <?php if (!$dash_tenant_col_ok): ?><div class="small muted">Run dashboard-tenant-obis-setup.sql to set this separately.</div><?php endif; ?>
                        </div>
                    </div>
                    <div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="dashboard_has_generator" value="1" id="dGen" <?php echo $dash['has_generator'] ? 'checked' : ''; ?> <?php echo $dd; ?>><label class="form-check-label small" for="dGen">Show generator usage</label></div>
                    <div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="dashboard_tenant_cards" value="1" id="dCards" <?php echo $dash['tenant_cards'] ? 'checked' : ''; ?> <?php echo $dd; ?>><label class="form-check-label small" for="dCards">Show the Tenant &amp; Common Area cards</label></div>
                    <div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="dashboard_total_line" value="1" id="dTotal" <?php echo $dash['total_line'] ? 'checked' : ''; ?> <?php echo $dd; ?>><label class="form-check-label small" for="dTotal">Show "Total Property Usage" on the graph</label></div>
                    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="dashboard_auto_meters" value="1" id="dAuto" <?php echo $dash['auto_meters'] ? 'checked' : ''; ?> <?php echo $dd; ?>><label class="form-check-label small" for="dAuto">Also use every meter of the property in the meter register (Solar = solar, Electrical = grid, Water = water graph)</label></div>
                    <div class="mb-2">
                        <label class="form-label">Water flow diagram title</label>
                        <input type="text" name="dashboard_flow_title" class="form-control form-control-sm" maxlength="100" placeholder="Water Flow Overview" value="<?php echo htmlspecialchars($dash['flow_title']); ?>" <?php echo $dd; ?>>
                    </div>
                    <?php if ($dash['source'] === 'legacy' && $dash_cols_ok): ?>
                        <div class="small text-warning mb-2">This property is not in the property register: the standard dashboard settings apply.</div>
                    <?php endif; ?>
                    <?php if ($can_edit && $dash_cols_ok): ?>
                        <div class="text-end"><button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-save me-1"></i>Save</button></div>
                    <?php endif; ?>
                </form>
            </div>

            <div class="panel">
                <h6 class="mb-3">Meter Status (all properties)</h6>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="save_system">
                    <input type="hidden" name="property" value="<?php echo htmlspecialchars($property); ?>">
                    <?php $sd = ($can_edit && $sys_ok) ? '' : 'disabled'; ?>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label">Online if the last reading is at most</label>
                            <div class="input-group input-group-sm"><input type="number" step="0.5" min="0.5" name="meter_online_hours" class="form-control" value="<?php echo htmlspecialchars((string)lumSystemSetting('meter_online_hours', 6)); ?>" <?php echo $sd; ?>><span class="input-group-text bg-dark text-white border-secondary">hours old</span></div>
                        </div>
                        <div class="col-6">
                            <label class="form-label">"Data Lag" up to</label>
                            <div class="input-group input-group-sm"><input type="number" step="0.5" min="1" name="meter_lag_hours" class="form-control" value="<?php echo htmlspecialchars((string)lumSystemSetting('meter_lag_hours', 72)); ?>" <?php echo $sd; ?>><span class="input-group-text bg-dark text-white border-secondary">hours</span></div>
                        </div>
                    </div>
                    <div class="small muted mt-2">Older readings show the meter as Offline.</div>
                    <?php if ($can_edit && $sys_ok): ?>
                        <div class="text-end mt-2"><button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-save me-1"></i>Save</button></div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    // Icon and colour only matter for the water flow diagram
    (function () {
        const role = document.getElementById('pmRole');
        if (!role) return;
        function toggle() {
            const flow = (role.value === 'flow_in' || role.value === 'flow_out');
            document.querySelectorAll('.flow-only').forEach(function (el) { el.style.display = flow ? '' : 'none'; });
        }
        role.addEventListener('change', toggle);
        toggle();
    })();
</script>
</body>
</html>