# Production image: multi-stage, non-root, Laravel Octane on FrankenPHP (worker mode keeps the app in memory).
# Targets: `app` (web, horizon, scheduler share it) and `ssr` (Inertia server-side rendering on Node).

# 1. PHP dependencies, no dev packages.
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist --ignore-platform-reqs --no-autoloader

# 2. Front-end build (client + SSR bundle). Needs vendor for Ziggy-free Inertia helpers only via composer.json paths.
FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
RUN npm run build

# 3. Runtime.
FROM dunglas/frankenphp:1-php8.4-bookworm AS app
# librsvg (rsvg-convert) + the Inter font render the "We claimed …" share cards on the server.
RUN apt-get update && apt-get install -y --no-install-recommends librsvg2-bin fonts-inter && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_mysql redis intl zip bcmath pcntl opcache \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/prod/php.ini "$PHP_INI_DIR/conf.d/zz-t2t.ini"
WORKDIR /app
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build
COPY --from=assets /app/bootstrap/ssr ./bootstrap/ssr
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && rm -rf node_modules tests prototype storybook-static .git \
    && useradd --uid 10001 --create-home t2t \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R t2t:t2t storage bootstrap/cache /data /config
USER t2t
ENV APP_ENV=production LOG_STACK=json OCTANE_SERVER=frankenphp
EXPOSE 8000
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD curl -fsS http://127.0.0.1:8000/up || exit 1
CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8000", "--workers=auto", "--max-requests=1000"]

# 4. Server-side rendering.
FROM node:22-bookworm-slim AS ssr
WORKDIR /app
COPY --from=assets /app/bootstrap/ssr ./bootstrap/ssr
COPY --from=assets /app/node_modules ./node_modules
COPY --from=assets /app/package.json ./package.json
USER node
EXPOSE 13714
CMD ["node", "bootstrap/ssr/ssr.js"]
