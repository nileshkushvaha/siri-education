<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Enums\InstructorStatus;
use App\Exceptions\Instructor\AvailabilityChangeRequiresConfirmationException;
use App\Livewire\Frontend\Instructor\AvailabilityManager;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\TeacherAvailability;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Instructor\InstructorAvailabilityService;
use App\Services\Instructor\InstructorTimeOffService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 24I — GAP-019/SRS-10-12/SRS §10.24: before an availability
 * reduction that would leave future CONFIRMED bookings outside the
 * instructor's effective schedule commits, the mutation services throw
 * a typed requires-confirmation result (mutating nothing); the caller
 * re-submits with the opaque impact fingerprint as explicit
 * acknowledgment; the fingerprint is recomputed under the instructor
 * lock so a stale acknowledgment (new booking, cancelled booking,
 * edited proposal, other window changes) never authorizes a materially
 * different change. Affected bookings are NEVER cancelled, moved, or
 * otherwise touched.
 */
class AvailabilityChangeImpactTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Frozen clock: a Monday. All window/booking fixtures are
        // expressed relative to this instant.
        Carbon::setTestNow('2026-07-20 08:00:00');

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        BookingType::factory()->create(['key' => 'free_demo', 'duration_minutes' => 30]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function instructor(string $timezone = 'UTC'): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('instructor');

        UserProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['instructor_status' => InstructorStatus::Approved, 'profile_visibility' => 'public', 'timezone' => $timezone],
        );

        return $user->refresh();
    }

    private function window(User $teacher, Weekday $day = Weekday::Monday, string $start = '09:00:00', string $end = '17:00:00', string $timezone = 'UTC'): TeacherAvailability
    {
        return TeacherAvailability::query()->create([
            'teacher_id' => $teacher->id,
            'day_of_week' => $day,
            'start_time' => $start,
            'end_time' => $end,
            'timezone' => $timezone,
            'is_active' => true,
            'created_by' => $teacher->id,
            'updated_by' => $teacher->id,
        ]);
    }

    private function confirmedBooking(User $teacher, CarbonImmutable $startsAt, int $minutes = 60, BookingStatus $status = BookingStatus::Confirmed): Booking
    {
        return Booking::factory()->create([
            'instructor_id' => $teacher->id,
            'status' => $status,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes($minutes),
        ]);
    }

    /** Next Monday 10:00 UTC — inside the default 09:00-17:00 Monday window. */
    private function nextMondayTen(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-07-27 10:00:00');
    }

    private function service(): InstructorAvailabilityService
    {
        return app(InstructorAvailabilityService::class);
    }

    private function capturedImpact(callable $mutation): AvailabilityChangeRequiresConfirmationException
    {
        try {
            $mutation();
            $this->fail('Expected AvailabilityChangeRequiresConfirmationException.');
        } catch (AvailabilityChangeRequiresConfirmationException $exception) {
            return $exception;
        }
    }

    // ── 1/2. No-impact mutations pass without warning ────────────────────────

    public function test_creating_availability_never_requires_confirmation(): void
    {
        $teacher = $this->instructor();

        $created = $this->service()->create([
            'teacher_id' => $teacher->id,
            'day_of_week' => Weekday::Tuesday,
            'start_time' => '09:00',
            'end_time' => '17:00',
        ], $teacher);

        $this->assertTrue($created->exists);
    }

    public function test_shortening_a_window_with_no_confirmed_booking_succeeds_without_warning(): void
    {
        $teacher = $this->instructor();
        $window = $this->window($teacher);

        $updated = $this->service()->update($window, ['end_time' => '12:00'], $teacher);

        $this->assertSame('12:00:00', $updated->end_time);
    }

    // ── 3/4/5/6. Two-step flow over a confirmed booking ─────────────────────

    public function test_shortening_over_a_confirmed_booking_requires_confirmation_and_first_submission_mutates_nothing(): void
    {
        $teacher = $this->instructor();
        $window = $this->window($teacher);
        $booking = $this->confirmedBooking($teacher, $this->nextMondayTen());

        $exception = $this->capturedImpact(fn () => $this->service()->update($window, ['start_time' => '11:00'], $teacher));

        $this->assertSame(1, $exception->impact->affectedCount);
        $this->assertSame([$booking->id], $exception->impact->affectedBookingIds);
        $this->assertSame('09:00:00', $window->fresh()->start_time, 'First submission must not mutate availability.');
        $this->assertNull(Activity::query()->where('log_name', 'teacher_availability')->where('event', 'updated')->first(), 'Warning-only preview must not create a success audit.');
    }

    public function test_valid_confirmation_applies_the_mutation_and_leaves_the_booking_unchanged(): void
    {
        $teacher = $this->instructor();
        $window = $this->window($teacher);
        $booking = $this->confirmedBooking($teacher, $this->nextMondayTen());
        $originalBooking = $booking->fresh()->getAttributes();

        $exception = $this->capturedImpact(fn () => $this->service()->update($window, ['start_time' => '11:00'], $teacher));

        $updated = $this->service()->update($window, ['start_time' => '11:00'], $teacher, $exception->impact->fingerprint);

        $this->assertSame('11:00:00', $updated->start_time);
        $this->assertSame($originalBooking, $booking->fresh()->getAttributes(), 'The affected booking must remain byte-for-byte unchanged.');
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }

    // ── 7/8/9. Delete / deactivate / move all gate ───────────────────────────

    public function test_deleting_an_affecting_window_requires_confirmation_then_applies(): void
    {
        $teacher = $this->instructor();
        $window = $this->window($teacher);
        $this->confirmedBooking($teacher, $this->nextMondayTen());

        $exception = $this->capturedImpact(fn () => $this->service()->delete($window, $teacher));
        $this->assertTrue($window->fresh()->exists);

        $this->service()->delete($window, $teacher, $exception->impact->fingerprint);
        $this->assertNull($window->fresh());
    }

    public function test_deactivating_an_affecting_window_requires_confirmation(): void
    {
        $teacher = $this->instructor();
        $window = $this->window($teacher);
        $this->confirmedBooking($teacher, $this->nextMondayTen());

        $this->capturedImpact(fn () => $this->service()->setActive($window, false, $teacher));

        $this->assertTrue($window->fresh()->is_active);
    }

    public function test_moving_the_weekday_requires_confirmation(): void
    {
        $teacher = $this->instructor();
        $window = $this->window($teacher);
        $this->confirmedBooking($teacher, $this->nextMondayTen());

        $exception = $this->capturedImpact(fn () => $this->service()->update($window, ['day_of_week' => Weekday::Wednesday], $teacher));

        $this->assertSame(1, $exception->impact->affectedCount);
    }

    // ── 10. Another window still covering the booking → no false warning ────

    public function test_no_warning_when_another_active_window_still_covers_the_booking(): void
    {
        $teacher = $this->instructor();
        $morning = $this->window($teacher, Weekday::Monday, '09:00:00', '12:00:00');
        $this->window($teacher, Weekday::Monday, '12:00:00', '17:00:00');
        // 10:00-11:00 booking is covered by BOTH the morning window and… no —
        // by the morning window only; deleting the AFTERNOON window is safe.
        $this->confirmedBooking($teacher, $this->nextMondayTen());

        $afternoon = TeacherAvailability::query()
            ->forTeacher($teacher->id)
            ->where('start_time', '12:00:00')
            ->firstOrFail();

        // Deleting the afternoon window leaves the booking covered → no warning.
        $this->service()->delete($afternoon, $teacher);
        $this->assertNull($afternoon->fresh());

        // Deleting the morning window WOULD strand it → warning.
        $this->capturedImpact(fn () => $this->service()->delete($morning, $teacher));
    }

    // ── 11. Non-committed bookings never trigger the warning ─────────────────

    public function test_cancelled_completed_and_past_bookings_do_not_trigger_the_warning(): void
    {
        $teacher = $this->instructor();
        $window = $this->window($teacher);

        $this->confirmedBooking($teacher, $this->nextMondayTen(), status: BookingStatus::Cancelled);
        $this->confirmedBooking($teacher, $this->nextMondayTen()->addHours(2), status: BookingStatus::Completed);
        $this->confirmedBooking($teacher, $this->nextMondayTen()->addHours(4), status: BookingStatus::Pending);
        // A past confirmed booking (last Monday) is historical.
        $this->confirmedBooking($teacher, CarbonImmutable::parse('2026-07-13 10:00:00'));

        $deleted = false;
        $this->service()->delete($window, $teacher);
        $deleted = true;

        $this->assertTrue($deleted, 'No warning expected — none of these bookings are future confirmed lessons.');
    }

    // ── 12. Recurring occurrences evaluated independently ────────────────────

    public function test_each_recurring_occurrence_is_evaluated_independently(): void
    {
        $teacher = $this->instructor();
        $window = $this->window($teacher);
        // Two weekly occurrences of a recurring series — separate rows.
        $this->confirmedBooking($teacher, $this->nextMondayTen());
        $this->confirmedBooking($teacher, $this->nextMondayTen()->addWeek());

        $exception = $this->capturedImpact(fn () => $this->service()->delete($window, $teacher));

        $this->assertSame(2, $exception->impact->affectedCount);
    }

    // ── 13. Time-off overlap requires confirmation ───────────────────────────

    public function test_time_off_over_a_confirmed_booking_requires_confirmation_and_preserves_it(): void
    {
        $teacher = $this->instructor();
        $this->window($teacher);
        $booking = $this->confirmedBooking($teacher, $this->nextMondayTen());
        $originalBooking = $booking->fresh()->getAttributes();

        $timeOff = app(InstructorTimeOffService::class);
        $data = [
            'teacher_id' => $teacher->id,
            'starts_at' => '2026-07-27 09:00:00',
            'ends_at' => '2026-07-27 18:00:00',
        ];

        $exception = $this->capturedImpact(fn () => $timeOff->create($data, $teacher));
        $this->assertSame(1, $exception->impact->affectedCount);
        $this->assertDatabaseCount('teacher_unavailability', 0);

        $leave = $timeOff->create($data, $teacher, $exception->impact->fingerprint);
        $this->assertTrue($leave->exists);
        $this->assertSame($originalBooking, $booking->fresh()->getAttributes());
    }

    public function test_time_off_not_overlapping_any_booking_needs_no_confirmation(): void
    {
        $teacher = $this->instructor();
        $this->window($teacher);
        $this->confirmedBooking($teacher, $this->nextMondayTen());

        $leave = app(InstructorTimeOffService::class)->create([
            'teacher_id' => $teacher->id,
            'starts_at' => '2026-07-29 09:00:00',
            'ends_at' => '2026-07-29 18:00:00',
        ], $teacher);

        $this->assertTrue($leave->exists);
    }

    // ── 17/18/19. Stale fingerprints ─────────────────────────────────────────

    public function test_a_new_confirmed_booking_after_preview_invalidates_the_fingerprint(): void
    {
        $teacher = $this->instructor();
        $window = $this->window($teacher);
        $this->confirmedBooking($teacher, $this->nextMondayTen());

        $exception = $this->capturedImpact(fn () => $this->service()->delete($window, $teacher));
        $staleToken = $exception->impact->fingerprint;

        // A second confirmed lesson lands after the preview.
        $this->confirmedBooking($teacher, $this->nextMondayTen()->addHours(3));

        $refreshed = $this->capturedImpact(fn () => $this->service()->delete($window, $teacher, $staleToken));

        $this->assertTrue($window->fresh()->exists, 'A stale acknowledgment must not authorize the mutation.');
        $this->assertSame(2, $refreshed->impact->affectedCount, 'The refreshed warning reflects the new impact.');
        $this->assertNotSame($staleToken, $refreshed->impact->fingerprint);
    }

    public function test_a_cancelled_booking_after_preview_refreshes_the_impact(): void
    {
        $teacher = $this->instructor();
        $window = $this->window($teacher);
        $booking = $this->confirmedBooking($teacher, $this->nextMondayTen());

        $exception = $this->capturedImpact(fn () => $this->service()->delete($window, $teacher));
        $staleToken = $exception->impact->fingerprint;

        $booking->update(['status' => BookingStatus::Cancelled]);

        // Impact is now empty — the mutation proceeds regardless of the
        // stale token, since no confirmation is required at all.
        $this->service()->delete($window, $teacher, $staleToken);
        $this->assertNull($window->fresh());
    }

    public function test_changed_proposed_values_invalidate_a_prior_acknowledgment(): void
    {
        $teacher = $this->instructor();
        $window = $this->window($teacher);
        $this->confirmedBooking($teacher, $this->nextMondayTen());

        $exception = $this->capturedImpact(fn () => $this->service()->update($window, ['start_time' => '11:00'], $teacher));
        $tokenForElevenAm = $exception->impact->fingerprint;

        // The instructor edits the proposal to a different reduction.
        $this->capturedImpact(fn () => $this->service()->update($window, ['start_time' => '12:00'], $teacher, $tokenForElevenAm));

        $this->assertSame('09:00:00', $window->fresh()->start_time);
    }

    // ── 20/22. Bypass and authorization ──────────────────────────────────────

    public function test_direct_service_invocation_cannot_bypass_confirmation(): void
    {
        $teacher = $this->instructor();
        $window = $this->window($teacher);
        $this->confirmedBooking($teacher, $this->nextMondayTen());

        $this->expectException(AvailabilityChangeRequiresConfirmationException::class);

        $this->service()->delete($window, $teacher, 'forged-or-guessed-token');
    }

    public function test_unauthorized_actor_cannot_mutate_another_instructors_availability(): void
    {
        $teacher = $this->instructor();
        $window = $this->window($teacher);
        $other = $this->instructor();

        $this->expectException(AuthorizationException::class);

        $this->service()->delete($window, $other);
    }

    // ── 24. Duplicate confirmation does not duplicate mutation/audit ─────────

    public function test_duplicate_delete_confirmation_does_not_duplicate_mutation_or_audit(): void
    {
        $teacher = $this->instructor();
        $window = $this->window($teacher);
        $this->confirmedBooking($teacher, $this->nextMondayTen());

        $exception = $this->capturedImpact(fn () => $this->service()->delete($window, $teacher));
        $this->service()->delete($window, $teacher, $exception->impact->fingerprint);

        try {
            $this->service()->delete($window->fresh() ?? $window, $teacher, $exception->impact->fingerprint);
        } catch (\Throwable) {
            // A second submission fails safely (the row is gone).
        }

        // The model's own Spatie LogsActivity auto-log also writes a
        // 'deleted' row per real deletion (pre-existing convention);
        // count only the SERVICE's governed audit records here.
        $this->assertSame(
            1,
            Activity::query()
                ->where('log_name', 'teacher_availability')
                ->where('description', 'Instructor availability window deleted.')
                ->count(),
            'Exactly one deletion audit record.',
        );
    }

    // ── 26. Audit content ────────────────────────────────────────────────────

    public function test_acknowledged_change_is_audited_with_safe_impact_properties(): void
    {
        $teacher = $this->instructor();
        $window = $this->window($teacher);
        $booking = $this->confirmedBooking($teacher, $this->nextMondayTen());

        $exception = $this->capturedImpact(fn () => $this->service()->update($window, ['start_time' => '11:00'], $teacher));
        $this->service()->update($window, ['start_time' => '11:00'], $teacher, $exception->impact->fingerprint);

        $activity = Activity::query()->where('log_name', 'teacher_availability')->where('event', 'updated')->latest('id')->firstOrFail();

        $this->assertSame(1, $activity->properties['affected_booking_count']);
        $this->assertTrue($activity->properties['had_affected_bookings']);
        $this->assertTrue($activity->properties['impact_acknowledged']);
        $this->assertNotNull($activity->properties['impact_fingerprint']);
        $this->assertSame('UTC', $activity->properties['current']['timezone']);

        $serialized = (string) json_encode($activity->properties);
        $this->assertStringNotContainsString($booking->student->name ?? '@@none@@', $serialized);
        $this->assertStringNotContainsString($exception->impact->fingerprint, $serialized, 'Only a fingerprint PREFIX is stored — never the raw acknowledgment token.');
    }

    // ── 28. Timezone behavior matches existing availability semantics ────────

    public function test_instructor_timezone_windows_are_evaluated_correctly(): void
    {
        $teacher = $this->instructor('America/New_York');
        // Monday 09:00-17:00 New York = 13:00-21:00 UTC (EDT, July).
        $window = $this->window($teacher, Weekday::Monday, '09:00:00', '17:00:00', 'America/New_York');
        // Booking Monday 14:00 UTC = 10:00 New York — inside the window.
        $booking = $this->confirmedBooking($teacher, CarbonImmutable::parse('2026-07-27 14:00:00'));

        // Shortening to start at 11:00 New York (15:00 UTC) strands the 10:00 NY lesson.
        $exception = $this->capturedImpact(fn () => $this->service()->update($window, ['start_time' => '11:00'], $teacher));

        $this->assertSame([$booking->id], $exception->impact->affectedBookingIds);
        // Summaries render in the instructor's timezone.
        $this->assertStringContainsString('10:00 AM', $exception->impact->affectedSummaries[0]['starts_at']);
    }

    // ── Boundary: booking exactly at window edges ────────────────────────────

    public function test_booking_exactly_filling_the_window_is_affected_by_any_shortening(): void
    {
        $teacher = $this->instructor();
        $window = $this->window($teacher, Weekday::Monday, '10:00:00', '11:00:00');
        $this->confirmedBooking($teacher, $this->nextMondayTen(), minutes: 60);

        // The 10:00-11:00 booking exactly fills the window — currently covered.
        $this->capturedImpact(fn () => $this->service()->update($window, ['end_time' => '10:30'], $teacher));
        $this->assertSame('11:00:00', $window->fresh()->end_time);
    }

    // ── 15/16. Livewire warning panel ────────────────────────────────────────

    public function test_livewire_delete_shows_accessible_warning_and_cancel_leaves_availability_unchanged(): void
    {
        Permission::firstOrCreate(['name' => 'Delete:TeacherAvailability', 'guard_name' => 'web']);
        $teacher = $this->instructor();
        $window = $this->window($teacher);
        $this->confirmedBooking($teacher, $this->nextMondayTen());

        $component = Livewire::actingAs($teacher)
            ->test(AvailabilityManager::class)
            ->call('deleteWindow', $window->id)
            ->assertSee('confirmed upcoming lesson')
            ->assertSee('remain scheduled and unchanged');

        $this->assertTrue($window->fresh()->exists, 'First submission must not delete.');

        $component->call('cancelPendingImpact');
        $this->assertTrue($window->fresh()->exists);
        $this->assertNull($component->get('pendingImpact'));
    }

    public function test_livewire_confirm_applies_the_pending_deletion(): void
    {
        $teacher = $this->instructor();
        $window = $this->window($teacher);
        $this->confirmedBooking($teacher, $this->nextMondayTen());

        Livewire::actingAs($teacher)
            ->test(AvailabilityManager::class)
            ->call('deleteWindow', $window->id)
            ->call('confirmPendingImpact');

        $this->assertNull($window->fresh());
    }
}
