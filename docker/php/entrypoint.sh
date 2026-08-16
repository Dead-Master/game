#!/bin/sh
set -eu

cd /var/www/html

if [ "${SKIP_LARAVEL_BOOTSTRAP:-false}" = "true" ]; then
    exec "$@"
fi

mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

php artisan optimize:clear --no-ansi

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    attempts=0

    until php -r '
        try {
            new PDO(
                "mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE"),
                getenv("DB_USERNAME"),
                getenv("DB_PASSWORD")
            );
        } catch (Throwable $exception) {
            exit(1);
        }
    '; do
        attempts=$((attempts + 1))

        if [ "$attempts" -ge 30 ]; then
            echo "Database did not become available in time." >&2
            exit 1
        fi

        sleep 2
    done

    php artisan migrate --force --no-ansi
    php artisan storage:link --no-ansi 2>/dev/null || true
    touch /tmp/app-ready
fi

exec "$@"
