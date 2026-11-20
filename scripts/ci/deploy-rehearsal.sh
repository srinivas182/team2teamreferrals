#!/usr/bin/env bash
# Deploy rehearsal (Sprint 54a): runs the real deploy kit (deploy/) on the CI machine as if it were the server, and
# checks what matters on launch day:
#   1. a first deploy installs a working site (MySQL with sql_require_primary_key on, like DigitalOcean's)
#   2. the next deploy, with a new migration, switches over with no failed requests
#   3. a release whose web container never becomes healthy is rolled back: its migration undone, the old release
#      still serving, no failed requests
#   4. a release that fails the health checks after the switch is rolled back the same way
#   5. backups upload (encrypted) and the restore drill restores them
#   6. the health watch passes, and deploying an earlier release (a manual rollback) works
#
# Expects images localhost:5000/t2t:r1 and localhost:5000/t2t-ssr:r1 (built by the workflow) and a registry there.
set -euo pipefail

REPO=$(cd "$(dirname "$0")/../.." && pwd)
IMAGE=localhost:5000/t2t
SRV=${RUNNER_TEMP:-/tmp}/srv-t2t
export T2T_IMAGE=$IMAGE T2T_HEALTH_TIMEOUT=90
rm -rf "$SRV" && mkdir -p "$SRV"
cp -r "$REPO/deploy/bin" "$REPO/deploy/caddy" "$REPO/deploy/compose.yaml" "$REPO/deploy/compose.cpanel.yaml" "$SRV/"
cd "$SRV"

step() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
fail() { printf '\n\033[31mREHEARSAL FAILED: %s\033[0m\n' "$*"; T2T_TAG=$(release 2>/dev/null || echo r1) docker compose -f compose.yaml -f compose.override.yaml logs --tail 80 web horizon ssr caddy 2>&1 || true; exit 1; }
dcx() { T2T_TAG=${TAG:-r1} docker compose -f compose.yaml -f compose.cpanel.yaml -f compose.override.yaml "$@"; }
sql() { dcx exec -T mysql mysql -ut2t -psecret -N -B t2t -e "$1" 2>/dev/null; }
release() { grep '^T2T_TAG=' .release | cut -d= -f2; }
web_image() { docker inspect -f '{{.Config.Image}}' "$(docker ps -q --filter label=com.docker.compose.service=web | head -n 1)"; }

cat >compose.override.yaml <<'EOF'
# Rehearsal only: an S3 server standing in for Spaces. MySQL (strict like DigitalOcean's) and the absent virus
# scanner come from compose.cpanel.yaml, so the cPanel set-up is rehearsed too.
services:
  s3:
    # rclone's own S3 server stands in for Spaces (MinIO no longer publishes images).
    image: rclone/rclone:1.69
    command: ["serve", "s3", "/data", "--addr", ":9000", "--auth-key", "rehearsal,rehearsal-secret"]
    healthcheck: { test: ["CMD-SHELL", "nc -z 127.0.0.1 9000"], interval: 3s, retries: 40 }
EOF

cat >.env <<EOF
APP_NAME="Team to Team Referrals"
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:$(openssl rand -base64 32)
APP_URL=https://localhost
T2T_DOMAIN=localhost
T2T_TLS=internal
T2T_DB_CA=
T2T_HEALTH_SKIP=scanner
T2T_COMPOSE_EXTRA=compose.cpanel.yaml
T2T_CLAMAV_HOST=
T2T_MYSQL_ROOT_PASSWORD=root
LOG_CHANNEL=stack
LOG_STACK=json
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=t2t
DB_USERNAME=t2t
DB_PASSWORD=secret
SESSION_DRIVER=redis
SESSION_SECURE_COOKIE=true
MERCURE_JWT_SECRET=$(openssl rand -hex 24)
MEILISEARCH_KEY=$(openssl rand -hex 20)
MAIL_MAILER=log
SEO_INDEXABLE=false
SEO_CANONICAL_URL=https://localhost
SEO_FORCE_CANONICAL=true
BACKUP_BUCKET=t2t-backups
BACKUP_S3_ENDPOINT=http://s3:9000
BACKUP_S3_PROVIDER=Rclone
BACKUP_S3_KEY=rehearsal
BACKUP_S3_SECRET=rehearsal-secret
BACKUP_PASSPHRASE=$(openssl rand -hex 20)
ALERT_EMAIL=ops@example.com
EOF

