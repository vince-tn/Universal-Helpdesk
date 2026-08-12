set -e

cd /app

echo "[app] installing PHP dependencies"
composer install --no-interaction --prefer-dist --no-progress

if [ "${database_default_DBDriver}" = "MySQLi" ]; then
    host="${database_default_hostname:-db}"
    echo "[app] waiting for the database at ${host}"
    tries=0
    until mysqladmin ping \
        -h "$host" \
        -u"${database_default_username}" \
        -p"${database_default_password}" --silent >/dev/null 2>&1; do
        tries=$((tries + 1))
        if [ "$tries" -gt 60 ]; then
            echo "[app] the database never answered - carrying on so the error is visible in the app"
            break
        fi
        sleep 2
    done
fi

echo "[app] running migrations"
php spark migrate

php spark helpdesk:import-sqlite

echo "[app] serving on http://0.0.0.0:8080"
exec php spark serve --host 0.0.0.0 --port 8080
