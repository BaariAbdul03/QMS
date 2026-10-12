#!/usr/bin/env bash
#
# Run PHP with the extensions this project needs.
#
#   bash bin/php.sh tests/run_all.php
#   bash bin/php.sh -r 'echo PHP_VERSION;'
#
# The PHP build shipping with this machine does not enable pdo_mysql in its
# php.ini. Rather than editing a global config, the wrapper points
# PHP_INI_SCAN_DIR at a project-local directory that loads it. The main php.ini
# is still read, so mbstring/openssl/curl keep working.
#
# On a stack where pdo_mysql is already enabled (XAMPP, most Linux distros)
# plain `php` works and this wrapper is optional.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONF_DIR="$ROOT/.dev/php-conf"

if [ ! -f "$CONF_DIR/zz-qms.ini" ]; then
    mkdir -p "$CONF_DIR"
    printf 'extension=pdo_mysql\n' > "$CONF_DIR/zz-qms.ini"
fi

PHP_INI_SCAN_DIR="$(cygpath -m "$CONF_DIR" 2>/dev/null || echo "$CONF_DIR")" exec php "$@"