# Object storage exists before the first deploy, as on DigitalOcean; the first deploy starts the database itself.
step "Object storage"
TAG=r1 dcx up -d --wait s3
docker run --rm --network t2t_default -e RCLONE_CONFIG_BK_TYPE=s3 -e RCLONE_CONFIG_BK_PROVIDER=Rclone \
	-e RCLONE_CONFIG_BK_ENDPOINT=http://s3:9000 -e RCLONE_CONFIG_BK_ACCESS_KEY_ID=rehearsal -e RCLONE_CONFIG_BK_SECRET_ACCESS_KEY=rehearsal-secret \
	rclone/rclone:1.69 mkdir bk:t2t-backups

# Requests during a deploy: every one must succeed.
LOAD=$SRV/codes
load_start() {
	: >"$LOAD"
	(while :; do curl -sk -m 10 -o /dev/null -w '%{http_code}\n' --resolve localhost:443:127.0.0.1 https://localhost/ >>"$LOAD"; sleep 0.1; done) &
	LOAD_PID=$!
}
load_stop() {
	kill "$LOAD_PID" 2>/dev/null || true
	wait "$LOAD_PID" 2>/dev/null || true
	local total bad
	total=$(wc -l <"$LOAD")
	bad=$(grep -cv '^200$' "$LOAD" || true)
	echo "Requests during the deploy: $total, failed: $bad"
	[ "$total" -ge 20 ] || fail "too few requests were made during the deploy ($total)"
	[ "$bad" -eq 0 ] || fail "$bad requests failed during the deploy: $(sort "$LOAD" | uniq -c | tr '\n' ' ')"
}

# A release: the previous image plus one new migration (and optionally a broken web process).
build_release() { # tag, base, table, broken
	local ctx
	ctx=$(mktemp -d)
	mkdir -p "$ctx/m"
	cat >"$ctx/m/2099_01_01_00000${4:-0}_create_$3.php" <<PHP
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('$3', fn (Blueprint \$t) => \$t->id()); }
    public function down(): void { Schema::dropIfExists('$3'); }
};
PHP
	{
		echo "FROM $IMAGE:$2"
		echo "COPY m/ database/migrations/"
		[ "${5:-}" = broken ] && echo 'CMD ["php", "-r", "sleep(600);"]'
	} >"$ctx/Dockerfile"
	docker build -q -t "$IMAGE:$1" "$ctx" >/dev/null
	docker push -q "$IMAGE:$1" >/dev/null
	docker tag "$IMAGE-ssr:r1" "$IMAGE-ssr:$1" && docker push -q "$IMAGE-ssr:$1" >/dev/null
	docker image rm "$IMAGE:$1" "$IMAGE-ssr:$1" >/dev/null # so the deploy has to pull it
}

