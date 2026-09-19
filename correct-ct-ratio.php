<?php
// Correcting a meter's CT ratio, for now or for the past.
// A ratio that was captured wrongly from the start is not the same as a ratio that genuinely
// changed when a meter was re-programmed: the first corrects the history, the second starts
// a new period. Both are done from here, deliberately, and both are recorded.
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once '/var/www/Lynx/bootstrap.php';
lum_page('tenants', 'view');
lum_use('audit');
ini_set('pcre.jit', '0');

$pdo = lum_db('tenants');
$can_edit = lum_can('tenants', 'edit');
$errors = [];
$notices = [];

// --- What is being looked at ----------------------------------------------
$property = trim((string)($_REQUEST['property'] ?? ''));
$tenant_ref = trim((string)($_REQUEST['tenant_ref'] ?? ''));

$properties = $pdo->query("SELECT DISTINCT tenant_property FROM lum_tenants WHERE tenant_property <> '' ORDER BY tenant_property")->fetchAll(PDO::FETCH_COLUMN);
if (!empty($_SESSION['assigned_properties'])) {
    $allowed = array_map('trim', explode(',', $_SESSION['assigned_properties']));
    $properties = array_values(array_intersect($properties, $allowed));
    if ($property !== '' && !in_array($property, $allowed, true)) { $property = ''; $tenant_ref = ''; }
}

