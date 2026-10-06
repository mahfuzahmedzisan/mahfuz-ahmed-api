<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class ScoutSearch
{
    /**
     * @var list<string>
     */
    private const REMOTE_DRIVERS = [
        'typesense',
        'meilisearch',
        'algolia',
        'turbopuffer',
    ];

    /**
     * @param  EloquentBuilder<Model>  $query
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $options
     */
    public static function apply(
        EloquentBuilder $query,
        string $modelClass,
        string $search,
        array $options = [],
        ?int $limit = null,
        bool $orderByRelevance = true,
    ): bool {
        $driver = (string) config('scout.driver');

        if (! in_array($driver, self::REMOTE_DRIVERS, true)) {
            return false;
        }

        try {
            $limit ??= (int) config('scout.hit_limit', 1000);

            $ids = $modelClass::search($search)
                ->options($options)
                ->take($limit)
                ->keys()
                ->map(fn (mixed $id): int|string => is_numeric($id) ? (int) $id : (string) $id)
                ->filter(fn (int|string $id): bool => $id !== '' && $id !== 0)
                ->values()
                ->all();

            if ($ids === []) {
                $query->whereRaw('1 = 0');

                return true;
            }

            $qualifiedKeyName = $query->getModel()->getQualifiedKeyName();
            $query->whereIn($qualifiedKeyName, $ids);

            if ($orderByRelevance) {
                $query->orderByRaw(
                    'CASE '.$qualifiedKeyName.' '.collect($ids)
                        ->values()
                        ->map(fn (int|string $id, int $index): string => 'WHEN ? THEN '.$index)
                        ->implode(' ').' END',
                    $ids,
                );
            }

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
