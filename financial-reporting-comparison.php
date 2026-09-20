<?php
// Two financial reports beside each other: the same property on two billing cycles,
// or two properties, with the difference per tenant.
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once __DIR__ . '/bootstrap.php';
lum_page('financial_reports', 'view');
ini_set('pcre.jit', '0');
set_time_limit(900);

// The slip works with the classic connection variables, as the report pages do
lum_connect('tenants', 'meters', 'tariffs', 'manual', 'obis');

define('LUM_SLIP_DIR', LUM_ROOT . '/Tenant Management/Tenant Consumption Slips');
require_once LUM_SLIP_DIR . '/slip-engine.php';
require_once LUM_ROOT . '/Reporting/reporting-engine.php';

$tenant_pdo = lum_db('tenants');
$cycle_pdo  = lum_db('billing_cycle');

// --- What can be chosen ----------------------------------------------------
$properties = $tenant_pdo->query("SELECT DISTINCT tenant_property FROM lum_tenants WHERE tenant_property <> '' ORDER BY tenant_property")->fetchAll(PDO::FETCH_COLUMN);
if (!empty($_SESSION['assigned_properties'])) {
    $allowed = array_map('trim', explode(',', $_SESSION['assigned_properties']));
    $properties = array_values(array_intersect($properties, $allowed));
}

