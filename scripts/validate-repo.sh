#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null
done < <(find "$ROOT_DIR" -name '*.php' -print0 | sort -z)

python - <<'PY'
from pathlib import Path
import sys

root = Path('.')
patterns = ('https://lynx-um.co.za', '/var/www/Lynx', '/var/secure_configs/lynx_db.ini')
allowed = {
    ('bootstrap.php', 'https://lynx-um.co.za'),
    ('bootstrap.php', '/var/secure_configs/lynx_db.ini'),
}
violations = []
for path in sorted(root.rglob('*.php')):
    for lineno, line in enumerate(path.read_text(errors='ignore').splitlines(), 1):
        stripped = line.strip()
        if stripped.startswith(('//', '/*', '*', '#')):
            continue
        for pattern in patterns:
            if pattern in line and (path.name, pattern) not in allowed:
                violations.append(f'{path}:{lineno}:{stripped}')
if violations:
    print('Unexpected hardcoded deployment references found:')
    for item in violations:
        print(item)
    sys.exit(1)
print('Hardcoded deployment reference check passed.')
PY

php "$ROOT_DIR/scripts/validate-bootstrap.php" >/dev/null

echo "Repository validation passed."
