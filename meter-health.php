<?php
// Meter communication and reading coverage: which meters went quiet, when they went quiet
// together (one fault, not many), and how complete the readings are for a billing period.
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once __DIR__ . '/bootstrap.php';
lum_page('meters', 'view');
ini_set('pcre.jit', '0');

$meter_pdo  = lum_db('meters');
$tenant_pdo = lum_db('tenants');
$obis_pdo   = lum_db('obis');

// --- What the page is showing ---------------------------------------------
$property   = trim((string)($_GET['property'] ?? ''));
$quiet_days = max(1, min(365, (int)($_GET['days'] ?? 7)));
$from       = $_GET['from'] ?? date('Y-m-01');
$to         = $_GET['to']   ?? date('Y-m-d');
foreach (['from', 'to'] as $d) {
    $v = DateTime::createFromFormat('Y-m-d', $$d);
    if (!$v || $v->format('Y-m-d') !== $$d) { $$d = $d === 'from' ? date('Y-m-01') : date('Y-m-d'); }
}
$year = (int)substr($to, 0, 4);

// The same limits the dashboard uses (Configurations -> Meter status limits)
$status_online_hours = 6.0;
$status_lag_hours = 72.0;
try {
    foreach ($meter_pdo->query("SELECT setting_key, setting_value FROM sys_db_information.lum_system_settings
                                 WHERE setting_key IN ('meter_online_hours', 'meter_lag_hours')") as $r) {
        $v = trim((string)$r['setting_value']);
        if ($v === '') continue;
        if ($r['setting_key'] === 'meter_online_hours') $status_online_hours = (float)$v;
        if ($r['setting_key'] === 'meter_lag_hours') $status_lag_hours = (float)$v;
    }
} catch (Throwable $e) {
}

function lumHealthStatus($last_reading, $online_hours, $lag_hours) {
    if (!$last_reading) return ['No readings', 'danger'];
    $hours = (time() - strtotime($last_reading)) / 3600;
    if ($hours <= $online_hours) return ['Online', 'success'];
    if ($hours <= $lag_hours) return ['Data lag', 'warning text-dark'];
    return ['Offline', 'danger'];
}

$search = trim((string)($_GET['search'] ?? ''));

// Properties the user may see
$property_list = $meter_pdo->query("SELECT DISTINCT property FROM sys_db_tenants.lum_tenant_meters ORDER BY property")->fetchAll(PDO::FETCH_COLUMN);
if (!empty($_SESSION['assigned_properties'])) {
    $allowed = array_map('trim', explode(',', $_SESSION['assigned_properties']));
    $property_list = array_values(array_intersect($property_list, $allowed));
    if ($property !== '' && !in_array($property, $allowed, true)) $property = '';
}

// --- The meters that are supposed to be delivering readings ----------------
$sql = "SELECT m.meter_serial, m.meter_kind, m.property, m.tenant_ref,
               COALESCE(t.tenant_name, '') AS business, COALESCE(t.tenant_shop, '') AS shop,
               COALESCE(t.tenant_type, 'tenant') AS record_type,
               COALESCE(r.reading_method, 'automatic') AS reading_method,
               COALESCE(r.reading_note, '') AS reading_note,
               r.expected_interval_min
          FROM sys_db_tenants.lum_tenant_meters m
          LEFT JOIN sys_db_tenants.lum_tenants t ON t.tenant_ref = m.tenant_ref
          LEFT JOIN sys_db_meters.lum_meters r ON r.meter_serial = m.meter_serial
         WHERE (m.valid_to IS NULL OR m.valid_to >= CURDATE())";
$args = [];
if ($property !== '') { $sql .= " AND m.property = :p"; $args['p'] = $property; }
$sql .= " ORDER BY m.property, business, m.meter_serial";
$stmt = $meter_pdo->prepare($sql);
$stmt->execute($args);
$meters = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Property meters (common area, solar, mains) belong here too
$sql = "SELECT p.meter_serial,
               CASE WHEN p.role LIKE '%water%' OR p.role LIKE '%flow%' THEN 'water' ELSE 'electricity' END AS meter_kind,
               p.property, '' AS tenant_ref, CONCAT('Property meter (', p.role, ')') AS business, '' AS shop,
               'common' AS record_type,
               COALESCE(r.reading_method, 'automatic') AS reading_method, COALESCE(r.reading_note, '') AS reading_note,
               r.expected_interval_min
          FROM sys_db_meters.lum_property_meters p
          LEFT JOIN sys_db_meters.lum_meters r ON r.meter_serial = p.meter_serial
         WHERE p.active = 1";
if ($property !== '') { $sql .= " AND p.property = :p"; }
$stmt = $meter_pdo->prepare($sql);
$stmt->execute($args);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) { $meters[] = $row; }

