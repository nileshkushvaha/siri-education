<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Earnings\Support\FinancialFeatureToggle;
use App\Settings\InstructorEarningSettings;

/**
 * The ONLY sanctioned test bypass of the financial feature-switch write
 * guard. Fixtures legitimately need switches in arbitrary states
 * without satisfying the production activation preflights; this trait
 * makes that bypass explicit, narrowly scoped, and absent from
 * production namespaces. Production code must use
 * FinancialFeatureConfigurationService instead — architecture-tested.
 */
trait ManagesFinancialSettings
{
    /** @param array<string, mixed> $overrides */
    protected function setFinancialSettings(array $overrides): void
    {
        $settings = app(InstructorEarningSettings::class);

        foreach ($overrides as $key => $value) {
            $settings->{$key} = $value;
        }

        FinancialFeatureToggle::unguarded(fn () => $settings->save());
    }
}
