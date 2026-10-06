# Scout search template

Driver is selected only by `SCOUT_DRIVER`. Models do not name an engine.

Supported remote drivers: `typesense`, `meilisearch`, `algolia`, `turbopuffer`.
`collection` and `null` skip the remote engine. Text search then uses Eloquent
`LIKE` on the model's searchable string fields.

## Add a searchable model

1. Implement `App\Contracts\Scout\DefinesSearchIndex`.
2. Use `App\Models\Concerns\ConfiguresScoutSearch`.
3. Return only non-secret fields from `searchableDocument()`.
4. Register the class in `App\Support\Scout\SearchableModels`.
5. Run `php artisan scout:import-all --fresh`.

`id` is stored as a string and timestamps as unix integers so one payload works
on every driver.

## Request pipeline

`GET /api/v1/admin/users` applies text search first (`?q=` or `?filter[q]=`),
then [Spatie Query Builder](https://github.com/spatie/laravel-query-builder)
filters and sorts (`filter[role]`, `sort=-created_at`). If `sort` is present it
replaces Scout relevance order.

If the engine is down or the driver is not remote, the same endpoint stays
HTTP 200 and searches with `LIKE`.

Hybrid ID lookup is capped by `SCOUT_HIT_LIMIT` (default 1000). Larger catalogs
need engine pagination later.

## Import all models

```bash
php artisan scout:import-all
php artisan scout:import-all --fresh
php artisan scout:import-all --queue --chunk=500
php artisan scout:import-all --skip-sync-settings
```

The command discovers concrete Eloquent models under `app/Models` that use
Scout's `Searchable` trait. It syncs index settings when the active engine
supports that, then imports each model. Do not call it from the container
entrypoint.

## Switch driver

1. Set `SCOUT_DRIVER` and that engine's credentials.
2. Run `php artisan scout:import-all --fresh`.
3. Leave the previous engine's indexes unused, or delete them in that product.

### Coolify hosts

- Typesense: `TYPESENSE_HOST=typesense.next.maktechlaravel.cloud`, `TYPESENSE_PORT=443`, `TYPESENSE_PROTOCOL=https`
- Meilisearch: `MEILISEARCH_HOST=https://mailisearch.next.maktechlaravel.cloud`

Algolia uses `ALGOLIA_APP_ID` and `ALGOLIA_SECRET`. Turbopuffer is built into
Scout (`TURBOPUFFER_API_KEY`, optional `TURBOPUFFER_REGION`).
