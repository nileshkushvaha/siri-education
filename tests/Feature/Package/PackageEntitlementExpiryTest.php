<?php

declare(strict_types=1);

namespace Tests\Feature\Package;

use App\Models\StudentPackageEntitlement;
use App\Models\User;
use App\Package\Enums\PackageEntitlementStatus;
use App\Package\Services\PackageEntitlementService;
use Database\Seeders\PackagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 4C — authoritative, SYNCHRONOUS package expiry.
 *
 * The property under test throughout: an entitlement stops being usable
 * the instant it lapses, whether or not any scheduled job has run. The
 * sweep is housekeeping and is tested as such — never as the mechanism
 * that makes expiry true.
 *
 * Expiry and exhaustion are also kept distinct: `Expired` means time ran
 * out with lessons possibly unused; `Completed` means every lesson was
 * consumed. Neither may become the other.
 */
class PackageEntitlementExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PackagePermissionSeeder::class);
        foreach (['manager', 'instructor', 'student'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function entitlements(): PackageEntitlementService
    {
        return app(PackageEntitlementService::class);
    }

    /**
     * A standalone entitlement row. Deliberately built directly rather
     * than through the full proposal/purchase/settlement pipeline:
     * these tests are about the expiry rule alone, and the activation
     * path already has its own coverage in the settlement suite.
     */
    private function entitlement(
        ?string $expiresAt,
        PackageEntitlementStatus $status = PackageEntitlementStatus::Active,
        int $total = 5,
        int $used = 0,
    ): StudentPackageEntitlement {
        $student = User::factory()->create(['status' => 'active']);
        $student->assignRole('student');

        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->assignRole('instructor');

        $proposalId = Str::uuid()->toString();

        // No proposal row: proposal_id carries an FK, so insert through
        // the model only after creating a matching proposal would be
        // needed — instead we borrow the settlement suite's shortcut and
        // disable that one constraint for this fixture.
        return StudentPackageEntitlement::withoutEvents(function () use ($student, $instructor, $proposalId, $expiresAt, $status, $total, $used) {
            Schema::disableForeignKeyConstraints();

            $entitlement = StudentPackageEntitlement::query()->create([
                'student_id' => $student->id,
                'instructor_id' => $instructor->id,
                'proposal_id' => $proposalId,
                'paid_quantity' => $total,
                'bonus_quantity' => 0,
                'total_quantity' => $total,
                'used_quantity' => $used,
                'status' => $status,
                'validity_days' => $expiresAt === null ? null : 90,
                'activated_at' => now()->subDays(1),
                'expires_at' => $expiresAt,
            ]);

            Schema::enableForeignKeyConstraints();

            return $entitlement->refresh();
        });
    }

    // ── 1-4. The expiry boundary ──────────────────────────────────────────

    public function test_an_active_entitlement_before_its_expiry_is_usable(): void
    {
        Carbon::setTestNow('2027-01-01 12:00:00');
        $entitlement = $this->entitlement('2027-01-28 14:00:00');

        $this->assertTrue($this->entitlements()->usable($entitlement));
        $this->assertSame(PackageEntitlementStatus::Active, $entitlement->fresh()->status);

        Carbon::setTestNow();
    }

    /** "Valid for 90 days" ends AT the instant, not after it. */
    public function test_at_exactly_the_expiry_instant_it_is_no_longer_usable(): void
    {
        Carbon::setTestNow('2027-01-28 14:00:00');
        $entitlement = $this->entitlement('2027-01-28 14:00:00');

        $this->assertFalse($this->entitlements()->usable($entitlement));
        $this->assertSame(PackageEntitlementStatus::Expired, $entitlement->fresh()->status);

        Carbon::setTestNow();
    }

    public function test_reading_a_lapsed_entitlement_transitions_it_to_expired(): void
    {
        Carbon::setTestNow('2027-02-01 09:00:00');
        $entitlement = $this->entitlement('2027-01-28 14:00:00');

        $this->assertSame(PackageEntitlementStatus::Active, $entitlement->status);

        $expired = $this->entitlements()->expireIfNeeded($entitlement);

        $this->assertSame(PackageEntitlementStatus::Expired, $expired->status);
        $this->assertSame('expired', $entitlement->fresh()->status->value);
        // Unused lessons survive the expiry as a record of what was lost.
        $this->assertSame(5, $expired->remaining_quantity);

        Carbon::setTestNow();
    }

    public function test_a_null_expiry_never_expires(): void
    {
        Carbon::setTestNow('2037-01-01 00:00:00');
        $entitlement = $this->entitlement(null);

        $this->assertTrue($this->entitlements()->usable($entitlement));
        $this->assertSame(PackageEntitlementStatus::Active, $entitlement->fresh()->status);

        Carbon::setTestNow();
    }

    // ── 5-6. Terminal states are never relabelled ─────────────────────────

    public function test_a_completed_entitlement_never_becomes_expired(): void
    {
        Carbon::setTestNow('2027-02-01 09:00:00');
        // Every lesson was used, and the validity window then lapsed.
        $entitlement = $this->entitlement('2027-01-28 14:00:00', PackageEntitlementStatus::Completed, total: 5, used: 5);

        $this->entitlements()->expireIfNeeded($entitlement);

        // "Completed" is the true story — all five lessons were taken.
        $this->assertSame(PackageEntitlementStatus::Completed, $entitlement->fresh()->status);

        Carbon::setTestNow();
    }

    public function test_a_cancelled_entitlement_never_becomes_expired(): void
    {
        Carbon::setTestNow('2027-02-01 09:00:00');
        $entitlement = $this->entitlement('2027-01-28 14:00:00', PackageEntitlementStatus::Cancelled);

        $this->entitlements()->expireIfNeeded($entitlement);

        $this->assertSame(PackageEntitlementStatus::Cancelled, $entitlement->fresh()->status);

        Carbon::setTestNow();
    }

    // ── 7-8. The sweep is housekeeping, not the mechanism ─────────────────

    public function test_the_sweep_expires_due_entitlements(): void
    {
        Carbon::setTestNow('2027-02-01 09:00:00');

        $lapsed = $this->entitlement('2027-01-28 14:00:00');
        $current = $this->entitlement('2027-06-01 14:00:00');
        $unlimited = $this->entitlement(null);

        $this->assertSame(1, $this->entitlements()->expireDue());

        $this->assertSame(PackageEntitlementStatus::Expired, $lapsed->fresh()->status);
        $this->assertSame(PackageEntitlementStatus::Active, $current->fresh()->status);
        $this->assertSame(PackageEntitlementStatus::Active, $unlimited->fresh()->status);

        Carbon::setTestNow();
    }

    public function test_the_sweep_is_idempotent(): void
    {
        Carbon::setTestNow('2027-02-01 09:00:00');
        $entitlement = $this->entitlement('2027-01-28 14:00:00');

        $this->assertSame(1, $this->entitlements()->expireDue());
        // Nothing left to do; no state churn on a second run.
        $this->assertSame(0, $this->entitlements()->expireDue());
        $this->assertSame(0, $this->entitlements()->expireDue());

        $this->assertSame(PackageEntitlementStatus::Expired, $entitlement->fresh()->status);

        Carbon::setTestNow();
    }

    public function test_the_sweep_never_touches_completed_or_cancelled_entitlements(): void
    {
        Carbon::setTestNow('2027-02-01 09:00:00');

        $completed = $this->entitlement('2027-01-28 14:00:00', PackageEntitlementStatus::Completed, 5, 5);
        $cancelled = $this->entitlement('2027-01-28 14:00:00', PackageEntitlementStatus::Cancelled);

        $this->assertSame(0, $this->entitlements()->expireDue());
        $this->assertSame(PackageEntitlementStatus::Completed, $completed->fresh()->status);
        $this->assertSame(PackageEntitlementStatus::Cancelled, $cancelled->fresh()->status);

        Carbon::setTestNow();
    }

    /** The console command is a thin wrapper over the same rule. */
    public function test_the_console_command_expires_due_entitlements(): void
    {
        Carbon::setTestNow('2027-02-01 09:00:00');
        $entitlement = $this->entitlement('2027-01-28 14:00:00');

        $this->artisan('package-entitlements:expire')->assertSuccessful();

        $this->assertSame(PackageEntitlementStatus::Expired, $entitlement->fresh()->status);

        Carbon::setTestNow();
    }

    // Consumption-versus-expiry is proved through the canonical
    // ledger-backed path in PackageEntitlementConsumptionTest
    // (a_lesson_delivered_after_expiry_cannot_consume_the_package /
    // _before_expiry_consumes_normally). The tests that used to sit here
    // exercised the removed consumeLesson() mutator, which bypassed the
    // consumption ledger and the reservation claim — keeping them would
    // have meant keeping a second balance-mutation path alive purely to
    // satisfy its own tests.

    // ── Authorization ─────────────────────────────────────────────────────

    public function test_no_role_may_hand_edit_a_balance_or_expiry(): void
    {
        $entitlement = $this->entitlement('2027-01-28 14:00:00');

        $manager = User::factory()->create(['status' => 'active']);
        $manager->assignRole('manager');

        foreach ([$manager, $entitlement->student, $entitlement->instructor] as $user) {
            $this->assertFalse($user->can('update', $entitlement));
            $this->assertFalse($user->can('delete', $entitlement));
            $this->assertFalse($user->can('create', StudentPackageEntitlement::class));
        }
    }

    public function test_no_manual_consumption_permission_exists(): void
    {
        foreach ([
            'Update:StudentPackageEntitlement',
            'Consume:StudentPackageEntitlement',
            'Adjust:StudentPackageEntitlement',
            'Expire:StudentPackageEntitlement',
        ] as $permission) {
            $this->assertDatabaseMissing('permissions', ['name' => $permission]);
        }
    }
}
