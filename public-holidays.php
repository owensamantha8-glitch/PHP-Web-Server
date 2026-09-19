<?php
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once '/var/www/Lynx/bootstrap.php';
lum_page('configs', 'view');

// =========================================================================
// LYNX UTILITY MANAGEMENT - PUBLIC HOLIDAYS
// Same folder as view-configs.php. Public holidays decide the Time Of Use
// periods on consumption slips, the financial report and back billing.
// =========================================================================

lum_use('reporting'); // lumZaPublicHolidays()
// Audit trail (switches itself off if the logger is missing)
lum_use('audit');

$can_edit = lum_can('configs', 'edit');
$can_delete = lum_can('configs', 'delete');
$user_name = substr((string)($_SESSION['user_name'] ?? 'system'), 0, 50);

$db = null;
$db_error = '';
try {
    $db = lum_db('information'); // bootstrap.php
    if (!$db) throw new RuntimeException('sys_db_information is not available');
    $db->query("SELECT 1 FROM lum_public_holidays LIMIT 1");
} catch (\Throwable $e) {
    error_log('LUM public holidays page: ' . $e->getMessage());
    $db = null;
    $db_error = 'The public holidays table is not available. Please run public-holidays-setup.sql.';
}

$message = $_SESSION['ph_message'] ?? null;
unset($_SESSION['ph_message']);
$flash = function ($type, $text) { $_SESSION['ph_message'] = ['type' => $type, 'text' => $text]; };
$valid_date = function ($d) {
    $dt = DateTime::createFromFormat('Y-m-d', (string)$d);
    return $dt && $dt->format('Y-m-d') === $d;
};
$audit = function ($action, $date, $name, $before, $after, $note = null) {
    if (!function_exists('lum_audit_log')) return;
    lum_audit_log($action, 'public_holiday', (int)str_replace('-', '', (string)$date), $date . ' ' . $name, null, $before, $after, $note);
};
$fetch = function ($date) use ($db) {
    $st = $db->prepare("SELECT holiday_date, holiday_name, is_observed FROM lum_public_holidays WHERE holiday_date = ?");
    $st->execute([$date]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
};

$year = (int)($_GET['year'] ?? $_POST['year'] ?? date('Y'));
if ($year < 2015 || $year > 2100) $year = (int)date('Y');
$back = 'public-holidays.php?year=' . $year;

// =========================================================================
// ACTIONS
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db) {
    lum_require_csrf();
    $action = (string)($_POST['action'] ?? '');
  try {

    if ($action === 'add' || $action === 'update') {
        lum_require_access('configs', 'edit');
        $date = (string)($_POST['holiday_date'] ?? '');
        $name = trim((string)($_POST['holiday_name'] ?? ''));
        $observed = !empty($_POST['is_observed']) ? 1 : 0;
        $old_date = (string)($_POST['old_date'] ?? '');

        if (!$valid_date($date)) {
            $flash('warning', 'Please enter a valid date.');
        } elseif ($name === '' || strlen($name) > 100) {
            $flash('warning', 'Please enter a name (up to 100 characters).');
        } elseif ($action === 'add') {
            if ($fetch($date)) {
                $flash('warning', date('d M Y', strtotime($date)) . ' is already a public holiday.');
            } else {
                $st = $db->prepare("INSERT INTO lum_public_holidays (holiday_date, holiday_name, is_observed, created_by_name) VALUES (?, ?, ?, ?)");
                $st->execute([$date, $name, $observed, $user_name]);
                $audit('INSERT', $date, $name, null, ['holiday_date' => $date, 'holiday_name' => $name, 'is_observed' => $observed]);
                $flash('success', 'Added ' . $name . ' on ' . date('d M Y', strtotime($date)) . '.');
                $back = 'public-holidays.php?year=' . substr($date, 0, 4);
            }
        } else {
            $before = $valid_date($old_date) ? $fetch($old_date) : null;
            if (!$before) {
                $flash('warning', 'The public holiday was not found.');
            } elseif ($date !== $old_date && $fetch($date)) {
                $flash('warning', date('d M Y', strtotime($date)) . ' is already a public holiday.');
            } else {
                $st = $db->prepare("UPDATE lum_public_holidays SET holiday_date = ?, holiday_name = ?, is_observed = ?, updated_by_name = ? WHERE holiday_date = ?");
                $st->execute([$date, $name, $observed, $user_name, $old_date]);
                $audit('UPDATE', $date, $name, $before, ['holiday_date' => $date, 'holiday_name' => $name, 'is_observed' => $observed]);
                $flash('success', 'Saved ' . $name . ' (' . date('d M Y', strtotime($date)) . ').');
                $back = 'public-holidays.php?year=' . substr($date, 0, 4);
            }
        }
    } elseif ($action === 'delete') {
        lum_require_access('configs', 'delete');
        $date = (string)($_POST['holiday_date'] ?? '');
        $before = $valid_date($date) ? $fetch($date) : null;
        if ($before) {
            $db->prepare("DELETE FROM lum_public_holidays WHERE holiday_date = ?")->execute([$date]);
            $audit('DELETE', $date, $before['holiday_name'], $before, null);
            $flash('success', 'Removed ' . $before['holiday_name'] . ' (' . date('d M Y', strtotime($date)) . ').');
        }
    } elseif ($action === 'generate') {
        lum_require_access('configs', 'edit');
        $gen_year = (int)($_POST['gen_year'] ?? 0);
        if ($gen_year < 2015 || $gen_year > 2100) {
            $flash('warning', 'Please choose a valid year.');
        } else {
            $added = 0;
            $st = $db->prepare("INSERT IGNORE INTO lum_public_holidays (holiday_date, holiday_name, is_observed, created_by_name) VALUES (?, ?, ?, ?)");
            foreach (lumZaPublicHolidays($gen_year) as $d => $h) {
                $st->execute([$d, $h['name'], $h['observed'], $user_name]);
                if ($st->rowCount() > 0) {
                    $added++;
                    $audit('INSERT', $d, $h['name'], null, ['holiday_date' => $d, 'holiday_name' => $h['name'], 'is_observed' => $h['observed']],
                           'South African public holidays added for ' . $gen_year);
                }
            }
            $flash($added ? 'success' : 'info', $added ? "Added $added public holiday(s) for $gen_year." : "All South African public holidays for $gen_year were already listed.");
            $back = 'public-holidays.php?year=' . $gen_year;
        }
    }
  } catch (\Throwable $e) {
    error_log('LUM public holidays save failed: ' . $e->getMessage());
    $flash('danger', 'The change could not be saved. Please try again or contact the system administrator.');
  }
    header('Location: ' . $back);
    exit();
}

