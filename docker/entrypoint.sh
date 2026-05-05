#!/bin/sh
set -e

# Generate app key if not set
if [ -z "$APP_KEY" ]; then
    echo "Generating APP_KEY..."
    php artisan key:generate --force
fi

# Wait for the database to accept connections
echo "Waiting for database connection..."
until php -r "
try {
    \$dsn = 'mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_DATABASE');
    new PDO(\$dsn, getenv('DB_USERNAME'), getenv('DB_PASSWORD'));
    exit(0);
} catch (Exception \$e) {
    exit(1);
}
" 2>/dev/null; do
    echo "  database not ready, retrying in 2s..."
    sleep 2
done
echo "Database is ready."

# Run migrations
php artisan migrate --force

# Create storage symlink (ignore if already exists)
php artisan storage:link 2>/dev/null || true

# Cache for production
if [ "$APP_ENV" = "production" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

exec "$@"
