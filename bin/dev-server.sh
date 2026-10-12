#!/usr/bin/env bash
#
# Start / stop the QMS dev server.
#
#   bash bin/dev-server.sh start    # Apache on http://127.0.0.1:8080
#   bash bin/dev-server.sh stop
#   bash bin/dev-server.sh status
#
# Why Apache rather than `php -S`:
#   The PHP built-in server handles ONE request at a time. On Windows
#   PHP_CLI_SERVER_WORKERS is not supported ("forking is not supported on this
#   platform"), so a concurrency test would be serialised by the server and
#   would prove nothing about the queue. Apache's worker pool (mpm_winnt) runs
#   requests in parallel, which is what the project needs to demonstrate.
#
# The generated config is a COPY of the XAMPP httpd.conf living in .dev/; the
# original C:/xampp/apache/conf/httpd.conf is never modified.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
XAMPP_HOME="${XAMPP_HOME:-/c/xampp}"
PORT="${QMS_PORT:-8080}"

HTTPD="$XAMPP_HOME/apache/bin/httpd.exe"
STOCK_CONF="$XAMPP_HOME/apache/conf/httpd.conf"
CONF="$ROOT/.dev/httpd-qms.conf"

to_windows() { cygpath -m "$1" 2>/dev/null || echo "$1"; }

generate_config() {
    if [ ! -f "$STOCK_CONF" ]; then
        echo "XAMPP httpd.conf not found at $STOCK_CONF" >&2
        echo "Set XAMPP_HOME, or install XAMPP." >&2
        exit 1
    fi

    mkdir -p "$ROOT/.dev"

    sed -e "s|^Listen 80$|Listen 127.0.0.1:$PORT|" \
        -e "s|^DocumentRoot \"C:/xampp/htdocs\"$|DocumentRoot \"$(to_windows "$ROOT/public")\"|" \
        -e 's|^\(ServerName .*\)|# \1|' \
        -e 's|^[[:space:]]*Include conf/extra/httpd-ssl.conf|# ssl removed for the qms dev server|' \
        -e 's|^[[:space:]]*Include conf/extra/httpd-vhosts.conf|# vhosts removed for the qms dev server|' \
        "$STOCK_CONF" > "$CONF"

    cat >> "$CONF" <<EOF

# ---------------------------------------------------------------------------
# QMS dev overrides (generated copy; the stock httpd.conf is untouched)
# ---------------------------------------------------------------------------
ServerName localhost:$PORT
PidFile "$(to_windows "$ROOT/.dev/httpd.pid")"
ErrorLog "$(to_windows "$ROOT/.dev/httpd-error.log")"

<Directory "$(to_windows "$ROOT/public")">
    Options Indexes FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
EOF
}

port_in_use() {
    netstat -ano 2>/dev/null | grep -q ":$PORT .*LISTENING"
}

case "${1:-start}" in
    start)
        if port_in_use; then
            echo "Port $PORT is already in use. Stop that server first (bash bin/dev-server.sh stop)." >&2
            exit 1
        fi

        generate_config
        "$HTTPD" -t -f "$(to_windows "$CONF")" >/dev/null 2>&1

        mkdir -p "$ROOT/.dev"
        "$HTTPD" -f "$(to_windows "$CONF")" >"$ROOT/.dev/httpd-stdout.log" 2>&1 &
        disown 2>/dev/null || true

        for _ in $(seq 1 20); do
            if curl -s -o /dev/null "http://127.0.0.1:$PORT/api/health"; then
                echo "QMS running on http://127.0.0.1:$PORT (Apache, PHP $(
                    curl -s "http://127.0.0.1:$PORT/api/health" | sed -n 's/.*"php_version":"\([^"]*\)".*/\1/p'
                ))"
                exit 0
            fi

            sleep 0.5
        done

        echo "Apache did not answer on port $PORT. See $ROOT/.dev/httpd-error.log" >&2
        exit 1
        ;;
    stop)
        # `httpd -k shutdown` only works for an installed Windows service; this
        # instance runs as a plain console process, so it is stopped by PID.
        if [ -f "$ROOT/.dev/httpd.pid" ]; then
            pidfile_pid="$(tr -d '[:space:]' < "$ROOT/.dev/httpd.pid")"

            if [ -n "$pidfile_pid" ]; then
                taskkill //PID "$pidfile_pid" //F >/dev/null 2>&1 || true
            fi

            sleep 1
        fi

        # mpm_winnt runs a parent and a child; clear anything still on the port.
        for _ in 1 2 3; do
            holder="$(netstat -ano 2>/dev/null | grep ":$PORT .*LISTENING" | awk '{print $NF}' | head -1)"

            [ -z "$holder" ] && break

            taskkill //PID "$holder" //F >/dev/null 2>&1 || true
            sleep 1
        done

        if port_in_use; then
            echo "Something is still listening on $PORT that this script did not start." >&2
            exit 1
        fi

        rm -f "$ROOT/.dev/httpd.pid"
        echo "QMS dev server stopped."
        ;;
    status)
        if port_in_use; then
            echo "listening on $PORT"
            curl -s "http://127.0.0.1:$PORT/api/health"
            echo
        else
            echo "not running"
        fi
        ;;
    *)
        echo "usage: bash bin/dev-server.sh {start|stop|status}" >&2
        exit 2
        ;;
esac