// --- Applying a correction -------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'correct') {
    lum_require_access('tenants', 'edit');
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        die('Security Error: Invalid CSRF Token. Request Blocked.');
    }

    $link_id = (int)($_POST['link_id'] ?? 0);
    $new_ct = (float)str_replace(',', '.', (string)($_POST['new_ct'] ?? 0));
    $how = (string)($_POST['how'] ?? '');
    $from_date = trim((string)($_POST['from_date'] ?? ''));
    $reason = trim((string)($_POST['reason'] ?? ''));

    $row = null;
    if ($link_id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM lum_tenant_meters WHERE link_id = :id");
        $stmt->execute(['id' => $link_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        $errors[] = 'That assignment was not found.';
    } elseif ($new_ct <= 0) {
        $errors[] = 'The CT ratio must be more than zero.';
    } elseif ($reason === '') {
        $errors[] = 'Please say why it is being corrected. It appears on the assignment and in the audit trail.';
    } elseif (!empty($_SESSION['assigned_properties'])
              && !in_array($row['property'], array_map('trim', explode(',', $_SESSION['assigned_properties'])), true)) {
        $errors[] = 'You do not have access to that property.';
    } elseif ($how === 'from_date') {
        $d = DateTime::createFromFormat('Y-m-d', $from_date);
        if (!$d || $d->format('Y-m-d') !== $from_date) {
            $errors[] = 'The date must be as YYYY-MM-DD.';
        } elseif (!empty($row['valid_to']) && $from_date > $row['valid_to']) {
            $errors[] = 'That date is after this meter stopped serving the tenant.';
        }
    } elseif ($how !== 'always') {
        $errors[] = 'Please choose whether the ratio was always wrong or changed on a date.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            $old_ct = (float)$row['ct_ratio'];
            $who = $_SESSION['user_name'] ?? null;

            if ($how === 'always' || (!empty($row['valid_from']) && $from_date <= $row['valid_from'])) {
                // The ratio was wrong from the start: correct this period, and every earlier
                // period of the same meter and tenant, so the history is consistent.
                $upd = $pdo->prepare("UPDATE lum_tenant_meters
                                         SET ct_ratio = :ct, updated_at = NOW(),
                                             notes = TRIM(CONCAT(COALESCE(notes, ''), ' [CT ratio corrected from ',
                                                     :old, ' to ', :ct2, ' on ', CURDATE(), ': ', :why, ']'))
                                       WHERE tenant_ref = :ref AND meter_serial = :serial
                                         AND (valid_from IS NULL OR valid_from <= COALESCE(:upto, valid_from))");
                $upd->execute([
                    'ct' => $new_ct, 'old' => $old_ct, 'ct2' => $new_ct, 'why' => $reason,
                    'ref' => $row['tenant_ref'], 'serial' => $row['meter_serial'],
                    'upto' => !empty($row['valid_to']) ? $row['valid_to'] : null,
                ]);
                $changed = $upd->rowCount();
                $notices[] = 'The CT ratio of meter ' . $row['meter_serial'] . ' was corrected from '
                           . rtrim(rtrim(number_format($old_ct, 2), '0'), '.') . ' to '
                           . rtrim(rtrim(number_format($new_ct, 2), '0'), '.') . ' for ' . $changed . ' period(s).';
            } else {
                // The ratio changed on a date: the old period ends the day before, and a new
                // period starts with the new ratio.
                $day_before = date('Y-m-d', strtotime($from_date . ' -1 day'));
                $close = $pdo->prepare("UPDATE lum_tenant_meters SET valid_to = :d, updated_at = NOW(),
                                               notes = TRIM(CONCAT(COALESCE(notes, ''), ' [CT ratio ', :old,
                                                       ' until this date: ', :why, ']'))
                                         WHERE link_id = :id");
                $close->execute(['d' => $day_before, 'old' => $old_ct, 'why' => $reason, 'id' => $link_id]);

                $add = $pdo->prepare("INSERT INTO lum_tenant_meters
                        (tenant_ref, tenant_id, property, meter_serial, meter_kind, slot, ct_ratio,
                         valid_from, valid_to, source, confidence, created_by, notes)
                        VALUES (:ref, :tid, :prop, :serial, :kind, :slot, :ct, :from, :to, 'manual', 'high', :who, :note)");
                $add->execute([
                    'ref' => $row['tenant_ref'], 'tid' => $row['tenant_id'], 'prop' => $row['property'],
                    'serial' => $row['meter_serial'], 'kind' => $row['meter_kind'], 'slot' => $row['slot'],
                    'ct' => $new_ct, 'from' => $from_date, 'to' => $row['valid_to'] ?: null, 'who' => $who,
                    'note' => 'CT ratio ' . $new_ct . ' from ' . $from_date . ': ' . $reason,
                ]);
                $notices[] = 'Meter ' . $row['meter_serial'] . ' now runs at ' . $new_ct . ' from ' . $from_date
                           . ', and at ' . $old_ct . ' before it.';
            }

            // The tenant profile keeps the ratio in use now
            if (empty($row['valid_to']) && $row['meter_kind'] === 'electricity') {
                $slot = max(1, min(3, (int)$row['slot']));
                $col = 'tenant_electricalMeter_0' . $slot;
                $p = $pdo->prepare("UPDATE lum_tenants SET {$col}_ct_ratio = :ct
                                     WHERE tenant_ref = :ref AND CAST({$col} AS CHAR) = :serial");
                $p->execute(['ct' => $new_ct, 'ref' => $row['tenant_ref'], 'serial' => $row['meter_serial']]);
                if ($p->rowCount() > 0) $notices[] = 'The tenant profile was updated to match.';
            }

            $pdo->commit();

            if (function_exists('lum_audit_log')) {
                lum_audit_log('UPDATE', 'meter_assignment', $link_id,
                              $row['meter_serial'] . ' (' . $row['tenant_ref'] . ')', $row['property'],
                              ['ct_ratio' => $old_ct], ['ct_ratio' => $new_ct],
                              ($how === 'always' ? 'was always wrong' : 'changed on ' . $from_date) . ': ' . $reason);
            }
            $tenant_ref = $row['tenant_ref'];
            $property = $row['property'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('LUM CT ratio correction failed: ' . $e->getMessage());
            $errors[] = 'The correction was not made. The error has been logged.';
        }
    }
}

// --- What to show ----------------------------------------------------------
$tenants = [];
if ($property !== '') {
    $stmt = $pdo->prepare("SELECT tenant_ref, tenant_id, tenant_name, tenant_shop FROM lum_tenants
                            WHERE tenant_property = :p ORDER BY tenant_name");
    $stmt->execute(['p' => $property]);
    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$assignments = [];
$tenant_row = null;
if ($tenant_ref !== '') {
    $stmt = $pdo->prepare("SELECT * FROM lum_tenants WHERE tenant_ref = :r LIMIT 1");
    $stmt->execute(['r' => $tenant_ref]);
    $tenant_row = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM lum_tenant_meters WHERE tenant_ref = :r
                            ORDER BY meter_kind, slot, COALESCE(valid_from, '1970-01-01')");
    $stmt->execute(['r' => $tenant_ref]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf_token = $_SESSION['csrf_token'];

// Months already billed that a correction to these periods would affect: the slip changes,
// but the journal does not - that difference is what back billing is for.
$billed_months = [];
if ($tenant_row && !empty($tenant_row['tenant_id'])) {
    try {
        $j = lum_db('journal');
        $stmt = $j->prepare("SELECT l.period_start, l.period_end, l.kwh, l.elec_total, j.billing_label, j.status
                               FROM lum_journal_lines l
                               JOIN lum_journal j ON j.journal_id = l.journal_id
                              WHERE l.tenant_id = :tid ORDER BY l.period_start DESC LIMIT 12");
        $stmt->execute(['tid' => $tenant_row['tenant_id']]);
        $billed_months = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Correct a CT Ratio | Lynx Utility Management</title>
    <?php include LUM_ROOT . '/Layout/head-assets.php'; ?>
<style>
        body { background: #121212; color: #e0e0e0; }
        .card { background: #1a1a1a; border: 1px solid #2c2c2c; }
        .table > :not(caption) > * > * { background: #1a1a1a; color: #e0e0e0; border-color: #2c2c2c; }
        .closed td { opacity: 0.75; }
    </style>
</head>
<body>
<?php include LUM_ROOT . '/Layout/top-navbar.php'; ?>

<?php
$lum_sub_title = 'CT Ratios';
$lum_sub_back = 'https://lynx-um.co.za/Tenant Management/tenant-overview.php';
include LUM_ROOT . '/Layout/sub-navbar.php';
?>

<div class="container-fluid px-4 py-4" style="max-width: 1150px;">

<?php foreach ($errors as $e): ?><div class="alert alert-danger fs-6"><?php echo htmlspecialchars($e); ?></div><?php endforeach; ?>
<?php foreach ($notices as $n): ?><div class="alert alert-success fs-6"><?php echo htmlspecialchars($n); ?></div><?php endforeach; ?>

<div class="card p-3 mb-4">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-md-4">
      <label class="form-label fs-6" for="property">Property</label>
      <select class="form-select form-select-sm bg-dark text-white" id="property" name="property" onchange="this.form.submit()">
        <option value="">Choose...</option>
        <?php foreach ($properties as $p): ?>
          <option value="<?php echo htmlspecialchars($p); ?>" <?php echo $p === $property ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-5">
      <label class="form-label fs-6" for="tenant_ref">Tenant</label>
      <select class="form-select form-select-sm bg-dark text-white" id="tenant_ref" name="tenant_ref" onchange="this.form.submit()" <?php echo $tenants ? '' : 'disabled'; ?>>
        <option value="">Choose...</option>
        <?php foreach ($tenants as $t): ?>
          <option value="<?php echo htmlspecialchars($t['tenant_ref']); ?>" <?php echo $t['tenant_ref'] === $tenant_ref ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($t['tenant_name'] . ' (shop ' . ($t['tenant_shop'] ?: '-') . ')'); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3"><button type="submit" class="btn btn-sm btn-outline-light">Show</button></div>
  </form>
</div>

<?php if ($tenant_ref !== '' && $assignments): ?>
<div class="card p-3 mb-4">
  <h6 class="mb-3">Meters of <?php echo htmlspecialchars($tenant_row['tenant_name'] ?? $tenant_ref); ?></h6>
  <table class="table table-sm fs-6">
    <thead><tr><th>Service</th><th>Meter</th><th>Applied</th><th class="text-end">CT ratio</th><th>Notes</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($assignments as $a): $closed = !empty($a['valid_to']) && $a['valid_to'] < date('Y-m-d'); ?>
      <tr class="<?php echo $closed ? 'closed' : ''; ?>">
        <td><?php echo htmlspecialchars(ucfirst($a['meter_kind'])); ?> <?php echo (int)$a['slot']; ?></td>
        <td><?php echo htmlspecialchars($a['meter_serial']); ?></td>
        <td><?php echo htmlspecialchars(($a['valid_from'] ?: 'from the first reading') . ' to ' . ($a['valid_to'] ?: 'now')); ?></td>
        <td class="text-end"><?php echo $a['meter_kind'] === 'water' ? '-' : number_format((float)$a['ct_ratio'], 2); ?></td>
        <td class="small text-secondary"><?php echo htmlspecialchars((string)$a['notes']); ?></td>
        <td class="text-end">
          <?php if ($a['meter_kind'] === 'electricity' && $can_edit): ?>
            <button class="btn btn-sm btn-outline-warning py-0" type="button" data-bs-toggle="collapse" data-bs-target="#fix<?php echo (int)$a['link_id']; ?>">Correct</button>
          <?php endif; ?>
        </td>
      </tr>
      <?php if ($a['meter_kind'] === 'electricity' && $can_edit): ?>
      <tr class="collapse" id="fix<?php echo (int)$a['link_id']; ?>">
        <td colspan="6">
          <form method="POST" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="correct">
            <input type="hidden" name="link_id" value="<?php echo (int)$a['link_id']; ?>">
            <input type="hidden" name="property" value="<?php echo htmlspecialchars($property); ?>">
            <input type="hidden" name="tenant_ref" value="<?php echo htmlspecialchars($tenant_ref); ?>">
            <div class="col-md-2">
              <label class="form-label fs-6">Correct ratio</label>
              <input type="text" class="form-control form-control-sm bg-dark text-white" name="new_ct" value="<?php echo htmlspecialchars(number_format((float)$a['ct_ratio'], 2)); ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fs-6">What happened</label>
              <select class="form-select form-select-sm bg-dark text-white" name="how" required>
                <option value="always">The ratio was wrong all along - correct the history</option>
                <option value="from_date">The ratio changed on a date - start a new period</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label fs-6">Changed on</label>
              <input type="date" class="form-control form-control-sm bg-dark text-white" name="from_date" value="<?php echo htmlspecialchars($a['valid_from'] ?: date('Y-m-d')); ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label fs-6">Why</label>
              <input type="text" class="form-control form-control-sm bg-dark text-white" name="reason" placeholder="e.g. meter plate reads 40/5" required>
            </div>
            <div class="col-md-1">
              <button type="submit" class="btn btn-sm btn-warning">Apply</button>
            </div>
          </form>
        </td>
      </tr>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($billed_months): ?>
<div class="card p-3">
  <h6 class="mb-3">Months already billed for this tenant</h6>
  <table class="table table-sm fs-6">
    <thead><tr><th>Month</th><th>Period</th><th class="text-end">kWh billed</th><th class="text-end">Electricity</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($billed_months as $b): ?>
      <tr>
        <td><?php echo htmlspecialchars((string)$b['billing_label']); ?></td>
        <td><?php echo htmlspecialchars($b['period_start'] . ' to ' . $b['period_end']); ?></td>
        <td class="text-end"><?php echo number_format((float)$b['kwh'], 3); ?></td>
        <td class="text-end">R <?php echo number_format((float)$b['elec_total'], 2); ?></td>
        <td><?php echo htmlspecialchars((string)$b['status']); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php elseif ($tenant_ref !== ''): ?>
  <div class="alert alert-secondary fs-6">This tenant has no recorded meter assignments.</div>
<?php endif; ?>

</div>
    <?php include LUM_ROOT . '/Layout/foot-assets.php'; ?>
</body>
</html>
