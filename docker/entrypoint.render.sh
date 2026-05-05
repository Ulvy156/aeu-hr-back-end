#!/bin/sh
set -e

# Render injects $PORT — write it into the nginx config
envsubst '${PORT}' < /etc/nginx/templates/default.conf.template > /etc/nginx/http.d/default.conf

# Generate app key if not provided
if [ -z "$APP_KEY" ]; then
    echo "Generating APP_KEY..."
    php artisan key:generate --force
fi

# Wait for the MySQL database to be reachable
echo "Waiting for database..."
until php -r "
try {
    \$dsn = 'mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_DATABASE');
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

# Storage symlink (fails silently if already linked)
php artisan storage:link 2>/dev/null || true

# Production caches
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
