#!/bin/sh

set -eu

if { [ "$#" -ne 2 ] && [ "$#" -ne 3 ]; } || [ "$1" != '--confirm-replace' ]; then
    echo 'Usage: sh docker/restore-database.sh --confirm-replace path/to/backup.sql [isolated_database]' >&2
    echo 'This replaces the configured MySQL database. Restore only in an isolated environment.' >&2
    exit 2
fi

backup_path=$2
checksum_path="${backup_path}.sha256"
restore_database=${3:-$(docker compose exec -T mysql sh -c 'printf %s "$MYSQL_DATABASE"')}

case "$restore_database" in
    ''|*[!A-Za-z0-9_]*)
        echo 'The target database name may contain only letters, numbers and underscores.' >&2
        exit 1
        ;;
esac

if [ ! -s "$backup_path" ]; then
    echo "Backup not found or empty: $backup_path" >&2
    exit 1
fi

if [ ! -f "$checksum_path" ]; then
    echo "Checksum not found: $checksum_path" >&2
    exit 1
fi

shasum -a 256 --check "$checksum_path"

docker compose exec -T -e FTP_RESTORE_DATABASE="$restore_database" mysql sh -c '
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql \
        --user=root \
        "$FTP_RESTORE_DATABASE"
' < "$backup_path"

docker compose exec -T -e FTP_RESTORE_DATABASE="$restore_database" mysql sh -c '
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql \
        --batch \
        --skip-column-names \
        --user=root \
        "$FTP_RESTORE_DATABASE" \
        --execute="SELECT CONCAT(\"Restored migrations: \", COUNT(*)) FROM migrations"
'

echo 'Restore completed. Run the release smoke tests before using this environment.'
