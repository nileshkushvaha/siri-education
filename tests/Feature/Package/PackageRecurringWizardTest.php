<?php

declare(strict_types=1);

namespace Tests\Feature\Package;

use App\Booking\Enums\Weekday;
use App\Livewire\Frontend\Booking\BookingWizard;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Payment;
use App\Models\StudentPackageEntitlement;
use App\Models\StudentPackageEntitlementReservation;
use App\Models\Subject;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\WalletLedgerEntry;
use App\Package\Enums\PackageEntitlementStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * Phase 4E.4A (gap A) — the recurring/package interaction in the REAL
 * Booking Wizard.
 *
 * Version 1 package funding covers a single lesson. The server already
 * refuses a recurring request that carries an entitlement, and that
 * refusal is proved in PackageDownstreamSemanticsTest. What was never
 * proved is the half a student actually sees: that choosing "use my
 * package" and then switching to recurring does not silently carry a
 * discarded commercial choice into N ordinary paid bookings.
 *
 * These tests drive the Livewire component's real state transitions
 * (selectFunding / selectBillingMode) rather than asserting on the
 * service, because the defect this closes was a UI-state defect.
 */
class PackageRecurringWizardTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private User $student;

    private User $instructor;

    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['manager', 'instructor', 'student'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        $this->student->profile()->update([
            'phone_e164' => '+9199999'.str_pad((string) $this->student->id, 5, '0', STR_PAD_LEFT),
            'phone_verified_at' => now(),
        ]);

        $this->instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $this->instructor->id], ['instructor_status' => 'approved']);
        TeacherSubject::factory()->state(['teacher_id' => $this->instructor->id])->subject('maths', 1, 12)->create();

        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()
                ->state(['teacher_id' => $this->instructor->id])
                ->forDay($day)->between('09:00:00', '17:00:00')->create();
        }

        $priced = $this->createPaidBookingTypeWithPrice('paid_one_to_one', 499.00, 'INR');
        $this->assignBillingCountry($this->student, $priced['country']);
        $this->subject = $this->seedLessonSubject('maths');
    }

    private function entitlement(): StudentPackageEntitlement
    {
        return StudentPackageEntitlement::withoutEvents(function () {
            Schema::disableForeignKeyConstraints();

            $row = StudentPackageEntitlement::query()->create([
                'student_id' => $this->student->id,
                'instructor_id' => $this->instructor->id,
                'proposal_id' => Str::uuid()->toString(),
                'subject_id' => $this->subject->id,
                'paid_quantity' => 5,
                'bonus_quantity' => 0,
                'total_quantity' => 5,
                'used_quantity' => 0,
                'status' => PackageEntitlementStatus::Active,
                'validity_days' => 365,
                'activated_at' => now()->subDay(),
                'expires_at' => now()->addYear(),
            ]);

            Schema::enableForeignKeyConstraints();

            return $row->refresh();
        });
    }

    /**
     * The wizard mid-flow: a single paid booking with a package already
     * chosen.
     *
     * State is set directly rather than clicked through every phase
     * because what these tests assert is the RECURRING transition, not
     * the slot picker. `lockedInstructorId` is deliberately NOT set — it
     * is #[Locked] (a crafted client update must never reassign the
     * instructor), and neither selectFunding() nor selectBillingMode()
     * depends on it.
     */
    private function wizardWithPackageSelected(StudentPackageEntitlement $entitlement): Testable
    {
        return Livewire::actingAs($this->student)
            ->test(BookingWizard::class)
            ->set('type', 'paid_one_to_one')
            ->set('subject', 'maths')
            ->set('grade', 7)
            ->set('recurring', false)
            ->set('selectedSlotStartsAt', CarbonImmutable::now('UTC')->addDays(3)->setTime(10, 0)->toIso8601String())
            ->set('fundingOptions', [[
                'id' => (string) $entitlement->id,
                'name' => 'Package',
                'available_to_book' => 5,
            ]])
            ->call('selectFunding', (string) $entitlement->id);
    }

    // ── The state transition under test ───────────────────────────────────

    public function test_a_student_can_explicitly_select_package_funding_for_a_single_booking(): void
    {
        $entitlement = $this->entitlement();

        $this->wizardWithPackageSelected($entitlement)
            ->assertSet('packageEntitlementId', (string) $entitlement->id);
    }

    public function test_switching_to_recurring_clears_the_selected_package(): void
    {
        $entitlement = $this->entitlement();

        $component = $this->wizardWithPackageSelected($entitlement)
            ->assertSet('packageEntitlementId', (string) $entitlement->id)
            ->call('selectBillingMode', 'recurring');

        // The commercial choice is discarded VISIBLY — never carried into
        // a recurring series that would silently bill the student.
        $component->assertSet('recurring', true);
        $component->assertSet('packageEntitlementId', null);
        $component->assertSet('fundingOptions', []);
    }

    public function test_the_student_is_told_why_their_package_was_not_applied(): void
    {
        $entitlement = $this->entitlement();

        $component = $this->wizardWithPackageSelected($entitlement)
            ->call('selectBillingMode', 'recurring');

        $banner = $component->get('banner');

        $this->assertNotSame('', $banner, 'Dropping a package selection must be explained, never silent.');
        $this->assertStringContainsString('package', strtolower((string) $banner));
    }

    public function test_switching_back_to_single_does_not_leave_recurring_state_stuck(): void
    {
        $entitlement = $this->entitlement();

        $component = $this->wizardWithPackageSelected($entitlement)
            ->call('selectBillingMode', 'recurring')
            ->call('selectBillingMode', 'single');

        // Back in single mode the student may choose a package again —
        // nothing about the recurring detour permanently blocks it.
        $component->assertSet('recurring', false);
        $component->assertSet('packageEntitlementId', null);
    }

    public function test_choosing_recurring_creates_nothing_by_itself(): void
    {
        $entitlement = $this->entitlement();

        $this->wizardWithPackageSelected($entitlement)
            ->call('selectBillingMode', 'recurring');

        // Wizard state changes are not commitments: no booking, no
        // reservation, and no money moved anywhere.
        $this->assertSame(0, Booking::query()->count());
        $this->assertSame(0, StudentPackageEntitlementReservation::query()->count());
        $this->assertSame(0, BookingPayment::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, WalletLedgerEntry::query()->count());
        $this->assertSame(0, (int) $entitlement->refresh()->used_quantity);
    }

    public function test_a_recurring_booking_never_loads_package_funding_options(): void
    {
        $this->entitlement();

        $component = Livewire::actingAs($this->student)
            ->test(BookingWizard::class)
            ->set('type', 'paid_one_to_one')
            ->set('subject', 'maths')
            ->set('grade', 7)
            ->set('selectedSlotStartsAt', CarbonImmutable::now('UTC')->addDays(3)->setTime(10, 0)->toIso8601String())
            ->call('selectBillingMode', 'recurring');

        // Nobody is offered a choice the server would refuse.
        $component->assertSet('fundingOptions', []);
        $component->assertSet('packageEntitlementId', null);
    }

    public function test_normal_recurring_paid_booking_remains_selectable(): void
    {
        $component = Livewire::actingAs($this->student)
            ->test(BookingWizard::class)
            ->set('type', 'paid_one_to_one')
            ->set('subject', 'maths')
            ->set('grade', 7)
            ->call('selectBillingMode', 'recurring');

        // The Version 1 restriction removes PACKAGE funding from
        // recurring, never recurring itself.
        $component->assertSet('recurring', true);
        $component->assertHasNoErrors();
    }
}
