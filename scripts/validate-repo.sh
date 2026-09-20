#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null
done < <(find "$ROOT_DIR" -name '*.php' -print0 | sort -z)

php <<'PHP'
<?php
$patterns = ['https://lynx-um.co.za', '/var/www/Lynx', '/var/secure_configs/lynx_db.ini'];
$allowed = [
    'bootstrap.php' => ['https://lynx-um.co.za', '/var/secure_configs/lynx_db.ini'],
];
$violations = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('.', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') continue;
    $path = str_replace('\\', '/', $file->getPathname());
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

php "$ROOT_DIR/scripts/validate-bootstrap.php" >/dev/null

echo "Repository validation passed."
