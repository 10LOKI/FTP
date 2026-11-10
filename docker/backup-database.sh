#!/bin/sh

set -eu

backup_directory=${1:-storage/backups}
timestamp=$(date -u +%Y%m%dT%H%M%SZ)
backup_path="${backup_directory}/ftp-${timestamp}.sql"

mkdir -p "$backup_directory"

docker compose exec -T mysql sh -c '
    MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump \
        --user=root \
        --single-transaction \
        --routines \
        --triggers \
        --set-gtid-purged=OFF \
        "$MYSQL_DATABASE"
' > "$backup_path"

if [ ! -s "$backup_path" ]; then
    rm -f "$backup_path"
    echo 'Backup failed: the generated file is empty.' >&2
    exit 1
fi

shasum -a 256 "$backup_path" > "${backup_path}.sha256"
chmod 600 "$backup_path" "${backup_path}.sha256"

echo "$backup_path"
