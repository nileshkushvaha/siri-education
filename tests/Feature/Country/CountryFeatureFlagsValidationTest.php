<?php

declare(strict_types=1);

namespace Tests\Feature\Country;

use App\Models\Country;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * SRS §21.38 "must reference a valid feature",
 * §21.40 "Country Feature Dependency Missing") — enforced once, from
 * `Country::booted()`, so every write path is covered.
 */
class CountryFeatureFlagsValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unrecognized_feature_key_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        Country::factory()->create(['feature_flags' => ['not_a_real_feature' => false]]);
    }

    public function test_a_valid_disable_only_override_saves_successfully(): void
    {
        $country = Country::factory()->create(['feature_flags' => ['wallet' => false, 'demo_lessons' => false]]);

        $this->assertSame(['wallet' => false, 'demo_lessons' => false], $country->fresh()->feature_flags);
    }

    public function test_leaving_a_dependent_feature_enabled_while_its_dependency_is_disabled_is_normalized_to_disabled(): void
    {
        // Not rejected: the admin form dehydrates every toggle on every
        // save (see CountryFeatureFlags docblock), so a hard reject here
        // would misfire on any single-feature disable. The dependent is
        // silently normalized instead — the resolver already makes the
        // dependency authoritative at read time regardless.
        $country = Country::factory()->create(['feature_flags' => ['wallet' => false, 'wallet_recharge' => true]]);

        $this->assertSame(['wallet' => false, 'wallet_recharge' => false], $country->fresh()->feature_flags);
    }

    public function test_disabling_both_a_feature_and_its_dependent_together_saves_successfully(): void
    {
        $country = Country::factory()->create(['feature_flags' => ['wallet' => false, 'wallet_recharge' => false]]);

        $this->assertSame(['wallet' => false, 'wallet_recharge' => false], $country->fresh()->feature_flags);
    }

    public function test_null_feature_flags_is_left_untouched(): void
    {
        $country = Country::factory()->create(['feature_flags' => null]);

        $this->assertNull($country->fresh()->feature_flags);
    }

    public function test_updating_an_existing_country_also_validates(): void
    {
        $country = Country::factory()->create(['feature_flags' => ['wallet' => false]]);

        $this->expectException(ValidationException::class);

        $country->update(['feature_flags' => ['wallet' => false, 'not_a_real_feature' => true]]);
    }
}
