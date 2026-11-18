#!/usr/bin/env bash
# Backups to the backup bucket (a Spaces bucket in a different region from the server) (Sprint 54a).
#
#   bin/backup.sh            everything: the database, then files (nightly, by the t2t-backup timer)
#   bin/backup.sh db [label] the database only; label "pre-deploy" is used by deploy.sh
#   bin/backup.sh files      files only
#
# Database: mysqldump (consistent, without locking: --single-transaction) → gzip → encrypted with AES-256 using
# BACKUP_PASSPHRASE → uploaded. Kept: daily 35 days, monthly (the 1st) 13 months, pre-deploy 14 days.
# Files: the server's storage volume and the private-files bucket are mirrored; changed or deleted files are kept
# for 90 days under files/history/<date>/. DigitalOcean also keeps 7 days of point-in-time recovery for the database.
#
# Settings in .env: BACKUP_BUCKET, BACKUP_S3_ENDPOINT, BACKUP_S3_KEY, BACKUP_S3_SECRET, BACKUP_PASSPHRASE,
# optional BACKUP_S3_PROVIDER (DigitalOcean), FILES_BUCKET and FILES_S3_ENDPOINT (the private-files bucket).
set -Eeuo pipefail

# shellcheck source=lib.sh
source "$(dirname "$0")/lib.sh"

WHAT=${1:-all}
LABEL=${2:-daily}
BUCKET=$(envval BACKUP_BUCKET)
[ -n "$BUCKET" ] || { echo "Backups are off: BACKUP_BUCKET is empty in .env (fine on staging)."; exit 0; }
[ -n "$(envval BACKUP_PASSPHRASE)" ] || { echo "BACKUP_PASSPHRASE is not set in .env."; exit 2; }
RCLONE_IMAGE=rclone/rclone:1.69
MYSQL_IMAGE=mysql:8.4
STAMP=$(date -u +%Y-%m-%dT%H%M%SZ)
NET=t2t_default

exec 8>"$T2T_ROOT/.backup.lock"
flock -w 600 8 || { echo "Another backup is still running."; exit 1; }
LOG="logs/backup-$(date -u +%Y%m%d).log"
exec > >(tee -a "$LOG") 2>&1

# rclone with two remotes, configured from the environment: "bk" (backups) and "src" (private files).
rclone() {
	docker run --rm -i --network "$NET" \
		-e RCLONE_CONFIG_BK_TYPE=s3 -e "RCLONE_CONFIG_BK_PROVIDER=$(envval BACKUP_S3_PROVIDER | sed 's/^$/DigitalOcean/')" \
		-e "RCLONE_CONFIG_BK_ENDPOINT=$(envval BACKUP_S3_ENDPOINT)" -e "RCLONE_CONFIG_BK_ACCESS_KEY_ID=$(envval BACKUP_S3_KEY)" \
		-e "RCLONE_CONFIG_BK_SECRET_ACCESS_KEY=$(envval BACKUP_S3_SECRET)" -e RCLONE_CONFIG_BK_ACL=private \
		-e RCLONE_CONFIG_SRC_TYPE=s3 -e "RCLONE_CONFIG_SRC_PROVIDER=$(envval BACKUP_S3_PROVIDER | sed 's/^$/DigitalOcean/')" \
		-e "RCLONE_CONFIG_SRC_ENDPOINT=$(envval FILES_S3_ENDPOINT)" -e "RCLONE_CONFIG_SRC_ACCESS_KEY_ID=$(envval BACKUP_S3_KEY)" \
		-e "RCLONE_CONFIG_SRC_SECRET_ACCESS_KEY=$(envval BACKUP_S3_SECRET)" \
		"$@"
}
rc() { rclone "$RCLONE_IMAGE" "$@"; }

