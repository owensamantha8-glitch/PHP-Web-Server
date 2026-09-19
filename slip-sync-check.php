<?php
// Are the bulk slips and the single slips billing the same figures?
// For every tenant of a property this works out the slip twice - once the way a single
// slip does it, once the way the bulk export does it - and compares the totals. For a
// tenant set to separate slips it also checks the two parts add up to the whole.
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once __DIR__ . '/bootstrap.php';
lum_page('consumption_slips', 'view');
ini_set('pcre.jit', '0');
set_time_limit(600);

define('LUM_SLIP_DIR', LUM_ROOT . '/Tenant Management/Tenant Consumption Slips');
require_once LUM_SLIP_DIR . '/slip-engine.php';
require_once LUM_ROOT . '/Reporting/reporting-engine.php';

$property_name = trim((string)($_REQUEST['property'] ?? ''));
$results = [];
$fatal = '';

// The slip needs the same request the report would give it
if ($property_name !== '') {
    $_REQUEST['property'] = $property_name;
    $_REQUEST['part'] = 'all';
    require LUM_SLIP_DIR . '/slip-context.php';

    $tenant_db_conn = lum_db('tenants');
    $stmt = $tenant_db_conn->prepare("SELECT * FROM lum_tenants WHERE tenant_property = :p
                                       AND (tenant_occupancy_end_date IS NULL OR tenant_occupancy_end_date >= :from)
                                     ORDER BY tenant_name");
    $stmt->execute(['p' => $property_name, 'from' => $start_date]);
    $tenants_to_check = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Each tenant's own report settings, exactly as the bulk export applies them
    $tenant_report_settings = function_exists('lumTenantReportSettings')
        ? lumTenantReportSettings($tenant_db_conn, $report_year ?? (int)date('Y'), $report_month ?? (int)date('n'))
        : [];

    // One run of the slip for one tenant, returning its totals
    $run_slip = function (array $tenant_row, $mode, $part) use (&$tenant_report_settings) {
        $tenant = $tenant_row;
        $slip_part = $part;
        $slip_render_mode = $mode;
        $slip_chart_key = 'sync-' . $tenant['tenant_id'] . '-' . $mode . '-' . $part;
        $slip_totals = [];
        require LUM_SLIP_DIR . '/slip-tenant-settings-apply.php';
        require LUM_SLIP_DIR . '/slip-tenant.php';
        ob_start();
        include LUM_SLIP_DIR . '/slip-render.php';
        ob_end_clean();
        require LUM_SLIP_DIR . '/slip-tenant-settings-restore.php';
        return $slip_totals;
    };

    foreach ($tenants_to_check as $t) {
        try {
            $single = $run_slip($t, 'single', 'all');
            $bulk   = $run_slip($t, 'bulk', 'all');
            $row = [
                'tenant' => $t['tenant_name'], 'shop' => $t['tenant_shop'], 'id' => $t['tenant_id'],
                'layout' => $t['slip_layout'] ?? 'combined',
                'single' => (float)($single['total'] ?? 0),
                'bulk'   => (float)($bulk['total'] ?? 0),
                'elec'   => (float)($single['elec_total'] ?? 0),
                'water'  => (float)($single['water_total'] ?? 0),
                'parts'  => null,
            ];
            if (($t['slip_layout'] ?? 'combined') === 'separate') {
                $e = $run_slip($t, 'bulk', 'electricity');
                $w = $run_slip($t, 'bulk', 'water');
                $row['parts'] = (float)($e['total'] ?? 0) + (float)($w['total'] ?? 0);
                $row['part_elec'] = (float)($e['total'] ?? 0);
                $row['part_water'] = (float)($w['total'] ?? 0);
            }
            $results[] = $row;
        } catch (Throwable $e) {
            error_log('LUM slip sync check: ' . $e->getMessage());
            $results[] = ['tenant' => $t['tenant_name'], 'shop' => $t['tenant_shop'], 'id' => $t['tenant_id'],
                          'error' => $e->getMessage()];
        }
    }
}

$properties = lum_db('tenants')->query("SELECT DISTINCT tenant_property FROM lum_tenants WHERE tenant_property <> '' ORDER BY tenant_property")->fetchAll(PDO::FETCH_COLUMN);
if (!empty($_SESSION['assigned_properties'])) {
    $allowed = array_map('trim', explode(',', $_SESSION['assigned_properties']));
    $properties = array_values(array_intersect($properties, $allowed));
}
$diffs = 0;
foreach ($results as $r) {
    if (isset($r['error'])) { $diffs++; continue; }
    if (abs($r['single'] - $r['bulk']) >= 0.01) $diffs++;
    elseif ($r['parts'] !== null && abs($r['parts'] - $r['single']) >= 0.01) $diffs++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Slip Sync Check | Lynx Utility Management</title>
    <?php include LUM_ROOT . '/Layout/head-assets.php'; ?>
<style>
        body { background:#121212; color:#e0e0e0; }
        .card { background:#1a1a1a; border:1px solid #2c2c2c; }
        .table > :not(caption) > * > * { background:#1a1a1a; color:#e0e0e0; border-color:#2c2c2c; }
        .bad td { background:#2b1416; }
    </style>
</head>
<body>
<?php include LUM_ROOT . '/Layout/top-navbar.php'; ?>

<?php
$lum_sub_title = 'Slip Sync Check';
$lum_sub_back = 'https://lynx-um.co.za/Reporting/financial-reporting-overview.php';
include LUM_ROOT . '/Layout/sub-navbar.php';
?>

<div class="container-fluid px-4 py-4" style="max-width: 1100px;">

  <form method="GET" class="row g-2 align-items-end mb-4">
    <div class="col-md-5">
      <label class="form-label fs-6" for="property">Property</label>
      <select class="form-select form-select-sm bg-dark text-white" id="property" name="property">
        <option value="">Choose...</option>
        <?php foreach ($properties as $p): ?>
          <option value="<?php echo htmlspecialchars($p); ?>" <?php echo $p === $property_name ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3"><button class="btn btn-danger btn-sm px-4" type="submit">Check</button></div>
  </form>

  <?php if ($property_name !== ''): ?>
    <div class="alert <?php echo $diffs ? 'alert-danger' : 'alert-success'; ?> fs-6">
      <?php if ($diffs): ?>
        <strong><?php echo $diffs; ?> tenant(s) do not match.</strong>
      <?php else: ?>
        <strong>Every tenant matches.</strong>
      <?php endif; ?>
      <div class="mt-1"><?php echo htmlspecialchars($property_name); ?>,
        <?php echo htmlspecialchars($start_date . ' to ' . $end_date); ?>
        <?php if (!empty($has_period_2)): ?>and <?php echo htmlspecialchars($start_date_2 . ' to ' . $end_date_2); ?><?php endif; ?>
      </div>
    </div>

    <div class="card p-0">
      <table class="table table-sm fs-6 mb-0">
        <thead><tr>
          <th>Tenant</th><th>Shop</th><th>Slip</th>
          <th class="text-end">Single</th><th class="text-end">Bulk</th>
          <th class="text-end">Electricity part</th><th class="text-end">Water part</th><th class="text-end">Parts together</th>
        </tr></thead>
        <tbody>
        <?php foreach ($results as $r): ?>
          <?php
            $bad = isset($r['error'])
                || abs(($r['single'] ?? 0) - ($r['bulk'] ?? 0)) >= 0.01
                || (($r['parts'] ?? null) !== null && abs($r['parts'] - $r['single']) >= 0.01);
          ?>
          <tr class="<?php echo $bad ? 'bad' : ''; ?>">
            <td><?php echo htmlspecialchars($r['tenant']); ?></td>
            <td><?php echo htmlspecialchars((string)$r['shop']); ?></td>
            <td><?php echo htmlspecialchars($r['layout'] ?? ''); ?></td>
            <?php if (isset($r['error'])): ?>
              <td colspan="5" class="text-danger">Could not be worked out: <?php echo htmlspecialchars(substr($r['error'], 0, 120)); ?></td>
            <?php else: ?>
              <td class="text-end">R <?php echo number_format($r['single'], 2); ?></td>
              <td class="text-end">R <?php echo number_format($r['bulk'], 2); ?></td>
              <td class="text-end"><?php echo isset($r['part_elec']) ? 'R ' . number_format($r['part_elec'], 2) : '-'; ?></td>
              <td class="text-end"><?php echo isset($r['part_water']) ? 'R ' . number_format($r['part_water'], 2) : '-'; ?></td>
              <td class="text-end"><?php echo $r['parts'] !== null ? 'R ' . number_format($r['parts'], 2) : '-'; ?></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

</div>
</body>
</html>
