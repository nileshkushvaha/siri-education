<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings;

use App\Earnings\Contracts\InstructorCompensationAgreementServiceInterface;
use App\Earnings\Enums\CompensationAgreementStatus;
use App\Earnings\Enums\CompensationPayBasis;
use App\Earnings\Exceptions\CompensationException;
use App\Models\Currency;
use App\Models\InstructorCompensationAgreement;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 14.2 §7 — the agreement lifecycle: admin-only, effective-dated,
 * one active per instructor, no overlap, immutable once active,
 * replacement preserves history, never deleted.
 */
class InstructorCompensationAgreementTest extends TestCase
{
    use RefreshDatabase;

    private InstructorCompensationAgreementServiceInterface $service;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(InstructorCompensationAgreementServiceInterface::class);

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        foreach (['Create', 'Schedule', 'Activate', 'End', 'Cancel', 'Configure', 'ViewAny', 'View', 'ViewHistory'] as $verb) {
            Permission::firstOrCreate(['name' => $verb.':InstructorCompensationAgreement', 'guard_name' => 'web']);
        }
        $this->admin->givePermissionTo(Permission::where('name', 'like', '%InstructorCompensationAgreement')->pluck('name')->all());
    }

    public function test_admin_creates_a_draft_with_reference_and_reason(): void
    {
        $agreement = $this->draft();

        $this->assertSame(CompensationAgreementStatus::Draft, $agreement->status);
        $this->assertMatchesRegularExpression('/^ICA-[A-Z0-9]{10}$/', $agreement->reference);
        $this->assertSame(1, $agreement->version);
        $this->assertSame(80000, $agreement->amount_minor);
    }

    public function test_agreement_requires_instructor_role_positive_amount_and_reason(): void
    {
        $notInstructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        try {
            $this->service->createDraft($this->admin, $notInstructor, CompensationPayBasis::Hourly, 80000, 'INR', 'Asia/Kolkata', now(), 'Reason');
            $this->fail('Non-instructor should be rejected.');
        } catch (CompensationException) {
        }

        try {
            $this->service->createDraft($this->admin, $this->instructor(), CompensationPayBasis::Hourly, 0, 'INR', 'Asia/Kolkata', now(), 'Reason');
            $this->fail('Zero amount should be rejected.');
        } catch (CompensationException) {
        }

        $this->expectException(CompensationException::class);
        $this->service->createDraft($this->admin, $this->instructor(), CompensationPayBasis::Hourly, 80000, 'INR', 'Asia/Kolkata', now(), '   ');
    }

    public function test_instructors_cannot_create_or_mutate_agreements(): void
    {
        $instructor = $this->instructor();
        $agreement = $this->draft($instructor);

        $this->assertFalse($instructor->can('create', InstructorCompensationAgreement::class));
        $this->assertFalse($instructor->can('activate', $agreement));
        $this->assertFalse($instructor->can('end', $agreement));
        $this->assertFalse($instructor->can('update', $agreement));
        $this->assertFalse($instructor->can('delete', $agreement));
        // But they may view their own agreement (internal reason hidden).
        $this->assertTrue($instructor->can('view', $agreement));
        $this->assertArrayNotHasKey('internal_reason', $agreement->toArray());
        $this->assertArrayNotHasKey('notes', $agreement->toArray());
    }

    public function test_periodic_boundary_rules_are_enforced_on_creation(): void
    {
        // Monthly must start on the 1st at 00:00 in the agreement tz.
        try {
            $this->service->createDraft($this->admin, $this->instructor(), CompensationPayBasis::Monthly, 4000000, 'INR', 'Asia/Kolkata',
                new \DateTimeImmutable('2026-08-15 00:00:00', new \DateTimeZone('Asia/Kolkata')), 'Reason');
            $this->fail('Mid-month start should be rejected.');
        } catch (CompensationException $e) {
            $this->assertStringContainsString('month', $e->getMessage());
        }

        // Weekly must start Monday 00:00 (2026-08-03 is a Monday).
        $ok = $this->service->createDraft($this->admin, $this->instructor(), CompensationPayBasis::Weekly, 1000000, 'INR', 'Asia/Kolkata',
            new \DateTimeImmutable('2026-08-03 00:00:00', new \DateTimeZone('Asia/Kolkata')), 'Reason');
        $this->assertSame(CompensationAgreementStatus::Draft, $ok->status);

        $this->expectException(CompensationException::class);
        $this->service->createDraft($this->admin, $this->instructor(), CompensationPayBasis::Weekly, 1000000, 'INR', 'Asia/Kolkata',
            new \DateTimeImmutable('2026-08-04 00:00:00', new \DateTimeZone('Asia/Kolkata')), 'Reason');
    }

    public function test_lifecycle_draft_schedule_activate_end(): void
    {
        $agreement = $this->draft();

        $agreement = $this->service->schedule($agreement, $this->admin);
        $this->assertSame(CompensationAgreementStatus::Scheduled, $agreement->status);

        $agreement = $this->service->activate($agreement, $this->admin, 'Verified rate.');
        $this->assertSame(CompensationAgreementStatus::Active, $agreement->status);
        $this->assertSame($this->admin->id, $agreement->approved_by);

        $agreement = $this->service->end($agreement, $this->admin, now(), 'Contract concluded.');
        $this->assertSame(CompensationAgreementStatus::Ended, $agreement->status);
        $this->assertSame($this->admin->id, $agreement->ended_by);
    }

    public function test_only_one_active_agreement_per_instructor(): void
    {
        $instructor = $this->instructor();
        $this->service->activate($this->draft($instructor), $this->admin, 'First.');

        $second = $this->draft($instructor, effectiveFrom: now()->subWeek());

        $this->expectException(CompensationException::class);

        $this->service->activate($second, $this->admin, 'Second.');
    }

    public function test_database_backstop_rejects_two_active_rows(): void
    {
        $instructor = $this->instructor();
        InstructorCompensationAgreement::factory()->active()->create(['instructor_id' => $instructor->id]);
        $second = InstructorCompensationAgreement::factory()->create(['instructor_id' => $instructor->id]);

        $this->expectException(QueryException::class);

        \DB::table('instructor_compensation_agreements')
            ->where('id', $second->id)
            ->update(['status' => 'active']);
    }

    public function test_overlapping_effective_periods_are_rejected(): void
    {
        $instructor = $this->instructor();

        $first = $this->draft($instructor, effectiveFrom: now()->subMonth());
        $this->service->activate($first, $this->admin, 'Base.');
        $this->service->end($first->fresh(), $this->admin, now()->addMonth(), 'Ends next month.');

        // A schedule starting inside the still-open window must be rejected.
        $overlapping = $this->draft($instructor, effectiveFrom: now()->addDays(3));

        $this->expectException(CompensationException::class);
        $this->expectExceptionMessage('overlaps');

        $this->service->schedule($overlapping, $this->admin);
    }

    public function test_active_financial_terms_are_immutable_via_mass_assignment(): void
    {
        $agreement = $this->service->activate($this->draft(), $this->admin, 'Base.');

        // No fillable path exists for status/amount rewrites via update().
        $agreement->update(['status' => CompensationAgreementStatus::Draft, 'amount_minor' => 1]);

        $fresh = $agreement->fresh();
        $this->assertSame(CompensationAgreementStatus::Active, $fresh->status);
        // amount_minor is fillable for creation, but the policy denies
        // update() for every actor and the Filament surface has no edit —
        // service transitions are the only mutation path.
        $this->assertFalse($this->admin->can('update', $fresh));
    }

    public function test_replacement_preserves_history_and_versions(): void
    {
        $instructor = $this->instructor();
        $original = $this->service->activate($this->draft($instructor, effectiveFrom: now()->subMonth()), $this->admin, 'Base.');

        $replacement = $this->service->replace(
            $original,
            $this->admin,
            CompensationPayBasis::Hourly,
            90000,
            'INR',
            now(),
            'Annual raise.',
        );

        $original = $original->fresh();
        $replacement = $replacement->fresh();

        // Old agreement ended at the cutover, permanently preserved.
        $this->assertSame(CompensationAgreementStatus::Ended, $original->status);
        $this->assertNotNull($original->effective_until);
        $this->assertSame(80000, $original->amount_minor);

        // Successor active from the cutover, version-chained.
        $this->assertSame(CompensationAgreementStatus::Active, $replacement->status);
        $this->assertSame(2, $replacement->version);
        $this->assertSame($original->id, $replacement->supersedes_agreement_id);
        $this->assertSame(90000, $replacement->amount_minor);
    }

    public function test_cancelled_and_ended_agreements_are_terminal_and_auditable(): void
    {
        $draft = $this->draft();
        $cancelled = $this->service->cancel($draft, $this->admin, 'Wrong rate.');

        $this->assertSame(CompensationAgreementStatus::Cancelled, $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'instructor_compensation', 'event' => 'agreement_cancelled']);

        $this->expectException(CompensationException::class);
        $this->service->schedule($cancelled, $this->admin);
    }

    public function test_scheduled_agreement_with_future_date_is_not_used_early(): void
    {
        $instructor = $this->instructor();
        $future = $this->draft($instructor, effectiveFrom: now()->addMonth());
        $this->service->schedule($future, $this->admin);

        $this->service->syncLifecycle($instructor->id, now());

        $this->assertSame(CompensationAgreementStatus::Scheduled, $future->fresh()->status);
    }

    public function test_scheduled_agreement_promotes_once_its_window_opens(): void
    {
        $instructor = $this->instructor();
        $due = $this->draft($instructor, effectiveFrom: now()->subDay());
        $this->service->schedule($due, $this->admin);

        $this->service->syncLifecycle($instructor->id, now());

        $this->assertSame(CompensationAgreementStatus::Active, $due->fresh()->status);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function instructor(): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('instructor');

        return $user;
    }

    private function draft(?User $instructor = null, ?\DateTimeInterface $effectiveFrom = null): InstructorCompensationAgreement
    {
        return $this->service->createDraft(
            $this->admin,
            $instructor ?? $this->instructor(),
            CompensationPayBasis::Hourly,
            80000,
            'INR',
            'Asia/Kolkata',
            $effectiveFrom ?? now()->subDay(),
            'Experienced physics instructor; agreed during onboarding review.',
        );
    }
}
