#!/usr/bin/env bash
# Restore drill (Sprint 54a): proves the backups can actually be restored, monthly (t2t-restore-drill timer) or by
# hand. Nothing live is touched: the newest database backup is downloaded, decrypted and loaded into a throwaway
# MySQL container, then checked against the live database; the file backups are compared with the live files.
#
#   bin/restore-drill.sh            the newest daily backup
#   bin/restore-drill.sh <key>      a specific one, e.g. db/pre-deploy/2026-12-01T081000Z.sql.gz.enc
#
# The result (and how long the restore took: the recovery time to expect) is logged, recorded for Admin and
# t2t:health, and sent to the alert webhook if it fails.
set -Eeuo pipefail

# shellcheck source=lib.sh
source "$(dirname "$0")/lib.sh"

BUCKET=$(envval BACKUP_BUCKET)
[ -n "$BUCKET" ] || { echo "Backups are off: BACKUP_BUCKET is empty in .env (fine on staging)."; exit 0; }
RCLONE_IMAGE=rclone/rclone:1.69
MYSQL_IMAGE=mysql:8.4
NET=t2t_default
WORK=$(mktemp -d)
DRILL=t2t-restore-drill-$$
LOG="logs/restore-drill-$(date -u +%Y%m%d).log"
exec > >(tee -a "$LOG") 2>&1

cleanup() {
	docker rm -f "$DRILL" >/dev/null 2>&1 || true
	rm -rf "$WORK"
}
trap cleanup EXIT

rc() {
	docker run --rm -i --network "$NET" -v "$WORK:/work" \
		-e RCLONE_CONFIG_BK_TYPE=s3 -e "RCLONE_CONFIG_BK_PROVIDER=$(envval BACKUP_S3_PROVIDER | sed 's/^$/DigitalOcean/')" \
		-e "RCLONE_CONFIG_BK_ENDPOINT=$(envval BACKUP_S3_ENDPOINT)" -e "RCLONE_CONFIG_BK_ACCESS_KEY_ID=$(envval BACKUP_S3_KEY)" \
		-e "RCLONE_CONFIG_BK_SECRET_ACCESS_KEY=$(envval BACKUP_S3_SECRET)" \
		"$RCLONE_IMAGE" "$@"
}

fail() {
	say "Restore drill FAILED: $*"
	artisan_live t2t:ops:record restore-drill failed "$*"
	notify "Restore drill FAILED: $*. Log: $T2T_ROOT/$LOG"
	exit 1
}

# Live counts, through the running app (its database connection already has the right TLS settings).
live_count() {
	docker exec "$(containers_of web | tail -n 1)" php -r '
		require "vendor/autoload.php"; $app = require "bootstrap/app.php";
		$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
		echo Illuminate\Support\Facades\DB::table($argv[1])->count();' "$1" 2>/dev/null || echo "?"
}

KEY=${1:-}
if [ -z "$KEY" ]; then
	KEY=db/daily/$(rc lsf --files-only "bk:$BUCKET/db/daily" | sort | tail -n 1)
	[ "$KEY" != db/daily/ ] || fail "no daily database backup found in $BUCKET"
fi
say "Restore drill from $BUCKET/$KEY"
start=$(date +%s)

rc copyto "bk:$BUCKET/$KEY" /work/backup.enc || fail "could not download $KEY"
BACKUP_PASSPHRASE=$(envval BACKUP_PASSPHRASE) openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass env:BACKUP_PASSPHRASE \
	-in "$WORK/backup.enc" | gunzip >"$WORK/dump.sql" || fail "could not decrypt or unpack (wrong BACKUP_PASSPHRASE?)"
tail -n 1 "$WORK/dump.sql" | grep -q 'Dump completed' || fail "the dump is incomplete (no 'Dump completed' line)"

ROOTPW=$(openssl rand -hex 16)
docker run -d --name "$DRILL" --tmpfs /var/lib/mysql:rw,size=8g -e "MYSQL_ROOT_PASSWORD=$ROOTPW" -e MYSQL_DATABASE=drill "$MYSQL_IMAGE" >/dev/null
for _ in $(seq 1 90); do
	docker exec "$DRILL" mysqladmin ping -uroot "-p$ROOTPW" --silent >/dev/null 2>&1 && break
	sleep 2
done
# The official image restarts once after initialising; wait until the real server answers queries.
for _ in $(seq 1 60); do
	docker exec "$DRILL" mysql -uroot "-p$ROOTPW" -e 'select 1' drill >/dev/null 2>&1 && break
	sleep 2
done
docker exec -i "$DRILL" mysql -uroot "-p$ROOTPW" drill <"$WORK/dump.sql" || fail "the dump did not load into MySQL"
restored_s=$(($(date +%s) - start))

q() { docker exec "$DRILL" mysql -uroot "-p$ROOTPW" -N -B drill -e "$1" 2>/dev/null; }
tables=$(q "select count(*) from information_schema.tables where table_schema = 'drill'")
migrations=$(q 'select count(*) from migrations')
report="tables $tables, migrations $migrations"
for t in users teams referrals; do
	n=$(q "select count(*) from $t") || fail "table $t is missing from the restored copy"
	report="$report, $t $n (live $(live_count "$t"))"
done
[ "${tables:-0}" -ge 20 ] || fail "only ${tables:-0} tables restored"
live_migrations=$(live_count migrations)
if [ "$live_migrations" != "?" ] && [ "$migrations" -gt "$live_migrations" ]; then
	fail "the backup has more migrations ($migrations) than the live database ($live_migrations)"
fi

# Files: how many live files are missing from the backup (new since last night's backup is normal).
missing=$(docker run --rm --network "$NET" -v t2t_storage:/data:ro \
	-e RCLONE_CONFIG_BK_TYPE=s3 -e "RCLONE_CONFIG_BK_PROVIDER=$(envval BACKUP_S3_PROVIDER | sed 's/^$/DigitalOcean/')" \
	-e "RCLONE_CONFIG_BK_ENDPOINT=$(envval BACKUP_S3_ENDPOINT)" -e "RCLONE_CONFIG_BK_ACCESS_KEY_ID=$(envval BACKUP_S3_KEY)" \
	-e "RCLONE_CONFIG_BK_SECRET_ACCESS_KEY=$(envval BACKUP_S3_SECRET)" \
	"$RCLONE_IMAGE" check /data "bk:$BUCKET/files/storage" --one-way --size-only --exclude '.health*/**' 2>&1 | grep -c 'not in' || true)
report="$report; files missing from backup: $missing; restored in ${restored_s}s"

say "Restore drill OK: $report"
artisan_live t2t:ops:record restore-drill ok "$KEY: $report"
