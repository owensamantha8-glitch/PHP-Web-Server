<?php
// Sets the estimate time ranges (the occupancy override) to cover a billing period.
// Reached from the warning on the consumption slip sidebar.
// Shared settings, login and page access (see /var/www/Lynx/bootstrap.php)
require_once __DIR__ . '/bootstrap.php';
lum_page('configs', 'edit');
lum_use('audit');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: https://lynx-um.co.za/Configs/view-configs.php');
    exit();
}
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
    die('Security Error: Invalid CSRF Token. Request Blocked.');
}

// The period the slip is billing
$from = trim((string)($_POST['period_start'] ?? ''));
$to   = trim((string)($_POST['period_end'] ?? ''));
foreach ([$from, $to] as $d) {
    $v = DateTime::createFromFormat('Y-m-d', $d);
    if (!$v || $v->format('Y-m-d') !== $d) {
        $_SESSION['error_message'] = 'The billing period was not understood, so the estimate windows were left alone.';
        header('Location: https://lynx-um.co.za/Configs/view-configs.php');
        exit();
    }
}
if (strtotime($to) < strtotime($from)) {
    $_SESSION['error_message'] = 'The billing period ends before it starts, so the estimate windows were left alone.';
    header('Location: https://lynx-um.co.za/Configs/view-configs.php');
    exit();
}

// Each register is read at its own times, so the window starts and ends where that
// register actually reports: water hourly at :58, the electricity registers on the hour.
$pdo = lum_db('information');
$done = [];
try {
    $before = $pdo->query("SELECT obis_code, start_time, end_time FROM lum_estimate_time_ranges")->fetchAll(PDO::FETCH_ASSOC);

    $ranges = [];
    foreach ($pdo->query("SELECT obis_code, start_time, end_time FROM lum_obis_time_ranges") as $r) {
        $ranges[trim((string)$r['obis_code'])] = ['start' => $r['start_time'], 'end' => $r['end_time']];
    }

    $stmt = $pdo->prepare("UPDATE lum_estimate_time_ranges SET start_time = :s, end_time = :e WHERE obis_code = :c");
    foreach ($before as $row) {
        $code = trim((string)$row['obis_code']);
        $day_start = $ranges[$code]['start'] ?? '00:00:00';
        $day_end   = $ranges[$code]['end'] ?? '23:59:59';
        // A register read once a day (end time 00:00:00) still needs a window that ends
        // at the end of the last day, otherwise it covers nothing.
        if ($day_end === '00:00:00') $day_end = '23:59:59';
        $stmt->execute(['s' => $from . ' ' . $day_start, 'e' => $to . ' ' . $day_end, 'c' => $code]);
        $done[$code] = $from . ' ' . $day_start . ' to ' . $to . ' ' . $day_end;
    }

    $after = $pdo->query("SELECT obis_code, start_time, end_time FROM lum_estimate_time_ranges")->fetchAll(PDO::FETCH_ASSOC);
    if (function_exists('lum_audit_log')) {
        lum_audit_log('UPDATE', 'estimate_time_ranges', 0, 'Estimate windows set to ' . $from . ' - ' . $to,
                      null, ['windows' => json_encode($before)], ['windows' => json_encode($after)]);
    }
    $_SESSION['success_message'] = 'The estimate windows now cover ' . $from . ' to ' . $to . '.';
} catch (Throwable $e) {
    error_log('LUM estimate windows not updated: ' . $e->getMessage());
    $_SESSION['error_message'] = 'The estimate windows could not be updated. The error has been logged.';
}

// Back to the slip the warning came from, if it was one of ours
$return = (string)($_POST['return_to'] ?? '');
if ($return !== '' && $return[0] === '/' && strpos($return, '//') !== 0) {
    header('Location: https://lynx-um.co.za' . $return);
    exit();
}
header('Location: https://lynx-um.co.za/Configs/view-configs.php');
exit();
