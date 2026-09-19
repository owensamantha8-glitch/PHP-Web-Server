<?php
// Electricity and water usage of a property over two periods, side by side.
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once __DIR__ . '/bootstrap.php';
lum_page('meters', 'view');
lum_use('meters');
ini_set('pcre.jit', '0');
set_time_limit(600);

$meter_pdo = lum_db('meters');
$obis_pdo  = lum_db('obis');
$tenant_pdo = lum_db('tenants');

// --- What is being compared ------------------------------------------------
$property = trim((string)($_GET['property'] ?? ''));
$service  = (($_GET['service'] ?? 'electricity') === 'water') ? 'water' : 'electricity';
$type     = trim((string)($_GET['meter_type'] ?? ''));

$today = date('Y-m-d');
$a_from = $_GET['a_from'] ?? date('Y-m-01', strtotime('-1 month'));
$a_to   = $_GET['a_to']   ?? date('Y-m-t', strtotime('-1 month'));
$b_from = $_GET['b_from'] ?? date('Y-m-01');
$b_to   = $_GET['b_to']   ?? $today;
foreach (['a_from', 'a_to', 'b_from', 'b_to'] as $d) {
    $v = DateTime::createFromFormat('Y-m-d', (string)$$d);
    if (!$v || $v->format('Y-m-d') !== $$d) { $$d = $today; }
}

$properties = $meter_pdo->query("SELECT DISTINCT meter_property FROM lum_meters WHERE meter_property <> '' ORDER BY meter_property")->fetchAll(PDO::FETCH_COLUMN);
if (!empty($_SESSION['assigned_properties'])) {
    $allowed = array_map('trim', explode(',', $_SESSION['assigned_properties']));
    $properties = array_values(array_intersect($properties, $allowed));
    if ($property !== '' && !in_array($property, $allowed, true)) $property = '';
}

// The meter types this property actually has, for the chosen service
$types = [];
if ($property !== '') {
    $like = $service === 'water' ? 'Water%' : 'Electrical%';
    $stmt = $meter_pdo->prepare("SELECT DISTINCT meter_type FROM lum_meters
                                  WHERE meter_property = :p AND meter_type LIKE :like AND meter_type <> ''
                                  ORDER BY meter_type");
    $stmt->execute(['p' => $property, 'like' => $like]);
    $types = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($type !== '' && !in_array($type, $types, true)) $type = '';
}

// --- The meters to read ----------------------------------------------------
$meters = [];
if ($property !== '') {
    $sql = "SELECT meter_serial, meter_type, meter_tenant, meter_shop, ct_ratio,
                   COALESCE(reading_method, 'automatic') AS reading_method
              FROM lum_meters
             WHERE meter_property = :p AND meter_type LIKE :like";
    $args = ['p' => $property, 'like' => $service === 'water' ? 'Water%' : 'Electrical%'];
    if ($type !== '') { $sql .= " AND meter_type = :t"; $args['t'] = $type; }
    $sql .= " ORDER BY meter_tenant, meter_serial";
    $stmt = $meter_pdo->prepare($sql);
    $stmt->execute($args);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $meters[trim((string)$row['meter_serial'])] = $row;
    }
}

// --- Reading the usage -----------------------------------------------------
$code = $service === 'water' ? '81100' : '11180';
$unit = $service === 'water' ? 'kl' : 'kWh';

// Usage of every meter between two dates: the last reading less the first
function lumUsageInRange(PDO $pdo, $code, array $serials, $from, $to) {
    $out = [];
    if (!$serials) return $out;
    $years = range((int)date('Y', strtotime($from)), (int)date('Y', strtotime($to)));
    $in = implode(',', array_fill(0, count($serials), '?'));
    foreach ($years as $y) {
        $tb = "db_obis_{$code}_{$y}.tb_obis_{$code}_{$y}";
        $col = $code . '_value';
        try {
            $stmt = $pdo->prepare("SELECT meter_serial, MIN(`{$col}`) AS first_val, MAX(`{$col}`) AS last_val,
                                          COUNT(*) AS readings, MIN(Time_stamp) AS first_ts, MAX(Time_stamp) AS last_ts
                                     FROM {$tb}
                                    WHERE meter_serial IN ({$in})
                                      AND Time_stamp >= ? AND Time_stamp < DATE_ADD(?, INTERVAL 1 DAY)
                                    GROUP BY meter_serial");
            $stmt->execute(array_merge(array_values($serials), [$from . ' 00:00:00', $to]));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $s = trim((string)$r['meter_serial']);
                $used = (float)$r['last_val'] - (float)$r['first_val'];
                if (!isset($out[$s])) $out[$s] = ['used' => 0.0, 'readings' => 0, 'first' => $r['first_ts'], 'last' => $r['last_ts']];
                $out[$s]['used'] += max(0, $used);
                $out[$s]['readings'] += (int)$r['readings'];
                if ($r['first_ts'] < $out[$s]['first']) $out[$s]['first'] = $r['first_ts'];
                if ($r['last_ts'] > $out[$s]['last']) $out[$s]['last'] = $r['last_ts'];
            }
        } catch (Throwable $e) {
            // that year has no table yet
        }
    }
    return $out;
}

