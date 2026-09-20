<?php
$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Unable to determine repository root.\n");
    exit(1);
}
putenv('LUM_APP_ROOT=' . $root);
putenv('LUM_APP_URL=https://example.test');
putenv('LUM_LOGIN_PATH=/Sec/login.php');
require $root . '/bootstrap.php';
require $root . '/financial-journal-engine.php';

$bootstrap = $root . '/bootstrap.php';
$resolved = lum_resolve_path('/bootstrap.php');
if ($resolved === false || realpath($resolved) !== realpath($bootstrap)) {
    fwrite(STDERR, "lum_resolve_path() did not resolve bootstrap.php within the app root.\n");
    exit(1);
}

foreach (['../bootstrap.php', '/../../etc/passwd', '..\\bootstrap.php'] as $bad) {
    if (lum_resolve_path($bad) !== false) {
        fwrite(STDERR, "lum_resolve_path() accepted traversal input: {$bad}\n");
        exit(1);
    }
}

if (lum_resolve_path('/missing/bootstrap.php') !== false) {
    fwrite(STDERR, "lum_resolve_path() incorrectly fell back from a missing nested path.\n");
    exit(1);
}

if (lum_resolve_path('/tmp/bootstrap.php', false) !== false) {
    fwrite(STDERR, "lum_resolve_path() accepted an absolute path outside the app root.\n");
    exit(1);
}

if (!lum_is_safe_return_path('/index.php')
    || lum_is_safe_return_path('//evil.example/')
    || lum_is_safe_return_path('https://evil.example/')
    || lum_is_safe_return_path('/foo/../bar')) {
    fwrite(STDERR, "Return-path validation no longer matches the expected safety rules.\n");
    exit(1);
}

if (!function_exists('lumJournalDb')) {
    fwrite(STDERR, "financial-journal-engine.php did not load correctly through bootstrap-dependent validation.\n");
    exit(1);
}

echo "Bootstrap validation passed.\n";
