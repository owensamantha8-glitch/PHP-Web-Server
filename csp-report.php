<?php
// =========================================================================
// LYNX UTILITY MANAGEMENT - CONTENT-SECURITY-POLICY REPORTS
// Location: /var/www/Lynx/Sec/csp-report.php
// -------------------------------------------------------------------------
// Browsers send a report here when the Content-Security-Policy blocks (or, in
// report-only mode, would block) something. Each report becomes one
// "LUM CSP:" line in the PHP error log. No login: browsers send these reports
// without the session. Reports are size-limited and limited per address.
// =========================================================================

require_once __DIR__ . '/bootstrap.php'; // Shared settings only (errors are logged, never displayed)

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit();
}

// At most 30 reports per address per minute
$lum_tmp = sys_get_temp_dir();
$lum_bucket = $lum_tmp . '/lum-csp-' . substr(hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '')), 0, 16) . '-' . date('YmdHi');
$lum_count = is_file($lum_bucket) ? (int)@file_get_contents($lum_bucket) : 0;
if ($lum_count >= 30) {
    http_response_code(204);
    exit();
}
@file_put_contents($lum_bucket, (string)($lum_count + 1), LOCK_EX);
if (mt_rand(1, 50) === 1) {
    // Remove old counters now and then
    foreach ((glob($lum_tmp . '/lum-csp-*') ?: []) as $lum_old) {
        if (is_file($lum_old) && filemtime($lum_old) < time() - 300) @unlink($lum_old);
    }
}

// The report: "report-uri" format ({"csp-report": {...}}) or Reporting API format ([{"type": "csp-violation", "body": {...}}])
$lum_raw = (string)file_get_contents('php://input', false, null, 0, 16384);
$lum_data = json_decode($lum_raw, true);
$lum_reports = [];
if (is_array($lum_data) && isset($lum_data['csp-report']) && is_array($lum_data['csp-report'])) {
    $lum_reports[] = $lum_data['csp-report'];
} elseif (is_array($lum_data)) {
    foreach (array_slice($lum_data, 0, 20) as $lum_r) {
        if (is_array($lum_r) && ($lum_r['type'] ?? '') === 'csp-violation' && isset($lum_r['body']) && is_array($lum_r['body'])) {
            $lum_reports[] = $lum_r['body'];
        }
    }
}

// One log line per report (no control characters, each value at most 300 characters)
$lum_clean = function ($v) {
    return substr(preg_replace('/[\x00-\x1F\x7F]+/', ' ', is_scalar($v) ? (string)$v : ''), 0, 300);
};
foreach ($lum_reports as $lum_r) {
    $lum_get = function (...$keys) use ($lum_r) {
        foreach ($keys as $k) {
            if (isset($lum_r[$k]) && $lum_r[$k] !== '') return $lum_r[$k];
        }
        return '';
    };
    $lum_sample = $lum_get('script-sample', 'sample');
    error_log('LUM CSP: ' . (($lum_get('disposition') === 'enforce') ? 'BLOCKED' : 'would block')
        . ' | page ' . $lum_clean($lum_get('document-uri', 'documentURL'))
        . ' | directive ' . $lum_clean($lum_get('effective-directive', 'effectiveDirective', 'violated-directive'))
        . ' | resource ' . $lum_clean($lum_get('blocked-uri', 'blockedURL'))
        . ' | at ' . $lum_clean($lum_get('source-file', 'sourceFile')) . ':' . (int)$lum_get('line-number', 'lineNumber')
        . ($lum_sample !== '' ? ' | sample ' . $lum_clean($lum_sample) : ''));
}

http_response_code(204);