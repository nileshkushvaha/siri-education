<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\DTOs\RescheduleBookingData;
use App\Booking\Enums\BookingActivityAction;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\InvalidStatusTransitionException;
use App\Booking\Exceptions\RescheduleLimitReachedException;
use App\Booking\Exceptions\SlotUnavailableException;
use App\Models\Booking;
use App\Models\BookingActivity;
use App\Models\BookingPayment;
use App\Models\BookingType;
use App\Models\Currency;
use App\Models\TeacherAvailability;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Settings\BookingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 24D — integration tests exercising the full runtime path:
 * BookingService::reschedule() → RescheduleLimitPolicy::decide() →
 * (allowed) AvailabilityService::ensureAvailable() → RescheduleBookingAction
 * → booking_activities. Every test uses a real approved teacher with a
 * genuinely open availability window, so a real SlotUnavailableException
 * can occur exactly like production — the reschedule-limit rejection is
 * never confused with an availability rejection.
 */
class RescheduleLimitExecutionTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private User $student;

    private BookingType $type;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $this->teacher->id], ['instructor_status' => 'approved']);

        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $this->teacher->id])
                ->forDay($day)->between('06:00:00', '22:00:00')->create();
        }

        $this->student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        $this->type = BookingType::factory()->create(['requires_approval' => false]);
    }

    private function setLimit(int $limit): void
    {
        $settings = app(BookingSettings::class);
        $settings->reschedule_limit = $limit;
        $settings->save();
    }

    private function bookingAt(CarbonImmutable $startsAt): Booking
    {
        return Booking::factory()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->teacher->id,
            'booking_type_id' => $this->type->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::NotRequired,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
        ]);
    }

    private function reschedule(Booking $booking, CarbonImmutable $to, BookingActor $actor = BookingActor::Student): Booking
    {
        return app(BookingServiceInterface::class)->reschedule($booking, new RescheduleBookingData(
            startsAt: $to,
            actor: $actor,
        ));
    }

    private function successfulRescheduleCount(Booking $booking): int
    {
        return BookingActivity::query()
            ->where('booking_id', $booking->id)
            ->where('action', BookingActivityAction::Rescheduled)
            ->count();
    }

    // ── 1/8. First reschedule succeeds; durable across a fresh lookup ───────

    public function test_first_student_reschedule_succeeds_when_allowance_exists(): void
    {
        $this->setLimit(2);
        $booking = $this->bookingAt(CarbonImmutable::now()->addDays(3)->setTime(10, 0));

        $newStart = CarbonImmutable::now()->addDays(4)->setTime(11, 0);
        $updated = $this->reschedule($booking, $newStart);

        $this->assertTrue($updated->starts_at->equalTo($newStart));
        $this->assertSame(1, $this->successfulRescheduleCount($booking));

        // Durable: a fresh service instance / fresh DB read agrees.
        $this->assertSame(1, BookingActivity::query()->where('booking_id', $booking->id)->where('action', BookingActivityAction::Rescheduled)->count());
    }

    // ── 2. Rejected after the configured number of successes ────────────────

    public function test_reschedule_is_rejected_after_the_configured_number_of_successes(): void
    {
        $this->setLimit(2);
        $booking = $this->bookingAt(CarbonImmutable::now()->addDays(1)->setTime(10, 0));

        $this->reschedule($booking, CarbonImmutable::now()->addDays(2)->setTime(10, 0));
        $this->reschedule($booking, CarbonImmutable::now()->addDays(3)->setTime(10, 0));

        $this->expectException(RescheduleLimitReachedException::class);
        $this->expectExceptionMessage('You have reached the reschedule limit for this lesson.');

        $this->reschedule($booking->fresh(), CarbonImmutable::now()->addDays(4)->setTime(10, 0));
    }

    // ── 3. Limit 0 blocks ordinary student rescheduling ─────────────────────

    public function test_limit_zero_blocks_ordinary_student_rescheduling(): void
    {
        $this->setLimit(0);
        $booking = $this->bookingAt(CarbonImmutable::now()->addDays(1)->setTime(10, 0));

        $this->expectException(RescheduleLimitReachedException::class);

        $this->reschedule($booking, CarbonImmutable::now()->addDays(2)->setTime(10, 0));
    }

    // ── 4. The original schedule does not count toward the limit ───────────

    public function test_original_schedule_does_not_count_toward_the_limit(): void
    {
        $this->setLimit(1);
        $booking = $this->bookingAt(CarbonImmutable::now()->addDays(1)->setTime(10, 0));

        // Never rescheduled yet — the original slot itself must not have
        // consumed any allowance.
        $this->assertSame(0, $this->successfulRescheduleCount($booking));

        $updated = $this->reschedule($booking, CarbonImmutable::now()->addDays(2)->setTime(10, 0));
        $this->assertSame(1, $this->successfulRescheduleCount($updated));
    }

    // ── 5. Failed availability validation does not consume allowance ───────

    public function test_failed_availability_validation_does_not_consume_allowance(): void
    {
        $this->setLimit(2);
        $booking = $this->bookingAt(CarbonImmutable::now()->addDays(1)->setTime(10, 0));

        // Outside the 06:00-22:00 availability window — must fail validation.
        $outsideWindow = CarbonImmutable::now()->addDays(2)->setTime(23, 30);

        try {
            $this->reschedule($booking, $outsideWindow);
            $this->fail('Expected SlotUnavailableException.');
        } catch (SlotUnavailableException) {
            // expected
        }

        $this->assertSame(0, $this->successfulRescheduleCount($booking->fresh()));
    }

    // ── 6. Conflict failure does not consume allowance ──────────────────────

    public function test_conflicting_target_slot_does_not_consume_allowance(): void
    {
        $this->setLimit(2);
        $conflictStart = CarbonImmutable::now()->addDays(2)->setTime(14, 0);

        // A second, unrelated confirmed booking already occupies this slot.
        $this->bookingAt($conflictStart);
        $booking = $this->bookingAt(CarbonImmutable::now()->addDays(1)->setTime(10, 0));

        try {
            $this->reschedule($booking, $conflictStart);
            $this->fail('Expected SlotUnavailableException.');
        } catch (SlotUnavailableException) {
            // expected
        }

        $this->assertSame(0, $this->successfulRescheduleCount($booking->fresh()));
    }

    // ── 7. A rolled-back transaction does not consume allowance ─────────────

    public function test_rolled_back_transaction_does_not_consume_allowance(): void
    {
        $this->setLimit(2);
        $booking = $this->bookingAt(CarbonImmutable::now()->addDays(1)->setTime(10, 0));

        try {
            DB::transaction(function () use ($booking): void {
                $this->reschedule($booking, CarbonImmutable::now()->addDays(2)->setTime(10, 0));

                throw new \RuntimeException('Simulated failure after the reschedule action ran.');
            });
            $this->fail('Expected the outer transaction to roll back.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated failure after the reschedule action ran.', $e->getMessage());
        }

        $this->assertSame(0, $this->successfulRescheduleCount($booking->fresh()));
        $this->assertTrue($booking->fresh()->starts_at->equalTo(CarbonImmutable::now()->addDays(1)->setTime(10, 0)));
    }

    // ── 9. Decreasing the setting blocks future attempts without rewriting history ─

    public function test_decreasing_the_limit_blocks_future_attempts_without_changing_history(): void
    {
        $this->setLimit(3);
        $booking = $this->bookingAt(CarbonImmutable::now()->addDays(1)->setTime(10, 0));
        $this->reschedule($booking, CarbonImmutable::now()->addDays(2)->setTime(10, 0));
        $this->reschedule($booking->fresh(), CarbonImmutable::now()->addDays(3)->setTime(10, 0));

        $this->assertSame(2, $this->successfulRescheduleCount($booking->fresh()));

        $this->setLimit(1);

        $this->expectException(RescheduleLimitReachedException::class);
        $this->reschedule($booking->fresh(), CarbonImmutable::now()->addDays(4)->setTime(10, 0));

        $this->assertSame(2, $this->successfulRescheduleCount($booking->fresh()), 'History must remain unchanged after a rejection.');
    }

    // ── 10. Increasing the setting permits another attempt ──────────────────

    public function test_increasing_the_limit_permits_another_attempt(): void
    {
        $this->setLimit(1);
        $booking = $this->bookingAt(CarbonImmutable::now()->addDays(1)->setTime(10, 0));
        $this->reschedule($booking, CarbonImmutable::now()->addDays(2)->setTime(10, 0));

        $this->setLimit(2);

        $updated = $this->reschedule($booking->fresh(), CarbonImmutable::now()->addDays(3)->setTime(10, 0));
        $this->assertSame(2, $this->successfulRescheduleCount($updated));
    }

    // ── 12. Direct service invocation cannot bypass enforcement ─────────────

    public function test_direct_service_invocation_cannot_bypass_enforcement(): void
    {
        $this->setLimit(0);
        $booking = $this->bookingAt(CarbonImmutable::now()->addDays(1)->setTime(10, 0));

        $this->expectException(RescheduleLimitReachedException::class);

        app(BookingServiceInterface::class)->reschedule($booking, new RescheduleBookingData(
            startsAt: CarbonImmutable::now()->addDays(2)->setTime(10, 0),
            actor: BookingActor::Student,
        ));
    }

    // ── 14/15. Meeting update occurs once after success, not after rejection ─
    // (Meeting side effects are covered end-to-end in MeetingLifecycleTest;
    // here we assert the pure absence/presence of the Rescheduled activity,
    // which is exactly what SyncMeetingOnBookingRescheduled key on.)

    public function test_no_activity_or_state_change_occurs_after_a_rejected_reschedule(): void
    {
        $this->setLimit(1);
        $booking = $this->bookingAt(CarbonImmutable::now()->addDays(1)->setTime(10, 0));
        $this->reschedule($booking, CarbonImmutable::now()->addDays(2)->setTime(10, 0));
        $afterFirst = $booking->fresh();

        try {
            $this->reschedule($afterFirst, CarbonImmutable::now()->addDays(3)->setTime(10, 0));
            $this->fail('Expected RescheduleLimitReachedException.');
        } catch (RescheduleLimitReachedException) {
            // expected
        }

        $unchanged = $booking->fresh();
        $this->assertTrue($unchanged->starts_at->equalTo($afterFirst->starts_at));
        $this->assertSame(1, $this->successfulRescheduleCount($unchanged));
    }

    // ── 18. Individual recurring occurrence count is isolated from siblings ─

    public function test_recurring_occurrence_reschedule_count_is_isolated_from_siblings(): void
    {
        $this->setLimit(1);
        $groupId = (string) Str::uuid();

        $occurrence1 = $this->bookingAt(CarbonImmutable::now()->addDays(1)->setTime(10, 0));
        $occurrence1->forceFill(['meta' => ['recurring_group' => $groupId]])->save();

        $occurrence2 = $this->bookingAt(CarbonImmutable::now()->addDays(8)->setTime(10, 0));
        $occurrence2->forceFill(['meta' => ['recurring_group' => $groupId]])->save();

        $this->reschedule($occurrence1, CarbonImmutable::now()->addDays(2)->setTime(10, 0));

        // occurrence1 is now exhausted, but occurrence2 (same recurring
        // group) must still have its own full allowance.
        $updatedOccurrence2 = $this->reschedule($occurrence2, CarbonImmutable::now()->addDays(9)->setTime(10, 0));

        $this->assertSame(1, $this->successfulRescheduleCount($occurrence1->fresh()));
        $this->assertSame(1, $this->successfulRescheduleCount($updatedOccurrence2));

        $this->expectException(RescheduleLimitReachedException::class);
        $this->reschedule($occurrence1->fresh(), CarbonImmutable::now()->addDays(3)->setTime(10, 0));
    }

    // ── 19. Free-demo behavior follows the same student limit ──────────────

    public function test_free_demo_reschedule_follows_the_same_configured_limit(): void
    {
        $demoType = BookingType::factory()->create(['key' => 'free_demo', 'is_paid' => false]);
        $this->setLimit(1);

        $booking = Booking::factory()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->teacher->id,
            'booking_type_id' => $demoType->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::NotRequired,
            'starts_at' => CarbonImmutable::now()->addDays(1)->setTime(10, 0),
            'ends_at' => CarbonImmutable::now()->addDays(1)->setTime(10, 30),
        ]);

        $this->reschedule($booking, CarbonImmutable::now()->addDays(2)->setTime(10, 0));

        $this->expectException(RescheduleLimitReachedException::class);
        $this->reschedule($booking->fresh(), CarbonImmutable::now()->addDays(3)->setTime(10, 0));
    }

    // ── 20/25. Paid booking follows the same limit; reschedule never mutates payment/wallet ─

    public function test_paid_booking_reschedule_follows_the_limit_and_never_mutates_payment_or_wallet(): void
    {
        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);

        $this->setLimit(1);

        $booking = Booking::factory()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->teacher->id,
            'booking_type_id' => $this->type->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::Paid,
            'starts_at' => CarbonImmutable::now()->addDays(3)->setTime(10, 0),
            'ends_at' => CarbonImmutable::now()->addDays(3)->setTime(10, 30),
            'price' => '499.00',
            'currency' => 'INR',
            'payment_reference' => 'PAY-RESCHEDULE-TEST',
        ]);

        BookingPayment::query()->create([
            'booking_id' => $booking->id,
            'user_id' => $this->student->id,
            'provider' => 'fake',
            'amount_minor' => 49900,
            'currency_code' => 'INR',
            'status' => 'captured',
            'idempotency_key' => 'PAY-RESCHEDULE-TEST',
        ]);

        $updated = $this->reschedule($booking, CarbonImmutable::now()->addDays(4)->setTime(10, 0));

        $this->assertSame(BookingPaymentStatus::Paid, $updated->payment_status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $this->student->id)->count());
        $this->assertNull(Wallet::query()->where('user_id', $this->student->id)->first());
        $this->assertSame('captured', BookingPayment::query()->where('booking_id', $booking->id)->sole()->status->value);

        $this->expectException(RescheduleLimitReachedException::class);
        $this->reschedule($updated, CarbonImmutable::now()->addDays(5)->setTime(10, 0));
    }

    // ── 21. Admin/instructor/system actor behavior matches the documented matrix ─

    public function test_admin_reschedule_is_exempt_from_the_student_limit(): void
    {
        $this->setLimit(1);
        $booking = $this->bookingAt(CarbonImmutable::now()->addDays(1)->setTime(10, 0));

        $this->reschedule($booking, CarbonImmutable::now()->addDays(2)->setTime(10, 0), BookingActor::Admin);
        $this->reschedule($booking->fresh(), CarbonImmutable::now()->addDays(3)->setTime(10, 0), BookingActor::Admin);
        $updated = $this->reschedule($booking->fresh(), CarbonImmutable::now()->addDays(4)->setTime(10, 0), BookingActor::Admin);

        $this->assertSame(3, $this->successfulRescheduleCount($updated));
    }

    public function test_instructor_reschedule_is_exempt_from_the_student_limit(): void
    {
        $this->setLimit(1);
        $booking = $this->bookingAt(CarbonImmutable::now()->addDays(1)->setTime(10, 0));

        $this->reschedule($booking, CarbonImmutable::now()->addDays(2)->setTime(10, 0), BookingActor::Instructor);
        $updated = $this->reschedule($booking->fresh(), CarbonImmutable::now()->addDays(3)->setTime(10, 0), BookingActor::Instructor);

        $this->assertSame(2, $this->successfulRescheduleCount($updated));
    }

    public function test_system_reschedule_is_exempt_from_the_student_limit(): void
    {
        $this->setLimit(1);
        $booking = $this->bookingAt(CarbonImmutable::now()->addDays(1)->setTime(10, 0));

        $this->reschedule($booking, CarbonImmutable::now()->addDays(2)->setTime(10, 0), BookingActor::System);
        $updated = $this->reschedule($booking->fresh(), CarbonImmutable::now()->addDays(3)->setTime(10, 0), BookingActor::System);

        $this->assertSame(2, $this->successfulRescheduleCount($updated));
    }

    // But once a student reschedule DOES consume the limit, a subsequent
    // ordinary student attempt is still governed normally even though an
    // admin/instructor reschedule happened in between.
    public function test_student_limit_still_applies_after_an_admin_reschedule_of_the_same_booking(): void
    {
        $this->setLimit(1);
        $booking = $this->bookingAt(CarbonImmutable::now()->addDays(1)->setTime(10, 0));

        // Admin reschedule does not consume the student's allowance.
        $this->reschedule($booking, CarbonImmutable::now()->addDays(2)->setTime(10, 0), BookingActor::Admin);

        $updated = $this->reschedule($booking->fresh(), CarbonImmutable::now()->addDays(3)->setTime(10, 0), BookingActor::Student);
        $this->assertSame(BookingStatus::Confirmed, $updated->status);

        $this->expectException(RescheduleLimitReachedException::class);
        $this->reschedule($updated, CarbonImmutable::now()->addDays(4)->setTime(10, 0), BookingActor::Student);
    }

    // ── 22. Reschedule activity contains sufficient audit evidence ─────────

    public function test_reschedule_activity_contains_sufficient_audit_evidence(): void
    {
        $this->setLimit(2);
        $booking = $this->bookingAt(CarbonImmutable::now()->addDays(1)->setTime(10, 0));
        $previousStartsAt = $booking->starts_at;

        $newStart = CarbonImmutable::now()->addDays(2)->setTime(10, 0);
        $this->reschedule($booking, $newStart, BookingActor::Student);

        $activity = BookingActivity::query()
            ->where('booking_id', $booking->id)
            ->where('action', BookingActivityAction::Rescheduled)
            ->sole();

        $this->assertSame(BookingActor::Student, $activity->actor_type);
        $this->assertSame($previousStartsAt->toIso8601String(), $activity->meta['previous_starts_at']);
        $this->assertSame(2, $activity->meta['reschedule_limit_applied']);
        $this->assertSame(1, $activity->meta['reschedule_successful_ordinal']);
        $this->assertFalse($activity->meta['reschedule_override_applied']);
        $this->assertSame('allowed', $activity->meta['reschedule_policy_code']);
    }

    // ── 26. Existing authorization and lifecycle restrictions remain intact ─

    public function test_completed_booking_cannot_be_rescheduled(): void
    {
        $this->setLimit(5);
        $booking = $this->bookingAt(CarbonImmutable::now()->subDays(1)->setTime(10, 0));
        $booking->forceFill(['status' => BookingStatus::Completed])->save();

        $this->expectException(InvalidStatusTransitionException::class);
        $this->reschedule($booking, CarbonImmutable::now()->addDays(2)->setTime(10, 0));
    }

    // ── 24. Reschedule followed by Phase 24C cancellation uses the final scheduled start ─

    public function test_reschedule_followed_by_cancellation_uses_the_final_scheduled_start(): void
    {
        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);

        $settings = app(BookingSettings::class);
        $settings->reschedule_limit = 2;
        $settings->cancellation_window_hours = 24;
        $settings->save();

        $originalStart = CarbonImmutable::now()->addHours(1);
        $newStart = CarbonImmutable::now()->addDays(3)->setTime(10, 0);

        $booking = Booking::factory()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->teacher->id,
            'booking_type_id' => $this->type->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::Paid,
            'starts_at' => $originalStart,
            'ends_at' => $originalStart->addMinutes(30),
            'price' => '499.00',
            'currency' => 'INR',
            'payment_reference' => 'PAY-RESCHEDULE-CANCEL-TEST',
        ]);

        BookingPayment::query()->create([
            'booking_id' => $booking->id,
            'user_id' => $this->student->id,
            'provider' => 'fake',
            'amount_minor' => 49900,
            'currency_code' => 'INR',
            'status' => 'captured',
            'idempotency_key' => 'PAY-RESCHEDULE-CANCEL-TEST',
        ]);

        // Cancelling relative to the ORIGINAL start (1h away) would be
        // late; relative to the rescheduled start (3 days away) it is
        // comfortably before the cutoff.
        $rescheduled = $this->reschedule($booking, $newStart);
        $this->assertTrue($rescheduled->starts_at->equalTo($newStart));

        app(BookingServiceInterface::class)->cancel($rescheduled, new CancelBookingData(BookingActor::Student, null));

        $wallet = Wallet::query()->where('user_id', $this->student->id)->where('currency_code', 'INR')->first();
        $this->assertNotNull($wallet, 'Refund should be eligible against the rescheduled start, not the original one.');
        $this->assertSame(49900, $wallet->balance_minor);

        // Cancellation must not alter the historical reschedule count.
        $this->assertSame(1, $this->successfulRescheduleCount($rescheduled->fresh()));
    }
}
