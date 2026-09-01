<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Tables;

/**
 * Maps a typed search term onto backed-enum lifecycle values by their
 * human label. With the filter panel gone, search is the only way to
 * narrow a user list — and an admin types "under review", not
 * "under_review". Returning a (possibly empty) list of stored values
 * keeps the caller a plain `whereIn`.
 */
final class LifecycleSearch
{
    /**
     * @param  list<\BackedEnum>  $cases
     * @return list<string>
     */
    public static function valuesMatching(array $cases, string $search): array
    {
        $needle = mb_strtolower(trim($search));

        if ($needle === '') {
            return [];
        }

        return array_values(array_map(
            fn (\BackedEnum $case): string => (string) $case->value,
            array_filter($cases, function (\BackedEnum $case) use ($needle): bool {
                $label = method_exists($case, 'label') ? $case->label() : (string) $case->value;

                return str_contains(mb_strtolower($label), $needle)
                    || str_contains(mb_strtolower((string) $case->value), $needle);
            }),
        ));
    }
}
