<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Concurrency;

use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\Feature\Booking\Concurrency\ConcurrencyTestCase;

/**
 * SRS-23-7: real multi-process race proving that
 * when only the final two active Super Admins remain, two concurrent
 * requests each deactivating a DIFFERENT one of them resolve to exactly
 * one success and one rejection — never both committing and leaving
 * zero. Mirrors the harness pattern proven throughout the Booking
 * domain (BookingRefundConcurrencyTest, RescheduleLimitConcurrencyTest).
 *
 * Reuses Tests\Feature\Booking\Concurrency\ConcurrencyTestCase as-is —
 * its race()/tearDownAfterClass() machinery is fully domain-agnostic
 * (a real-MySQL, real-subprocess harness), so introducing a duplicate
 * base class for the Admin domain would be pure repetition.
 */
class SuperAdminGuardConcurrencyTest extends ConcurrencyTestCase
{
    public function test_concurrent_deactivation_of_the_final_two_active_super_admins_resolves_to_exactly_one_success(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $a = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $a->assignRole('super_admin');

        $b = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $b->assignRole('super_admin');

        $results = $this->race([
            ['deactivate-super-admin', ['user_id' => $a->id]],
            ['deactivate-super-admin', ['user_id' => $b->id]],
        ]);

        $succeeded = array_values(array_filter($results, fn (array $r): bool => $r['ok']));
        $failed = array_values(array_filter($results, fn (array $r): bool => ! $r['ok']));

        $this->assertCount(1, $succeeded, json_encode($results));
        $this->assertCount(1, $failed, json_encode($results));
        $this->assertSame('App\Exceptions\LastActiveSuperAdminException', $failed[0]['exception']);

        $activeCount = User::role('super_admin')->where('status', User::STATUS_ACTIVE)->count();
        $this->assertSame(1, $activeCount, 'Exactly one active Super Admin must remain — never zero, never two unresolved.');
    }
}
