#!/usr/bin/env bash
#
# Activates a release on the server (milestone 12, docs/DEPLOY.md).
# Run by .github/workflows/deploy.yml over SSH after the release folder has
# been uploaded:
#
#   bash releases/<release>/deploy/remote-deploy.sh <deploy-path> <release>
#
# Layout under <deploy-path>:
#   releases/<UTC timestamp>-<sha>/   one folder per deploy (last KEEP_RELEASES kept)
#   shared/.env                       created by hand once (DEPLOY.md)
#   shared/storage/                   logs, sessions, cache, transfer proofs, backups
#   shared/uploads/                   images uploaded in the admin panel (public/images/uploads)
#   current -> releases/<release>     what the web server and cron use
#
# Everything up to the symlink switch happens in the new folder while the
# old release keeps serving, so a failure before the switch changes nothing
# for visitors. Migrations run before the switch: they must work with the
# code that is still live (DEPLOY.md, "Migrations").

set -euo pipefail

DEPLOY_PATH="${1:?deploy path required}"
RELEASE="${2:?release name required}"
PHP_BIN="${PHP_BIN:-php}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"

RELEASE_DIR="$DEPLOY_PATH/releases/$RELEASE"
SHARED="$DEPLOY_PATH/shared"

log() { printf '\n==> %s\n' "$*"; }
fail() { printf '\nDeploy failed: %s\n' "$*" >&2; exit 1; }

[[ "$RELEASE" =~ ^[0-9A-Za-z._-]+$ ]] || fail "invalid release name '$RELEASE'"
[[ -d "$RELEASE_DIR" ]] || fail "$RELEASE_DIR does not exist"
[[ -f "$SHARED/.env" ]] || fail "$SHARED/.env is missing; create it first (docs/DEPLOY.md, first-time setup)"

log "Shared folders"
mkdir -p \
    "$SHARED/storage/app/private" \
    "$SHARED/storage/app/public" \
    "$SHARED/storage/app/transfers" \
    "$SHARED/storage/app/backups" \
    "$SHARED/storage/framework/cache/data" \
    "$SHARED/storage/framework/sessions" \
    "$SHARED/storage/framework/views" \
    "$SHARED/storage/logs" \
    "$SHARED/uploads"
chmod 700 "$SHARED/storage/app/transfers" "$SHARED/storage/app/backups"
chmod 600 "$SHARED/.env"

log "Linking .env, storage and uploads into $RELEASE"
rm -rf "$RELEASE_DIR/storage" "$RELEASE_DIR/.env" "$RELEASE_DIR/public/images/uploads" "$RELEASE_DIR/public/storage"
ln -s "$SHARED/storage" "$RELEASE_DIR/storage"
ln -s "$SHARED/.env" "$RELEASE_DIR/.env"
ln -s "$SHARED/uploads" "$RELEASE_DIR/public/images/uploads"
ln -s "$SHARED/storage/app/public" "$RELEASE_DIR/public/storage"

cd "$RELEASE_DIR"

[[ -f public/build/manifest.json ]] || fail "public/build/manifest.json missing: the release was not built"
compgen -G "public/images/_variants/*.avif" > /dev/null || fail "no AVIF variants in the release: the CI image step did not run"

log "PHP: $("$PHP_BIN" -r 'echo PHP_VERSION;')"

log "Migrations"
"$PHP_BIN" artisan migrate --force --no-interaction

log "Caching config, routes, views and events"
"$PHP_BIN" artisan optimize --no-interaction

log "Switching current -> $RELEASE"
ln -sfn "$RELEASE_DIR" "$DEPLOY_PATH/current.next"
mv -Tf "$DEPLOY_PATH/current.next" "$DEPLOY_PATH/current"

log "Clearing the page cache and restarting queue workers"
"$PHP_BIN" artisan responsecache:clear --no-interaction
"$PHP_BIN" artisan queue:restart --no-interaction

log "Keeping the last $KEEP_RELEASES releases"
current_target="$(readlink -f "$DEPLOY_PATH/current")"
mapfile -t releases < <(find "$DEPLOY_PATH/releases" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort -r)
for old in "${releases[@]:$KEEP_RELEASES}"; do
    if [[ "$(readlink -f "$DEPLOY_PATH/releases/$old")" != "$current_target" ]]; then
        rm -rf "${DEPLOY_PATH:?}/releases/${old:?}"
        echo "removed $old"
    fi
done

log "Live: $RELEASE"
