#!/usr/bin/env bash
#
# Hostinger cron entry point (docs/DEPLOY.md section 4), every minute:
#   bash <DEPLOY_PATH>/current/deploy/schedule-run.sh >> /dev/null 2>&1
#
# Hostinger disables proc_open in PHP, and Laravel's scheduler needs it to
# start each task; without it every task "finishes" in milliseconds without
# running (no emails, no hold expiry, no backups). This re-enables only
# proc_open: the rest of Hostinger's disable_functions list stays as it is,
# and the website is unaffected. A script, because hPanel limits cron
# commands to 255 characters.
#
# The override is an extra ini file loaded through PHP_INI_SCAN_DIR, not a
# `php -d` flag: the scheduler starts every task as a new `php artisan ...`
# process, and a -d flag reaches only the first one. backup:run, which needs
# proc_open for mysqldump, failed every night that way. The environment
# variable is inherited by every process cron starts here, and only by those.

set -euo pipefail

PHP_BIN="${PHP_BIN:-/opt/alt/php83/usr/bin/php}"

cd "$(dirname "${BASH_SOURCE[0]}")/.."

# storage/ is the shared folder, so this survives deploys. Rewritten on every
# run, so a change to Hostinger's list is picked up within a minute.
INI_DIR="${PHP_CLI_INI_DIR:-storage/framework/php-cli}"
mkdir -p "$INI_DIR"
chmod 700 "$INI_DIR"
INI_DIR="$(cd "$INI_DIR" && pwd)"

disabled="$("$PHP_BIN" -r 'echo implode(",", array_diff(array_map("trim", explode(",", (string) ini_get("disable_functions"))), ["proc_open", ""]));')"

# Written beside the target and renamed, so a task starting at the same
# moment never reads half a file. The temporary name doesn't end in .ini,
# so PHP never loads it.
tmp="$INI_DIR/.zz-proc-open.$$"
printf '; Written by deploy/schedule-run.sh. Cron only, never the website.\ndisable_functions = "%s"\n' "$disabled" > "$tmp"
mv -f "$tmp" "$INI_DIR/zz-proc-open.ini"

# A leading empty entry keeps PHP's own scan directory (Hostinger's
# alt_php.ini) and adds ours after it, so ours is read last and wins.
export PHP_INI_SCAN_DIR="${PHP_INI_SCAN_DIR:-}:$INI_DIR"

exec "$PHP_BIN" artisan schedule:run
