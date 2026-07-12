<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings\Concurrency;

use App\Earnings\Enums\RazorpayXProviderLinkStatus;
use App\Models\InstructorPayoutDestinationProviderLink;
use App\Models\User;

/**
 * Real multi-process race for Phase 16B destination provisioning: two
 * processes calling provision() on the SAME payout method at (as near
 * as a shared time barrier allows) the same instant. The unique
 * constraint `ipdpl_method_provider_unique` plus findOrCreateLink()'s
 * SELECT ... FOR UPDATE is what must make this safe — never a second
 * link row, never two different Contacts for the same destination. Run
 * 3× consecutive per the phase spec's concurrency requirement.
 */
class RazorpayXDestinationProvisioningConcurrencyTest extends ConcurrencyTestCase
{
    public function test_concurrent_provisioning_of_the_same_payout_method_creates_exactly_one_link(): void
    {
        for ($run = 1; $run <= 3; $run++) {
            $instructor = $this->makeInstructor();
            $method = $this->verifiedMethod($instructor);
            $actorA = User::factory()->create(['status' => User::STATUS_ACTIVE]);
            $actorB = User::factory()->create(['status' => User::STATUS_ACTIVE]);

            $results = $this->race([
                ['razorpayx-provision', ['method_id' => $method->id, 'actor_id' => $actorA->id]],
                ['razorpayx-provision', ['method_id' => $method->id, 'actor_id' => $actorB->id]],
            ]);

            foreach ($results as $result) {
                $this->assertTrue($result['ok'], "Run {$run}: ".json_encode($result));
            }

            $links = InstructorPayoutDestinationProviderLink::query()
                ->where('payout_method_id', $method->id)
                ->where('provider', 'razorpayx')
                ->get();

            // Exactly one link row, converged to Ready with exactly one
            // Contact and one Fund Account, is the safety property that
            // matters — not that both racing calls individually observed
            // the final state in their own return value. A caller that
            // loses the row-lock claim safely returns the link's
            // current (possibly still in-flight) state rather than
            // erroring or creating a second Contact/Fund Account; per
            // ensureContact()/ensureFundAccount()'s claim-under-lock,
            // exactly one of the two racing processes always wins the
            // claim and carries it through to Ready before exiting.
            $this->assertCount(1, $links, "Run {$run}: exactly one provider link row must exist for this payout method.");
            $link = $links->first();
            $this->assertSame(RazorpayXProviderLinkStatus::Ready, $link->status, "Run {$run}");
            $this->assertNotNull($link->provider_contact_id, "Run {$run}");
            $this->assertNotNull($link->provider_fund_account_id, "Run {$run}");

            // Every non-null contact/fund-account id reported by either
            // worker must agree with the single persisted one — no
            // worker may have observed (or created) a different one.
            $reportedContactIds = array_unique(array_filter(array_column(array_column($results, 'result'), 'contact_id')));
            $reportedFundAccountIds = array_unique(array_filter(array_column(array_column($results, 'result'), 'fund_account_id')));
            $this->assertContainsEquals($link->provider_contact_id, $reportedContactIds, "Run {$run}");
            $this->assertLessThanOrEqual(1, count($reportedContactIds), "Run {$run}: no two distinct Contact ids may have been reported.");
            $this->assertContainsEquals($link->provider_fund_account_id, $reportedFundAccountIds, "Run {$run}");
            $this->assertLessThanOrEqual(1, count($reportedFundAccountIds), "Run {$run}: no two distinct Fund Account ids may have been reported.");
        }
    }
}
