#!/bin/sh
# Prepares the API on every start: app key, database wait, migrations, first-run seed, docs, caches.
set -e
cd /var/www/tessera/backend

# On Render the public URL is known; use it unless APP_URL is set.
APP_URL="${APP_URL:-${RENDER_EXTERNAL_URL:-http://localhost:${PORT:-8080}}}"
FRONTEND_URL="${FRONTEND_URL:-$APP_URL}"
CORS_ALLOWED_ORIGINS="${CORS_ALLOWED_ORIGINS:-$APP_URL}"
export APP_URL FRONTEND_URL CORS_ALLOWED_ORIGINS

# A stable APP_KEY is required (sessions, encrypted settings). Hosting sets it in the environment;
# local Docker generates one once and keeps it in the storage volume.
if [ -z "$APP_KEY" ]; then
    KEY_FILE=storage/app/private/.app_key
    mkdir -p storage/app/private
    if [ ! -s "$KEY_FILE" ]; then
        php -r 'echo "base64:".base64_encode(random_bytes(32));' > "$KEY_FILE"
        echo "Generated a new APP_KEY in the storage volume."
    fi
    APP_KEY=$(cat "$KEY_FILE")
    export APP_KEY
fi

echo "Waiting for PostgreSQL at ${DB_HOST}:${DB_PORT:-5432} ..."
i=0
until PGPASSWORD="$DB_PASSWORD" PGSSLMODE="${DB_SSLMODE:-prefer}" pg_isready -q -h "$DB_HOST" -p "${DB_PORT:-5432}" -U "$DB_USERNAME" -d "$DB_DATABASE"; do
    i=$((i + 1))
    if [ "$i" -ge 60 ]; then echo "PostgreSQL did not answer in 2 minutes." >&2; exit 1; fi
    sleep 2
done

php artisan config:clear >/dev/null
php artisan migrate --force

# Seed only an empty database: roles, menus, the MAIN branch and the owner (in APP_ENV=local the demo shop).
USERS=$(PGPASSWORD="$DB_PASSWORD" PGSSLMODE="${DB_SSLMODE:-prefer}" psql -h "$DB_HOST" -p "${DB_PORT:-5432}" -U "$DB_USERNAME" -d "$DB_DATABASE" -tAc 'SELECT count(*) FROM users')
if [ "$USERS" = "0" ]; then
    php artisan db:seed --force
    # Demo mode: two-step login off for the owner, demo PINs, promotions, a licence; then the full demo shop
    # (two branches, ~90 items, suppliers, trade customers, two weeks of sales, open paperwork). About 2 minutes.
    if [ "$TESSERA_DEMO" = "true" ]; then
        php artisan db:seed --class=DemoShowcaseSeeder --force
        php artisan db:seed --class=DemoShopSeeder --force
    fi
fi

php artisan storage:link >/dev/null 2>&1 || true
# The API docs are a convenience: a failure here must not stop the shop.
php artisan l5-swagger:generate >/dev/null 2>&1 || echo "API docs could not be generated; the app runs without them."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

chown -R www-data:www-data storage bootstrap/cache

envsubst '${PORT}' < /etc/nginx/tessera.conf.template > /etc/nginx/tessera.conf

exec "$@"
