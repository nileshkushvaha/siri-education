<?php

declare(strict_types=1);

namespace App\Earnings\Support;

/**
 * The single escape hatch through the financial feature-switch write
 * guard on InstructorEarningSettings. Production code must never call
 * this outside FinancialFeatureConfigurationService (architecture-
 * tested); the test suite reaches it only through the
 * Tests\Support\ManagesFinancialSettings fixture helper.
 */
final class FinancialFeatureToggle
{
    private static bool $unguarded = false;

    /** @template T @param callable(): T $callback @return T */
    public static function unguarded(callable $callback): mixed
    {
        self::$unguarded = true;

        try {
            return $callback();
        } finally {
            self::$unguarded = false;
        }
    }

    public static function isUnguarded(): bool
    {
        return self::$unguarded;
    }
}