// =========================================================================
// DATA
// =========================================================================
$holidays = [];
$years = [];
if ($db) {
    $st = $db->prepare("SELECT holiday_date, holiday_name, is_observed, created_by_name, created_at, updated_by_name, updated_at
                        FROM lum_public_holidays WHERE YEAR(holiday_date) = ? ORDER BY holiday_date");
    $st->execute([$year]);
    $holidays = $st->fetchAll(PDO::FETCH_ASSOC);
    $years = array_map('intval', $db->query("SELECT DISTINCT YEAR(holiday_date) FROM lum_public_holidays ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN));
}
$year_options = array_unique(array_merge($years, [(int)date('Y') - 1, (int)date('Y'), (int)date('Y') + 1, (int)date('Y') + 2, $year]));
sort($year_options);

// Standard South African holidays for the selected year that are not listed yet
$listed = array_column($holidays, 'holiday_date');
$missing = array_diff_key(lumZaPublicHolidays($year), array_flip($listed));
$next_year = (int)date('Y') + 1;
$next_year_missing = $db && !in_array($next_year, $years, true);

$edit_date = (string)($_GET['edit'] ?? '');
$editing = null;
foreach ($holidays as $h) if ($h['holiday_date'] === $edit_date) $editing = $h;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Public Holidays - Lynx Utility Management</title>
    <?php include LUM_ROOT . '/Layout/head-assets.php'; ?>
    <style>
        * { border-radius: 0 !important; }
        html { overflow-y: scroll; }
        body { background-color: #121212; color: #ffffff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        ::-webkit-scrollbar-track { background: #0a0a0a; }
        ::-webkit-scrollbar-thumb { background: #4a4a4a; }
        .action-bar { background-color: #0a0a0a; border-bottom: 1px solid #333333; padding: 10px 0; }
        .panel { background: linear-gradient(135deg, #222222, #2d2d2d); border: 1px solid #333333; padding: 24px; }
        .table-container { background-color: #000000; border: 1px solid #333333; }
        .table { margin-bottom: 0; color: #e0e0e0; }
        .table thead th { background-color: #1a1a1a; color: #aaaaaa; border-bottom: 2px solid #333; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.5px; padding: 10px 14px; }
        .table tbody td { border-bottom: 1px solid #222; padding: 10px 14px; vertical-align: middle; background-color: #000 !important; color: #ccc; }
        .table tbody tr:hover td { background-color: #111 !important; }
        .form-control, .form-select { background-color: #1a1a1a !important; border: 1px solid #444 !important; color: #fff !important; color-scheme: dark; }
        .form-control:focus, .form-select:focus { border-color: #e3000f !important; box-shadow: 0 0 0 0.25rem rgba(227, 0, 15, 0.25) !important; }
        .form-label { color: #ccc; font-size: 0.85rem; }
        .weekend { color: #ffc107; }
    </style>
</head>
<body>

<?php include LUM_ROOT . '/Layout/top-navbar.php'; ?>

<?php
$lum_sub_title = 'Public Holidays';
$lum_sub_back = 'view-configs.php';
ob_start();
?>
<form method="GET" class="d-flex align-items-center gap-2 m-0">
            <label class="small text-secondary">Year</label>
            <select name="year" class="form-select form-select-sm" style="width: 110px;" onchange="this.form.submit()">
                <?php foreach ($year_options as $yo): ?>
                    <option value="<?php echo $yo; ?>" <?php echo $yo === $year ? 'selected' : ''; ?>><?php echo $yo; ?><?php echo in_array($yo, $years, true) ? '' : ' (none)'; ?></option>
                <?php endforeach; ?>
            </select>
        </form>
<?php
$lum_sub_filters = ob_get_clean();
include LUM_ROOT . '/Layout/sub-navbar.php';
?>
<div class="container-fluid px-4 py-4">
    <?php if ($db_error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($db_error); ?></div><?php endif; ?>
    <?php if (!empty($message['text'])): ?>
        <div class="alert alert-<?php echo htmlspecialchars($message['type']); ?>"><?php echo htmlspecialchars($message['text']); ?></div>
    <?php endif; ?>
    <?php if ($next_year_missing): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-1"></i>No public holidays are listed for <?php echo $next_year; ?> yet. Time Of Use billing for <?php echo $next_year; ?> would treat every public holiday as a normal day.
            <?php if ($can_edit): ?><a href="public-holidays.php?year=<?php echo $next_year; ?>" class="alert-link">Add them now</a>.<?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- List -->
        <div class="col-lg-7">
            <div class="table-container">
                <table class="table">
                    <thead><tr><th>Date</th><th>Day</th><th>Public Holiday</th><th>Changed</th><th class="text-end"></th></tr></thead>
                    <tbody>
                    <?php if (empty($holidays)): ?>
                        <tr><td colspan="5" class="text-center text-secondary py-4">No public holidays listed for <?php echo $year; ?>.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($holidays as $h): $dow = (int)date('N', strtotime($h['holiday_date'])); ?>
                        <tr>
                            <td class="text-white text-nowrap"><?php echo date('d M Y', strtotime($h['holiday_date'])); ?></td>
                            <td class="<?php echo $dow >= 6 ? 'weekend' : ''; ?>"><?php echo date('l', strtotime($h['holiday_date'])); ?></td>
                            <td><?php echo htmlspecialchars($h['holiday_name']); ?><?php echo $h['is_observed'] ? ' <span class="badge bg-secondary">observed</span>' : ''; ?></td>
                            <td class="small text-secondary"><?php echo htmlspecialchars((string)($h['updated_by_name'] ?: $h['created_by_name'])); ?></td>
                            <td class="text-end text-nowrap">
                                <?php if ($can_edit): ?>
                                    <a href="public-holidays.php?year=<?php echo $year; ?>&amp;edit=<?php echo urlencode($h['holiday_date']); ?>" class="btn btn-sm btn-outline-info" title="Edit"><i class="bi bi-pencil"></i></a>
                                <?php endif; ?>
                                <?php if ($can_delete): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Remove <?php echo htmlspecialchars(addslashes($h['holiday_name']), ENT_QUOTES); ?> on <?php echo date('d M Y', strtotime($h['holiday_date'])); ?>?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="year" value="<?php echo $year; ?>">
                                        <input type="hidden" name="holiday_date" value="<?php echo htmlspecialchars($h['holiday_date']); ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove"><i class="bi bi-trash3"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Add / edit / generate -->
        <div class="col-lg-5">
            <?php if ($can_edit && $db): ?>
            <div class="panel mb-4">
                <h6 class="mb-3"><?php echo $editing ? 'Edit Public Holiday' : 'Add a Public Holiday'; ?></h6>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="<?php echo $editing ? 'update' : 'add'; ?>">
                    <input type="hidden" name="year" value="<?php echo $year; ?>">
                    <?php if ($editing): ?><input type="hidden" name="old_date" value="<?php echo htmlspecialchars($editing['holiday_date']); ?>"><?php endif; ?>
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label">Date</label>
                            <input type="date" name="holiday_date" class="form-control" required value="<?php echo htmlspecialchars($editing['holiday_date'] ?? ''); ?>">
                        </div>
                        <div class="col-md-7">
                            <label class="form-label">Name</label>
                            <input type="text" name="holiday_name" class="form-control" required maxlength="100" placeholder="e.g. Election Day" value="<?php echo htmlspecialchars($editing['holiday_name'] ?? ''); ?>">
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_observed" value="1" id="obs" <?php echo !empty($editing['is_observed']) ? 'checked' : ''; ?>>
                                <label class="form-check-label small text-secondary" for="obs">Observed day (the Monday after a Sunday holiday)</label>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <?php if ($editing): ?><a href="public-holidays.php?year=<?php echo $year; ?>" class="btn btn-outline-secondary btn-sm">Cancel</a><?php endif; ?>
                        <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-save me-1"></i><?php echo $editing ? 'Save' : 'Add'; ?></button>
                    </div>
                </form>
            </div>

            <?php if (!empty($missing)): ?>
            <!-- Standard public holidays of this year that are not listed yet -->
            <div class="panel">
                    <p class="small text-secondary mb-2">These standard public holidays are not listed yet:</p>
                    <ul class="small mb-3">
                        <?php foreach ($missing as $d => $h): ?>
                            <li><?php echo date('D d M Y', strtotime($d)); ?> &mdash; <?php echo htmlspecialchars($h['name']); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <form method="POST" class="m-0" onsubmit="return confirm('Add <?php echo count($missing); ?> public holiday(s) for <?php echo $year; ?>?');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="action" value="generate">
                        <input type="hidden" name="gen_year" value="<?php echo $year; ?>">
                        <button type="submit" class="btn btn-outline-light btn-sm"><i class="bi bi-calendar-plus me-1"></i>Add these <?php echo count($missing); ?> holidays</button>
                    </form>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

    <?php include LUM_ROOT . '/Layout/foot-assets.php'; ?>
</body>
</html>