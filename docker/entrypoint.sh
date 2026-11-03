#!/usr/bin/env bash
set -Eeuo pipefail

cd /var/www/html
: "${APP_KEY:?Run sh docker/prepare-env.sh before starting FTP.}"
: "${DB_HOST:?DB_HOST is required.}"
: "${DB_USERNAME:?DB_USERNAME is required.}"
: "${DB_PASSWORD:?DB_PASSWORD is required.}"
if [[ "${DB_CONNECTION:-}" != mysql ]]; then
    echo 'FTP requires DB_CONNECTION=mysql. The MySQL service runs in its own container.' >&2
    exit 1
fi
app_pid=''
shutdown() {
    local status=$?
    trap - EXIT TERM INT
    if [[ -n "$app_pid" ]]; then
        echo 'Stopping Laravel...'
        kill -TERM -- "-$app_pid" 2>/dev/null || true
        wait "$app_pid" 2>/dev/null || true
    fi
    exit "$status"
}
trap shutdown EXIT
trap 'exit 0' TERM INT

# Signal the whole HTTP process group, including PHP workers and startup commands.
run_app_command() {
    setsid gosu www-data "$@" &
    app_pid=$!
    wait "$app_pid"
    app_pid=''
}

run_app_command php artisan config:clear --no-interaction
run_app_command php docker/database.php wait
run_app_command php artisan migrate --force --no-interaction

setsid gosu www-data php artisan serve --host=0.0.0.0 --port=8000 --no-reload &
app_pid=$!

status=0
wait "$app_pid" || status=$?
echo 'The FTP HTTP process stopped; shutting down the container.' >&2
if [[ "$status" -eq 0 ]]; then
    status=1
fi
exit "$status"
