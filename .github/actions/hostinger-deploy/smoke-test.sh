#!/usr/bin/env bash
#
# Smoke test after a deploy or rollback (milestone 12): the homepage and
# /up (which checks the database) must answer 200. Retries a few times,
# because the first request after the switch warms OPcache and the caches.
#
#   SMOKE_TEST_TOKEN=... bash smoke-test.sh https://staging.ride-pakistan.com
#
# SMOKE_TEST_TOKEN is optional. When set it is sent as X-Smoke-Test, which a
# Cloudflare WAF skip rule can match so the runner isn't challenged
# (DEPLOY.md, Cloudflare).

set -uo pipefail

APP_URL="${1:?app url required}"
APP_URL="${APP_URL%/}"
ATTEMPTS="${SMOKE_ATTEMPTS:-5}"

headers=(-H "User-Agent: RidePakistan-Deploy-Smoke/1.0" -H "Cache-Control: no-cache")
if [[ -n "${SMOKE_TEST_TOKEN:-}" ]]; then
    headers+=(-H "X-Smoke-Test: ${SMOKE_TEST_TOKEN}")
fi

check() {
    local path="$1" status
    for ((i = 1; i <= ATTEMPTS; i++)); do
        status="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "${headers[@]}" "${APP_URL}${path}" || echo 000)"
        if [[ "$status" == "200" ]]; then
            echo "OK   ${path} (${status})"
            return 0
        fi
        echo "try ${i}/${ATTEMPTS}: ${path} answered ${status}"
        sleep $((i * 3))
    done
    echo "FAIL ${path}"
    return 1
}

failed=0
check "/" || failed=1
check "/up" || failed=1

exit "$failed"
