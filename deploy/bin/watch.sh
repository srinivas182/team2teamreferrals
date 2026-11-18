#!/usr/bin/env bash
# Every five minutes (t2t-watch timer) (Sprint 54a): is everything working? Catches what an uptime check on the home
# page can't see: stopped queues or scheduler, virus scanning down, search down, a missed backup, a filling disk.
#
# - A web container Docker reports unhealthy is restarted (the self-heal for a stuck process).
# - After two failed checks in a row, an alert goes to NOTIFY_WEBHOOK_URL and by email to ALERT_EMAIL (sent by the
#   app); when it recovers, a "recovered" message follows. Nothing is sent while it stays the same.
set -uo pipefail

# shellcheck source=lib.sh
source "$(dirname "$0")/lib.sh"

[ -f .release ] || exit 0 # nothing deployed yet
STATE=logs/.watch-state
prev=$(cat "$STATE" 2>/dev/null || echo "ok 0")
read -r prev_status prev_fails <<<"$prev"

for c in $(containers_of web); do
	if [ "$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{end}}' "$c")" = unhealthy ]; then
		say "Web container $c is unhealthy; restarting it."
		docker restart -t 20 "$c" >/dev/null || true
	fi
done

web=$(containers_of web | tail -n 1)
if [ -z "$web" ]; then
	out="No web container is running."
	healthy=false
else
	skip=()
	for s in $(envval T2T_HEALTH_SKIP | tr ',' ' '); do skip+=("--skip=$s"); done
	if out=$(docker exec "$web" php artisan t2t:health --json "${skip[@]}" 2>&1); then healthy=true; else healthy=false; fi
fi

if [ "$healthy" = true ]; then
	if [ "$prev_status" = alerted ]; then
		notify "Recovered: every health check passes again."
		docker exec "$web" php artisan t2t:ops:alert "Recovered" "Every health check passes again." >/dev/null 2>&1 || true
	fi
	echo "ok 0" >"$STATE"
	exit 0
fi

fails=$((prev_fails + 1))
failing=$(printf '%s' "$out" | python3 -c '
import json, sys
try:
    d = json.load(sys.stdin)
    print(", ".join(k + " (" + str(v.get("detail", "")) + ")" for k, v in d["checks"].items() if v.get("ok") is False))
except Exception:
    print("the health check could not run")
' 2>/dev/null)
say "Health check failed ($fails in a row): $failing"
if [ "$fails" -ge 2 ] && [ "$prev_status" != alerted ]; then
	notify "Something is not working: $failing. Runbook: docs/operations/runbooks.md"
	if [ -n "$web" ]; then docker exec "$web" php artisan t2t:ops:alert "Something is not working" "$failing" >/dev/null 2>&1 || true; fi
	echo "alerted $fails" >"$STATE"
else
	echo "$prev_status $fails" | sed 's/^ok /failing /' >"$STATE"
fi
exit 1
