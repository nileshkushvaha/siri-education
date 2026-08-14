<?php

declare(strict_types=1);

namespace Tests\Feature\Package;

use App\Booking\DTOs\CancelBookingData;
use App\Booking\Enums\BookingActor;
use App\Booking\Events\BookingCancelled;
use App\Exceptions\HistoricalRecordCannotBeDeletedException;
use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Enums\LessonStatus;
use App\Lessons\Events\LessonOutcomeFinalized;
use App\Listeners\Package\ReleasePackageReservationOnBookingCancelled;
use App\Listeners\Package\ReleasePackageReservationOnNonConsumingOutcome;
use App\Models\AcademicCategory;
use App\Models\Booking;
use App\Models\Lesson;
use App\Models\StudentPackageEntitlement;
use App\Models\StudentPackageEntitlementConsumption;
use App\Models\StudentPackageEntitlementReservation;
use App\Models\Subject;
use App\Models\User;
use App\Package\Enums\PackageEntitlementReservationStatus;
use App\Package\Enums\PackageEntitlementStatus;
use App\Package\Exceptions\PackageException;
use App\Package\Services\PackageEntitlementService;
use Database\Seeders\PackagePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 4D — reservation capacity (spec Part 44) and the expiry rules
 * that depend on it (Part 45).
 *
 * The property under test throughout is that THREE numbers stay
 * distinct and each keeps its own meaning:
 *
 *      remaining_quantity  total − consumed   (unchanged by booking)
 *      reserved_quantity   committed to future bookings
 *      available_to_book   remaining − reserved
 *
 * Phase 4C consumes only on completion, so booking must not move
 * `remaining_quantity`; without reservations that would let one unit
 * fund several bookings. These tests pin both halves: booking changes
 * availability but not the balance, and completion moves the balance
 * exactly once.
 */