function lumComparisonCycles(PDO $pdo, $property) {
    if ($property === '') return [];
    try {
        $stmt = $pdo->prepare("SELECT period_id, billing_month_label, period_start_date, period_end_date
                                 FROM lum_billing_period_ledger
                                WHERE property_name = :p
                                ORDER BY period_start_date DESC");
        $stmt->execute(['p' => $property]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

$a_property = trim((string)($_GET['a_property'] ?? ''));
$b_property = trim((string)($_GET['b_property'] ?? $a_property));
$a_cycle    = (int)($_GET['a_cycle'] ?? 0);
$b_cycle    = (int)($_GET['b_cycle'] ?? 0);

$a_cycles = lumComparisonCycles($cycle_pdo, $a_property);
$b_cycles = lumComparisonCycles($cycle_pdo, $b_property);

// --- Working out one report ------------------------------------------------
// Every tenant of a property for one billing cycle, using the same calculation the
// financial report and the slips use.
function lumComparisonReport($property, array $cycle) {
    // The slip and its context read these by name
    global $tenant_db_conn, $meter_db_conn, $tariff_db_conn, $manual_db_conn, $obis_db_conn;
    global $core_db_conn, $info_db_conn, $cycle_db_conn, $tenant_crud, $meter_crud;
    global $lum_ctx_vars, $lum_gen_cache, $public_holidays;

    $out = ['tenants' => [], 'total' => 0.0, 'elec' => 0.0, 'water' => 0.0, 'error' => ''];
    if ($property === '' || empty($cycle)) return $out;

    $_REQUEST['property'] = $property;
    $_REQUEST['start_date'] = date('Y-m-d', strtotime($cycle['period_start_date']));
    $_REQUEST['end_date'] = date('Y-m-d', strtotime($cycle['period_end_date']));
    $_REQUEST['start_date_2'] = '';
    $_REQUEST['end_date_2'] = '';
    $_REQUEST['part'] = 'all';
    unset($_REQUEST['water_start_date'], $_REQUEST['water_end_date']);

    try {
        include LUM_SLIP_DIR . '/slip-context.php';   // sets the dates, tariffs and property settings

        $pdo = lum_db('tenants');
        $stmt = $pdo->prepare("SELECT * FROM lum_tenants WHERE tenant_property = :p ORDER BY tenant_name");
        $stmt->execute(['p' => $property]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $tenant_report_settings = function_exists('lumTenantReportSettings')
            ? lumTenantReportSettings($pdo, $report_year ?? (int)date('Y'), $report_month ?? (int)date('n')) : [];

        foreach ($rows as $tenant) {
            $slip_render_mode = 'data';
            $slip_chart_key = 'cmp-' . $tenant['tenant_id'];
            $slip_totals = [];
            require LUM_SLIP_DIR . '/slip-tenant-settings-apply.php';
            require LUM_SLIP_DIR . '/slip-tenant.php';
            ob_start();
            include LUM_SLIP_DIR . '/slip-render.php';
            ob_end_clean();
            require LUM_SLIP_DIR . '/slip-tenant-settings-restore.php';

            $key = $tenant['tenant_ref'] ?: ('T' . $tenant['tenant_id']);
            $out['tenants'][$key] = [
                'name'  => $tenant['tenant_name'],
                'shop'  => $tenant['tenant_shop'],
                'kwh'   => (float)($slip_totals['kwh'] ?? 0),
                'kl'    => (float)($slip_totals['water_kl'] ?? 0),
                'elec'  => (float)($slip_totals['elec_total'] ?? 0),
                'water' => (float)($slip_totals['water_total'] ?? 0),
                'total' => (float)($slip_totals['total'] ?? 0),
            ];
            $out['total'] += (float)($slip_totals['total'] ?? 0);
            $out['elec']  += (float)($slip_totals['elec_total'] ?? 0);
            $out['water'] += (float)($slip_totals['water_total'] ?? 0);
        }
    } catch (Throwable $e) {
        error_log('LUM report comparison: ' . $e->getMessage());
        $out['error'] = 'This report could not be worked out.';
    }
    return $out;
}

$report_a = $report_b = null;
$cycle_a = $cycle_b = null;
if ($a_property !== '' && $b_property !== '' && $a_cycle > 0 && $b_cycle > 0) {
    foreach ($a_cycles as $c) { if ((int)$c['period_id'] === $a_cycle) $cycle_a = $c; }
    foreach ($b_cycles as $c) { if ((int)$c['period_id'] === $b_cycle) $cycle_b = $c; }
    if ($cycle_a && $cycle_b) {
        $report_a = lumComparisonReport($a_property, $cycle_a);
        $report_b = lumComparisonReport($b_property, $cycle_b);
    }
}

// The tenants of both reports, side by side
$lines = [];
if ($report_a && $report_b) {
    foreach (array_keys($report_a['tenants'] + $report_b['tenants']) as $key) {
        $a = $report_a['tenants'][$key] ?? null;
        $b = $report_b['tenants'][$key] ?? null;
        $lines[] = [
            'name'  => $a['name'] ?? $b['name'],
            'shop'  => $a['shop'] ?? $b['shop'],
            'a'     => $a,
            'b'     => $b,
            'diff'  => (float)($b['total'] ?? 0) - (float)($a['total'] ?? 0),
        ];
    }
    usort($lines, static function ($x, $y) { return abs($y['diff']) <=> abs($x['diff']); });
}

$lum_sub_title = 'Financial Report Comparison';
$lum_sub_back = lum_app_url('/Reporting/financial-reporting-overview.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Financial Report Comparison | Lynx Utility Management</title>
    <?php include LUM_ROOT . '/Layout/head-assets.php'; ?>
<style>
        body { background: #121212; color: #ffffff; }
        .card { background: #1a1a1a; border: 1px solid #2c2c2c; color: #ffffff; }
        .table > :not(caption) > * > * { background: #1a1a1a; color: #ffffff; border-color: #2c2c2c; }
        .up { color: #ff6b6b; }
        .down { color: #51cf66; }
        .num { font-size: 1.4rem; font-weight: 600; }
        th { white-space: nowrap; }
    </style>
</head>
<body>
<?php include LUM_ROOT . '/Layout/top-navbar.php'; ?>
<?php include LUM_ROOT . '/Layout/sub-navbar.php'; ?>

<div class="container-fluid px-4 pb-5">

  <form method="GET" class="card p-3 mb-4">
    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label fs-6" for="a_property">Report A: property</label>
        <select class="form-select form-select-sm bg-dark text-white" id="a_property" name="a_property" onchange="this.form.submit()">
          <option value="">Choose...</option>
          <?php foreach ($properties as $p): ?>
            <option value="<?php echo htmlspecialchars($p); ?>" <?php echo $p === $a_property ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fs-6" for="a_cycle">Report A: billing cycle</label>
        <select class="form-select form-select-sm bg-dark text-white" id="a_cycle" name="a_cycle" <?php echo $a_cycles ? '' : 'disabled'; ?>>
          <option value="">Choose...</option>
          <?php foreach ($a_cycles as $c): ?>
            <option value="<?php echo (int)$c['period_id']; ?>" <?php echo (int)$c['period_id'] === $a_cycle ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($c['billing_month_label'] . '  (' . date('d M Y', strtotime($c['period_start_date'])) . ' - ' . date('d M Y', strtotime($c['period_end_date'])) . ')'); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fs-6" for="b_property">Report B: property</label>
        <select class="form-select form-select-sm bg-dark text-white" id="b_property" name="b_property" onchange="this.form.submit()">
          <option value="">Choose...</option>
          <?php foreach ($properties as $p): ?>
            <option value="<?php echo htmlspecialchars($p); ?>" <?php echo $p === $b_property ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fs-6" for="b_cycle">Report B: billing cycle</label>
        <select class="form-select form-select-sm bg-dark text-white" id="b_cycle" name="b_cycle" <?php echo $b_cycles ? '' : 'disabled'; ?>>
          <option value="">Choose...</option>
          <?php foreach ($b_cycles as $c): ?>
            <option value="<?php echo (int)$c['period_id']; ?>" <?php echo (int)$c['period_id'] === $b_cycle ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($c['billing_month_label'] . '  (' . date('d M Y', strtotime($c['period_start_date'])) . ' - ' . date('d M Y', strtotime($c['period_end_date'])) . ')'); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12">
        <button type="submit" class="btn btn-danger btn-sm px-4">Compare</button>
      </div>
    </div>
  </form>

  <?php if ($report_a && $report_b): ?>
    <?php if ($report_a['error'] || $report_b['error']): ?>
      <div class="alert alert-danger fs-6"><?php echo htmlspecialchars($report_a['error'] . ' ' . $report_b['error']); ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
      <div class="col-md-4"><div class="card p-3">
        <div class="fs-6"><?php echo htmlspecialchars($a_property . ' - ' . $cycle_a['billing_month_label']); ?></div>
        <div class="num">R <?php echo number_format($report_a['total'], 2); ?></div>
      </div></div>
      <div class="col-md-4"><div class="card p-3">
        <div class="fs-6"><?php echo htmlspecialchars($b_property . ' - ' . $cycle_b['billing_month_label']); ?></div>
        <div class="num">R <?php echo number_format($report_b['total'], 2); ?></div>
      </div></div>
      <div class="col-md-4"><div class="card p-3">
        <div class="fs-6">Difference</div>
        <?php $d = $report_b['total'] - $report_a['total']; ?>
        <div class="num <?php echo $d > 0 ? 'up' : ($d < 0 ? 'down' : ''); ?>">
          <?php echo ($d > 0 ? '+' : '') . 'R ' . number_format($d, 2); ?>
          <?php if ($report_a['total'] > 0): ?>
            <span class="fs-6">(<?php echo ($d > 0 ? '+' : '') . number_format($d / $report_a['total'] * 100, 1); ?>%)</span>
          <?php endif; ?>
        </div>
      </div></div>
    </div>

    <div class="card p-0">
      <div class="table-responsive">
        <table class="table table-sm fs-6 mb-0">
          <thead>
            <tr>
              <th rowspan="2">Tenant</th><th rowspan="2">Shop</th>
              <th colspan="3" class="text-center border-start">A: <?php echo htmlspecialchars($cycle_a['billing_month_label']); ?></th>
              <th colspan="3" class="text-center border-start">B: <?php echo htmlspecialchars($cycle_b['billing_month_label']); ?></th>
              <th colspan="2" class="text-center border-start">Difference</th>
            </tr>
            <tr>
              <th class="text-end border-start">kWh</th><th class="text-end">kl</th><th class="text-end">Total</th>
              <th class="text-end border-start">kWh</th><th class="text-end">kl</th><th class="text-end">Total</th>
              <th class="text-end border-start">Rand</th><th class="text-end">%</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($lines as $l): $a = $l['a']; $b = $l['b']; $pc = ($a && $a['total'] > 0) ? $l['diff'] / $a['total'] * 100 : null; ?>
            <tr>
              <td><?php echo htmlspecialchars($l['name']); ?></td>
              <td><?php echo htmlspecialchars((string)$l['shop']); ?></td>
              <td class="text-end border-start"><?php echo $a ? number_format($a['kwh'], 1) : '-'; ?></td>
              <td class="text-end"><?php echo $a ? number_format($a['kl'], 2) : '-'; ?></td>
              <td class="text-end"><?php echo $a ? 'R ' . number_format($a['total'], 2) : '<span class="text-white-50">not billed</span>'; ?></td>
              <td class="text-end border-start"><?php echo $b ? number_format($b['kwh'], 1) : '-'; ?></td>
              <td class="text-end"><?php echo $b ? number_format($b['kl'], 2) : '-'; ?></td>
              <td class="text-end"><?php echo $b ? 'R ' . number_format($b['total'], 2) : '<span class="text-white-50">not billed</span>'; ?></td>
              <td class="text-end border-start <?php echo $l['diff'] > 0 ? 'up' : ($l['diff'] < 0 ? 'down' : ''); ?>">
                <?php echo ($l['diff'] > 0 ? '+' : '') . 'R ' . number_format($l['diff'], 2); ?>
              </td>
              <td class="text-end <?php echo $l['diff'] > 0 ? 'up' : ($l['diff'] < 0 ? 'down' : ''); ?>">
                <?php echo $pc === null ? '-' : (($pc > 0 ? '+' : '') . number_format($pc, 1) . '%'); ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr class="fw-semibold">
              <td colspan="2">Total</td>
              <td colspan="2" class="border-start"></td>
              <td class="text-end">R <?php echo number_format($report_a['total'], 2); ?></td>
              <td colspan="2" class="border-start"></td>
              <td class="text-end">R <?php echo number_format($report_b['total'], 2); ?></td>
              <td class="text-end border-start <?php echo $d > 0 ? 'up' : ($d < 0 ? 'down' : ''); ?>">
                <?php echo ($d > 0 ? '+' : '') . 'R ' . number_format($d, 2); ?>
              </td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  <?php endif; ?>

</div>
</body>
</html>
