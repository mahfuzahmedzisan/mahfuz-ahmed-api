# Deploying the API (Coolify + Docker)

The image is rebuilt from scratch on every deploy, so anything written inside
the container is gone the moment it ships. Two things must therefore live
outside the image: the Passport signing keys and the `storage` directory.

Get these wrong and the symptom is always the same - everyone who was logged in
starts getting `401 Unauthenticated` right after a deploy.

## 1. Passport signing keys (required)

Passport signs every access token with an RSA key. Without
`PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY` set, it falls back to
`storage/oauth-private.key` inside the container, which a rebuild replaces -
invalidating every token ever issued.

Generate the pair once, locally:

```bash
php artisan passport:keys
```

Copy the full contents of `storage/oauth-private.key` and
`storage/oauth-public.key` into the Coolify environment variables of the same
name. Include the `-----BEGIN ...-----` and `-----END ...-----` lines and keep
the line breaks - Coolify's multiline value editor handles them.

Rotating these keys signs out every user, so treat them as long-lived secrets.

## 2. Password grant client (required)

`config/services.php` reads the client credentials from the environment and
throws a 500 if either is missing. Create the client once:

```bash
php artisan passport:client --password
```

Store the id and secret as `PASSPORT_PASSWORD_CLIENT_ID` and
`PASSPORT_PASSWORD_CLIENT_SECRET` in Coolify.

Never run `passport:install` or `passport:client` from a deploy step. Each run
mints a *new* client and orphans every token issued to the old one.

## 3. Persistent storage volume (required)

Uploaded media is written to the `public` disk, which resolves to
`storage/app/public`. That is inside the container. Without a volume, every
deploy deletes the entire media library.

In Coolify, add a persistent volume mounted at `/var/www/storage`.

Because the volume shadows the directory baked into the image, the container
needs its skeleton recreated on boot; `docker/entrypoint.sh` handles that along
with `storage:link`.

## 4. Database

Passport `oauth_*` tables live in the database and survive a rebuild on their
own. Run migrations with `php artisan migrate --force`.

Never run `migrate:fresh` against production. It truncates `oauth_access_tokens`
and `oauth_refresh_tokens` along with everything else.

## 5. Redis (required for cache + queues)

This image expects an **external** Redis (Coolify Redis resource or managed
host). The PHP `redis` extension is already enabled in the Dockerfile. Do not
run Redis inside the app container.

Set in Coolify (or `.env`):

```env
REDIS_CLIENT=phpredis
REDIS_URL=redis://default:PASSWORD@HOST:PORT/0
QUEUE_CONNECTION=redis
CACHE_STORE=redis
```

`REDIS_URL` alone is enough; `REDIS_HOST` / `REDIS_PASSWORD` / `REDIS_PORT` are
fallbacks when the URL is empty. The Supervisor queue worker reads
`QUEUE_CONNECTION` (default `redis` in the image).

API sessions stay on `SESSION_DRIVER=file` (or `database`) — Passport tokens do
not need Redis sessions.

## Configuration caching

`docker/entrypoint.sh` caches config and routes at container start rather than
at image build. This matters: Coolify injects environment variables into the
running container, not necessarily into the build, so caching during the build
can bake `.env.example` placeholders into `bootstrap/cache/config.php` where
they silently override the real values.

## Verifying a deploy did not sign everyone out

Stay logged in to the admin panel, deploy, then reload. If you land back on the
login page, one of the three requirements above is not in place - check the
Passport key variables first.

## 6. Search (optional at runtime)

Search engines stay **outside** this image (Typesense, Meilisearch, Algolia, or
Turbopuffer). `SCOUT_DRIVER=collection` or an unreachable engine still serves
admin lists through Eloquent `LIKE`.

Do not run imports from the container entrypoint. After setting `SCOUT_DRIVER`
and that engine's credentials in Coolify, run once:

```bash
php artisan scout:import-all --fresh
```

Use `--queue` when Redis workers are up and the catalog is large. Switching
engines is the same command against the new driver. See `docs/scout.md`.
