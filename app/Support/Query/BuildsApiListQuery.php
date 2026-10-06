<?php

namespace App\Support\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\QueryBuilder;

final class BuildsApiListQuery
{
    /**
     * @param  Builder<Model>  $query
     * @param  array<int, mixed>  $filters
     * @param  array<int, mixed>  $sorts
     */
    public static function make(Builder $query, array $filters, array $sorts): QueryBuilder
    {
        return QueryBuilder::for($query)
            ->allowedFilters(...$filters)
            ->allowedSorts(...$sorts);
    }

    public static function perPage(Request $request, int $default = 15, int $max = 50): int
    {
        return max(1, min($max, $request->integer('per_page', $default)));
    }
}
