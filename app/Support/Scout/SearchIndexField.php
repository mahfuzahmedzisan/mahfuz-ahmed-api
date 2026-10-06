<?php

namespace App\Support\Scout;

final class SearchIndexField
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $searchable = true,
        public bool $filterable = false,
        public bool $sortable = false,
        public bool $optional = true,
    ) {}

    public static function string(
        string $name,
        bool $filterable = false,
        bool $searchable = true,
    ): self {
        return new self($name, 'string', $searchable, $filterable);
    }

    public static function int64(
        string $name,
        bool $sortable = false,
        bool $filterable = false,
        bool $searchable = false,
    ): self {
        return new self($name, 'int64', $searchable, $filterable, $sortable, false);
    }
}
