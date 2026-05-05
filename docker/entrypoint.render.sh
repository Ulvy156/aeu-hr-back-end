#!/bin/sh
set -e

# Render injects $PORT — write it into the nginx config
envsubst '${PORT}' < /etc/nginx/templates/default.conf.template > /etc/nginx/http.d/default.conf

# Laravel requires a .env file on disk even when env vars come from the OS.
# Copy .env.example as a placeholder — Render's env vars override all values in it.
if [ ! -f .env ]; then
    cp .env.example .env
fi

# Generate app key if not provided
if [ -z "$APP_KEY" ]; then
    echo "Generating APP_KEY..."
    php artisan key:generate --force
fi

# Wait for the MySQL database to be reachable
echo "Waiting for database..."
until php -r "
try {
    \$dsn = 'pgsql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_DATABASE');
    new PDO(\$dsn, getenv('DB_USERNAME'), getenv('DB_PASSWORD'));
    exit(0);
} catch (Exception \$e) {
    exit(1);
}
" 2>/dev/null; do
    echo "  not ready, retrying in 3s..."
    sleep 3
done
echo "Database is ready."

# Run migrations (Laravel uses a lock so concurrent runs are safe)
php artisan migrate --force

# Seed only on first deploy — skip if users table already has data
USER_COUNT=$(php artisan tinker --execute="echo \App\Models\User::count();" 2>/dev/null | tr -d '[:space:]')
if [ "$USER_COUNT" = "0" ]; then
    echo "Empty database detected — running seeders..."
    php artisan db:seed --force
    echo "Seeding complete."
else
    echo "Database already has data — skipping seed."
fi

# Storage symlink (fails silently if already linked)
php artisan storage:link 2>/dev/null || true

# Production caches
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