// --- Their last reading, and how many days they reported in the period -----
$tables = [
    'electricity' => ["db_obis_11180_{$year}.tb_obis_11180_{$year}", "db_obis_11181_{$year}.tb_obis_11181_{$year}", "db_obis_11182_{$year}.tb_obis_11182_{$year}"],
    'water'       => ["db_obis_81100_{$year}.tb_obis_81100_{$year}"],
];
$last = [];
$days_seen = [];
$in_period = [];   // per meter: readings, and the first and last inside the period
foreach ($tables as $kind => $list) {
    foreach ($list as $tb) {
        try {
            foreach ($obis_pdo->query("SELECT meter_serial, MAX(Time_stamp) AS last_ts FROM {$tb} GROUP BY meter_serial") as $r) {
                $s = trim((string)$r['meter_serial']);
                if (!isset($last[$s]) || $r['last_ts'] > $last[$s]) $last[$s] = $r['last_ts'];
            }
            $q = $obis_pdo->prepare("SELECT meter_serial, COUNT(DISTINCT DATE(Time_stamp)) AS days,
                                            COUNT(*) AS readings,
                                            MIN(Time_stamp) AS first_in_period, MAX(Time_stamp) AS last_in_period
                                       FROM {$tb} WHERE Time_stamp >= :f AND Time_stamp < DATE_ADD(:t, INTERVAL 1 DAY)
                                      GROUP BY meter_serial");
            $q->execute(['f' => $from, 't' => $to]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $s = trim((string)$r['meter_serial']);
                $days_seen[$s] = max($days_seen[$s] ?? 0, (int)$r['days']);
                $in_period[$s]['readings'] = ($in_period[$s]['readings'] ?? 0) + (int)$r['readings'];
                if (!isset($in_period[$s]['first']) || $r['first_in_period'] < $in_period[$s]['first']) $in_period[$s]['first'] = $r['first_in_period'];
                if (!isset($in_period[$s]['last']) || $r['last_in_period'] > $in_period[$s]['last']) $in_period[$s]['last'] = $r['last_in_period'];
            }
        } catch (Throwable $e) {
            // A year with no data yet: nothing to add
        }
    }
}

// --- Sort them into what matters -------------------------------------------
$period_days = max(1, (int)((strtotime($to) - strtotime($from)) / 86400) + 1);
$quiet = [];        // automatic meters with nothing recent
$manual = [];       // read on site, so silence is expected
$partial = [];      // reporting, but with gaps in the period
$fine = 0;
$by_property = [];

foreach ($meters as $m) {
    $s = trim((string)$m['meter_serial']);
    $m['last_reading'] = $last[$s] ?? null;
    $m['days_reported'] = $days_seen[$s] ?? 0;
    $m['coverage'] = (int)round($m['days_reported'] / $period_days * 100);

    // How far the first and last readings sit from the edges of the period. A closing
    // reading hours before the period ends moves that consumption into the next bill.
    $m['first_in_period'] = $in_period[$s]['first'] ?? null;
    $m['last_in_period']  = $in_period[$s]['last'] ?? null;
    $m['start_gap_hours'] = $m['first_in_period']
        ? round((strtotime($m['first_in_period']) - strtotime($from . ' 00:00:00')) / 3600, 1) : null;
    $m['end_gap_hours'] = $m['last_in_period']
        ? round((strtotime($to . ' 23:59:59') - strtotime($m['last_in_period'])) / 3600, 1) : null;

    // Readings received against what this meter is supposed to deliver
    $interval = (int)($m['expected_interval_min'] ?? 0);
    $m['readings_in_period'] = $in_period[$s]['readings'] ?? 0;
    $m['expected_readings'] = $interval > 0 ? (int)round($period_days * 1440 / $interval) : 0;
    $m['reading_share'] = $m['expected_readings'] > 0
        ? (int)round($m['readings_in_period'] / $m['expected_readings'] * 100) : null;
    $m['days_quiet'] = $m['last_reading'] ? (int)floor((time() - strtotime($m['last_reading'])) / 86400) : null;
    [$m['status'], $m['status_colour']] = lumHealthStatus($m['last_reading'], $status_online_hours, $status_lag_hours);
    if ($search !== '' && stripos($m['meter_serial'] . ' ' . $m['business'] . ' ' . $m['shop'], $search) === false) {
        continue;
    }

    $p = $m['property'] ?: '(no property)';
    if (!isset($by_property[$p])) $by_property[$p] = ['total' => 0, 'quiet' => 0, 'manual' => 0, 'partial' => 0];
    $by_property[$p]['total']++;

    if ($m['reading_method'] !== 'automatic') {
        $manual[] = $m; $by_property[$p]['manual']++;
    } elseif ($m['last_reading'] === null || $m['days_quiet'] >= $quiet_days) {
        $quiet[] = $m; $by_property[$p]['quiet']++;
    } elseif ($m['coverage'] < 90) {
        $partial[] = $m; $by_property[$p]['partial']++;
    } else {
        $fine++;
    }
}

// Meters delivering readings that belong to nobody
$unassigned = [];
try {
    $known = [];
    foreach ($meters as $m) { $known[trim((string)$m['meter_serial'])] = true; }
    foreach ($last as $serial => $ts) {
        if (isset($known[$serial])) continue;
        if (strtotime($ts) < strtotime('-45 days')) continue;
        if ($search !== '' && stripos($serial, $search) === false) continue;
        $unassigned[] = ['meter_serial' => $serial, 'last_reading' => $ts];
    }
    usort($unassigned, static function ($a, $b) { return strcmp($b['last_reading'], $a['last_reading']); });
} catch (Throwable $e) {
}

// Readings too far from the edges of the period: the bill starts or stops early
$boundary = [];
foreach ($meters as $m) {
    if ($m['reading_method'] !== 'automatic' || $m['last_reading'] === null) continue;
    if (($m['start_gap_hours'] !== null && $m['start_gap_hours'] > 6)
        || ($m['end_gap_hours'] !== null && $m['end_gap_hours'] > 6)) {
        $boundary[] = $m;
    }
}
usort($boundary, static function ($a, $b) {
    return max($b['start_gap_hours'] ?? 0, $b['end_gap_hours'] ?? 0) <=> max($a['start_gap_hours'] ?? 0, $a['end_gap_hours'] ?? 0);
});

// Meters that went quiet on the same day are one fault, not several
$outages = [];
foreach ($quiet as $m) {
    if ($m['last_reading'] === null) continue;
    $day = substr($m['last_reading'], 0, 10);
    $outages[$m['property'] . '|' . $day][] = $m;
}
$outages = array_filter($outages, static function ($g) { return count($g) >= 3; });
krsort($outages);

usort($quiet, static function ($a, $b) { return ($b['days_quiet'] ?? 99999) <=> ($a['days_quiet'] ?? 99999); });
usort($partial, static function ($a, $b) { return $a['coverage'] <=> $b['coverage']; });
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Meter Health | Lynx Utility Management</title>
    <?php include LUM_ROOT . '/Layout/head-assets.php'; ?>
<style>
        body { background: #121212; color: #ffffff; }
        .card, .table, .form-select, .form-control, h6, label, td, th { color: #ffffff; }
        .text-secondary, .text-muted, .small { color: #cfd6dd !important; }
        .card { background: #1a1a1a; border: 1px solid #2c2c2c; }
        .table > :not(caption) > * > * { background: #1a1a1a; color: #e0e0e0; border-color: #2c2c2c; }
        .num { font-size: 1.6rem; font-weight: 600; }
        .outage { border-left: 3px solid #dc3545; }
    </style>
</head>
<body>
<?php include LUM_ROOT . '/Layout/top-navbar.php'; ?>

<?php
$lum_sub_title = 'Meter Health';
$lum_sub_back = lum_app_url('/Meter Management/meter-overview.php');
include LUM_ROOT . '/Layout/sub-navbar.php';
?>

<div class="container-fluid px-4 py-4">

  <form method="GET" class="row g-2 align-items-end mb-4">
    <div class="col-md-3">
      <label class="form-label fs-6" for="property">Property</label>
      <select class="form-select form-select-sm bg-dark text-white" id="property" name="property">
        <option value="">All properties</option>
        <?php foreach ($property_list as $p): ?>
          <option value="<?php echo htmlspecialchars($p); ?>" <?php echo $p === $property ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label fs-6" for="days">Quiet after</label>
      <select class="form-select form-select-sm bg-dark text-white" id="days" name="days">
        <?php foreach ([1, 2, 3, 7, 14, 30] as $d): ?>
          <option value="<?php echo $d; ?>" <?php echo $d === $quiet_days ? 'selected' : ''; ?>><?php echo $d; ?> day(s)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label fs-6" for="from">Period from</label>
      <input type="date" class="form-control form-control-sm bg-dark text-white" id="from" name="from" value="<?php echo htmlspecialchars($from); ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label fs-6" for="to">to</label>
      <input type="date" class="form-control form-control-sm bg-dark text-white" id="to" name="to" value="<?php echo htmlspecialchars($to); ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label fs-6" for="search">Search</label>
      <input type="text" class="form-control form-control-sm bg-dark text-white" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="meter, tenant or shop">
    </div>
    <div class="col-md-1">
      <button type="submit" class="btn btn-danger btn-sm px-4">Show</button>
    </div>
  </form>

  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="card p-3"><div class="num text-success"><?php echo $fine; ?></div><div class="fs-6 text-white">reporting normally</div></div></div>
    <div class="col-6 col-md-3"><div class="card p-3"><div class="num text-danger"><?php echo count($quiet); ?></div><div class="fs-6 text-white">quiet for <?php echo $quiet_days; ?>+ days</div></div></div>
    <div class="col-6 col-md-3"><div class="card p-3"><div class="num text-warning"><?php echo count($partial); ?></div><div class="fs-6 text-white">gaps in the period</div></div></div>
    <div class="col-6 col-md-3"><div class="card p-3"><div class="num text-info"><?php echo count($manual); ?></div><div class="fs-6 text-white">read on site</div></div></div>
    <div class="col-6 col-md-3"><div class="card p-3"><div class="num <?php echo $boundary ? 'text-warning' : 'text-success'; ?>"><?php echo count($boundary); ?></div><div class="fs-6 text-white">first or last reading far from the period edge</div></div></div>
  </div>

  <?php if ($outages): ?>
  <div class="card p-3 mb-4 outage">
    <h6 class="mb-3"><i class="bi bi-exclamation-triangle me-2 text-danger"></i>Meters that went quiet together</h6>
    <?php foreach ($outages as $key => $group):
        [$prop, $day] = explode('|', $key); ?>
      <p class="fs-6 mb-2">
        <strong><?php echo htmlspecialchars($prop); ?></strong> &middot; <?php echo count($group); ?> meters stopped on
        <strong><?php echo htmlspecialchars($day); ?></strong>
        (<?php echo (int)floor((time() - strtotime($day)) / 86400); ?> days ago):
        <span class="text-white-50"><?php
          echo htmlspecialchars(implode(', ', array_map(static function ($m) { return $m['meter_serial']; }, array_slice($group, 0, 12))));
          if (count($group) > 12) echo ' and ' . (count($group) - 12) . ' more';
        ?></span>
      </p>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($boundary): ?>
  <div class="card p-3 mb-4">
    <h6 class="mb-3"><i class="bi bi-arrows-collapse me-2 text-warning"></i>Readings that do not reach the edges of the period</h6>
    <div class="table-responsive" style="max-height: 360px; overflow-y: auto;">
      <table class="table table-sm fs-6">
        <thead class="sticky-top"><tr><th>Property</th><th>Tenant</th><th>Meter</th><th>First reading</th><th>Hours after the start</th><th>Last reading</th><th>Hours before the end</th></tr></thead>
        <tbody>
        <?php foreach ($boundary as $m): ?>
          <tr>
            <td><?php echo htmlspecialchars($m['property']); ?></td>
            <td><?php echo htmlspecialchars($m['business'] ?: '-'); ?></td>
            <td><?php echo htmlspecialchars($m['meter_serial']); ?></td>
            <td><?php echo $m['first_in_period'] ? htmlspecialchars(substr($m['first_in_period'], 0, 16)) : '-'; ?></td>
            <td class="<?php echo ($m['start_gap_hours'] > 6) ? 'text-warning' : ''; ?>"><?php echo $m['start_gap_hours'] === null ? '-' : $m['start_gap_hours']; ?></td>
            <td><?php echo $m['last_in_period'] ? htmlspecialchars(substr($m['last_in_period'], 0, 16)) : '-'; ?></td>
            <td class="<?php echo ($m['end_gap_hours'] > 6) ? 'text-warning' : ''; ?>"><?php echo $m['end_gap_hours'] === null ? '-' : $m['end_gap_hours']; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($quiet): ?>
  <div class="card p-3 mb-4">
    <h6 class="mb-3">Not reporting (<?php echo count($quiet); ?>)</h6>
    <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
      <table class="table table-sm fs-6">
        <thead class="sticky-top"><tr><th>Property</th><th>Tenant</th><th>Shop</th><th>Meter</th><th>Type</th><th>Status</th><th>Last reading</th><th>Days</th></tr></thead>
        <tbody>
        <?php foreach ($quiet as $m): ?>
          <tr>
            <td><?php echo htmlspecialchars($m['property']); ?></td>
            <td><?php echo htmlspecialchars($m['business'] ?: '-'); ?><?php echo $m['record_type'] === 'project' ? ' <span class="badge bg-secondary">project</span>' : ''; ?></td>
            <td><?php echo htmlspecialchars($m['shop']); ?></td>
            <td><?php echo htmlspecialchars($m['meter_serial']); ?></td>
            <td><?php echo htmlspecialchars($m['meter_kind']); ?></td>
            <td><span class="badge bg-<?php echo htmlspecialchars($m['status_colour']); ?>"><?php echo htmlspecialchars($m['status']); ?></span></td>
            <td><?php echo $m['last_reading'] ? htmlspecialchars(substr($m['last_reading'], 0, 16)) : '<span class="text-danger">never</span>'; ?></td>
            <td><?php echo $m['days_quiet'] === null ? '-' : (int)$m['days_quiet']; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($partial): ?>
  <div class="card p-3 mb-4">
    <h6 class="mb-3">Reporting, but with gaps in <?php echo htmlspecialchars($from); ?> to <?php echo htmlspecialchars($to); ?> (<?php echo count($partial); ?>)</h6>
    <div class="table-responsive" style="max-height: 360px; overflow-y: auto;">
      <table class="table table-sm fs-6">
        <thead class="sticky-top"><tr><th>Property</th><th>Tenant</th><th>Shop</th><th>Meter</th><th>Days with readings</th><th>Coverage</th><th>Readings received</th></tr></thead>
        <tbody>
        <?php foreach ($partial as $m): ?>
          <tr>
            <td><?php echo htmlspecialchars($m['property']); ?></td>
            <td><?php echo htmlspecialchars($m['business'] ?: '-'); ?></td>
            <td><?php echo htmlspecialchars($m['shop']); ?></td>
            <td><?php echo htmlspecialchars($m['meter_serial']); ?></td>
            <td><?php echo (int)$m['days_reported']; ?> of <?php echo $period_days; ?></td>
            <td><?php echo (int)$m['coverage']; ?>%</td>
            <td><?php echo $m['expected_readings'] > 0
                    ? (int)$m['readings_in_period'] . ' of ' . (int)$m['expected_readings'] . ' (' . (int)$m['reading_share'] . '%)'
                    : (int)$m['readings_in_period']; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($unassigned): ?>
  <div class="card p-3 mb-4">
    <h6 class="mb-3">Delivering readings but on no tenant and no property (<?php echo count($unassigned); ?>)</h6>
    <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
      <table class="table table-sm fs-6">
        <thead class="sticky-top"><tr><th>Meter</th><th>Last reading</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($unassigned, 0, 200) as $u): ?>
          <tr>
            <td><?php echo htmlspecialchars($u['meter_serial']); ?></td>
            <td><?php echo htmlspecialchars(substr($u['last_reading'], 0, 16)); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <div class="card p-3 mb-4">
    <h6 class="mb-3">Per property</h6>
    <table class="table table-sm fs-6">
      <thead><tr><th>Property</th><th>Meters</th><th>Quiet</th><th>With gaps</th><th>Read on site</th></tr></thead>
      <tbody>
      <?php foreach ($by_property as $p => $c): ?>
        <tr>
          <td><?php echo htmlspecialchars($p); ?></td>
          <td><?php echo (int)$c['total']; ?></td>
          <td class="<?php echo $c['quiet'] ? 'text-danger' : ''; ?>"><?php echo (int)$c['quiet']; ?></td>
          <td class="<?php echo $c['partial'] ? 'text-warning' : ''; ?>"><?php echo (int)$c['partial']; ?></td>
          <td class="text-info"><?php echo (int)$c['manual']; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($manual): ?>
  <div class="card p-3">
    <h6 class="mb-3">Read on site (<?php echo count($manual); ?>) - silence here is expected</h6>
    <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
      <table class="table table-sm fs-6">
        <thead class="sticky-top"><tr><th>Property</th><th>Tenant</th><th>Meter</th><th>Why</th><th>Last automatic reading</th></tr></thead>
        <tbody>
        <?php foreach ($manual as $m): ?>
          <tr>
            <td><?php echo htmlspecialchars($m['property']); ?></td>
            <td><?php echo htmlspecialchars($m['business'] ?: '-'); ?></td>
            <td><?php echo htmlspecialchars($m['meter_serial']); ?></td>
            <td><?php echo htmlspecialchars($m['reading_note'] ?: $m['reading_method']); ?></td>
            <td><?php echo $m['last_reading'] ? htmlspecialchars(substr($m['last_reading'], 0, 10)) : 'none'; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

</div>
</body>
</html>
