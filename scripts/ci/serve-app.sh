#!/usr/bin/env bash
# Serve the built app for browser checks: production mode, SQLite, one of every public page, gzip as in production,
# at http://127.0.0.1:8001. Used by the CI performance, screen and weekly Lighthouse jobs (run after npm run build).
set -euo pipefail
cp .env.example .env && php artisan key:generate
sed -i 's/^APP_ENV=.*/APP_ENV=production/; s/^APP_DEBUG=.*/APP_DEBUG=false/; s/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/; s/^SESSION_DRIVER=.*/SESSION_DRIVER=file/; s/^CACHE_STORE=.*/CACHE_STORE=file/; s#^APP_URL=.*#APP_URL=http://127.0.0.1:8001#' .env
printf 'SEO_INDEXABLE=true\nSEO_CANONICAL_URL=http://127.0.0.1:8001\nSESSION_SECURE_COOKIE=false\n' >> .env  # plain http locally
touch database/database.sqlite && php artisan migrate --force
php artisan tinker --execute="require base_path('scripts/ci/seed-public-pages.php');"
# People, a partner and a referral for the signed-in checks; the partner's public pages are in the Lighthouse runs too.
php artisan tinker --execute="require base_path('scripts/ci/seed-screens.php');"
php artisan optimize
(php artisan inertia:start-ssr >/dev/null 2>&1 &)
(php artisan serve --port=8000 >/dev/null 2>&1 &)
(node scripts/ci/gzip-proxy.mjs 8001 8000 >/dev/null 2>&1 &)
for _ in $(seq 1 30); do curl -fs -o /dev/null http://127.0.0.1:8001/ && exit 0; sleep 1; done
echo "The app did not start" >&2; exit 1
