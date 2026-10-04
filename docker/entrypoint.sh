#!/bin/sh
set -e

cd /var/www

# A persistent volume mounted at storage/ shadows the tree baked into the image,
# so the skeleton has to be recreated on every boot rather than at build time.
mkdir -p \
    storage/app/public \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Cache here, not in the Dockerfile. Coolify injects environment variables into
# the running container, so caching during the build would freeze .env.example
# placeholders into bootstrap/cache/config.php, where they outrank the real
# values at runtime.
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Recreate public/storage; the symlink points into the volume and may be stale
# or missing after the image is rebuilt.
php artisan storage:link --force

exec "$@"
