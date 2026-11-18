#!/usr/bin/env bash
# Deploy one release with no downtime, and put the previous one back automatically if anything fails (Sprint 54a).
#
#   T2T_IMAGE=ghcr.io/owner/repo bin/deploy.sh <tag>
#
# <tag> is the git commit the images were built from. Deploying an earlier tag is how you roll back by hand.
#
# Steps: pull the images → back up the database → run new database migrations → start the new web container next to
# the old one and wait until it is healthy → move traffic over and stop the old one → restart queues, scheduler and
# SSR on the new release → check everything (t2t:health and a request through Caddy). If any step after the
# migrations fails, the migrations of this deploy are rolled back and the previous release is started again.
set -Eeuo pipefail

# shellcheck source=lib.sh
source "$(dirname "$0")/lib.sh"

TAG=${1:?usage: deploy.sh <tag>}
IMAGE=${T2T_IMAGE:-$(current_image)}
[ -n "$IMAGE" ] || { echo "Set T2T_IMAGE (e.g. ghcr.io/owner/repo)."; exit 2; }
[ -f .env ] || { echo "No .env in $T2T_ROOT. The deploy workflow writes it from the ENV_FILE secret."; exit 2; }
HEALTH_TIMEOUT=${T2T_HEALTH_TIMEOUT:-180}

exec 9>"$T2T_ROOT/.deploy.lock"
flock -n 9 || { echo "Another deploy is running."; exit 1; }
LOG="logs/deploy-$(date -u +%Y%m%dT%H%M%SZ)-${TAG:0:12}.log"
exec > >(tee -a "$LOG") 2>&1

PREV=$(current_tag)
FIRST=false
[ -z "$PREV" ] && FIRST=true
MIGRATED=false
BATCH_BEFORE=""
BATCH_AFTER=0
STAGE=start
if [ "$FIRST" = true ]; then say "Deploying ${TAG:0:12} (first deploy)."; else say "Deploying ${TAG:0:12} (live now: ${PREV:0:12})."; fi

# The database CA (DigitalOcean Managed MySQL) and TLS for Caddy: an origin certificate when one is installed,
# Caddy's own local CA for a rehearsal, otherwise a Let's Encrypt certificate Caddy gets by itself.
mkdir -p secrets/db
touch secrets/db/ca.crt
if [ -s secrets/certs/origin.pem ] && [ -s secrets/certs/origin.key ]; then
	echo 'tls /etc/caddy/certs/origin.pem /etc/caddy/certs/origin.key' >caddy/tls.caddy
elif [ "$(envval T2T_TLS)" = internal ]; then
	echo 'tls internal' >caddy/tls.caddy
else
	: >caddy/tls.caddy
fi

# Wait until a container's Docker health check passes.
wait_healthy() {
	local c=$1 waited=0 state
	while [ "$waited" -lt "$HEALTH_TIMEOUT" ]; do
		state=$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$c" 2>/dev/null || echo gone)
		case $state in
		healthy) return 0 ;;
		gone | exited | dead) return 1 ;;
		esac
		sleep 2
		waited=$((waited + 2))
	done
	return 1
}

new_web=""
rollback() {
	local why=$1
	trap - ERR
	set +e
	say "FAILED at '$STAGE': $why. Rolling back."
	[ -n "$new_web" ] && docker rm -f "$new_web" >/dev/null 2>&1
	# A migration that failed part-way has still recorded the ones before it in this deploy's batch.
	if [ "$STAGE" = migrations ] && [ -n "$BATCH_BEFORE" ]; then
		BATCH_AFTER=$(max_batch)
		[ "$BATCH_AFTER" -gt "$BATCH_BEFORE" ] && MIGRATED=true
	fi
	if [ "$MIGRATED" = true ]; then
		say "Rolling back this deploy's database migrations."
		dc "$TAG" run --rm --no-deps -T web php artisan migrate:rollback --force --batch="$BATCH_AFTER" || say "Migration rollback failed: restore the pre-deploy backup if the old release misbehaves (docs/operations/runbooks.md)."
	fi
	if [ -n "$PREV" ]; then
		say "Starting ${PREV:0:12} again."
		dc "$PREV" up -d --no-deps ssr horizon scheduler
		local failed=() c
		for c in $(containers_of web); do
			[ "$(docker inspect -f '{{.Config.Image}}' "$c")" = "$IMAGE:$TAG" ] && failed+=("$c")
		done
		if [ "$(containers_of web | wc -w)" -eq "${#failed[@]}" ]; then
			# No web container on the previous release is left: start one next to the failed one(s), then swap.
			dc "$PREV" up -d --no-deps --no-recreate --scale "web=$((${#failed[@]} + 1))" web
			local back
			back=$(for c in $(containers_of web); do [ "$(docker inspect -f '{{.Config.Image}}' "$c")" = "$IMAGE:$PREV" ] && echo "$c"; done | head -n 1)
			wait_healthy "$back" || say "The previous release is not healthy either; see the runbook."
		fi
		# Stopped gracefully, so requests already in flight finish.
		[ "${#failed[@]}" -gt 0 ] && { docker stop -t 20 "${failed[@]}" >/dev/null; docker rm -f "${failed[@]}" >/dev/null; }
	fi
	artisan_live t2t:ops:record deploy failed "${TAG:0:12} failed at $STAGE: $why"
	notify "Deploy of ${TAG:0:12} FAILED at '$STAGE' ($why). ${PREV:+Rolled back to ${PREV:0:12}.} Log: $T2T_ROOT/$LOG"
	exit 1
}
trap 'rollback "command failed on line $LINENO"' ERR

