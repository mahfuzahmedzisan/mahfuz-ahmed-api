<?php

namespace App\Contracts\Scout;

use App\Support\Scout\SearchIndexDefinition;

interface DefinesSearchIndex
{
    public static function searchIndexDefinition(): SearchIndexDefinition;

    /**
     * Business fields only. The search trait adds the string primary key.
     *
     * @return array<string, mixed>
     */
    public function searchableDocument(): array;
}
