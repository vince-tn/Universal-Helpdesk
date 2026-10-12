#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "$0")/.."

log() { echo "[deploy] $*"; }

env_value() {
    { grep -E "^$1=" .env || true; } | tail -n 1 | cut -d= -f2- | sed -e "s/^['\"]//" -e "s/['\"]\$//"
}

app_answers() {
    for _ in $(seq 1 90); do
        if docker compose exec -T app php -r 'exit(@file_get_contents("http://127.0.0.1:8080/login") === false ? 1 : 0);' >/dev/null 2>&1; then
            return 0
        fi
        sleep 2
    done

    return 1
}

if [ ! -f .env ]; then
    log "no .env in $(pwd) - set this copy up first (deploy/README.md)"
    exit 1
fi

branch=$(env_value DEPLOY_BRANCH)

if [ -z "$branch" ]; then
    log "DEPLOY_BRANCH is not set in .env - nothing to follow"
    exit 1
fi

exec 9>"/tmp/chikiting-deploy-$(pwd | md5sum | cut -c1-12).lock"
flock -n 9 || exit 0

if ! git fetch --quiet origin "+refs/heads/release/${branch}:refs/remotes/origin/release/${branch}"; then
    log "could not fetch release/${branch} from GitHub - trying again next run"
    exit 1
fi

state=.git/chikiting-deployed
[ -f "$state" ] || git rev-parse HEAD > "$state"

deployed=$(cat "$state")
target=$(git rev-parse "refs/remotes/origin/release/${branch}")

if [ "$deployed" = "$target" ]; then
    exit 0
fi

if ! git diff --quiet HEAD --; then
    log "tracked files in $(pwd) were edited by hand - not deploying ${target:0:7} over them:"
    git status --short --untracked-files=no
    exit 1
fi

changed=$(git diff --name-only "$deployed" "$target" 2>/dev/null) || changed=$(git ls-files)

touched() { echo "$changed" | grep -qE "$1"; }

log "release/${branch}: ${deployed:0:7} -> ${target:0:7}"
git -c advice.detachedHead=false checkout --quiet --detach "$target"

if touched '^(docker/|docker-compose[^/]*\.yml$)'; then
    log "container setup changed - rebuilding"
    docker compose up -d --build --remove-orphans
else
    docker compose up -d --no-recreate
fi

if ! app_answers; then
    log "the app is not answering before the update - logs: docker compose logs --tail=100 app"
    exit 1
fi

if touched '^composer\.(json|lock)$'; then
    log "PHP packages changed - installing"
    docker compose exec -T app composer install --no-interaction --prefer-dist --no-progress
fi

log "applying database migrations"
docker compose exec -T app php spark migrate

if touched '^n8n/provision/'; then
    log "n8n workflows changed - re-importing"
    HELPDESK_FORCE_IMPORT=true docker compose up -d --no-deps --force-recreate n8n
fi

if ! app_answers; then
    log "UPDATED TO ${target:0:7} BUT THE APP IS NOT ANSWERING"
    log "logs:    docker compose logs --tail=100 app"
    log "go back: revert the commit on GitHub - the box follows it within minutes"
    exit 1
fi

echo "$target" > "$state"
log "deployed ${target:0:7}: $(git log -1 --format=%s "$target")"
