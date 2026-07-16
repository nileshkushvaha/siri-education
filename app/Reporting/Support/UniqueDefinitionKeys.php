<?php

declare(strict_types=1);

namespace App\Reporting\Support;

use Closure;

/**
 * Shared "index by stable key, fail loudly on a duplicate" helper used
 * by both `ReportRegistry` and `MetricRegistry` — kept here (rather
 * than duplicated in each registry) so the duplicate-key guard has
 * exactly one implementation to test.
 */
final class UniqueDefinitionKeys
{
    /**
     * @template T
     *
     * @param  list<T>  $definitions
     * @param  Closure(T): string  $keyOf
     * @param  Closure(string): void  $onDuplicate  called (and expected to throw) when a key repeats
     * @return array<string, T>
     */
    public static function index(array $definitions, Closure $keyOf, Closure $onDuplicate): array
    {
        $indexed = [];

        foreach ($definitions as $definition) {
            $key = $keyOf($definition);

            if (isset($indexed[$key])) {
                $onDuplicate($key);
            }

            $indexed[$key] = $definition;
        }

        return $indexed;
    }
}
