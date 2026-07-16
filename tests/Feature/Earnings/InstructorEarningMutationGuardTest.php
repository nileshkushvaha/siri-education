<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings;

use App\Earnings\Exceptions\EarningException;
use App\Models\InstructorEarning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 17 closure audit — InstructorEarning had a state-machine guard
 * on `status` transitions (TransitionInstructorEarningAction) but no
 * model-level guard protecting its monetary identity
 * (earning_amount_minor/currency_id/currency_code), unlike Wallet and
 * InstructorRatingAggregate which both guard their financial columns
 * at the Eloquent-event layer. No legitimate code path ever updates
 * these columns after creation (grep-verified — they're written once,
 * at row creation, and only ever read afterward), so the guard is
 * unconditional: there is no authorized-mutation escape hatch because
 * none is ever needed.
 */
class InstructorEarningMutationGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_mutation_of_the_earning_amount_throws(): void
    {
        $earning = InstructorEarning::factory()->create(['earning_amount_minor' => 35000]);

        $this->expectException(EarningException::class);

        $earning->update(['earning_amount_minor' => 999999]);
    }

    public function test_direct_mutation_of_the_currency_code_throws(): void
    {
        $earning = InstructorEarning::factory()->create(['currency_code' => 'INR']);

        $this->expectException(EarningException::class);

        $earning->update(['currency_code' => 'USD']);
    }

    public function test_updating_an_unguarded_column_does_not_throw(): void
    {
        $earning = InstructorEarning::factory()->create(['notes' => null]);

        $earning->update(['notes' => 'Reviewed by finance.']);

        $this->assertSame('Reviewed by finance.', $earning->fresh()->notes);
    }

    public function test_creating_an_earning_with_its_amount_set_does_not_throw(): void
    {
        // The guard only fires on `updating` — the one-time write at
        // creation must remain unaffected.
        $earning = InstructorEarning::factory()->create(['earning_amount_minor' => 50000]);

        $this->assertSame(50000, $earning->earning_amount_minor);
    }
}