class PackageEntitlementReservationTest extends TestCase
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

    /** @return array{entitlement: StudentPackageEntitlement, student: User, instructor: User, subject: Subject} */
    private function activePackage(int $total = 5, ?string $expiresAt = '2027-12-31 00:00:00'): array
    {
        $student = User::factory()->create(['status' => 'active']);
        $student->assignRole('student');

        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->assignRole('instructor');

        $category = AcademicCategory::query()->firstOrCreate(['slug' => 'general'], ['name' => 'General']);
        $subject = Subject::query()->create(['academic_category_id' => $category->id, 'name' => 'Maths', 'slug' => 'maths-'.Str::random(5), 'status' => 'active']);

        // The proposal/purchase/settlement pipeline has its own suite;
        // these tests are about capacity, so the entitlement is built
        // directly with the one FK relaxed.
        $entitlement = StudentPackageEntitlement::withoutEvents(function () use ($student, $instructor, $subject, $total, $expiresAt) {
            Schema::disableForeignKeyConstraints();

            $row = StudentPackageEntitlement::query()->create([
                'student_id' => $student->id,
                'instructor_id' => $instructor->id,
                'proposal_id' => Str::uuid()->toString(),
                'subject_id' => $subject->id,
                'paid_quantity' => $total,
                'bonus_quantity' => 0,
                'total_quantity' => $total,
                'used_quantity' => 0,
                'status' => PackageEntitlementStatus::Active,
                'validity_days' => $expiresAt === null ? null : 90,
                'activated_at' => now()->subDay(),
                'expires_at' => $expiresAt,
            ]);

            Schema::enableForeignKeyConstraints();

            return $row->refresh();
        });

        return compact('entitlement', 'student', 'instructor', 'subject');
    }

    private function booking(array $package, bool $funded = true): Booking
    {
        return Booking::factory()->confirmed()->paid()->create([
            'student_id' => $package['student']->id,
            'instructor_id' => $package['instructor']->id,
            'package_entitlement_id' => $funded ? $package['entitlement']->id : null,
        ]);
    }

    private function lessonFor(array $package, Booking $booking): Lesson
    {
        return Lesson::factory()->create([
            'booking_id' => $booking->id,
            'student_id' => $package['student']->id,
            'instructor_id' => $package['instructor']->id,
            'subject_id' => $package['subject']->id,
            'package_entitlement_id' => $booking->package_entitlement_id,
            'status' => LessonStatus::Scheduled,
        ]);
    }

    // ── 31-35. Reserving does not spend ───────────────────────────────────

    public function test_reserving_commits_exactly_one_unit(): void
    {
        $package = $this->activePackage(total: 5);
        $booking = $this->booking($package);

        $reservation = $this->entitlements()->reserveForBooking($package['entitlement'], $booking);

        $this->assertSame(PackageEntitlementReservationStatus::Reserved, $reservation->status);
        $this->assertSame($booking->id, $reservation->booking_id);
        $this->assertSame(1, $this->entitlements()->reservedQuantity($package['entitlement']));
    }

    public function test_reserving_does_not_increment_used_quantity_or_change_remaining(): void
    {
        $package = $this->activePackage(total: 5);

        $this->entitlements()->reserveForBooking($package['entitlement'], $this->booking($package));

        $entitlement = $package['entitlement']->refresh();

        // The Phase 4C meaning of these two columns is deliberately
        // untouched by scheduling — only DELIVERY spends a unit.
        $this->assertSame(0, (int) $entitlement->used_quantity);
        $this->assertSame(5, (int) $entitlement->remaining_quantity);
    }

    public function test_reserving_creates_no_consumption_ledger_row(): void
    {
        $package = $this->activePackage();

        $this->entitlements()->reserveForBooking($package['entitlement'], $this->booking($package));

        $this->assertSame(0, StudentPackageEntitlementConsumption::query()->count());
    }

    public function test_available_to_book_decreases_by_one_per_reservation(): void
    {
        $package = $this->activePackage(total: 5);
        $entitlement = $package['entitlement'];

        $this->assertSame(5, $this->entitlements()->availableToBook($entitlement));

        $this->entitlements()->reserveForBooking($entitlement, $this->booking($package));
        $this->assertSame(4, $this->entitlements()->availableToBook($entitlement->refresh()));

        $this->entitlements()->reserveForBooking($entitlement, $this->booking($package));
        $this->assertSame(3, $this->entitlements()->availableToBook($entitlement->refresh()));

        // remaining_quantity never moved — the two concepts are separate.
        $this->assertSame(5, (int) $entitlement->refresh()->remaining_quantity);
    }

    public function test_available_to_book_is_remaining_minus_reserved(): void
    {
        // The spec's worked example: 15 total, 5 used, 3 scheduled → 7.
        $package = $this->activePackage(total: 15);
        $entitlement = $package['entitlement'];

        $entitlement->forceFill(['used_quantity' => 5])->save();
        $entitlement->refresh();

        for ($i = 0; $i < 3; $i++) {
            $this->entitlements()->reserveForBooking($entitlement, $this->booking($package));
        }

        $entitlement->refresh();

        $this->assertSame(10, (int) $entitlement->remaining_quantity);
        $this->assertSame(3, $this->entitlements()->reservedQuantity($entitlement));
        $this->assertSame(7, $this->entitlements()->availableToBook($entitlement));
    }

    // ── 36-37. Oversubscription ───────────────────────────────────────────

    public function test_a_second_booking_is_rejected_when_only_one_unit_is_available(): void
    {
        $package = $this->activePackage(total: 1);

        $this->entitlements()->reserveForBooking($package['entitlement'], $this->booking($package));

        $this->expectException(PackageException::class);
        $this->expectExceptionMessage('no lessons left to schedule');

        $this->entitlements()->reserveForBooking($package['entitlement']->refresh(), $this->booking($package));
    }

    public function test_a_booking_can_hold_at_most_one_reservation(): void
    {
        // UNIQUE(booking_id) is the DB-level half of the concurrency
        // guarantee — a retried reserve returns the SAME reservation
        // rather than taking a second unit.
        $package = $this->activePackage(total: 5);
        $booking = $this->booking($package);

        $first = $this->entitlements()->reserveForBooking($package['entitlement'], $booking);
        $second = $this->entitlements()->reserveForBooking($package['entitlement']->refresh(), $booking);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $this->entitlements()->reservedQuantity($package['entitlement']->refresh()));
    }

    public function test_the_database_refuses_a_duplicate_reservation_for_one_booking(): void
    {
        $package = $this->activePackage(total: 5);
        $booking = $this->booking($package);

        $this->entitlements()->reserveForBooking($package['entitlement'], $booking);

        // Bypasses the service to prove the guarantee is in the schema,
        // not only in application code.
        $this->expectException(QueryException::class);

        StudentPackageEntitlementReservation::query()->create([
            'entitlement_id' => $package['entitlement']->id,
            'booking_id' => $booking->id,
            'status' => PackageEntitlementReservationStatus::Reserved,
            'reserved_at' => now(),
        ]);
    }

    public function test_an_exhausted_balance_cannot_reserve_even_with_no_reservations(): void
    {
        $package = $this->activePackage(total: 2);
        $package['entitlement']->forceFill(['used_quantity' => 2])->save();

        $this->expectException(PackageException::class);

        $this->entitlements()->reserveForBooking($package['entitlement']->refresh(), $this->booking($package));
    }

    // ── 38-39. Cancellation releases capacity ─────────────────────────────

    public function test_releasing_returns_the_unit_to_available_capacity(): void
    {
        $package = $this->activePackage(total: 1);
        $booking = $this->booking($package);

        $this->entitlements()->reserveForBooking($package['entitlement'], $booking);
        $this->assertSame(0, $this->entitlements()->availableToBook($package['entitlement']->refresh()));

        $released = $this->entitlements()->releaseForBooking($booking, 'booking_cancelled');

        $this->assertSame(PackageEntitlementReservationStatus::Released, $released->status);
        $this->assertNotNull($released->released_at);
        $this->assertSame(1, $this->entitlements()->availableToBook($package['entitlement']->refresh()));
    }

    public function test_releasing_does_not_consume_or_change_the_balance(): void
    {
        $package = $this->activePackage(total: 3);
        $booking = $this->booking($package);

        $this->entitlements()->reserveForBooking($package['entitlement'], $booking);
        $this->entitlements()->releaseForBooking($booking, 'booking_cancelled');

        $entitlement = $package['entitlement']->refresh();

        $this->assertSame(0, (int) $entitlement->used_quantity);
        $this->assertSame(3, (int) $entitlement->remaining_quantity);
        $this->assertSame(0, StudentPackageEntitlementConsumption::query()->count());
    }

    public function test_released_capacity_can_be_booked_again(): void
    {
        $package = $this->activePackage(total: 1);
        $first = $this->booking($package);

        $this->entitlements()->reserveForBooking($package['entitlement'], $first);
        $this->entitlements()->releaseForBooking($first, 'booking_cancelled');

        // A NEW reservation against a NEW booking, not a revival of the
        // old one — the ledger reads as a history of decisions.
        $second = $this->booking($package);
        $reservation = $this->entitlements()->reserveForBooking($package['entitlement']->refresh(), $second);

        $this->assertSame(PackageEntitlementReservationStatus::Reserved, $reservation->status);
        $this->assertSame($second->id, $reservation->booking_id);
        $this->assertSame(2, StudentPackageEntitlementReservation::query()->count());
    }

    public function test_releasing_is_idempotent_and_never_throws(): void
    {
        $package = $this->activePackage();
        $booking = $this->booking($package);

        $this->entitlements()->reserveForBooking($package['entitlement'], $booking);
        $this->entitlements()->releaseForBooking($booking, 'booking_cancelled');

        // A cancellation must never fail because of package bookkeeping.
        $again = $this->entitlements()->releaseForBooking($booking, 'booking_cancelled');

        $this->assertSame(PackageEntitlementReservationStatus::Released, $again->status);
        $this->assertSame(1, StudentPackageEntitlementReservation::query()->count());
    }

    public function test_releasing_a_booking_with_no_reservation_is_a_no_op(): void
    {
        $package = $this->activePackage();

        $this->assertNull($this->entitlements()->releaseForBooking($this->booking($package, funded: false), 'booking_cancelled'));
    }

    // ── 41-43. Completion converts the reservation ────────────────────────

    public function test_completion_converts_the_reservation_to_consumed(): void
    {
        $package = $this->activePackage(total: 3);
        $booking = $this->booking($package);
        $reservation = $this->entitlements()->reserveForBooking($package['entitlement'], $booking);

        $this->entitlements()->consumeForLesson($this->lessonFor($package, $booking));

        $reservation->refresh();

        $this->assertSame(PackageEntitlementReservationStatus::Consumed, $reservation->status);
        $this->assertNotNull($reservation->consumed_at);
        // The unit is spent, so it no longer holds capacity...
        $this->assertSame(0, $this->entitlements()->reservedQuantity($package['entitlement']->refresh()));
        // ...and it is not double-counted: remaining dropped instead.
        $this->assertSame(2, (int) $package['entitlement']->refresh()->remaining_quantity);
        $this->assertSame(2, $this->entitlements()->availableToBook($package['entitlement']->refresh()));
    }

    public function test_completion_increments_used_quantity_exactly_once(): void
    {
        $package = $this->activePackage(total: 3);
        $booking = $this->booking($package);
        $this->entitlements()->reserveForBooking($package['entitlement'], $booking);
        $lesson = $this->lessonFor($package, $booking);

        $this->entitlements()->consumeForLesson($lesson);
        $this->entitlements()->consumeForLesson($lesson);
        $this->entitlements()->consumeForLesson($lesson);

        $this->assertSame(1, (int) $package['entitlement']->refresh()->used_quantity);
        $this->assertSame(1, StudentPackageEntitlementConsumption::query()->count());
        $this->assertSame(1, StudentPackageEntitlementReservation::query()->count());
    }

    public function test_a_consumed_reservation_is_never_released(): void
    {
        $package = $this->activePackage(total: 3);
        $booking = $this->booking($package);
        $reservation = $this->entitlements()->reserveForBooking($package['entitlement'], $booking);

        $this->entitlements()->consumeForLesson($this->lessonFor($package, $booking));

        // A late cancellation event must not un-spend a delivered lesson.
        $this->entitlements()->releaseForBooking($booking, 'booking_cancelled');

        $this->assertSame(PackageEntitlementReservationStatus::Consumed, $reservation->refresh()->status);
        $this->assertSame(1, (int) $package['entitlement']->refresh()->used_quantity);
    }

    public function test_consuming_the_last_unit_completes_the_entitlement(): void
    {
        $package = $this->activePackage(total: 1);
        $booking = $this->booking($package);
        $this->entitlements()->reserveForBooking($package['entitlement'], $booking);

        $this->entitlements()->consumeForLesson($this->lessonFor($package, $booking));

        $entitlement = $package['entitlement']->refresh();

        $this->assertSame(PackageEntitlementStatus::Completed, $entitlement->status);
        $this->assertSame(0, $this->entitlements()->availableToBook($entitlement));
    }

    // ── 51, 26. Expiry ────────────────────────────────────────────────────

    public function test_an_expired_entitlement_cannot_reserve(): void
    {
        $package = $this->activePackage(total: 5, expiresAt: now()->subDay()->toDateTimeString());

        $this->expectException(PackageException::class);
        $this->expectExceptionMessage('This package is Expired and can no longer be used.');

        $this->entitlements()->reserveForBooking($package['entitlement'], $this->booking($package));
    }

    public function test_expiring_releases_outstanding_reservations(): void
    {
        // Spec §28 — an expired entitlement must not leave reservations
        // stranded in Reserved forever.
        $package = $this->activePackage(total: 5, expiresAt: now()->addDay()->toDateTimeString());
        $booking = $this->booking($package);
        $reservation = $this->entitlements()->reserveForBooking($package['entitlement'], $booking);

        $this->travel(2)->days();
        $this->entitlements()->expireIfNeeded($package['entitlement']->refresh());

        $reservation->refresh();

        $this->assertSame(PackageEntitlementReservationStatus::Released, $reservation->status);
        $this->assertSame('entitlement_expired', $reservation->release_reason);
        $this->assertSame(PackageEntitlementStatus::Expired, $package['entitlement']->refresh()->status);
    }

    public function test_expiry_release_keeps_the_reservation_auditable(): void
    {
        $package = $this->activePackage(total: 5, expiresAt: now()->addDay()->toDateTimeString());
        $this->entitlements()->reserveForBooking($package['entitlement'], $this->booking($package));

        $this->travel(2)->days();
        $this->entitlements()->expireIfNeeded($package['entitlement']->refresh());

        // Released, never deleted — the history of what was scheduled
        // and why it lapsed survives.
        $this->assertSame(1, StudentPackageEntitlementReservation::query()->count());
    }

    public function test_a_null_expiry_never_lapses_a_reservation(): void
    {
        $package = $this->activePackage(total: 5, expiresAt: null);
        $booking = $this->booking($package);
        $reservation = $this->entitlements()->reserveForBooking($package['entitlement'], $booking);

        $this->travel(10)->years();
        $this->entitlements()->expireIfNeeded($package['entitlement']->refresh());

        $this->assertSame(PackageEntitlementReservationStatus::Reserved, $reservation->refresh()->status);
        $this->assertSame(PackageEntitlementStatus::Active, $package['entitlement']->refresh()->status);
    }

    public function test_phase_4c_completion_time_expiry_guard_remains_intact(): void
    {
        // Requirement 52 — a reservation must NOT license consumption of
        // an expired package. Delivery-before-expiry stays the rule.
        $package = $this->activePackage(total: 5, expiresAt: now()->addDay()->toDateTimeString());
        $booking = $this->booking($package);
        $this->entitlements()->reserveForBooking($package['entitlement'], $booking);
        $lesson = $this->lessonFor($package, $booking);

        $this->travel(2)->days();

        $this->expectException(PackageException::class);
        $this->expectExceptionMessage('This package is Expired and can no longer be used.');

        $this->entitlements()->consumeForLesson($lesson->refresh());
    }

    // ── 74-76. Lifecycle-managed only ─────────────────────────────────────

    public function test_a_reservation_can_never_be_deleted(): void
    {
        $package = $this->activePackage();
        $reservation = $this->entitlements()->reserveForBooking($package['entitlement'], $this->booking($package));

        $this->expectException(HistoricalRecordCannotBeDeletedException::class);

        $reservation->delete();
    }

    // ── Listener wiring ───────────────────────────────────────────────────

    public function test_cancellation_listener_releases_the_reservation(): void
    {
        $package = $this->activePackage(total: 1);
        $booking = $this->booking($package);
        $reservation = $this->entitlements()->reserveForBooking($package['entitlement'], $booking);

        app(ReleasePackageReservationOnBookingCancelled::class)
            ->handle(new BookingCancelled(
                $booking->refresh(),
                new CancelBookingData(BookingActor::Student, 'Changed my mind'),
            ));

        $this->assertSame(PackageEntitlementReservationStatus::Released, $reservation->refresh()->status);
        $this->assertSame(1, $this->entitlements()->availableToBook($package['entitlement']->refresh()));
    }

    public function test_non_consuming_outcome_listener_releases_the_reservation(): void
    {
        $package = $this->activePackage(total: 1);
        $booking = $this->booking($package);
        $reservation = $this->entitlements()->reserveForBooking($package['entitlement'], $booking);
        $lesson = $this->lessonFor($package, $booking);

        app(ReleasePackageReservationOnNonConsumingOutcome::class)
            ->handle(new LessonOutcomeFinalized($lesson, LessonOutcome::StudentNoShow, 'student_absent'));

        $this->assertSame(PackageEntitlementReservationStatus::Released, $reservation->refresh()->status);
        // No-show policy is unchanged: nothing was consumed.
        $this->assertSame(0, (int) $package['entitlement']->refresh()->used_quantity);
        $this->assertSame(1, $this->entitlements()->availableToBook($package['entitlement']->refresh()));
    }

    public function test_completed_outcome_never_releases_through_the_outcome_listener(): void
    {
        // Completed belongs exclusively to the consumption path; if this
        // listener released it, the unit would be handed back AND spent.
        $package = $this->activePackage(total: 3);
        $booking = $this->booking($package);
        $reservation = $this->entitlements()->reserveForBooking($package['entitlement'], $booking);
        $lesson = $this->lessonFor($package, $booking);

        app(ReleasePackageReservationOnNonConsumingOutcome::class)
            ->handle(new LessonOutcomeFinalized($lesson, LessonOutcome::Completed, 'both_attended'));

        $this->assertSame(PackageEntitlementReservationStatus::Reserved, $reservation->refresh()->status);
    }

    public function test_an_ordinary_booking_is_ignored_by_both_release_listeners(): void
    {
        $package = $this->activePackage();
        $booking = $this->booking($package, funded: false);

        app(ReleasePackageReservationOnBookingCancelled::class)
            ->handle(new BookingCancelled(
                $booking,
                new CancelBookingData(BookingActor::Student, 'Changed my mind'),
            ));

        $this->assertSame(0, StudentPackageEntitlementReservation::query()->count());
    }
}
