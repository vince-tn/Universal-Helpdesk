#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "$0")/.."

dest="${BACKUP_DIR:-$HOME/chikiting-backups}"
keep="${BACKUP_KEEP_DAYS:-14}"
stamp=$(date +%Y-%m-%d_%H%M)
work="$dest/.partial-$stamp"

mkdir -p "$work"
chmod 700 "$dest"

if ! docker compose exec -T db sh -c 'export MYSQL_PWD="$MYSQL_PASSWORD"; exec mysqldump --single-transaction --no-tablespaces -uhelpdesk helpdesk' < /dev/null | gzip > "$work/database.sql.gz" \
    || ! gzip -cd "$work/database.sql.gz" | tail -n 1 | grep -q "Dump completed"; then
    echo "[backup] $stamp: the database dump failed or is incomplete - nothing kept"
    rm -rf "$work"
    exit 1
fi

tar -czf "$work/uploads.tar.gz" -C writable uploads

mv "$work" "$dest/$stamp"
find "$dest" -mindepth 1 -maxdepth 1 -type d -name '20*' -mtime +"$keep" -exec rm -rf {} +

echo "[backup] $stamp: database $(du -h "$dest/$stamp/database.sql.gz" | cut -f1), uploads $(du -h "$dest/$stamp/uploads.tar.gz" | cut -f1), $(find "$dest" -mindepth 1 -maxdepth 1 -type d -name '20*' | wc -l) backup(s) kept"
