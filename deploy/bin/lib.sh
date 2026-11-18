#!/usr/bin/env bash
# Shared helpers for the server scripts (Sprint 54a). Sourced, not run.
# shellcheck disable=SC2034

T2T_ROOT=${T2T_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}
cd "$T2T_ROOT" || exit 1
mkdir -p logs secrets/certs

# Read one value from .env without sourcing it (values may hold characters the shell would interpret).
envval() {
	local line
	# "|| true" inside the substitution: deploy.sh's ERR trap reaches into subshells (set -E).
	line=$(grep -E "^${1}=" .env 2>/dev/null | tail -n 1 || true)
	line=${line#*=}
	line=${line%$'\r'}
	if [[ $line == \"*\" || $line == \'*\' ]]; then line=${line:1:${#line}-2}; fi
	printf '%s' "$line"
}

say() { printf '%s  %s\n' "$(date -u +%H:%M:%S)" "$*"; }

# The release that is live now (empty before the first deploy).
current_tag() { if [ -f .release ]; then grep -E '^T2T_TAG=' .release | cut -d= -f2 || true; fi; }
current_image() { if [ -f .release ]; then grep -E '^T2T_IMAGE=' .release | cut -d= -f2 || true; fi; }

# docker compose for a given release tag; picks up compose.override.yaml when present (staging or the CI rehearsal).
dc() {
	local tag=$1
	shift
	local files=(-f compose.yaml)
	[ -f compose.override.yaml ] && files+=(-f compose.override.yaml)
	T2T_IMAGE=$IMAGE T2T_TAG=$tag docker compose "${files[@]}" "$@"
}

# Post to the alert webhook (Slack, Discord, Teams or any service that accepts {"text": ...}), if one is set.
notify() {
	local url
	url=$(envval NOTIFY_WEBHOOK_URL)
	[ -n "$url" ] || return 0
	local text
	text="[$(envval APP_NAME | tr -d '"') $(envval APP_ENV)] $*"
	curl -fsS -m 10 -H 'Content-Type: application/json' \
		-d "$(printf '{"text":%s,"content":%s}' "$(json_str "$text")" "$(json_str "$text")")" "$url" >/dev/null || true
}

json_str() { printf '%s' "$1" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))'; }

# The running containers of one service, oldest first.
containers_of() {
	docker ps -q --filter "label=com.docker.compose.project=t2t" --filter "label=com.docker.compose.service=$1" | tac
}

# Run an artisan command in the live web container (for recording ops results). Never fails the caller.
artisan_live() {
	local c
	c=$(containers_of web | tail -n 1)
	[ -n "$c" ] || return 0
	docker exec "$c" php artisan "$@" >/dev/null 2>&1 || true
}
