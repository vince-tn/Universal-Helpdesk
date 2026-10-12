#!/usr/bin/env bash
# Chikiting System - keeps this copy of the app on the newest tested commit.
#
# Run every minute by the chikiting-deploy@<copy> systemd timer (see
# deploy/README.md). Each copy of the app on the box says in its own .env which
# branch it follows:
#
#   DEPLOY_BRANCH=main        production
#   DEPLOY_BRANCH=staging     staging
#
# It follows release/<branch>, not the branch itself. GitHub only moves
# release/<branch> after the tests pass (.github/workflows/release.yml), so a
# broken push never reaches the box.
#
# When there is something new it:
#   - moves the code to the new commit (the app reads its code straight from
#     this folder, so PHP changes are live at once)
#   - rebuilds the containers if their setup changed
#   - installs PHP packages if composer.json or composer.lock changed
#   - applies the database migrations
#   - re-imports the n8n workflows if they changed
#   - checks the app answers, and says so loudly if it does not
#
# A step that fails is retried on the next run: the commit only counts as
# deployed once everything above has worked.
#
# It will not touch a folder where tracked files were edited by hand - those
# edits would be lost. Make the change through GitHub instead.

set -euo pipefail

cd "$(dirname "$0")/.."

log() { echo "[deploy] $*"; }

env_value() {
    # The last KEY=value line in .env, with surrounding quotes removed.
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

# One run at a time per copy, so a slow rebuild never overlaps the next tick.
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

# Unknown starting point (history rewritten, say): treat everything as changed.
changed=$(git diff --name-only "$deployed" "$target" 2>/dev/null) || changed=$(git ls-files)

touched() { echo "$changed" | grep -qE "$1"; }

log "release/${branch}: ${deployed:0:7} -> ${target:0:7}"
git -c advice.detachedHead=false checkout --quiet --detach "$target"

if touched '^(docker/|docker-compose[^/]*\.yml$)'; then
    log "container setup changed - rebuilding"
    docker compose up -d --build --remove-orphans
else
    # Only starts whatever is not running; leaves running containers alone.
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
    # On the box the repo is the source of truth: this replaces this copy's
    # workflows with the committed ones. Change workflows in the repo, not in
    # the box's n8n editor - editor changes are overwritten by the next import.
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
