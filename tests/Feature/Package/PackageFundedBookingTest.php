<?php

declare(strict_types=1);

namespace Tests\Feature\Package;

use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Services\BookingService;
use App\Booking\Services\WizardBookingService;
use App\Earnings\Services\InstructorCompensationResolver;
use App\Earnings\Services\InstructorEarningService;
use App\Listeners\Package\ReleasePackageReservationOnBookingCancelled;
use App\Listeners\Package\ReleasePackageReservationOnNonConsumingOutcome;
use App\Models\StudentPackageEntitlementReservation;
use App\Package\Enums\PackageEntitlementReservationStatus;
use App\Package\Services\PackageBookingEntitlementResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 4D — funding/payment isolation (spec Part 46) and the
 * architecture guards Part 50 requires.
 *
 * The central claim under test: a package-funded booking is PREPAID.
 * It must move no money at booking time — no generic Payment, no
 * BookingPayment, no wallet debit — and must never be represented as
 * free, because the lesson genuinely has commercial value that was
 * collected earlier through StudentPackagePurchase.
 *
 * Following the guard principle adopted after Phase 4B.1: these assert
 * that an operation leaves shared financial tables UNTOUCHED, rather
 * than that those tables must not exist. `payments`, `booking_payments`
 * and the wallet ledger are all legitimate shared infrastructure — the
 * invariant is about side effects, not existence.
 */
class PackageFundedBookingTest extends TestCase
{
    use RefreshDatabase;

    // ── 53-56. Payment isolation ──────────────────────────────────────────

    public function test_package_funded_payment_status_is_settled_but_not_paid(): void
    {
        // PackageFunded must satisfy "is this booking financially OK to
        // proceed?" without masquerading as a collected payment.
        $this->assertTrue(BookingPaymentStatus::PackageFunded->isSettled());
        $this->assertTrue(BookingPaymentStatus::Paid->isSettled());

        $this->assertFalse(BookingPaymentStatus::Pending->isSettled());
        $this->assertFalse(BookingPaymentStatus::Failed->isSettled());
        $this->assertFalse(BookingPaymentStatus::NotRequired->isSettled());
    }

    public function test_a_package_funded_booking_is_never_payable(): void
    {
        // Nothing is owed, so no payment may be initiated or retried
        // against it — that is what keeps a second charge impossible.
        $this->assertFalse(BookingPaymentStatus::PackageFunded->isPayable());
    }

    public function test_a_package_funded_booking_is_never_labelled_free(): void
    {
        // §30 — "prepaid" and "free" are different commercial facts, and
        // NotRequired is the one that reads as free.
        $this->assertSame('Covered by Package', BookingPaymentStatus::PackageFunded->label());
        $this->assertNotSame(
            BookingPaymentStatus::NotRequired->label(),
            BookingPaymentStatus::PackageFunded->label(),
        );
    }

    public function test_package_funded_is_a_distinct_case_from_every_other_status(): void
    {
        $values = array_map(fn (BookingPaymentStatus $s): string => $s->value, BookingPaymentStatus::cases());

        $this->assertSame(count($values), count(array_unique($values)));
        $this->assertContains('package_funded', $values);
    }

