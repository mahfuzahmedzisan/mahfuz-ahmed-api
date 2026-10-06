<?php

namespace App\Support\Scout;

use App\Contracts\Scout\DefinesSearchIndex;

final class SearchIndexSettings
{
    /**
     * @return array<class-string, array<string, mixed>>
     */
    public static function meilisearch(): array
    {
        $settings = [];

        foreach (self::definitions() as $class => $definition) {
            $settings[$class] = [
                'searchableAttributes' => $definition->searchableNames(),
                'filterableAttributes' => array_values(array_unique(array_merge(
                    ['id'],
                    $definition->filterableNames(),
                ))),
                'sortableAttributes' => $definition->sortableNames(),
                'displayedAttributes' => ['id'],
            ];
        }

        return $settings;
    }

    /**
     * @return array<class-string, array<string, mixed>>
     */
    public static function typesense(): array
    {
        $settings = [];

        foreach (self::definitions() as $class => $definition) {
            $schema = [
                'fields' => $definition->typesenseFields(),
            ];

            if ($definition->defaultSortingField() !== null) {
                $schema['default_sorting_field'] = $definition->defaultSortingField();
            }

            $settings[$class] = [
                'collection-schema' => $schema,
                'search-parameters' => [
                    'query_by' => $definition->queryBy(),
                ],
            ];
        }

        return $settings;
    }

    /**
     * @return array<class-string, array<string, mixed>>
     */
    public static function algolia(): array
    {
        $settings = [];

        foreach (self::definitions() as $class => $definition) {
            $settings[$class] = [
                'searchableAttributes' => $definition->searchableNames(),
                'attributesForFaceting' => array_map(
                    fn (string $name): string => 'filterOnly('.$name.')',
                    $definition->filterableNames(),
                ),
            ];
        }

        return $settings;
    }

    /**
     * @return array<class-string, array<string, mixed>>
     */
    public static function turbopuffer(): array
    {
        $settings = [];

        foreach (self::definitions() as $class => $definition) {
            $names = $definition->likeColumns();
            $weight = count($names);
            $searchable = [];
            $schema = [];

            foreach ($names as $name) {
                $searchable[$name] = $weight;
                $weight--;
                $schema[$name] = [
                    'type' => 'string',
                    'full_text_search' => true,
                ];
            }

            $settings[$class] = [
                'searchable-attributes' => $searchable,
                'schema' => $schema,
            ];
        }

        return $settings;
    }

    /**
     * @return array<class-string, SearchIndexDefinition>
     */
    private static function definitions(): array
    {
        $definitions = [];

        foreach (SearchableModels::classes() as $class) {
            if (! is_a($class, DefinesSearchIndex::class, true)) {
                continue;
            }

            $definitions[$class] = $class::searchIndexDefinition();
        }

        return $definitions;
    }
}
