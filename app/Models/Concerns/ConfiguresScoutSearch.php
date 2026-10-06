<?php

namespace App\Models\Concerns;

use Laravel\Scout\Searchable;

trait ConfiguresScoutSearch
{
    use Searchable;

    public function shouldBeSearchable(): bool
    {
        return $this->exists;
    }

    /**
     * Typesense requires a string id and unix timestamps. Other Scout engines
     * accept the same payload, so models never branch on the active driver.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $document = $this->searchableDocument();
        $document['id'] = (string) $this->getKey();

        foreach (['created_at', 'updated_at'] as $column) {
            if (array_key_exists($column, $document) || $this->{$column} === null) {
                continue;
            }

            $document[$column] = $this->{$column}->getTimestamp();
        }

        return $document;
    }
}
