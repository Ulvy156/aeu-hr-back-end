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

# Wait for the PostgreSQL database to be reachable.
# Supports both DB_URL (single URL) and individual DB_HOST/PORT/DATABASE/USERNAME/PASSWORD vars.
echo "Waiting for database..."
until php -r "
\$url = getenv('DB_URL');
if (\$url) {
    \$p    = parse_url(\$url);
    \$host = \$p['host'];
    \$port = \$p['port'] ?? 5432;
    \$db   = ltrim(\$p['path'], '/');
    \$user = \$p['user'];
    \$pass = \$p['pass'] ?? '';
} else {
    \$host = getenv('DB_HOST');
    \$port = getenv('DB_PORT') ?: 5432;
    \$db   = getenv('DB_DATABASE');
    \$user = getenv('DB_USERNAME');
    \$pass = getenv('DB_PASSWORD');
}
try {
    new PDO(\"pgsql:host={\$host};port={\$port};dbname={\$db};sslmode=disable\", \$user, \$pass, [PDO::ATTR_TIMEOUT => 5]);
    exit(0);
} catch (Exception \$e) {
    fwrite(STDERR, '  error: ' . \$e->getMessage() . PHP_EOL);
    exit(1);
}
"; do
    echo "  not ready, retrying in 3s..."
    sleep 3
done
echo "Database is ready."

# Run migrations (Laravel uses a lock so concurrent runs are safe)
php artisan migrate --force

php artisan db:seed --force

# Storage symlink (fails silently if already linked)
php artisan storage:link 2>/dev/null || true

# Production caches
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
