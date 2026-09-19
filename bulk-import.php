<?php
// Bulk import of tenants, meters and manual readings from a CSV file.
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once __DIR__ . '/bootstrap.php';
lum_page('imports', 'view');
lum_use('audit');
ini_set('pcre.jit', '0');

// What can be imported. The columns a file may use are read from the database
// itself, so a heading that is not a real column is refused rather than ignored.
$LUM_IMPORT_TARGETS = [
    'meters' => [
        'label'    => 'Meters (the meter register)',
        'db'       => 'meters',
        'table'    => 'lum_meters',
        'required' => ['meter_serial'],
        'unique'   => ['meter_serial'],
        'example'  => 'meter_serial,meter_type,meter_property,meter_shop,meter_tenant,meter_location',
    ],
    'tenants' => [
        'label'    => 'Tenants',
        'db'       => 'tenants',
        'table'    => 'lum_tenants',
        'required' => ['tenant_property', 'tenant_name'],
        'unique'   => ['tenant_property', 'tenant_code', 'tenant_shop'],
        'example'  => 'tenant_property,tenant_name,tenant_code,tenant_shop,tenant_shop_area,tenant_comm_area,'
                    . 'tenant_electricalMeter_01,tenant_electricalMeter_01_ct_ratio,tenant_waterMeter_01,'
                    . 'tenant_electrical_tariff_charge,tenant_water_tariff_charge,tenant_occupancy_start_date',
    ],
    'water_readings' => [
        'label'    => 'Manual water readings',
        'db'       => 'manual',
        'table'    => 'manual_readings_water',
        'required' => ['property', 'reading', 'reading_date', 'water_serial'],
        'unique'   => ['water_serial', 'reading_date'],
        'example'  => 'property,tenant,shop,water_serial,reading,reading_date',
    ],
    'genrun_readings' => [
        'label'    => 'Generator run time',
        'db'       => 'manual',
        'table'    => 'manual_readings_genrun',
        'required' => [],
        'unique'   => [],
        'example'  => '',
    ],
];

$target_key = $_POST['target'] ?? $_GET['target'] ?? '';
$target     = $LUM_IMPORT_TARGETS[$target_key] ?? null;
$errors     = [];
$notices    = [];
$preview    = null;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

// The columns of the target table, with their type and whether they may be empty
function lum_import_columns(PDO $pdo, $table) {
    $stmt = $pdo->prepare("SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, EXTRA, COLUMN_KEY
                             FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
                            ORDER BY ORDINAL_POSITION");
    $stmt->execute(['t' => $table]);
    $cols = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cols[$c['COLUMN_NAME']] = [
            'type'     => strtolower($c['DATA_TYPE']),
            'nullable' => $c['IS_NULLABLE'] === 'YES',
            'auto'     => strpos((string)$c['EXTRA'], 'auto_increment') !== false,
        ];
    }
    return $cols;
}

// Read the uploaded file into headings and rows
function lum_import_read_csv($path, &$errors) {
    $rows = [];
    $fh = @fopen($path, 'r');
    if (!$fh) { $errors[] = 'The file could not be opened.'; return [[], []]; }
    $headings = fgetcsv($fh);
    if (!$headings) { fclose($fh); $errors[] = 'The file is empty.'; return [[], []]; }
    // Strip a byte order mark from the first heading, and tidy the rest
    $headings[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headings[0]);
    $headings = array_map(static function ($h) { return trim((string)$h); }, $headings);
    while (($line = fgetcsv($fh)) !== false) {
        if (count($line) === 1 && trim((string)$line[0]) === '') continue;   // blank line
        $rows[] = $line;
        if (count($rows) > 20000) { $errors[] = 'The file has more than 20,000 rows. Please split it.'; break; }
    }
    fclose($fh);
    return [$headings, $rows];
}

