#!/bin/sh
# PHP coding standard (PSR-12 based, see .php-cs-fixer.dist.php).
#   sh scripts/lint.sh          check only (CI)
#   sh scripts/lint.sh --fix    rewrite files in place
# Downloads a checksum-pinned PHP-CS-Fixer release; nothing is installed globally.
set -eu
root=$(CDPATH='' cd -- "$(dirname "$0")/.." && pwd)
tool=$(mktemp)
trap 'rm -f "$tool"' EXIT
curl -fsSL https://github.com/PHP-CS-Fixer/PHP-CS-Fixer/releases/download/v3.95.25/php-cs-fixer.phar -o "$tool"
php -r 'exit(hash_equals(trim(file_get_contents($argv[1])),hash_file("sha256",$argv[2])) ? 0 : 1);' "$root/scripts/php-cs-fixer.sha256" "$tool"
if [ "${1:-}" = --fix ]; then
  php "$tool" fix --config="$root/.php-cs-fixer.dist.php" --sequential
else
  php "$tool" fix --config="$root/.php-cs-fixer.dist.php" --dry-run --diff --sequential
fi
