<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

class Translations
{
    /**
     * @param  array<int, string>  $groups
     * @return array<string, string>
     */
    public function forGroups(array $groups): array
    {
        $lines = Lang::getLoader()->load(app()->getLocale(), '*', '*');

        return array_filter(
            $lines,
            fn (string $key): bool => Str::startsWith($key, array_map(fn (string $group): string => $group.'.', $groups)),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
