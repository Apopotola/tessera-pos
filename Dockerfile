# Tessera POS: one image with the API (Laravel on PHP-FPM), the web app (Next.js) and Nginx in front,
# so the browser sees one origin. Supervisor also runs the scheduler and the queue worker.
# Used by docker-compose.yml (local and demo) and by Render (render.yaml).

# ---------- 1. Web app build (Next.js, standalone output) ----------
FROM node:22-alpine AS web
WORKDIR /app/frontend
RUN npm install -g pnpm@11.16.0
COPY frontend/package.json frontend/pnpm-lock.yaml frontend/pnpm-workspace.yaml ./
# Slow links to the npm registry are common: long timeouts, retries, and a cache that survives rebuilds.
RUN --mount=type=cache,id=tessera-pnpm,target=/root/.local/share/pnpm/store \
    pnpm config set fetch-timeout 600000 \
 && pnpm config set fetch-retries 6 \
 && pnpm config set network-concurrency 8 \
 && pnpm install --frozen-lockfile
COPY frontend/ ./
# The API is on the page's own origin behind Nginx (see frontend/api/urls.ts).
ENV NEXT_PUBLIC_API_SAME_ORIGIN=true \
    NEXT_TELEMETRY_DISABLED=1
RUN pnpm build \
 && cp -r .next/static .next/standalone/.next/static \
 && cp -r public .next/standalone/public

# ---------- 2. PHP base with the extensions the API needs ----------
FROM php:8.4-fpm-alpine AS php-base
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pgsql bcmath intl gd zip opcache pcntl

# ---------- 3. API dependencies (Composer) ----------
FROM php-base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app/backend
# The whole backend first: the merge plugin reads Modules/*/composer.json.
COPY backend/ ./
# Tests are not shipped: their base classes are dev-only, and the API docs scan would trip over them.
# Each module registers resources/views; Git does not keep the empty ones, and view:cache needs them.
RUN rm -rf .env tests Modules/*/tests \
 && for m in Modules/*/; do mkdir -p "${m}resources/views"; done \
 && composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader

# ---------- 4. Runtime ----------
FROM php-base
RUN apk add --no-cache nginx supervisor nodejs postgresql-client gettext tini \
 && mkdir -p /run/nginx /var/log/supervisor

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-tessera.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-tessera.conf
COPY docker/nginx.conf.template /etc/nginx/tessera.conf.template
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/tessera-entrypoint
RUN chmod +x /usr/local/bin/tessera-entrypoint

WORKDIR /var/www/tessera
COPY --from=vendor /app/backend ./backend
COPY --from=web /app/frontend/.next/standalone ./frontend

RUN mkdir -p backend/storage/app/public backend/storage/app/private \
        backend/storage/framework/cache/data backend/storage/framework/sessions \
        backend/storage/framework/views backend/storage/logs backend/bootstrap/cache \
 && chown -R www-data:www-data backend/storage backend/bootstrap/cache frontend

ENV PORT=8080 \
    TZ=Africa/Nairobi
EXPOSE 8080
ENTRYPOINT ["/sbin/tini", "--", "tessera-entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