// Is this value usable in this column?
function lum_import_check_value($value, array $col) {
    $v = trim((string)$value);
    if ($v === '') return [null, null];                       // empty means "leave it"
    if (in_array($col['type'], ['int', 'bigint', 'smallint', 'tinyint'], true)) {
        if (!preg_match('/^-?\d+$/', $v)) return [null, 'must be a whole number'];
        return [$v, null];
    }
    if (in_array($col['type'], ['decimal', 'float', 'double'], true)) {
        $n = str_replace([' ', ','], ['', '.'], $v);
        if (!is_numeric($n)) return [null, 'must be a number'];
        return [$n, null];
    }
    if ($col['type'] === 'date' || $col['type'] === 'datetime') {
        $d = DateTime::createFromFormat($col['type'] === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s', $v);
        if (!$d) {
            $d = DateTime::createFromFormat('Y-m-d', substr($v, 0, 10));
            if (!$d) return [null, 'must be a date as YYYY-MM-DD'];
            $v = $d->format($col['type'] === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s');
        }
        return [$v, null];
    }
    return [$v, null];
}

// Check every row: headings, required values, types, duplicates in the file and in the database
function lum_import_validate(PDO $pdo, array $target, array $headings, array $rows, array $columns) {
    $unknown = [];
    foreach ($headings as $h) {
        if ($h !== '' && !isset($columns[$h])) $unknown[] = $h;
    }
    $usable = [];
    foreach ($headings as $i => $h) {
        if ($h !== '' && isset($columns[$h]) && !$columns[$h]['auto']) $usable[$h] = $i;
    }
    $missing = [];
    foreach ($target['required'] as $r) {
        if (!isset($usable[$r])) $missing[] = $r;
    }

    // What the unique columns already hold, so duplicates are recognised
    $existing = [];
    $uniq = array_values(array_filter($target['unique'], static function ($c) use ($usable) { return isset($usable[$c]); }));
    if ($uniq && !$missing && !$unknown) {
        $cols = '`' . implode('`, `', $uniq) . '`';
        foreach ($pdo->query("SELECT {$cols} FROM `{$target['table']}`")->fetchAll(PDO::FETCH_NUM) as $r) {
            $existing[implode('|', array_map(static function ($v) { return strtolower(trim((string)$v)); }, $r))] = true;
        }
    }

    $checked = ['unknown_headings' => $unknown, 'missing_required' => $missing, 'usable' => $usable, 'rows' => [],
                'ok' => 0, 'skip' => 0, 'bad' => 0];
    if ($unknown || $missing) return $checked;

    $seen = [];
    foreach ($rows as $n => $line) {
        $values = [];
        $problems = [];
        foreach ($usable as $name => $idx) {
            [$v, $why] = lum_import_check_value($line[$idx] ?? '', $columns[$name]);
            if ($why !== null) { $problems[] = $name . ' ' . $why; continue; }
            $values[$name] = $v;
        }
        foreach ($target['required'] as $r) {
            if (!isset($values[$r]) || $values[$r] === null || $values[$r] === '') $problems[] = $r . ' is required';
        }
        $status = 'import';
        if ($problems) {
            $status = 'error';
        } elseif ($uniq) {
            $key = implode('|', array_map(static function ($c) use ($values) {
                return strtolower(trim((string)($values[$c] ?? '')));
            }, $uniq));
            if (isset($existing[$key])) { $status = 'skip'; $problems[] = 'already on the system'; }
            elseif (isset($seen[$key]))  { $status = 'skip'; $problems[] = 'appears earlier in this file (row ' . $seen[$key] . ')'; }
            else { $seen[$key] = $n + 2; }
        }
        $checked['rows'][] = ['line' => $n + 2, 'values' => $values, 'status' => $status, 'problems' => $problems];
        $checked[$status === 'import' ? 'ok' : ($status === 'skip' ? 'skip' : 'bad')]++;
    }
    return $checked;
}

// ---------------------------------------------------------------------------
// Step 1: a file was uploaded - check it and show what would happen
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'check') {
    lum_require_access('imports', 'edit');
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        die('Security Error: Invalid CSRF Token. Request Blocked.');
    }
    if (!$target) {
        $errors[] = 'Please choose what you are importing.';
    } elseif (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Please choose a CSV file. (A file larger than the server allows arrives as an error.)';
    } else {
        $pdo = lum_db($target['db']);
        $columns = lum_import_columns($pdo, $target['table']);
        if (!$columns) {
            $errors[] = 'The table ' . $target['table'] . ' was not found.';
        } else {
            [$headings, $rows] = lum_import_read_csv($_FILES['csv']['tmp_name'], $errors);
            if (!$errors) {
                $preview = lum_import_validate($pdo, $target, $headings, $rows, $columns);
                $preview['file'] = basename($_FILES['csv']['name']);
                // Keep it for the second step; only the rows that would be imported
                $_SESSION['lum_import'] = [
                    'target' => $target_key,
                    'file'   => $preview['file'],
                    'rows'   => array_values(array_map(static function ($r) { return $r['values']; },
                                array_filter($preview['rows'], static function ($r) { return $r['status'] === 'import'; }))),
                ];
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Step 2: confirmed - write the rows
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import') {
    lum_require_access('imports', 'edit');
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
        die('Security Error: Invalid CSRF Token. Request Blocked.');
    }
    $held = $_SESSION['lum_import'] ?? null;
    if (!$held || empty($held['rows']) || !isset($LUM_IMPORT_TARGETS[$held['target']])) {
        $errors[] = 'There is nothing waiting to be imported. Please upload the file again.';
    } else {
        $target_key = $held['target'];
        $target = $LUM_IMPORT_TARGETS[$target_key];
        $pdo = lum_db($target['db']);
        $written = 0;
        $failed = [];
        try {
            $pdo->beginTransaction();
            foreach ($held['rows'] as $i => $values) {
                if (!$values) continue;
                $cols = array_keys($values);
                $sql = 'INSERT INTO `' . $target['table'] . '` (`' . implode('`, `', $cols) . '`) VALUES ('
                     . implode(', ', array_map(static function ($c) { return ':' . $c; }, $cols)) . ')';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($values);
                $written++;
            }
            // A new tenant needs its stable reference, as the tenant form gives it
            if ($target_key === 'tenants') {
                $pdo->exec("UPDATE lum_tenants SET tenant_ref = CONCAT('T', LPAD(tenant_id, 5, '0'))
                             WHERE tenant_ref IS NULL OR tenant_ref = ''");
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $written = 0;
            error_log('LUM bulk import failed: ' . $e->getMessage());
            $errors[] = 'The import was stopped and nothing was saved. The error has been logged.';
        }
        if ($written > 0) {
            $notices[] = $written . ' row(s) imported into ' . $target['label'] . '.';
            if ($target_key === 'tenants') {
                $notices[] = 'Run the meter assignment backfill afterwards so the new tenants get their meter assignments.';
            }
            if (function_exists('lum_audit_log')) {
                lum_audit_log('IMPORT', 'bulk_import', 0, $target['label'] . ' - ' . ($held['file'] ?? 'file'),
                              null, null, null, $written . ' row(s) imported');
            }
        }
        unset($_SESSION['lum_import']);
    }
}

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf_token = $_SESSION['csrf_token'];
$can_import = lum_can('imports', 'edit');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bulk Import | Lynx Utility Management</title>
    <?php include LUM_ROOT . '/Layout/head-assets.php'; ?>
<style>
        body { background: #121212; color: #e0e0e0; }
        .card, .table { background: #1a1a1a; color: #e0e0e0; }
        .table > :not(caption) > * > * { background: #1a1a1a; color: #e0e0e0; border-color: #2c2c2c; }
        .row-import { border-left: 3px solid #198754; }
        .row-skip   { border-left: 3px solid #6c757d; }
        .row-error  { border-left: 3px solid #dc3545; }
        code { color: #ff8a8a; }
    </style>
</head>
<body>
<?php include LUM_ROOT . '/Layout/top-navbar.php'; ?>

<?php
$lum_sub_title = 'Bulk Import';
$lum_sub_back = 'https://lynx-um.co.za/index.php';
include LUM_ROOT . '/Layout/sub-navbar.php';
?>

<div class="container-fluid px-4 pb-5" style="max-width: 1200px;">

<?php foreach ($errors as $e): ?>
  <div class="alert alert-danger fs-6"><?php echo htmlspecialchars($e); ?></div>
<?php endforeach; ?>
<?php foreach ($notices as $n): ?>
  <div class="alert alert-success fs-6"><?php echo htmlspecialchars($n); ?></div>
<?php endforeach; ?>

<?php if (!$can_import): ?>
  <div class="alert alert-warning fs-6">You may look at this page, but importing needs edit rights.</div>
<?php endif; ?>

<?php if ($preview === null): ?>
  <div class="card p-4 mb-4">
    <h5 class="mb-3">1. What are you importing?</h5>
    <form method="POST" enctype="multipart/form-data" action="">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
      <input type="hidden" name="action" value="check">
      <div class="row g-3 align-items-end">
        <div class="col-md-5">
          <label class="form-label fs-6" for="target">Type</label>
          <select class="form-select bg-dark text-white" id="target" name="target" required>
            <option value="">Choose...</option>
            <?php foreach ($LUM_IMPORT_TARGETS as $k => $t): ?>
              <option value="<?php echo htmlspecialchars($k); ?>" <?php echo $k === $target_key ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($t['label']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-5">
          <label class="form-label fs-6" for="csv">CSV file</label>
          <input class="form-control bg-dark text-white" type="file" id="csv" name="csv" accept=".csv,text/csv" required>
        </div>
        <div class="col-md-2">
          <button type="submit" class="btn btn-danger w-100" <?php echo $can_import ? '' : 'disabled'; ?>>Check the file</button>
        </div>
      </div>
    </form>
  </div>

  <div class="card p-4">
    <h5 class="mb-3">File layout</h5>
    <table class="table table-sm fs-6">
      <thead><tr><th>Type</th><th>Example heading line</th></tr></thead>
      <tbody>
      <?php foreach ($LUM_IMPORT_TARGETS as $k => $t): ?>
        <tr>
          <td class="text-nowrap"><?php echo htmlspecialchars($t['label']); ?></td>
          <td><code><?php echo htmlspecialchars($t['example'] ?: '(the column names of ' . $t['table'] . ')'); ?></code></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php else: ?>
  <div class="card p-4 mb-4">
    <h5 class="mb-3">2. What this file would do</h5>
    <p class="fs-6"><?php echo htmlspecialchars($preview['file']); ?> &middot; <?php echo htmlspecialchars($target['label']); ?></p>

    <?php if (!empty($preview['unknown_headings'])): ?>
      <div class="alert alert-danger fs-6">
        These headings are not columns of <code><?php echo htmlspecialchars($target['table']); ?></code>:
        <strong><?php echo htmlspecialchars(implode(', ', $preview['unknown_headings'])); ?></strong>.
        Correct the heading line and upload it again.
      </div>
    <?php elseif (!empty($preview['missing_required'])): ?>
      <div class="alert alert-danger fs-6">
        These columns must be in the file: <strong><?php echo htmlspecialchars(implode(', ', $preview['missing_required'])); ?></strong>.
      </div>
    <?php else: ?>
      <div class="d-flex gap-4 mb-3 fs-6">
        <span><i class="bi bi-check-circle text-success me-1"></i><strong><?php echo (int)$preview['ok']; ?></strong> to import</span>
        <span><i class="bi bi-dash-circle text-secondary me-1"></i><strong><?php echo (int)$preview['skip']; ?></strong> skipped (already there)</span>
        <span><i class="bi bi-x-circle text-danger me-1"></i><strong><?php echo (int)$preview['bad']; ?></strong> with a problem</span>
      </div>

      <div class="table-responsive" style="max-height: 460px; overflow-y: auto;">
        <table class="table table-sm fs-6">
          <thead class="sticky-top"><tr><th>Line</th><th>Status</th><th>Detail</th><th>Values</th></tr></thead>
          <tbody>
          <?php foreach (array_slice($preview['rows'], 0, 500) as $r): ?>
            <tr class="row-<?php echo htmlspecialchars($r['status']); ?>">
              <td><?php echo (int)$r['line']; ?></td>
              <td class="text-nowrap">
                <?php echo $r['status'] === 'import' ? 'import' : ($r['status'] === 'skip' ? 'skip' : 'problem'); ?>
              </td>
              <td><?php echo htmlspecialchars(implode('; ', $r['problems'])); ?></td>
              <td class="text-truncate" style="max-width: 520px;">
                <?php
                  $bits = [];
                  foreach ($r['values'] as $k => $v) { if ($v !== null && $v !== '') $bits[] = $k . '=' . $v; }
                  echo htmlspecialchars(implode(', ', array_slice($bits, 0, 8)));
                ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (count($preview['rows']) > 500): ?>
        <p class="fs-6 text-secondary">Showing the first 500 of <?php echo count($preview['rows']); ?> rows; all of them will be imported.</p>
      <?php endif; ?>

      <form method="POST" action="" class="mt-3">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="action" value="import">
        <button type="submit" class="btn btn-danger me-2" <?php echo ($can_import && $preview['ok'] > 0) ? '' : 'disabled'; ?>>
          Import the <?php echo (int)$preview['ok']; ?> row(s)
        </button>
        <a class="btn btn-outline-light" href="bulk-import.php">Start again</a>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

</div>
</body>
</html>