STAGE=pull
say "Pulling images."
dc "$TAG" pull --quiet web ssr

STAGE=services
dc "$TAG" up -d --no-deps redis meilisearch clamav caddy
dc "$TAG" exec -T caddy caddy reload --config /etc/caddy/Caddyfile --force >/dev/null 2>&1 || true
dc "$TAG" up -d --wait --no-deps redis

STAGE=backup
if [ "$FIRST" = false ] && [ -n "$(envval BACKUP_BUCKET)" ] && [ "${T2T_SKIP_BACKUP:-}" != 1 ]; then
	say "Backing up the database before changing anything."
	bin/backup.sh db pre-deploy || { say "Backup failed; nothing was changed."; trap - ERR; notify "Deploy of ${TAG:0:12} stopped: the pre-deploy backup failed. Nothing changed."; exit 1; }
fi

STAGE=migrations
# The newest migration batch, so a rollback undoes exactly what this deploy applied (and nothing on an existing
# database when there was nothing new, e.g. a new server pointed at the live database).
max_batch() {
	local out
	out=$(dc "$TAG" run --rm --no-deps -T web php artisan migrate:status --no-ansi 2>/dev/null || true)
	printf '%s' "$out" | grep -o '\[[0-9][0-9]*\]' | tr -d '[]' | sort -n | tail -n 1 | grep . || echo 0
}
status=$(dc "$TAG" run --rm --no-deps -T web php artisan migrate:status --no-ansi 2>/dev/null || true)
pending=$(printf '%s\n' "$status" | grep -c 'Pending' || true)
BATCH_BEFORE=$(max_batch)
if [ "$FIRST" = true ]; then
	say "First deploy on this server: installing (migrations, industries, locations, content pack; safe on an existing database)."
	dc "$TAG" run --rm --no-deps -T web php artisan t2t:install
elif [ "$pending" -gt 0 ]; then
	say "Running $pending new migration(s)."
	dc "$TAG" run --rm --no-deps -T web php artisan migrate --force
else
	say "No new migrations."
fi
BATCH_AFTER=$(max_batch)
[ "$BATCH_AFTER" -gt "$BATCH_BEFORE" ] && MIGRATED=true
dc "$TAG" run --rm --no-deps -T web sh -c 'php artisan t2t:permissions:sync && php artisan t2t:catalogue:sync && php artisan scout:sync-index-settings'
[ "$FIRST" = true ] && dc "$TAG" run --rm --no-deps -T web php artisan scout:import 'App\Domains\Identity\Models\User'

STAGE=ssr
dc "$TAG" up -d --no-deps ssr

STAGE=web
old_web=$(containers_of web)
if [ -z "$old_web" ]; then
	dc "$TAG" up -d --no-deps web
	new_web=$(containers_of web)
else
	count=$(wc -w <<<"$old_web")
	dc "$TAG" up -d --no-deps --no-recreate --scale "web=$((count + 1))" web
	new_web=$(comm -13 <(sort <<<"$old_web") <(containers_of web | sort) | head -n 1)
fi
[ -n "$new_web" ] || rollback "the new web container did not start"
say "Waiting for the new web container to be healthy."
wait_healthy "$new_web" || rollback "the new web container did not become healthy within ${HEALTH_TIMEOUT}s"
if [ -n "$old_web" ]; then
	say "New container healthy; stopping the old one."
	sleep 3 # let Caddy's health check mark the new one up before the old one goes
	# shellcheck disable=SC2086
	docker stop -t 30 $old_web >/dev/null && docker rm $old_web >/dev/null
fi
new_web=""

STAGE=workers
say "Restarting queues and the scheduler on the new release."
dc "$TAG" up -d --no-deps horizon scheduler

STAGE=verify
skip=(--skip=scheduler)
[ "$FIRST" = true ] && skip+=(--skip=scanner)
for s in $(envval T2T_HEALTH_SKIP | tr ',' ' '); do skip+=("--skip=$s"); done
domain=$(envval T2T_DOMAIN)
ok=false
for _ in $(seq 1 15); do
	if [ "${T2T_REHEARSAL_FAIL_VERIFY:-}" != 1 ] \
		&& docker exec "$(containers_of web | tail -n 1)" php artisan t2t:health "${skip[@]}" >/dev/null 2>&1 \
		&& curl -fsSk -m 10 --resolve "$domain:443:127.0.0.1" "https://$domain/up" >/dev/null; then
		ok=true
		break
	fi
	sleep 4
done
if [ "$ok" != true ]; then
	docker exec "$(containers_of web | tail -n 1)" php artisan t2t:health "${skip[@]}" || true
	rollback "health checks failed after the switch"
fi

trap - ERR
STAGE=finished
printf 'T2T_IMAGE=%s\nT2T_TAG=%s\nT2T_PREVIOUS=%s\n' "$IMAGE" "$TAG" "$PREV" >.release
echo "$(date -u +%FT%TZ) $TAG" >>releases.log

# Keep the images of the last three releases (for fast rollbacks); remove older ones.
keep=$(tail -n 3 releases.log | awk '{print $2}')
for t in $(docker image ls "$IMAGE" --format '{{.Tag}}'; docker image ls "$IMAGE-ssr" --format '{{.Tag}}'); do
	grep -qx "$t" <<<"$keep" || docker image rm "$IMAGE:$t" "$IMAGE-ssr:$t" >/dev/null 2>&1 || true
done
docker image prune -f >/dev/null || true

artisan_live t2t:ops:record deploy ok "${TAG:0:12}"
say "Deployed ${TAG:0:12}."
notify "Deployed ${TAG:0:12}${PREV:+ (was ${PREV:0:12})}."
