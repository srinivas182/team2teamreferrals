#!/bin/sh
set -e
# First run: install dependencies, create .env from the Docker defaults, generate the key, migrate.
[ -d vendor ] || composer install --no-interaction
[ -f .env ] || cp .env.docker .env
grep -q '^APP_KEY=base64' .env || php artisan key:generate --force
php artisan migrate --force --graceful
exec "$@"
