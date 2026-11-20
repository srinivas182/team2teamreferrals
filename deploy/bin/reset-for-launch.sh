#!/usr/bin/env bash
# Reset for launch (go-live): back up the database, then clear all member, team, referral, billing and demo data,
# keeping industries, groups, places, pages, settings and staff accounts (t2t:launch:reset). Run on the server:
#
#   sudo -u t2tdeploy /srv/t2t/bin/reset-for-launch.sh            shows what would be cleared, changes nothing
#   sudo -u t2tdeploy /srv/t2t/bin/reset-for-launch.sh --go       backs up, asks you to type the domain, then resets
#
# Remove DEMO_INSTALL from the settings (ENV_FILE) and deploy before running it, or the demo comes straight back.
set -euo pipefail

# shellcheck source=lib.sh
source "$(dirname "$0")/lib.sh"

web=$(containers_of web | tail -n 1)
[ -n "$web" ] || { echo "The app isn't running."; exit 1; }
domain=$(envval T2T_DOMAIN)

if [ "${1:-}" != --go ]; then
	docker exec "$web" php artisan t2t:launch:reset --dry-run
	echo
	echo "Nothing changed. To reset: $0 --go"
	exit 0
fi

say "Backing up the database first."
mkdir -p backups
chmod 700 backups
file="backups/pre-launch-reset-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
ca=()
tls=()
if [ -s secrets/db/ca.crt ]; then
	ca=(-v "$T2T_ROOT/secrets/db:/ca:ro")
	tls=(--ssl-mode=VERIFY_CA --ssl-ca=/ca/ca.crt)
fi
if ! docker run --rm --network t2t_default "${ca[@]}" -e "MYSQL_PWD=$(envval DB_PASSWORD)" mysql:8.4 \
	mysqldump --host="$(envval DB_HOST)" --port="$(envval DB_PORT | sed 's/^$/3306/')" --user="$(envval DB_USERNAME)" "${tls[@]}" \
	--single-transaction --quick --hex-blob --set-gtid-purged=OFF --no-tablespaces "$(envval DB_DATABASE)" | gzip -6 >"$file"; then
	rm -f "$file"
	echo "The backup failed; nothing was changed."
	exit 1
fi
chmod 600 "$file"
say "Backup saved on the server: $T2T_ROOT/$file ($(du -h "$file" | cut -f1)). Keep it until launch has settled."
if [ -n "$(envval BACKUP_BUCKET)" ]; then bin/backup.sh db pre-reset || echo "(The copy to the backup bucket failed; the server copy above is still there.)"; fi

read -r -p "Type the domain ($domain) to clear the data: " typed
docker exec "$web" php artisan t2t:launch:reset --confirm="$typed"
