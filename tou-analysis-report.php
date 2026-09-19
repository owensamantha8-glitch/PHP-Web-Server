<?php
require_once __DIR__ . '/bootstrap.php';
lum_page('consumption_slips', 'view');

lum_connect('obis');
lum_use('reporting');

$meter_id = $_GET['meter_id'] ?? '';
$start = $_GET['start_date'] ?? '';
$end = $_GET['end_date'] ?? '';
$obis_code = $_GET['obis_code'] ?? '1.1.1.8.0';
$tou_algo = $_GET['tou_algorithm'] ?? 'None';
$tenant_id = $_GET['tenant_id'] ?? '';

// Only accept real dates and known values
$lum_is_date = function ($d) {
    $dt = DateTime::createFromFormat('Y-m-d', (string)$d);
    return ($dt && $dt->format('Y-m-d') === $d);
};
if ($start !== '' && !$lum_is_date($start)) $start = '';
if ($end !== '' && !$lum_is_date($end)) $end = '';
if (!in_array($obis_code, ['1.1.1.8.0', '1.1.1.8.1', '1.1.1.8.2'], true)) $obis_code = '1.1.1.8.0';
// Algorithms from Tariffs -> TOU Periods
$lum_tou_algos = function_exists('lumTouAlgorithmList') ? lumTouAlgorithmList() : ['Tshwane', 'Ekurhuleni', 'Bitou', 'George'];
if (!in_array($tou_algo, array_merge($lum_tou_algos, ['None']), true)) $tou_algo = 'None';
$tenant_id = ($tenant_id !== '' && ctype_digit((string)$tenant_id)) ? (int)$tenant_id : '';

// Property access: restricted users may only analyse meters of tenants on their own properties
if (lum_allowed_properties() !== null) {
    $lum_meter_ok = false;
    if ($tenant_id !== '') {
        lum_connect('tenants');
        $lum_t = $tenant_db_conn->prepare("SELECT tenant_property, tenant_electricalMeter_01, tenant_electricalMeter_02, tenant_electricalMeter_03 FROM lum_tenants WHERE tenant_id = :id");
        $lum_t->execute(['id' => $tenant_id]);
        $lum_row = $lum_t->fetch(PDO::FETCH_ASSOC);
        if ($lum_row && lum_can_access_property($lum_row['tenant_property'])) {
            $lum_meters = array_map('strval', [$lum_row['tenant_electricalMeter_01'], $lum_row['tenant_electricalMeter_02'], $lum_row['tenant_electricalMeter_03']]);
            $lum_meter_ok = in_array((string)$meter_id, $lum_meters, true);
        }
    }
    if (!$lum_meter_ok) {
        lum_deny('You do not have access to this meter.');
    }
}

// The back link restores the slip's settings
$back_url = "view-consumption-slip.php";
$query_params = [];
if (!empty($tenant_id)) $query_params['tenant_id'] = $tenant_id;
if (!empty($start)) $query_params['start_date'] = $start;
if (!empty($end)) $query_params['end_date'] = $end;
if (!empty($obis_code)) $query_params['obis_code'] = $obis_code;
if (!empty($tou_algo)) $query_params['tou_algorithm'] = $tou_algo;

if (!empty($query_params)) {
    $back_url .= '?' . http_build_query($query_params);
}

