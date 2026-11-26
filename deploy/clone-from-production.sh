#!/usr/bin/env bash
# copies the live chikiting database and uploads into a fresh branded copy, live chikiting is only read
set -euo pipefail

name="${1:?usage: clone-from-production.sh <instance name>}"
prod=/opt/chikiting/production
dest="$HOME/apps/$name"
stamp=$(date +%Y-%m-%d_%H%M)
work="$HOME/backups/$name/clone-$stamp"

mkdir -p "$work"
chmod 700 "$HOME/backups/$name"

cd "$prod"
echo "[clone] dumping live chikiting (read only, single transaction)"
docker compose exec -T db sh -c 'export MYSQL_PWD="$MYSQL_PASSWORD"; exec mysqldump --single-transaction --no-tablespaces -uhelpdesk helpdesk' < /dev/null | gzip > "$work/database.sql.gz"
gzip -cd "$work/database.sql.gz" | tail -n 1 | grep -q "Dump completed" || { echo "[clone] dump incomplete, stopping"; exit 1; }
tar -czf "$work/uploads.tar.gz" -C writable uploads

cd "$dest"
echo "[clone] starting only the database of $name"
docker compose up -d db
for _ in $(seq 1 60); do
    docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysqladmin ping -uhelpdesk --silent' >/dev/null 2>&1 && break
    sleep 2
done

tickets=$(docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysql -uhelpdesk helpdesk -N -e "select count(*) from information_schema.tables where table_schema=database()"' 2>/dev/null || echo 0)
if [ "${tickets:-0}" != "0" ]; then
    echo "[clone] $name already has tables, not overwriting"
    exit 1
fi

echo "[clone] importing"
gzip -cd "$work/database.sql.gz" | docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysql -uhelpdesk helpdesk'
mkdir -p writable
tar -xzf "$work/uploads.tar.gz" -C writable

echo "[clone] done, kept the source copy in $work; now run: docker compose up -d --build"
