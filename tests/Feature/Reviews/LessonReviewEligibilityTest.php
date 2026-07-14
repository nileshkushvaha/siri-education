<?php

declare(strict_types=1);

namespace Tests\Feature\Reviews;

use App\Booking\Enums\BookingPaymentStatus;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Lesson;
use App\Models\LessonReviewEligibility;
use App\Models\User;
use App\Notifications\Reviews\ReviewPublishedStudentNotification;
use App\Notifications\Reviews\ReviewRequestedNotification;
use App\Notifications\Reviews\ReviewSubmittedNotification;
use App\Reviews\Contracts\ReviewEligibilityServiceInterface;
use App\Reviews\Enums\LessonReviewEligibilityMode;
use App\Reviews\Enums\LessonReviewEligibilityStatus;
use App\Reviews\Enums\ReviewableLessonType;
use App\Settings\ReviewSettings;
use Carbon\CarbonImmutable;
use Database\Seeders\LessonPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 17H — verified student review eligibility: the SRS
 * creation matrix (paid/demo × policy), idempotency, outcome-override
 * revoke/manual-review/restore, expiration, authorization, and the
 * guarantee that nothing outside the eligibility table ever changes.
 */
class LessonReviewEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private LessonLifecycleServiceInterface $lifecycle;

    private LessonOutcomeServiceInterface $outcomes;

    private ReviewEligibilityServiceInterface $eligibility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lifecycle = app(LessonLifecycleServiceInterface::class);
        $this->outcomes = app(LessonOutcomeServiceInterface::class);
        $this->eligibility = app(ReviewEligibilityServiceInterface::class);

        $this->enableReviews();
    }

    // ── 1–4. Creation matrix ─────────────────────────────────────────

    public function test_completed_paid_lesson_creates_public_review_eligibility(): void
    {
        $lesson = $this->paidLesson();
        $this->outcomes->finalize($lesson, LessonOutcome::Completed);

        $record = $this->eligibilityFor($lesson);

        $this->assertSame(ReviewableLessonType::Paid, $record->lesson_type);
        $this->assertSame(LessonReviewEligibilityMode::PublicReview, $record->eligibility_mode);
        $this->assertSame(LessonReviewEligibilityStatus::Open, $record->status);
        $this->assertSame($lesson->student_id, $record->student_id);
        $this->assertSame($lesson->instructor_id, $record->instructor_id);
        $this->assertSame($lesson->booking_id, $record->booking_id);
        $this->assertSame('completed', $record->outcome_snapshot);
        $this->assertSame(1, $record->source_outcome_version);
        $this->assertSame(1, $record->version);
    }

    public function test_completed_demo_creates_private_only_eligibility_by_default(): void
    {
        $lesson = $this->demoLesson();
        $this->outcomes->finalize($lesson, LessonOutcome::Completed);

        $record = $this->eligibilityFor($lesson);

        $this->assertSame(ReviewableLessonType::Demo, $record->lesson_type);
        $this->assertSame(LessonReviewEligibilityMode::PrivateFeedback, $record->eligibility_mode);
        $this->assertSame(LessonReviewEligibilityStatus::Open, $record->status);
    }

    public function test_demo_policy_disabled_creates_no_eligibility(): void
    {
        $this->enableReviews(['demo_review_policy' => 'disabled']);
        $lesson = $this->demoLesson();

        $this->outcomes->finalize($lesson, LessonOutcome::Completed);

        $this->assertNull($this->eligibilityForOrNull($lesson));
    }

    public function test_demo_public_policy_creates_public_eligibility(): void
    {
        $this->enableReviews(['demo_review_policy' => 'public']);
        $lesson = $this->demoLesson();

        $this->outcomes->finalize($lesson, LessonOutcome::Completed);

        $this->assertSame(LessonReviewEligibilityMode::PublicReview, $this->eligibilityFor($lesson)->eligibility_mode);
    }

    // ── 5–9. Non-completed outcomes ──────────────────────────────────

    public function test_student_no_show_creates_no_standard_eligibility(): void
    {
        $lesson = $this->paidLesson();
        $this->outcomes->finalize($lesson, LessonOutcome::StudentNoShow);

        $this->assertNull($this->eligibilityForOrNull($lesson));
    }

    public function test_instructor_no_show_creates_no_standard_eligibility(): void
    {
        $lesson = $this->paidLesson();
        $this->outcomes->finalize($lesson, LessonOutcome::InstructorNoShow);

        $this->assertNull($this->eligibilityForOrNull($lesson));
    }

    public function test_both_absent_creates_no_eligibility(): void
    {
        $lesson = $this->paidLesson();
        $this->outcomes->finalize($lesson, LessonOutcome::BothAbsent);

        $this->assertNull($this->eligibilityForOrNull($lesson));
    }

    public function test_technical_issue_creates_no_eligibility(): void
    {
        $lesson = $this->paidLesson();
        $this->outcomes->finalize($lesson, LessonOutcome::TechnicalIssue);

        $this->assertNull($this->eligibilityForOrNull($lesson));
    }

    public function test_cancelled_lesson_creates_no_eligibility(): void
    {
        $lesson = $this->paidLesson();
        $this->outcomes->finalize($lesson, LessonOutcome::Cancelled);

        $this->assertNull($this->eligibilityForOrNull($lesson));
    }

    // ── 10. Kill switch ───────────────────────────────────────────────

    public function test_reviews_disabled_setting_creates_no_eligibility(): void
    {
        $this->enableReviews(['reviews_enabled' => false]);
        $lesson = $this->paidLesson();

        $this->outcomes->finalize($lesson, LessonOutcome::Completed);

        $this->assertNull($this->eligibilityForOrNull($lesson));
    }

    // ── 11. Review window timing ─────────────────────────────────────

    public function test_review_window_uses_lesson_completion_time_not_command_time(): void
    {
        $t0 = CarbonImmutable::parse('2026-01-10 12:00:00', 'UTC');
        CarbonImmutable::setTestNow($t0);

        $lesson = $this->paidLesson();
        $this->outcomes->finalize($lesson, LessonOutcome::Completed);

        // Time moves on well past when a sweep/command might later run.
        CarbonImmutable::setTestNow($t0->addDays(5));

        $record = $this->eligibilityFor($lesson);

        $this->assertTrue($record->opens_at->equalTo($t0));
        $this->assertTrue($record->expires_at->equalTo($t0->addDays(14)));

        CarbonImmutable::setTestNow();
    }

    // ── 12–13. Idempotency ────────────────────────────────────────────

    public function test_duplicate_outcome_events_create_one_record(): void
    {
        $lesson = $this->paidLesson();
        $this->outcomes->finalize($lesson, LessonOutcome::Completed);

        // A replayed finalize event reaches the service a second time.
        $this->eligibility->handleOutcomeFinalized($lesson->refresh(), LessonOutcome::Completed);
        $this->eligibility->handleOutcomeFinalized($lesson->refresh(), LessonOutcome::Completed);

        $this->assertSame(1, LessonReviewEligibility::query()->where('lesson_id', $lesson->id)->count());
    }

    public function test_concurrent_processing_creates_one_record(): void
    {
        $lesson = $this->paidLesson();

        // Simulates another worker having already inserted the row
        // between this worker's existence-check and its own insert.
        LessonReviewEligibility::factory()->create([
            'lesson_id' => $lesson->id,
            'booking_id' => $lesson->booking_id,
            'student_id' => $lesson->student_id,
            'instructor_id' => $lesson->instructor_id,
        ]);

        $this->outcomes->finalize($lesson->refresh(), LessonOutcome::Completed);

        $this->assertSame(1, LessonReviewEligibility::query()->where('lesson_id', $lesson->id)->count());
    }

    // ── 14–16. Outcome overrides ──────────────────────────────────────

    public function test_completed_to_cancelled_override_revokes_unused_eligibility(): void
    {
        $lesson = $this->paidLesson();
        $admin = $this->admin();
        $this->outcomes->finalize($lesson, LessonOutcome::Completed);
        $record = $this->eligibilityFor($lesson);

        $this->outcomes->override($lesson->refresh(), $admin, LessonOutcome::Cancelled, 'Booking cancelled after the fact.');

        $record->refresh();
        $this->assertSame(LessonReviewEligibilityStatus::Revoked, $record->status);
        $this->assertNotNull($record->revoked_at);
        $this->assertSame('Booking cancelled after the fact.', $record->revoked_reason);
        $this->assertSame(2, $record->version);
        // History preserved, never deleted.
        $this->assertCount(1, $record->history);
        $this->assertSame('open', $record->history[0]['status']);
        $this->assertDatabaseCount('lesson_review_eligibilities', 1);
    }

    public function test_completed_to_non_completed_override_marks_used_eligibility_for_manual_review(): void
    {
        $lesson = $this->paidLesson();
        $admin = $this->admin();
        $this->outcomes->finalize($lesson, LessonOutcome::Completed);
        $record = $this->eligibilityFor($lesson);

        // Simulate a review having already been submitted (Phase 17H
        // does not implement submission — this proves the safety rule
        // regardless of how the record reached Used).
        $record->forceFill(['status' => LessonReviewEligibilityStatus::Used, 'used_at' => now()])->save();

        $this->outcomes->override($lesson->refresh(), $admin, LessonOutcome::StudentNoShow, 'Attendance evidence corrected.');

        $record->refresh();
        $this->assertSame(LessonReviewEligibilityStatus::ManualReview, $record->status);
        // A used record is never revoked and its used_at is preserved —
        // a submitted review must never be silently hidden.
        $this->assertNotNull($record->used_at);
        $this->assertNull($record->revoked_at);
    }

    public function test_non_completed_to_completed_override_creates_eligibility(): void
    {
        $lesson = $this->paidLesson();
        $admin = $this->admin();
        $this->outcomes->finalize($lesson, LessonOutcome::StudentNoShow);
        $this->assertNull($this->eligibilityForOrNull($lesson));

        $this->outcomes->override($lesson->refresh(), $admin, LessonOutcome::Completed, 'Instructor proved delivery.');

        $record = $this->eligibilityFor($lesson);
        $this->assertSame(LessonReviewEligibilityStatus::Open, $record->status);
        $this->assertSame(1, LessonReviewEligibility::query()->where('lesson_id', $lesson->id)->count());
    }

    public function test_revoked_eligibility_is_restored_on_correction_back_to_completed(): void
    {
        $lesson = $this->paidLesson();
        $admin = $this->admin();
        $this->outcomes->finalize($lesson, LessonOutcome::Completed);
        $record = $this->eligibilityFor($lesson);

        $this->outcomes->override($lesson->refresh(), $admin, LessonOutcome::Cancelled, 'First correction.');
        $this->assertSame(LessonReviewEligibilityStatus::Revoked, $record->refresh()->status);

        $this->outcomes->override($lesson->refresh(), $admin, LessonOutcome::Completed, 'Reversed again — cancellation was itself wrong.');

        $record->refresh();
        $this->assertSame(LessonReviewEligibilityStatus::Open, $record->status);
        $this->assertNull($record->revoked_at);
        $this->assertNull($record->revoked_reason);
        $this->assertSame(3, $record->version);
        // Still exactly one row — restored, never duplicated.
        $this->assertSame(1, LessonReviewEligibility::query()->where('lesson_id', $lesson->id)->count());
    }

    // ── 17. Policy snapshot immutability ──────────────────────────────

    public function test_existing_eligibility_preserves_its_original_policy_snapshot(): void
    {
        $lesson = $this->paidLesson();
        $this->outcomes->finalize($lesson, LessonOutcome::Completed);
        $record = $this->eligibilityFor($lesson);

        $originalExpiresAt = $record->expires_at;
        $this->assertSame(14, $record->metadata['review_window_days']);

        // Settings change AFTER the window opened — must never retroactively
        // alter an already-open eligibility.
        $this->enableReviews(['review_window_days' => 30, 'paid_lesson_reviews_enabled' => false]);

        $record->refresh();
        $this->assertSame(14, $record->metadata['review_window_days']);
        $this->assertTrue($record->expires_at->equalTo($originalExpiresAt));
        $this->assertSame(LessonReviewEligibilityStatus::Open, $record->status);
    }

    // ── 18–19. Expiration ──────────────────────────────────────────────

    public function test_expiration_command_expires_overdue_open_eligibility(): void
    {
        $lesson = $this->paidLesson();
        $this->outcomes->finalize($lesson, LessonOutcome::Completed);
        $record = $this->eligibilityFor($lesson);
        $record->forceFill(['expires_at' => now()->subDay()])->save();

        $this->artisan('reviews:expire-eligibility')
            ->expectsOutputToContain('Expired 1 review eligibility window(s).')->assertSuccessful();
        $this->artisan('reviews:expire-eligibility')
            ->expectsOutputToContain('Expired 0 review eligibility window(s).')->assertSuccessful();

        $record->refresh();
        $this->assertSame(LessonReviewEligibilityStatus::Expired, $record->status);
        $this->assertSame(2, $record->version);
    }

    public function test_expiration_command_does_not_change_used_or_revoked_records(): void
    {
        $used = LessonReviewEligibility::factory()->used()->create(['expires_at' => now()->subDay()]);
        $revoked = LessonReviewEligibility::factory()->revoked()->create(['expires_at' => now()->subDay()]);
        $stillOpen = LessonReviewEligibility::factory()->create(['expires_at' => now()->addDay()]);

        app(ReviewEligibilityServiceInterface::class)->expireDue();

        $this->assertSame(LessonReviewEligibilityStatus::Used, $used->refresh()->status);
        $this->assertSame(LessonReviewEligibilityStatus::Revoked, $revoked->refresh()->status);
        $this->assertSame(LessonReviewEligibilityStatus::Open, $stillOpen->refresh()->status);
    }

    // ── 20–21. Authorization ────────────────────────────────────────────

    public function test_student_can_access_only_their_own_eligibility(): void
    {
        $lesson = $this->paidLesson();
        $this->outcomes->finalize($lesson, LessonOutcome::Completed);
        $record = $this->eligibilityFor($lesson);

        $owner = $lesson->student;
        $stranger = User::factory()->create();

        $this->assertTrue($owner->can('view', $record));
        $this->assertFalse($stranger->can('view', $record));
    }

    public function test_instructor_cannot_view_or_modify_eligibility(): void
    {
        $lesson = $this->paidLesson();
        $this->outcomes->finalize($lesson, LessonOutcome::Completed);
        $record = $this->eligibilityFor($lesson);

        $instructor = $lesson->instructor;

        $this->assertFalse($instructor->can('view', $record));
        $this->assertFalse($instructor->can('viewAny', LessonReviewEligibility::class));
        // No create/update/delete ability exists at all — undefined
        // abilities deny by default.
        $this->assertFalse($instructor->can('update', $record));
        $this->assertFalse($instructor->can('delete', $record));
    }

    // ── 22–24. No side effects ───────────────────────────────────────

    public function test_no_review_rating_or_public_aggregate_is_created(): void
    {
        $lesson = $this->paidLesson();
        $this->outcomes->finalize($lesson, LessonOutcome::Completed);

        foreach (['reviews', 'ratings', 'instructor_review_aggregates'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Phase 17H must not create a {$table} table.");
        }

        $this->assertDatabaseCount('lesson_review_eligibilities', 1);
    }

    public function test_no_notification_is_sent(): void
    {
        Notification::fake();

        // Exercised directly against the eligibility service (open,
        // revoke-on-override, expire) — isolating the Reviews domain
        // from the pre-existing lesson/booking-completion notification
        // pipeline, which is unrelated to this phase. Phase 17S later
        // attaches ReviewRequestedNotification to the eligibility-opened
        // event specifically — that is the one expected exception here.
        $lesson = $this->paidLesson()->refresh();
        $this->eligibility->handleOutcomeFinalized($lesson, LessonOutcome::Completed);
        $this->eligibility->handleOutcomeOverridden($lesson->refresh(), LessonOutcome::Completed, LessonOutcome::Cancelled, 'Corrected.');
        $this->eligibilityFor($lesson)->forceFill(['expires_at' => now()->subDay(), 'status' => LessonReviewEligibilityStatus::Open])->save();
        $this->artisan('reviews:expire-eligibility');

        Notification::assertSentToTimes($this->eligibilityFor($lesson)->student, ReviewRequestedNotification::class, 1);
        Notification::assertNotSentTo($this->eligibilityFor($lesson)->student, ReviewSubmittedNotification::class);
        Notification::assertNotSentTo($this->eligibilityFor($lesson)->student, ReviewPublishedStudentNotification::class);
    }

    public function test_no_wallet_payment_earning_or_settlement_record_changes(): void
    {
        $lesson = $this->paidLesson();
        $this->outcomes->finalize($lesson, LessonOutcome::Completed);

        $this->assertSame(0, DB::table('wallets')->count());
        $this->assertSame(0, DB::table('wallet_ledger_entries')->count());
        $this->assertSame(0, DB::table('instructor_earnings')->count());
        $this->assertSame(0, DB::table('instructor_settlement_batches')->count());
        $this->assertSame(BookingPaymentStatus::Paid, $lesson->booking->refresh()->payment_status);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function paidLesson(): Lesson
    {
        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'booking_type_id' => BookingType::factory()->paid(),
            'starts_at' => $endsAt->copy()->subMinutes(60),
            'ends_at' => $endsAt,
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => '499.00',
            'currency' => 'INR',
        ]);

        return $this->lifecycle->createFromBooking($booking);
    }

    private function demoLesson(): Lesson
    {
        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'starts_at' => $endsAt->copy()->subMinutes(60),
            'ends_at' => $endsAt,
            'payment_status' => BookingPaymentStatus::NotRequired,
            'price' => null,
            'currency' => null,
        ]);

        return $this->lifecycle->createFromBooking($booking);
    }

    /** @param array<string, mixed> $overrides */
    private function enableReviews(array $overrides = []): void
    {
        $settings = app(ReviewSettings::class);
        $settings->reviews_enabled = true;
        $settings->paid_lesson_reviews_enabled = true;
        $settings->demo_review_policy = 'private_only';
        $settings->review_window_days = 14;

        foreach ($overrides as $key => $value) {
            $settings->{$key} = $value;
        }

        $settings->save();
    }

    private function eligibilityFor(Lesson $lesson): LessonReviewEligibility
    {
        return LessonReviewEligibility::query()->where('lesson_id', $lesson->id)->firstOrFail();
    }

    private function eligibilityForOrNull(Lesson $lesson): ?LessonReviewEligibility
    {
        return LessonReviewEligibility::query()->where('lesson_id', $lesson->id)->first();
    }

    private function admin(): User
    {
        $this->seed(LessonPermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('manager');

        return $admin;
    }
}
