#!/usr/bin/env bash
#
# Points `current` back at an earlier release (milestone 12, docs/DEPLOY.md
# "Rollback"). Code only: migrations are not reversed, which is why every
# migration must work with the previous release's code.
#
#   bash rollback.sh <deploy-path>             # the release before the live one
#   bash rollback.sh <deploy-path> <release>   # a specific release folder
#
# Run by the deploy workflow (action "rollback", or automatically when the
# smoke test fails), or by hand over SSH from any folder.

set -euo pipefail

DEPLOY_PATH="${1:?deploy path required}"
TARGET="${2:-}"
PHP_BIN="${PHP_BIN:-php}"

fail() { printf '\nRollback failed: %s\n' "$*" >&2; exit 1; }

[[ -L "$DEPLOY_PATH/current" ]] || fail "$DEPLOY_PATH/current is not a symlink; nothing is deployed"

live="$(basename "$(readlink -f "$DEPLOY_PATH/current")")"
# A here-string, not < <(...): Hostinger's CageFS shell has no /dev/fd.
mapfile -t releases <<<"$(find "$DEPLOY_PATH/releases" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort)"

if [[ -z "$TARGET" ]]; then
    for i in "${!releases[@]}"; do
        if [[ "${releases[$i]}" == "$live" ]] && (( i > 0 )); then
            TARGET="${releases[$((i - 1))]}"
        fi
    done
    [[ -n "$TARGET" ]] || fail "no release older than $live to go back to"
fi

[[ "$TARGET" =~ ^[0-9A-Za-z._-]+$ ]] || fail "invalid release name '$TARGET'"
[[ -d "$DEPLOY_PATH/releases/$TARGET" ]] || fail "release $TARGET does not exist"
[[ "$TARGET" != "$live" ]] || fail "$TARGET is already live"

echo "Rolling back: $live -> $TARGET"

cd "$DEPLOY_PATH/releases/$TARGET"

# Rebuild the caches: .env may have changed since this release was live.
"$PHP_BIN" artisan optimize --no-interaction

ln -sfn "$DEPLOY_PATH/releases/$TARGET" "$DEPLOY_PATH/current.next"
mv -Tf "$DEPLOY_PATH/current.next" "$DEPLOY_PATH/current"

"$PHP_BIN" artisan responsecache:clear --no-interaction
"$PHP_BIN" artisan queue:restart --no-interaction

echo "Live: $TARGET (was $live)"
