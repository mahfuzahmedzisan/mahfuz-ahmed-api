#!/bin/sh
set -eu

cd /var/www

if [ -z "${APP_KEY:-}" ]; then
    echo "ERROR: APP_KEY must be configured before the container starts." >&2
    exit 1
fi

# A persistent volume mounted at storage/ shadows the tree baked into the image,
# so the skeleton has to be recreated on every boot rather than only at build time.
mkdir -p \
    bootstrap/cache \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs

chown -R www-data:www-data storage bootstrap/cache
chmod -R u=rwX,g=rwX,o=rX storage bootstrap/cache

# Cache here, not in the Dockerfile. Coolify injects environment variables into
# the running container, so caching during the build would freeze placeholders
# into bootstrap/cache where they outrank the real values at runtime.
su -s /bin/sh www-data -c 'php artisan config:cache --no-interaction'
su -s /bin/sh www-data -c 'php artisan route:cache --no-interaction'
su -s /bin/sh www-data -c 'php artisan view:cache --no-interaction'

# Recreate public/storage; the symlink points into the volume and may be stale
# or missing after the image is rebuilt.
su -s /bin/sh www-data -c 'php artisan storage:link --force --no-interaction'

exec "$@"