step "1. First deploy"
bin/deploy.sh r1 || fail "the first deploy failed"
[ "$(release)" = r1 ] || fail "the release file doesn't say r1"
home=$(curl -sk -m 20 --resolve localhost:443:127.0.0.1 -o "$SRV/home.html" -w '%{http_code} %{redirect_url}' https://localhost/)
grep -q 'Team to Team' "$SRV/home.html" || fail "the home page didn't load through Caddy ($home): $(head -c 300 "$SRV/home.html")"
curl -fsSk --resolve localhost:443:127.0.0.1 https://www.localhost/ -o /dev/null -w '%{http_code}' --resolve www.localhost:443:127.0.0.1 | grep -q 301 || fail "www didn't redirect"
TAG=r1 dcx exec -T web php artisan t2t:health --skip=scanner --skip=scheduler || fail "t2t:health failed after the first deploy"

step "2. A new release with a migration, under load"
build_release r2 r1 rehearsal_probe 1
load_start
bin/deploy.sh r2 || { load_stop; fail "the r2 deploy failed"; }
load_stop
[ "$(release)" = r2 ] || fail "r2 isn't live"
[ "$(web_image)" = "$IMAGE:r2" ] || fail "the web container isn't on r2"
[ "$(sql "select count(*) from information_schema.tables where table_schema='t2t' and table_name='rehearsal_probe'")" = 1 ] || fail "r2's migration didn't run"

step "3. A release whose web container never becomes healthy is rolled back"
build_release r3 r2 rehearsal_broken 2 broken
load_start
if T2T_HEALTH_TIMEOUT=40 bin/deploy.sh r3; then load_stop; fail "the broken r3 deploy reported success"; fi
load_stop
[ "$(release)" = r2 ] || fail "after the failed deploy the release isn't r2"
[ "$(web_image)" = "$IMAGE:r2" ] || fail "after the failed deploy the web container isn't r2"
[ "$(sql "select count(*) from information_schema.tables where table_schema='t2t' and table_name='rehearsal_broken'")" = 0 ] || fail "r3's migration wasn't rolled back"
[ "$(sql "select count(*) from information_schema.tables where table_schema='t2t' and table_name='rehearsal_probe'")" = 1 ] || fail "r2's table went missing"

step "3b. A release whose second migration fails is rolled back, including its first migration"
ctx=$(mktemp -d) && mkdir -p "$ctx/m"
cat >"$ctx/m/2099_01_01_000004_create_rehearsal_half.php" <<'PHP'
<?php
return new class extends Illuminate\Database\Migrations\Migration {
    public function up(): void { Illuminate\Support\Facades\Schema::create('rehearsal_half', fn ($t) => $t->id()); }
    public function down(): void { Illuminate\Support\Facades\Schema::dropIfExists('rehearsal_half'); }
};
PHP
cat >"$ctx/m/2099_01_01_000005_explode.php" <<'PHP'
<?php
return new class extends Illuminate\Database\Migrations\Migration {
    public function up(): void { throw new RuntimeException('rehearsal: this migration fails'); }
    public function down(): void {}
};
PHP
printf 'FROM %s:r2\nCOPY m/ database/migrations/\n' "$IMAGE" >"$ctx/Dockerfile"
docker build -q -t "$IMAGE:r5" "$ctx" >/dev/null && docker push -q "$IMAGE:r5" >/dev/null
docker tag "$IMAGE-ssr:r1" "$IMAGE-ssr:r5" && docker push -q "$IMAGE-ssr:r5" >/dev/null
if bin/deploy.sh r5; then fail "the r5 deploy with a failing migration reported success"; fi
{ [ "$(release)" = r2 ] && [ "$(web_image)" = "$IMAGE:r2" ]; } || fail "after the failed migration r2 isn't live"
[ "$(sql "select count(*) from information_schema.tables where table_schema='t2t' and table_name='rehearsal_half'")" = 0 ] || fail "the first migration of the failed batch wasn't rolled back"
[ "$(sql "select count(*) from migrations where migration like '2099%'")" = 1 ] || fail "the migrations table has the wrong rehearsal entries"

step "4. A release that fails the checks after the switch is rolled back"
build_release r4 r2 rehearsal_late 3
load_start
if T2T_REHEARSAL_FAIL_VERIFY=1 bin/deploy.sh r4; then load_stop; fail "the r4 deploy reported success"; fi
load_stop
[ "$(release)" = r2 ] || fail "after the late failure the release isn't r2"
[ "$(web_image)" = "$IMAGE:r2" ] || fail "after the late failure the web container isn't r2"
[ "$(docker ps -q --filter label=com.docker.compose.service=web | wc -l)" -eq 1 ] || fail "more than one web container left running"
[ "$(sql "select count(*) from information_schema.tables where table_schema='t2t' and table_name='rehearsal_late'")" = 0 ] || fail "r4's migration wasn't rolled back"
TAG=r2 dcx exec -T horizon php -r 'exit(0);' || fail "horizon isn't running after the rollback"
[ "$(docker inspect -f '{{.Config.Image}}' "$(docker ps -q --filter label=com.docker.compose.service=horizon)")" = "$IMAGE:r2" ] || fail "horizon isn't back on r2"

step "5. Backups and the restore drill"
# A file in the server's storage volume, as a profile photo would be, so the file backup has something to copy.
docker run --rm -v t2t_storage:/data alpine:3.20 sh -c 'mkdir -p /data/public && echo rehearsal > /data/public/rehearsal.txt && chown -R 10001:10001 /data/public'
bin/backup.sh || fail "the backup failed"
objects=$(docker run --rm --network t2t_default -e RCLONE_CONFIG_BK_TYPE=s3 -e RCLONE_CONFIG_BK_PROVIDER=Rclone \
	-e RCLONE_CONFIG_BK_ENDPOINT=http://s3:9000 -e RCLONE_CONFIG_BK_ACCESS_KEY_ID=rehearsal -e RCLONE_CONFIG_BK_SECRET_ACCESS_KEY=rehearsal-secret \
	rclone/rclone:1.69 lsf -R bk:t2t-backups)
echo "$objects"
grep -q '^db/daily/.*\.sql\.gz\.enc$' <<<"$objects" || fail "no database backup in the bucket"
grep -q '^db/pre-deploy/' <<<"$objects" || fail "no pre-deploy backups in the bucket"
grep -q '^files/storage/public/rehearsal.txt$' <<<"$objects" || fail "the file backup is missing the storage file"
bin/restore-drill.sh || fail "the restore drill failed"
TAG=r2 dcx exec -T web php artisan t2t:health --json --skip=scanner | tee /dev/stderr | grep -q '"backup"' || fail "t2t:health doesn't report the backup"

step "6. The health watch, and a manual rollback to r1"
bin/watch.sh || fail "the health watch failed"
bin/deploy.sh r1 || fail "deploying r1 again failed"
{ [ "$(release)" = r1 ] && [ "$(web_image)" = "$IMAGE:r1" ]; } || fail "r1 isn't live after the manual rollback"
curl -fsSk --resolve localhost:443:127.0.0.1 https://localhost/up >/dev/null || fail "the site isn't up after the manual rollback"

step "7. Behind another web server (the cPanel set-up): Caddy on 127.0.0.1:8088 only, without TLS"
printf 'T2T_CADDYFILE=Caddyfile.proxy\nT2T_HTTP_BIND=127.0.0.1:8088\nT2T_HTTPS_BIND=127.0.0.1:8443\n' >>.env
bin/deploy.sh r2 || fail "the deploy behind a proxy failed"
[ "$(release)" = r2 ] || fail "r2 isn't live behind the proxy"
# As Apache forwards it: the visitor's https in X-Forwarded-Proto, the visitor's address appended to X-Forwarded-For.
page=$(curl -s -m 20 -o "$SRV/proxied.html" -w '%{http_code}' -H 'Host: localhost' -H 'X-Forwarded-Proto: https' \
	-H 'X-Forwarded-For: 6.6.6.6, 203.0.113.7' http://127.0.0.1:8088/)
{ [ "$page" = 200 ] && grep -q 'Team to Team' "$SRV/proxied.html"; } || fail "the home page didn't load behind the proxy ($page)"
# Plain http from the proxy goes to https (the app's canonical redirect), not round in circles.
[ "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -H 'Host: localhost' http://127.0.0.1:8088/about)" = "301 https://localhost/about" ] ||
	fail "plain http behind the proxy didn't redirect to https once"
# Nothing listens on the public ports any more.
if curl -s -m 3 -o /dev/null http://127.0.0.1:80/ || curl -sk -m 3 -o /dev/null https://127.0.0.1:443/; then fail "ports 80/443 are still published"; fi
# Caddy works out the visitor's address from the right of X-Forwarded-For, not the proxy's or a forged one.
seen=$(TAG=r2 dcx logs --since 3m caddy 2>/dev/null | grep -o '"client_ip":"[^"]*"' | sort | uniq -c)
grep -q '"client_ip":"203.0.113.7"' <<<"$seen" || fail "Caddy didn't see the visitor's address 203.0.113.7 behind the proxy: $seen"
if grep -q '"client_ip":"6.6.6.6"' <<<"$seen"; then fail "Caddy believed a forged address behind the proxy"; fi

printf '\n\033[32mRehearsal passed.\033[0m\n'