// Usage per day, for the shape of the two periods
function lumUsageByDay(PDO $pdo, $code, array $serials, $from, $to) {
    $out = [];
    if (!$serials) return $out;
    $years = range((int)date('Y', strtotime($from)), (int)date('Y', strtotime($to)));
    $in = implode(',', array_fill(0, count($serials), '?'));
    foreach ($years as $y) {
        $tb = "db_obis_{$code}_{$y}.tb_obis_{$code}_{$y}";
        $col = $code . '_value';
        try {
            $stmt = $pdo->prepare("SELECT DATE(Time_stamp) AS d, SUM(day_use) AS used FROM (
                                      SELECT meter_serial, DATE(Time_stamp) AS Time_stamp,
                                             MAX(`{$col}`) - MIN(`{$col}`) AS day_use
                                        FROM {$tb}
                                       WHERE meter_serial IN ({$in})
                                         AND Time_stamp >= ? AND Time_stamp < DATE_ADD(?, INTERVAL 1 DAY)
                                       GROUP BY meter_serial, DATE(Time_stamp)
                                   ) x GROUP BY DATE(Time_stamp) ORDER BY d");
            $stmt->execute(array_merge(array_values($serials), [$from . ' 00:00:00', $to]));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[$r['d']] = (float)$r['used'];
            }
        } catch (Throwable $e) {
        }
    }
    ksort($out);
    return $out;
}

$serials = array_keys($meters);
$usage_a = $usage_b = [];
$days_a = $days_b = [];
if ($serials) {
    $usage_a = lumUsageInRange($obis_pdo, $code, $serials, $a_from, $a_to);
    $usage_b = lumUsageInRange($obis_pdo, $code, $serials, $b_from, $b_to);
    $days_a = lumUsageByDay($obis_pdo, $code, $serials, $a_from, $a_to);
    $days_b = lumUsageByDay($obis_pdo, $code, $serials, $b_from, $b_to);
}

$a_days = max(1, (int)((strtotime($a_to) - strtotime($a_from)) / 86400) + 1);
$b_days = max(1, (int)((strtotime($b_to) - strtotime($b_from)) / 86400) + 1);

// Per meter, with the CT ratio applied to electricity
$rows = [];
$total_a = $total_b = 0.0;
foreach ($meters as $serial => $m) {
    $ct = ($service === 'electricity' && (float)$m['ct_ratio'] > 0) ? (float)$m['ct_ratio'] : 1.0;
    $ua = isset($usage_a[$serial]) ? $usage_a[$serial]['used'] * $ct : null;
    $ub = isset($usage_b[$serial]) ? $usage_b[$serial]['used'] * $ct : null;
    if ($ua === null && $ub === null) continue;
    $diff = (float)$ub - (float)$ua;
    $rows[] = [
        'serial' => $serial, 'type' => $m['meter_type'], 'tenant' => $m['meter_tenant'], 'shop' => $m['meter_shop'],
        'ct' => $ct, 'method' => $m['reading_method'],
        'a' => $ua, 'b' => $ub, 'diff' => $diff,
        'pc' => ($ua > 0) ? $diff / $ua * 100 : null,
        'a_day' => $ua !== null ? $ua / $a_days : null,
        'b_day' => $ub !== null ? $ub / $b_days : null,
        'readings_a' => $usage_a[$serial]['readings'] ?? 0,
        'readings_b' => $usage_b[$serial]['readings'] ?? 0,
    ];
    $total_a += (float)$ua;
    $total_b += (float)$ub;
}
usort($rows, static function ($x, $y) { return abs($y['diff']) <=> abs($x['diff']); });

$total_diff = $total_b - $total_a;
$total_pc = $total_a > 0 ? $total_diff / $total_a * 100 : null;
$avg_a = $total_a / $a_days;
$avg_b = $total_b / $b_days;