backup_db() {
	local key="db/$LABEL/$STAMP.sql.gz.enc" ca=() size
	say "Database → $BUCKET/$key"
	[ -s secrets/db/ca.crt ] && ca=(-v "$T2T_ROOT/secrets/db:/ca:ro")
	local tls=()
	[ -s secrets/db/ca.crt ] && tls=(--ssl-mode=VERIFY_CA --ssl-ca=/ca/ca.crt)
	# set -e doesn't apply inside a function called with ||, so every step's result is checked explicitly.
	if ! docker run --rm --network "$NET" "${ca[@]}" -e "MYSQL_PWD=$(envval DB_PASSWORD)" "$MYSQL_IMAGE" \
		mysqldump --host="$(envval DB_HOST)" --port="$(envval DB_PORT | sed 's/^$/3306/')" --user="$(envval DB_USERNAME)" "${tls[@]}" \
		--single-transaction --quick --hex-blob --set-gtid-purged=OFF --no-tablespaces \
		--default-character-set=utf8mb4 "$(envval DB_DATABASE)" |
		gzip -6 |
		BACKUP_PASSPHRASE=$(envval BACKUP_PASSPHRASE) openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -pass env:BACKUP_PASSPHRASE |
		rc rcat "bk:$BUCKET/$key"; then
		rc deletefile "bk:$BUCKET/$key" >/dev/null 2>&1 || true
		echo "The database dump or upload failed."
		return 1
	fi
	size=$(rc lsjson "bk:$BUCKET/$key" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d[0]["Size"] if d else 0)')
	if [ "${size:-0}" -lt 1024 ]; then
		rc deletefile "bk:$BUCKET/$key" >/dev/null 2>&1 || true
		echo "The database backup came out too small ($size bytes)."
		return 1
	fi
	say "Database backup done: $((size / 1024)) KB."
	if [ "$LABEL" = daily ] && [ "$(date -u +%d)" = 01 ]; then
		rc copyto "bk:$BUCKET/$key" "bk:$BUCKET/db/monthly/$STAMP.sql.gz.enc" || return 1
	fi
	rc delete --min-age 35d "bk:$BUCKET/db/daily" || true
	rc delete --min-age 14d "bk:$BUCKET/db/pre-deploy" || true
	rc delete --min-age 400d "bk:$BUCKET/db/monthly" || true
	DB_SIZE=$size
}

backup_files() {
	local day
	day=$(date -u +%Y-%m-%d)
	say "Files on the server → $BUCKET/files/storage"
	docker run --rm -i --network "$NET" -v t2t_storage:/data:ro \
		-e RCLONE_CONFIG_BK_TYPE=s3 -e "RCLONE_CONFIG_BK_PROVIDER=$(envval BACKUP_S3_PROVIDER | sed 's/^$/DigitalOcean/')" \
		-e "RCLONE_CONFIG_BK_ENDPOINT=$(envval BACKUP_S3_ENDPOINT)" -e "RCLONE_CONFIG_BK_ACCESS_KEY_ID=$(envval BACKUP_S3_KEY)" \
		-e "RCLONE_CONFIG_BK_SECRET_ACCESS_KEY=$(envval BACKUP_S3_SECRET)" -e RCLONE_CONFIG_BK_ACL=private \
		"$RCLONE_IMAGE" sync /data "bk:$BUCKET/files/storage" --backup-dir "bk:$BUCKET/files/history/$day/storage" --exclude '.health*/**' --fast-list || return 1
	if [ -n "$(envval FILES_BUCKET)" ]; then
		say "Private files bucket $(envval FILES_BUCKET) → $BUCKET/files/private"
		rc sync "src:$(envval FILES_BUCKET)" "bk:$BUCKET/files/private" --backup-dir "bk:$BUCKET/files/history/$day/private" --exclude '.health*/**' --fast-list || return 1
	fi
	rc delete --min-age 90d "bk:$BUCKET/files/history" || true
	rc rmdirs --leave-root "bk:$BUCKET/files/history" || true
}

status=ok
detail=""
DB_SIZE=0
trap 'status=failed' ERR
set +e
case $WHAT in
all | db) backup_db || status=failed ;;
esac
case $WHAT in
all | files) [ "$status" = ok ] && { backup_files || status=failed; } ;;
esac
set -e
trap - ERR

detail="$WHAT/$LABEL $STAMP, database $((DB_SIZE / 1024)) KB"
if [ "$status" = ok ]; then
	say "Backup OK ($detail)."
	[ "$LABEL" = daily ] && artisan_live t2t:ops:record backup ok "$detail"
	exit 0
fi
say "Backup FAILED ($detail)."
artisan_live t2t:ops:record backup failed "$detail"
notify "Backup FAILED ($WHAT/$LABEL). Log: $T2T_ROOT/$LOG"
exit 1
