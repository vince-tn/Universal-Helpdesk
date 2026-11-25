#!/usr/bin/env bash
# sets up one branded copy: bare repo, working copy, .env, tailscale folder
set -euo pipefail

name="$1"
brand="$2"
port="$3"
domains="$4"

repos="$HOME/repos"
apps="$HOME/apps"
tsdir="$apps/.tailscale/$name"
prod_env=/opt/chikiting/production/.env
tailnet="${TAILNET:-taild3e5e6.ts.net}"

mkdir -p "$repos" "$tsdir/state" "$tsdir/config"
[ -d "$repos/$name.git" ] || git init -q --bare -b main "$repos/$name.git"

if [ ! -d "$apps/$name/.git" ]; then
    git clone -q "$repos/$name.git" "$apps/$name"
fi

cd "$apps/$name"
git fetch -q origin "+refs/heads/release/main:refs/remotes/origin/release/main"
git -c advice.detachedHead=false checkout -q --detach origin/release/main

pick() { { grep -E "^$1=" "$prod_env" || true; } | tail -n 1; }

if [ ! -f .env ]; then
    umask 077
    {
        echo "# $name - branded service desk copy, never commit this file"
        echo "DEPLOY_BRANCH=main"
        echo "COMPOSE_PROJECT_NAME=$name"
        echo "COMPOSE_FILE=docker-compose.yml:docker-compose.prod.yml:docker-compose.instance.yml"
        echo "INSTANCE=$name"
        echo "BRAND=$brand"
        echo "BRAND_NAME='$5'"
        echo "APP_URL=https://$name.$tailnet/"
        echo "APP_BIND=0.0.0.0"
        echo "APP_PORT=$port"
        echo "PUBLIC_HOST_SUFFIXES=.$tailnet"
        echo "TS_HOSTNAME=$name"
        echo "TS_DIR=$tsdir"
        echo "TS_AUTHKEY="
        echo "ALLOWED_EMAIL_DOMAINS=$domains"
        pick MAIL_HOST; pick MAIL_PORT; pick MAIL_CRYPTO; pick MAIL_USER; pick MAIL_FROM; pick MAIL_PASS
        echo "MAIL_FROM_NAME='$5'"
        echo "MYSQL_PASSWORD=$(openssl rand -hex 18)"
        echo "MYSQL_ROOT_PASSWORD=$(openssl rand -hex 18)"
        echo "OPENROUTER_API_KEY="
        echo "OPENROUTER_MODEL=openrouter/free"
        echo "CONFIDENTIAL_LOCAL_ONLY=on"
    } > .env
fi

cat > "$tsdir/config/funnel.json" <<'JSON'
{
  "TCP": { "443": { "HTTPS": true } },
  "Web": { "${TS_CERT_DOMAIN}:443": { "Handlers": { "/": { "Proxy": "http://app:8080" } } } },
  "AllowFunnel": { "${TS_CERT_DOMAIN}:443": true }
}
JSON

git rev-parse HEAD > .git/chikiting-deployed
echo "[new-instance] $name ready at $apps/$name on port $port"