$lum_sub_title = 'Usage Comparison';
$lum_sub_back = 'https://lynx-um.co.za/Meter Management/meter-overview.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Usage Comparison | Lynx Utility Management</title>
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
    <div class="row g-3 align-items-end">
      <div class="col-md-3">
        <label class="form-label fs-6" for="property">Property</label>
        <select class="form-select form-select-sm bg-dark text-white" id="property" name="property" onchange="this.form.submit()">
          <option value="">Choose...</option>
          <?php foreach ($properties as $p): ?>
            <option value="<?php echo htmlspecialchars($p); ?>" <?php echo $p === $property ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label fs-6" for="service">Service</label>
        <select class="form-select form-select-sm bg-dark text-white" id="service" name="service" onchange="this.form.submit()">
          <option value="electricity" <?php echo $service === 'electricity' ? 'selected' : ''; ?>>Electricity</option>
          <option value="water" <?php echo $service === 'water' ? 'selected' : ''; ?>>Water</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fs-6" for="meter_type">Meter type</label>
        <select class="form-select form-select-sm bg-dark text-white" id="meter_type" name="meter_type" <?php echo $types ? '' : 'disabled'; ?>>
          <option value="">All types at this property</option>
          <?php foreach ($types as $t): ?>
            <option value="<?php echo htmlspecialchars($t); ?>" <?php echo $t === $type ? 'selected' : ''; ?>><?php echo htmlspecialchars($t); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4"></div>

      <div class="col-md-3">
        <label class="form-label fs-6" for="a_from">Period A from</label>
        <input type="date" class="form-control form-control-sm bg-dark text-white" id="a_from" name="a_from" value="<?php echo htmlspecialchars($a_from); ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label fs-6" for="a_to">Period A to</label>
        <input type="date" class="form-control form-control-sm bg-dark text-white" id="a_to" name="a_to" value="<?php echo htmlspecialchars($a_to); ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label fs-6" for="b_from">Period B from</label>
        <input type="date" class="form-control form-control-sm bg-dark text-white" id="b_from" name="b_from" value="<?php echo htmlspecialchars($b_from); ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label fs-6" for="b_to">Period B to</label>
        <input type="date" class="form-control form-control-sm bg-dark text-white" id="b_to" name="b_to" value="<?php echo htmlspecialchars($b_to); ?>">
      </div>
      <div class="col-12">
        <button type="submit" class="btn btn-danger btn-sm px-4">Compare</button>
      </div>
    </div>
  </form>

  <?php if ($property !== '' && $rows): ?>
    <div class="row g-3 mb-4">
      <div class="col-md-3"><div class="card p-3">
        <div class="fs-6">A: <?php echo htmlspecialchars($a_from . ' to ' . $a_to); ?> (<?php echo $a_days; ?> days)</div>
        <div class="num"><?php echo number_format($total_a, 2) . ' ' . $unit; ?></div>
        <div class="fs-6"><?php echo number_format($avg_a, 2) . ' ' . $unit; ?> a day</div>
      </div></div>
      <div class="col-md-3"><div class="card p-3">
        <div class="fs-6">B: <?php echo htmlspecialchars($b_from . ' to ' . $b_to); ?> (<?php echo $b_days; ?> days)</div>
        <div class="num"><?php echo number_format($total_b, 2) . ' ' . $unit; ?></div>
        <div class="fs-6"><?php echo number_format($avg_b, 2) . ' ' . $unit; ?> a day</div>
      </div></div>
      <div class="col-md-3"><div class="card p-3">
        <div class="fs-6">Difference</div>
        <div class="num <?php echo $total_diff > 0 ? 'up' : ($total_diff < 0 ? 'down' : ''); ?>">
          <?php echo ($total_diff > 0 ? '+' : '') . number_format($total_diff, 2) . ' ' . $unit; ?>
        </div>
        <div class="fs-6"><?php echo $total_pc === null ? '-' : (($total_pc > 0 ? '+' : '') . number_format($total_pc, 1) . '%'); ?></div>
      </div></div>
      <div class="col-md-3"><div class="card p-3">
        <div class="fs-6">A day against a day</div>
        <?php $day_diff = $avg_b - $avg_a; $day_pc = $avg_a > 0 ? $day_diff / $avg_a * 100 : null; ?>
        <div class="num <?php echo $day_diff > 0 ? 'up' : ($day_diff < 0 ? 'down' : ''); ?>">
          <?php echo ($day_diff > 0 ? '+' : '') . number_format($day_diff, 2) . ' ' . $unit; ?>
        </div>
        <div class="fs-6"><?php echo $day_pc === null ? '-' : (($day_pc > 0 ? '+' : '') . number_format($day_pc, 1) . '%'); ?> a day
          &middot; <?php echo count($rows); ?> meters</div>
      </div></div>
    </div>

    <?php if ($days_a || $days_b): ?>
    <div class="card p-3 mb-4">
      <h6 class="mb-3">Day by day</h6>
      <canvas id="usageChart" height="90"></canvas>
    </div>
    <?php endif; ?>

    <div class="card p-0">
      <div class="table-responsive">
        <table class="table table-sm fs-6 mb-0">
          <thead>
            <tr>
              <th>Meter</th><th>Type</th><th>Tenant</th><th>Shop</th>
              <?php if ($service === 'electricity'): ?><th class="text-end">CT</th><?php endif; ?>
              <th class="text-end border-start">A (<?php echo $unit; ?>)</th><th class="text-end">A a day</th>
              <th class="text-end border-start">B (<?php echo $unit; ?>)</th><th class="text-end">B a day</th>
              <th class="text-end border-start">Difference</th><th class="text-end">%</th><th>Readings</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?php echo htmlspecialchars($r['serial']); ?></td>
              <td><?php echo htmlspecialchars((string)$r['type']); ?></td>
              <td><?php echo htmlspecialchars((string)$r['tenant']); ?></td>
              <td><?php echo htmlspecialchars((string)$r['shop']); ?></td>
              <?php if ($service === 'electricity'): ?><td class="text-end"><?php echo number_format($r['ct'], 2); ?></td><?php endif; ?>
              <td class="text-end border-start"><?php echo $r['a'] === null ? '<span class="text-white-50">no readings</span>' : number_format($r['a'], 2); ?></td>
              <td class="text-end"><?php echo $r['a_day'] === null ? '-' : number_format($r['a_day'], 2); ?></td>
              <td class="text-end border-start"><?php echo $r['b'] === null ? '<span class="text-white-50">no readings</span>' : number_format($r['b'], 2); ?></td>
              <td class="text-end"><?php echo $r['b_day'] === null ? '-' : number_format($r['b_day'], 2); ?></td>
              <td class="text-end border-start <?php echo $r['diff'] > 0 ? 'up' : ($r['diff'] < 0 ? 'down' : ''); ?>">
                <?php echo ($r['diff'] > 0 ? '+' : '') . number_format($r['diff'], 2); ?>
              </td>
              <td class="text-end <?php echo $r['diff'] > 0 ? 'up' : ($r['diff'] < 0 ? 'down' : ''); ?>">
                <?php echo $r['pc'] === null ? '-' : (($r['pc'] > 0 ? '+' : '') . number_format($r['pc'], 1) . '%'); ?>
              </td>
              <td class="text-white-50"><?php echo (int)$r['readings_a']; ?> / <?php echo (int)$r['readings_b']; ?>
                <?php if ($r['method'] !== 'automatic'): ?><span class="badge bg-info text-dark ms-1">on site</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr class="fw-semibold">
              <td colspan="<?php echo $service === 'electricity' ? 5 : 4; ?>">Total</td>
              <td class="text-end border-start"><?php echo number_format($total_a, 2); ?></td>
              <td class="text-end"><?php echo number_format($avg_a, 2); ?></td>
              <td class="text-end border-start"><?php echo number_format($total_b, 2); ?></td>
              <td class="text-end"><?php echo number_format($avg_b, 2); ?></td>
              <td class="text-end border-start <?php echo $total_diff > 0 ? 'up' : ($total_diff < 0 ? 'down' : ''); ?>">
                <?php echo ($total_diff > 0 ? '+' : '') . number_format($total_diff, 2); ?>
              </td>
              <td class="text-end"><?php echo $total_pc === null ? '-' : number_format($total_pc, 1) . '%'; ?></td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

  <?php elseif ($property !== ''): ?>
    <div class="alert alert-secondary fs-6">No readings for these meters in either period.</div>
  <?php endif; ?>