if (empty($meter_id) || empty($start) || empty($end) || $tou_algo === 'None') {
    die("<div style='padding:20px; font-family:sans-serif; color:#fff; background:#121212; height:100vh;'>
            <div class='mb-4'>
                <a href='" . htmlspecialchars($back_url) . "' class='btn btn-outline-light btn-sm'><i class='bi bi-arrow-left me-1'></i> Back to Slip</a>
            </div>
            <h4>Invalid Request</h4>
            <p>Missing parameters or the selected tenant is not on a Time of Use (TOU) tariff.</p>
         </div>");
}

$public_holidays = lumPublicHolidays();

$clean_serial = @preg_replace('/[^a-zA-Z0-9]/', '', $meter_id);
$cleanCode = @preg_replace('/[^0-9]/', '', $obis_code);
$readingYear = date('Y', strtotime($start));

$db = "db_obis_" . $cleanCode . "_" . $readingYear;
$table = "tb_obis_" . $cleanCode . "_" . $readingYear;
$col = $cleanCode . "_value";

// Daily reading window per OBIS code
$ranges = get_obis_time_ranges($obis_db_conn);
$time_start = $ranges[$obis_code]['start'] ?? ' 00:00:00';
$time_end = $ranges[$obis_code]['end'] ?? ' 23:59:59';

$stmt_open = $obis_db_conn->prepare("SELECT Time_stamp, `$col` as val FROM `$db`.`$table` WHERE meter_serial = :serial AND Time_stamp >= :start ORDER BY Time_stamp ASC LIMIT 1");
$stmt_open->execute(['serial' => $clean_serial, 'start' => $start . $time_start]);
$open_row = $stmt_open->fetch(PDO::FETCH_ASSOC);
$open_val = $open_row ? (float)$open_row['val'] : 0;
$open_ts = $open_row ? $open_row['Time_stamp'] : null;

$stmt_close = $obis_db_conn->prepare("SELECT Time_stamp, `$col` as val FROM `$db`.`$table` WHERE meter_serial = :serial AND Time_stamp <= :end ORDER BY Time_stamp DESC LIMIT 1");
$stmt_close->execute(['serial' => $clean_serial, 'end' => $end . $time_end]);
$close_row = $stmt_close->fetch(PDO::FETCH_ASSOC);
$close_val = $close_row ? (float)$close_row['val'] : 0;
$close_ts = $close_row ? $close_row['Time_stamp'] : null;

$kwh_total = max(0, $close_val - $open_val);

$logs = [];
$daily = [];
$tou_raw = ['peak' => 0, 'std' => 0, 'off' => 0];

if ($open_ts && $close_ts) {
    $stmt_all = $obis_db_conn->prepare("SELECT Time_stamp, `$col` as val FROM `$db`.`$table` WHERE meter_serial = :serial AND Time_stamp >= :start AND Time_stamp <= :end ORDER BY Time_stamp ASC");
    $stmt_all->execute(['serial' => $clean_serial, 'start' => $open_ts, 'end' => $close_ts]);

    $prev_ts = null;
    $prev_v = null;
    while ($r = $stmt_all->fetch(PDO::FETCH_ASSOC)) {
        $cur_v = (float)$r['val'];
        $cur_ts = $r['Time_stamp'];

        if ($prev_v !== null) {
            $diff = $cur_v - $prev_v;
            if ($diff >= 0 && $diff < 2000) {
                $mid_ts = strtotime($cur_ts) - 900;
                $bucket = get_tou_bucket($mid_ts, $tou_algo, $public_holidays);
                $date_str = date('Y-m-d', $mid_ts);

                $tou_raw[$bucket] += $diff;

                if (!isset($daily[$date_str])) {
                    $daily[$date_str] = ['peak' => 0, 'std' => 0, 'off' => 0, 'total' => 0];
                }
                $daily[$date_str][$bucket] += $diff;
                $daily[$date_str]['total'] += $diff;

                $logs[] = [
                    'date' => $date_str,
                    'start_time' => date('H:i', strtotime($prev_ts)),
                    'end_time' => date('H:i', strtotime($cur_ts)),
                    'start_val' => $prev_v,
                    'end_val' => $cur_v,
                    'diff' => $diff,
                    'bucket' => ucfirst($bucket)
                ];
            }
        }
        $prev_v = $cur_v;
        $prev_ts = $cur_ts;
    }
}

// Scale the TOU split so its total matches the metered kWh
$tou_recon = $tou_raw;
$ratio = 1;
$tou_sum = $tou_raw['peak'] + $tou_raw['std'] + $tou_raw['off'];
if ($tou_sum > 0 && abs($tou_sum - $kwh_total) > 0.001) {
    $ratio = $kwh_total / $tou_sum;
    $tou_recon['peak'] = round($tou_raw['peak'] * $ratio, 2);
    $tou_recon['std'] = round($tou_raw['std'] * $ratio, 2);
    $tou_recon['off'] = round($kwh_total - $tou_recon['peak'] - $tou_recon['std'], 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TOU Analysis - <?php echo htmlspecialchars($meter_id); ?></title>
    <?php include LUM_ROOT . '/Layout/head-assets.php'; ?>
    <style>
        body { background-color: #e2e8f0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 2rem; }
        .card { border: none; box-shadow: 0 4px 6px rgba(0,0,0,0.1); margin-bottom: 2rem; }
        .table th { background-color: #1a1d20; color: #fff; }
        .badge-peak { background-color: #dc3545; }
        .badge-std { background-color: #ffc107; color: #000; }
        .badge-off { background-color: #198754; }
    </style>
</head>
<body>

    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="fw-bold mb-1">Time of Use (TOU) Analysis</h2>
                <h5 class="text-muted">Meter: <?php echo htmlspecialchars($meter_id); ?> | Algorithm: <?php echo htmlspecialchars($tou_algo); ?></h5>
            </div>
            <div>
                <?php if (!empty($tenant_id)): ?>
                    <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn btn-dark shadow-sm">
                        <i class="bi bi-arrow-left me-1"></i> Back to Slip
                    </a>
                <?php else: ?>
                    <button onclick="window.close()" class="btn btn-outline-dark">Close Window</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-5">
                <div class="card p-4">
                    <h5 class="fw-bold border-bottom pb-2 mb-3">Daily Accumulation</h5>
                    <table class="table table-sm table-bordered align-middle">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th class="text-end">Peak</th>
                                <th class="text-end">Standard</th>
                                <th class="text-end">Off-Peak</th>
                                <th class="text-end fw-bold bg-secondary text-white">Daily Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php ksort($daily); foreach($daily as $d => $v): ?>
                            <tr>
                                <td><?php echo date('d M Y', strtotime($d)); ?></td>
                                <td class="text-end text-danger"><?php echo number_format($v['peak'], 2); ?></td>
                                <td class="text-end text-warning text-dark"><?php echo number_format($v['std'], 2); ?></td>
                                <td class="text-end text-success"><?php echo number_format($v['off'], 2); ?></td>
                                <td class="text-end fw-bold bg-light"><?php echo number_format($v['total'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-dark">
                            <tr>
                                <th>RAW TOTALS</th>
                                <th class="text-end"><?php echo number_format($tou_raw['peak'], 2); ?></th>
                                <th class="text-end"><?php echo number_format($tou_raw['std'], 2); ?></th>
                                <th class="text-end"><?php echo number_format($tou_raw['off'], 2); ?></th>
                                <th class="text-end fs-6"><?php echo number_format($tou_sum, 2); ?></th>
                            </tr>
                        </tfoot>
                    </table>

                    <h5 class="fw-bold border-bottom pb-2 mb-3 mt-4">Final Slip Reconciliation</h5>
                    <p class="small text-muted mb-2">The system forces the sum of the TOU blocks to perfectly match the Total Consumption (Closing Reading - Opening Reading) to account for minor data gaps or sync issues.</p>
                    <table class="table table-sm table-bordered align-middle">
                        <tbody>
                            <tr><th>Opening Reading</th><td class="text-end"><?php echo number_format($open_val, 2); ?></td></tr>
                            <tr><th>Closing Reading</th><td class="text-end"><?php echo number_format($close_val, 2); ?></td></tr>
                            <tr class="table-primary fw-bold"><th>Total Physical Consumption</th><td class="text-end"><?php echo number_format($kwh_total, 2); ?> kWh</td></tr>
                            <tr><th>Reconciliation Ratio Applied</th><td class="text-end text-muted">x <?php echo number_format($ratio, 6); ?></td></tr>
                            <tr class="table-dark text-white"><th colspan="2" class="text-center">Final Slip Values</th></tr>
                            <tr><th>Final Peak</th><td class="text-end fw-bold text-danger"><?php echo number_format($tou_recon['peak'], 2); ?> kWh</td></tr>
                            <tr><th>Final Standard</th><td class="text-end fw-bold text-warning text-dark"><?php echo number_format($tou_recon['std'], 2); ?> kWh</td></tr>
                            <tr><th>Final Off-Peak</th><td class="text-end fw-bold text-success"><?php echo number_format($tou_recon['off'], 2); ?> kWh</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card p-4 h-100">
                    <h5 class="fw-bold border-bottom pb-2 mb-3">Step-by-Step Interval Log</h5>
                    <div class="table-responsive" style="max-height: 800px; overflow-y: auto;">
                        <table class="table table-sm table-hover table-bordered align-middle" style="font-size: 0.85rem;">
                            <thead class="table-dark sticky-top">
                                <tr>
                                    <th>Date</th>
                                    <th>Interval Window</th>
                                    <th class="text-end">Start Rdg</th>
                                    <th class="text-end">End Rdg</th>
                                    <th class="text-end">Usage Math</th>
                                    <th class="text-center">TOU Bucket</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($logs as $log): 
                                    $badge = 'badge-std';
                                    if ($log['bucket'] === 'Peak') $badge = 'badge-peak';
                                    if ($log['bucket'] === 'Off') $badge = 'badge-off';
                                ?>
                                <tr>
                                    <td class="text-muted"><?php echo $log['date']; ?></td>
                                    <td class="fw-semibold"><?php echo $log['start_time']; ?> - <?php echo $log['end_time']; ?></td>
                                    <td class="text-end text-muted"><?php echo number_format($log['start_val'], 2, '.', ''); ?></td>
                                    <td class="text-end text-muted"><?php echo number_format($log['end_val'], 2, '.', ''); ?></td>
                                    <td class="text-end fw-bold"><?php echo number_format($log['diff'], 2, '.', ''); ?></td>
                                    <td class="text-center"><span class="badge <?php echo $badge; ?> w-100"><?php echo $log['bucket']; ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

</body>
</html>