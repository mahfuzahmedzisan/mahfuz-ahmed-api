<?php

namespace App\Services\Search;

use App\Contracts\Scout\DefinesSearchIndex;
use App\Support\ScoutSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ModelSearchService
{
    /**
     * @param  Builder<Model>  $query
     * @param  class-string<Model&DefinesSearchIndex>  $modelClass
     * @return Builder<Model>
     */
    public function applyTextSearch(
        Builder $query,
        string $modelClass,
        ?string $term,
        bool $preferRelevance = true,
    ): Builder {
        $term = trim((string) $term);

        if ($term === '' || ! is_a($modelClass, DefinesSearchIndex::class, true)) {
            return $query;
        }

        if (ScoutSearch::apply($query, $modelClass, $term, [], null, $preferRelevance)) {
            return $query;
        }

        $columns = $modelClass::searchIndexDefinition()->likeColumns();

        if ($columns === []) {
            return $query;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $query->where(function (Builder $inner) use ($columns, $like): void {
            foreach ($columns as $index => $column) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $inner->{$method}($column, 'like', $like);
            }
        });

        return $query;
    }
}
