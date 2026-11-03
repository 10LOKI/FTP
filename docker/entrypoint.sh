#!/usr/bin/env bash
set -Eeuo pipefail

cd /var/www/html
: "${APP_KEY:?Run sh docker/prepare-env.sh before starting FTP.}"
: "${DB_USERNAME:?DB_USERNAME is required.}"
: "${DB_PASSWORD:?DB_PASSWORD is required.}"
: "${MYSQL_ROOT_PASSWORD:?MYSQL_ROOT_PASSWORD is required.}"

if [[ "${DB_CONNECTION:-}" != mysql || "${DB_HOST:-}" != 127.0.0.1 || "${DB_PORT:-}" != 3306 || "${DB_DATABASE:-}" != ftp || "$DB_USERNAME" == root ]]; then
    echo 'FTP requires MySQL at 127.0.0.1:3306, database ftp and a non-root application user.' >&2
    exit 1
fi

mysql_pid=''
app_pid=''
shutdown() {
    local status=$?
    trap - EXIT TERM INT
    if [[ -n "$app_pid" ]]; then
        echo 'Stopping Laravel...'
        kill -TERM -- "-$app_pid" 2>/dev/null || true
        wait "$app_pid" 2>/dev/null || true
    fi
    if [[ -n "$mysql_pid" ]]; then
        echo 'Stopping MySQL...'
        kill -TERM "$mysql_pid" 2>/dev/null || true
        wait "$mysql_pid" 2>/dev/null || true
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

mkdir -p /var/lib/mysql /var/lib/mysql-files /run/mysqld
chown mysql:mysql /var/lib/mysql /var/lib/mysql-files /run/mysqld
rm -f /run/mysqld/mysqld.sock /run/mysqld/mysqld.sock.lock /run/mysqld/mysqld.pid

if [[ ! -d /var/lib/mysql/mysql ]]; then
    if [[ -n "$(find /var/lib/mysql -mindepth 1 -maxdepth 1 -print -quit)" ]]; then
        echo 'The MySQL volume is nonempty but has no system database; leaving its contents untouched.' >&2
        exit 1
    fi
    echo 'Initializing the empty MySQL volume...'
    mysqld --initialize-insecure &
    mysql_pid=$!
    wait "$mysql_pid"
    mysql_pid=''

    # Provision over a Unix socket while TCP is disabled.
    mysqld --skip-networking &
    mysql_pid=$!
    run_app_command php docker/mysql.php initialize
    kill -TERM "$mysql_pid"
    wait "$mysql_pid"
    mysql_pid=''
fi

echo 'Starting MySQL and waiting for the application account...'
mysqld &
mysql_pid=$!
run_app_command php docker/mysql.php wait
run_app_command php artisan config:clear --no-interaction
run_app_command php artisan migrate --force --no-interaction

setsid gosu www-data php artisan serve --host=0.0.0.0 --port=8000 --no-reload &
app_pid=$!

status=0
wait -n "$mysql_pid" "$app_pid" || status=$?
echo 'A required FTP process stopped; shutting down the container.' >&2
if [[ "$status" -eq 0 ]]; then
    status=1
fi
exit "$status"
