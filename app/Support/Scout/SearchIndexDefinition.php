<?php

namespace App\Support\Scout;

final class SearchIndexDefinition
{
    /**
     * @param  list<SearchIndexField>  $fields
     */
    public function __construct(public array $fields) {}

    /**
     * @return list<string>
     */
    public function searchableNames(): array
    {
        return array_values(array_map(
            fn (SearchIndexField $field): string => $field->name,
            array_filter($this->fields, fn (SearchIndexField $field): bool => $field->searchable),
        ));
    }

    /**
     * String columns used by the Eloquent LIKE fallback.
     *
     * @return list<string>
     */
    public function likeColumns(): array
    {
        return array_values(array_map(
            fn (SearchIndexField $field): string => $field->name,
            array_filter(
                $this->fields,
                fn (SearchIndexField $field): bool => $field->searchable && $field->type === 'string',
            ),
        ));
    }

    /**
     * @return list<string>
     */
    public function filterableNames(): array
    {
        return array_values(array_map(
            fn (SearchIndexField $field): string => $field->name,
            array_filter($this->fields, fn (SearchIndexField $field): bool => $field->filterable),
        ));
    }

    /**
     * @return list<string>
     */
    public function sortableNames(): array
    {
        return array_values(array_map(
            fn (SearchIndexField $field): string => $field->name,
            array_filter($this->fields, fn (SearchIndexField $field): bool => $field->sortable),
        ));
    }

    public function queryBy(): string
    {
        return implode(',', $this->likeColumns());
    }

    public function defaultSortingField(): ?string
    {
        foreach ($this->fields as $field) {
            if ($field->sortable && $field->type === 'int64') {
                return $field->name;
            }
        }

        return null;
    }

    /**
     * @return list<array{name: string, type: string, optional?: bool}>
     */
    public function typesenseFields(): array
    {
        $fields = [[
            'name' => 'id',
            'type' => 'string',
        ]];

        foreach ($this->fields as $field) {
            $definition = [
                'name' => $field->name,
                'type' => $field->type,
            ];

            if ($field->optional) {
                $definition['optional'] = true;
            }

            if ($field->filterable) {
                $definition['facet'] = true;
            }

            $fields[] = $definition;
        }

        return $fields;
    }
}
