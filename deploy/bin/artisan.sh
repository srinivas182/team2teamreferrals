#!/usr/bin/env bash
# Run an artisan command in the live app (Sprint 54a), e.g. on the server:
#   sudo -u deploy /srv/t2t/bin/artisan.sh t2t:super-admin you@example.com
#   sudo -u deploy /srv/t2t/bin/artisan.sh t2t:health
set -euo pipefail

# shellcheck source=lib.sh
source "$(dirname "$0")/lib.sh"

web=$(containers_of web | tail -n 1)
[ -n "$web" ] || { echo "The app isn't running (no web container)."; exit 1; }
tty=()
[ -t 0 ] && tty=(-it)
exec docker exec "${tty[@]}" "$web" php artisan "$@"
