<?php

namespace App\Support\Scout;

use App\Models\MediaItem;
use App\Models\User;

final class SearchableModels
{
    /**
     * Register every searchable model once. Engine-specific Scout config is
     * generated from this list.
     *
     * @return list<class-string>
     */
    public static function classes(): array
    {
        return [
            User::class,
            MediaItem::class,
        ];
    }
}
