<?php

declare(strict_types=1);

namespace Tests\Feature\Reviews;

use App\Models\InstructorRatingAggregate;
use App\Models\ReviewRatingContribution;
use App\Reviews\Exceptions\ReviewAggregateException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * InstructorRatingAggregate guards its sum/count columns against any
 * write outside InstructorRatingAggregateService. Its sibling ledger
 * table ReviewRatingContribution must carry an equivalent guard: a
 * stray ReviewRatingContribution::find($id)->update([...]) would
 * silently desync the ledger from the aggregate it feeds, with no
 * exception and no audit trail. Both models share the same
 * GUARDED_COLUMNS + withAuthorizedMutation() pattern.
 */
class RatingAggregateMutationGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_mutation_of_a_guarded_contribution_column_throws(): void
    {
        $contribution = ReviewRatingContribution::factory()->create(['included' => false]);

        $this->expectException(ReviewAggregateException::class);

        $contribution->update(['included' => true]);
    }

    public function test_authorized_mutation_of_a_guarded_contribution_column_succeeds(): void
    {
        $contribution = ReviewRatingContribution::factory()->create(['included' => false]);

        ReviewRatingContribution::withAuthorizedMutation(
            fn () => $contribution->update(['included' => true]),
        );

        $this->assertTrue($contribution->fresh()->included);
    }

    public function test_updating_an_unguarded_contribution_column_does_not_throw(): void
    {
        $contribution = ReviewRatingContribution::factory()->create(['version' => 1]);

        // `version` is not in GUARDED_COLUMNS on its own — updating it in
        // isolation without touching a guarded column must not throw.
        $contribution->update(['version' => 2]);

        $this->assertSame(2, $contribution->fresh()->version);
    }

    public function test_direct_mutation_of_a_guarded_aggregate_column_throws(): void
    {
        $aggregate = InstructorRatingAggregate::factory()->create(['eligible_review_count' => 0]);

        $this->expectException(ReviewAggregateException::class);

        $aggregate->update(['eligible_review_count' => 5]);
    }
}
