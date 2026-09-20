#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

while IFS= read -r -d '' file; do
  case "$file" in
    *" (1).php") continue ;;
  esac
  php -l "$file" >/dev/null
done < <(find "$ROOT_DIR" -name '*.php' -print0 | sort -z)

php <<'PHP'
<?php
$patterns = ['https://lynx-um.co.za', '/var/www/Lynx', '/var/secure_configs/lynx_db.ini'];
$allowed = [
    'bootstrap.php' => ['https://lynx-um.co.za', '/var/secure_configs/lynx_db.ini'],
    '.env.example' => ['https://example.com', '/var/www/Lynx', '/var/secure_configs/lynx_db.ini'],
];
$skipFiles = ['bootstrap.php'];
$skipSuffixes = [' (1).php'];
$violations = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('.', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') continue;
    $path = str_replace('\\', '/', $file->getPathname());
    if (in_array(basename($path), $skipFiles, true)) continue;
    foreach ($skipSuffixes as $suffix) {
        if (str_ends_with($path, $suffix)) continue 2;
    }
    $tokens = token_get_all(file_get_contents($path));
    foreach ($tokens as $token) {
        if (!is_array($token)) continue;
        [$type, $text, $line] = $token;
        if ($type === T_COMMENT || $type === T_DOC_COMMENT) continue;
        foreach ($patterns as $pattern) {
            if (strpos($text, $pattern) === false) continue;
            if (in_array($pattern, $allowed[basename($path)] ?? [], true)) continue;
            $violations[] = $path . ':' . $line . ':' . trim($text);
        }
    }
}
if ($violations) {
    fwrite(STDERR, "Unexpected hardcoded deployment references found:\n");
    foreach ($violations as $item) fwrite(STDERR, $item . "\n");
    exit(1);
}
fwrite(STDOUT, "Hardcoded deployment reference check passed.\n");
PHP

php <<'PHP'
<?php
$patterns = ['https://lynx-um.co.za', '/var/www/Lynx', '/var/secure_configs/lynx_db.ini'];
$allowed = [
    '.env.example' => ['https://example.com', '/var/www/Lynx', '/var/secure_configs/lynx_db.ini'],
    'validate-repo.sh' => ['https://lynx-um.co.za', '/var/www/Lynx', '/var/secure_configs/lynx_db.ini'],
];
$files = ['README.md', '.env.example', '.github/workflows/validate.yml', 'scripts/validate-repo.sh'];
foreach ($files as $path) {
    if (!is_file($path)) continue;
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    foreach ($lines as $lineNo => $line) {
        foreach ($patterns as $pattern) {
            if (strpos($line, $pattern) === false) continue;
            if (in_array($pattern, $allowed[basename($path)] ?? [], true)) continue;
            fwrite(STDERR, "Unexpected hardcoded deployment references found:\n{$path}:" . ($lineNo + 1) . ':' . trim($line) . "\n");
            exit(1);
        }
    }
}
PHP

php "$ROOT_DIR/scripts/validate-bootstrap.php" >/dev/null

echo "Repository validation passed."