</div>

<?php if ($property !== '' && $rows && ($days_a || $days_b)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ" crossorigin="anonymous"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const a = <?php echo json_encode(array_values($days_a)); ?>;
    const b = <?php echo json_encode(array_values($days_b)); ?>;
    const aLabels = <?php echo json_encode(array_keys($days_a)); ?>;
    const bLabels = <?php echo json_encode(array_keys($days_b)); ?>;
    const len = Math.max(a.length, b.length);
    const labels = [];
    for (let i = 0; i < len; i++) { labels.push('Day ' + (i + 1)); }
    const ctx = document.getElementById('usageChart');
    if (!ctx) return;
    new Chart(ctx.getContext('2d'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                { label: 'A', data: a, borderColor: '#0dcaf0', backgroundColor: '#0dcaf0', tension: 0.25, pointRadius: 0 },
                { label: 'B', data: b, borderColor: '#ff6b6b', backgroundColor: '#ff6b6b', tension: 0.25, pointRadius: 0 }
            ]
        },
        options: {
            responsive: true, animation: false,
            plugins: {
                legend: { position: 'bottom', labels: { color: '#ffffff' } },
                tooltip: { callbacks: { title: function (items) {
                    const i = items[0].dataIndex;
                    const which = items[0].datasetIndex === 0 ? aLabels : bLabels;
                    return which[i] || ('Day ' + (i + 1));
                } } }
            },
            scales: {
                x: { ticks: { color: '#cfd6dd' }, grid: { color: '#2c2c2c' } },
                y: { beginAtZero: true, ticks: { color: '#cfd6dd' }, grid: { color: '#2c2c2c' } }
            }
        }
    });
});
</script>
<?php endif; ?>
</body>
</html>
