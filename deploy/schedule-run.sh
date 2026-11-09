#!/usr/bin/env bash
#
# Hostinger cron entry point (docs/DEPLOY.md section 4), every minute:
#   bash <DEPLOY_PATH>/current/deploy/schedule-run.sh >> /dev/null 2>&1
#
# Hostinger disables proc_open in PHP, and Laravel's scheduler needs it to
# start each task; without it every task "finishes" in milliseconds without
# running (no emails, no hold expiry, no backups). This re-enables only
# proc_open, for this one process: the rest of Hostinger's disable_functions
# list stays as it is, and the website is unaffected. A script, because
# hPanel limits cron commands to 255 characters.

set -euo pipefail

PHP_BIN="${PHP_BIN:-/opt/alt/php83/usr/bin/php}"

cd "$(dirname "${BASH_SOURCE[0]}")/.."

disabled="$("$PHP_BIN" -r 'echo implode(",", array_diff(array_map("trim", explode(",", (string) ini_get("disable_functions"))), ["proc_open", ""]));')"

exec "$PHP_BIN" -d "disable_functions=${disabled}" artisan schedule:run