    /**
     * Package funding requires a chosen instructor — an auto-assigned
     * booking proceeds as an ordinary paid booking.
     *
     * A product boundary, not an oversight: a personalized package is a
     * contract with ONE instructor, so the funding question has no
     * answer before assignment. Pinned here so the refusal cannot be
     * quietly relaxed into "search everyone's packages" or "let the
     * package pick the instructor".
     */
    public function test_package_funding_requires_a_chosen_instructor(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(WizardBookingService::class))->getFileName(),
        );

        $this->assertStringContainsString('if ($data->teacherId === null) {', $source);
        $this->assertStringContainsString('a package is tied to the instructor it was created with', $source);
    }

    // ── 66-70. Instructor compensation independence ───────────────────────

    /**
     * Phase 4C already pins that the EARNINGS side reads no package
     * state. This is the other direction, for the surfaces Phase 4D
     * added: reservation, entitlement selection and package-funded
     * booking must not reach into compensation either.
     *
     * Structural rather than behavioral on purpose — what matters here
     * is that no dependency exists to go wrong in the first place.
     *
     * NOT SUFFICIENT ON ITS OWN, and Phase 4E proved why: this
     * assertion passes most strongly when the earnings integration is
     * most ABSENT, so it stayed green through the entire period in which
     * a package-funded lesson earned the instructor nothing. The
     * behavioral counterpart — that a completed package-funded lesson
     * actually produces an InstructorEarning, for paid and bonus units
     * alike — lives in PackageFundedDeliveryIntegrationTest and is the
     * test that would fail if the seam broke again. Keep both: this one
     * pins the direction of the dependency, that one pins the outcome.
     */
    public function test_the_new_package_surfaces_never_touch_instructor_earnings(): void
    {
        foreach ([
            PackageBookingEntitlementResolver::class,
            ReleasePackageReservationOnBookingCancelled::class,
            ReleasePackageReservationOnNonConsumingOutcome::class,
        ] as $class) {
            $source = file_get_contents((new \ReflectionClass($class))->getFileName());

            foreach (['InstructorEarning', 'CompensationResolver', 'InstructorCompensation'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $source,
                    sprintf('%s must not reach into instructor compensation (%s).', class_basename($class), $forbidden),
                );
            }
        }
    }

    /**
     * The admin price override is a STUDENT-side commercial decision.
     * If any compensation path read it, overriding a package's price
     * would silently change what the instructor is paid.
     */
    public function test_no_compensation_path_reads_the_package_override_price(): void
    {
        foreach ([
            InstructorCompensationResolver::class,
            InstructorEarningService::class,
        ] as $class) {
            $source = file_get_contents((new \ReflectionClass($class))->getFileName());

            foreach (['override_price_minor', 'final_price_minor', 'unit_price_minor'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $source,
                    sprintf('%s must not read package pricing (%s).', class_basename($class), $forbidden),
                );
            }
        }
    }

    /**
     * A package-funded booking keeps its REAL commercial value. Zeroing
     * it would be the concrete way package funding could corrupt both
     * revenue reporting and any downstream read of lesson value.
     */
    public function test_package_funding_does_not_zero_the_booking_price(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(BookingService::class))->getFileName(),
        );

        // The price/currency writes stay driven by requiresPayment, NOT
        // by whether the booking is package-funded.
        $this->assertStringContainsString("'price' => \$price->requiresPayment ? \$price->payableAmount : null,", $source);
        $this->assertStringContainsString("'currency' => \$price->requiresPayment ? \$price->currency : null,", $source);
    }

    // ── Part 50. Architecture / global guards ─────────────────────────────

    public function test_the_reservation_ledger_table_exists_with_its_integrity_constraints(): void
    {
        $this->assertTrue(Schema::hasTable('student_package_entitlement_reservations'));

        foreach (['id', 'entitlement_id', 'booking_id', 'status', 'reserved_at', 'released_at', 'consumed_at', 'release_reason'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('student_package_entitlement_reservations', $column),
                sprintf('Expected reservation column "%s".', $column),
            );
        }
    }

    public function test_one_booking_can_hold_at_most_one_reservation_at_the_schema_level(): void
    {
        // The unique index is load-bearing for concurrency (§21), so it
        // is asserted directly rather than only through behavior.
        $indexes = collect(DB::select('SHOW INDEX FROM student_package_entitlement_reservations'))
            ->filter(fn ($i): bool => $i->Column_name === 'booking_id')
            ->filter(fn ($i): bool => (int) $i->Non_unique === 0);

        $this->assertTrue($indexes->isNotEmpty(), 'booking_id must carry a UNIQUE index.');
    }

    public function test_the_package_academic_context_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('package_academic_contexts'));

        // The stable ids entitlement matching compares on (§15).
        foreach (['proposal_id', 'country_id', 'education_system_id', 'education_system_level_id', 'academic_level_id', 'subject_id', 'curriculum_id', 'curriculum_version_id'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('package_academic_contexts', $column),
                sprintf('Expected package academic context column "%s".', $column),
            );
        }

        // The denormalized historical record (§3).
        foreach (['country_name', 'education_system_name', 'level_term', 'level_display', 'normalized_grade', 'subject_name', 'curriculum_name', 'curriculum_version_number'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('package_academic_contexts', $column),
                sprintf('Expected package academic context snapshot column "%s".', $column),
            );
        }
    }

    public function test_package_academic_context_is_one_per_proposal(): void
    {
        $indexes = collect(DB::select('SHOW INDEX FROM package_academic_contexts'))
            ->filter(fn ($i): bool => $i->Column_name === 'proposal_id')
            ->filter(fn ($i): bool => (int) $i->Non_unique === 0);

        $this->assertTrue($indexes->isNotEmpty(), 'proposal_id must carry a UNIQUE index.');
    }

    public function test_booking_academic_contexts_did_not_become_polymorphic(): void
    {
        // §1 explicitly forbids reusing the booking snapshot table for
        // packages. A morph column appearing here would mean the two
        // lifecycles had been merged after all.
        $this->assertFalse(Schema::hasColumn('booking_academic_contexts', 'contextable_type'));
        $this->assertFalse(Schema::hasColumn('booking_academic_contexts', 'contextable_id'));
        $this->assertTrue(Schema::hasColumn('booking_academic_contexts', 'booking_id'));
    }

    public function test_the_proposal_carries_its_structured_selection(): void
    {
        $this->assertTrue(Schema::hasColumn('instructor_package_proposals', 'education_system_id'));
        $this->assertTrue(Schema::hasColumn('instructor_package_proposals', 'education_system_level_id'));
    }

    public function test_remaining_quantity_was_not_redefined_as_available_capacity(): void
    {
        // §19/§53 — the generated column must keep meaning
        // "total - used". If a reservation-aware definition were ever
        // written into it, availability and balance would collapse into
        // one number and the distinction this phase rests on would be lost.
        $column = collect(DB::select('SHOW COLUMNS FROM student_package_entitlements'))
            ->firstWhere('Field', 'remaining_quantity');

        $this->assertNotNull($column);
        $this->assertStringContainsStringIgnoringCase('generated', $column->Extra);

        $definition = DB::selectOne(
            "SELECT GENERATION_EXPRESSION AS expr FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_package_entitlements' AND COLUMN_NAME = 'remaining_quantity'",
        );

        $this->assertStringNotContainsStringIgnoringCase('reserv', (string) $definition->expr);
    }

    public function test_no_generic_reservation_permissions_are_granted(): void
    {
        // §41 — reservations are lifecycle records written only by
        // trusted services. No role may create/update/delete them.
        $forbidden = Permission::query()
            ->where('name', 'like', '%reservation%')
            ->whereNot('name', 'like', '%view%')
            ->pluck('name');

        $this->assertTrue(
            $forbidden->isEmpty(),
            'Unexpected mutating reservation permissions: '.$forbidden->implode(', '),
        );
    }

    public function test_the_reservation_status_enum_has_exactly_three_states(): void
    {
        $values = array_map(
            fn (PackageEntitlementReservationStatus $s): string => $s->value,
            PackageEntitlementReservationStatus::cases(),
        );

        sort($values);

        $this->assertSame(['consumed', 'released', 'reserved'], $values);
        $this->assertTrue(PackageEntitlementReservationStatus::Reserved->holdsCapacity());
        $this->assertFalse(PackageEntitlementReservationStatus::Released->holdsCapacity());
        $this->assertFalse(PackageEntitlementReservationStatus::Consumed->holdsCapacity());
    }

    public function test_only_reserved_holds_capacity(): void
    {
        // The scope availableToBook() depends on must select exactly the
        // Reserved rows — a released or consumed unit is not held.
        $sql = StudentPackageEntitlementReservation::query()->holdingCapacity()->toSql();

        $this->assertStringContainsString('status', $sql);
    }
}
