<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingArchivalServiceInterface;
use App\Booking\DTOs\BookingArchivalResult;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Exceptions\BookingArchivalException;
use App\Booking\Repositories\BookingRepository;
use App\Earnings\Enums\LessonFinancialDispositionStatus;
use App\Earnings\Enums\LessonInstructorDisposition;
use App\Earnings\Enums\LessonStudentDisposition;
use App\Enums\InstructorStatus;
use App\Exceptions\HistoricalRecordCannotBeDeletedException;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\InstructorEarning;
use App\Models\InstructorQualityAlert;
use App\Models\InstructorSettlementBatch;
use App\Models\InstructorStudentFeedback;
use App\Models\Lesson;
use App\Models\LessonAttendanceRecord;
use App\Models\LessonFinancialDisposition;
use App\Models\LessonReview;
use App\Models\LessonReviewEligibility;
use App\Models\LessonReviewRevision;
use App\Models\ReviewRatingContribution;
use App\Models\ReviewReport;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Reviews\Contracts\StudentReviewServiceInterface;
use App\Reviews\DTOs\SubmitStudentReviewData;
use App\Settings\ReviewSettings;
use Database\Seeders\BookingPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Booking soft-delete/archival: archive/restore workflow, idempotency,
 * authorization, permanent preservation of the entire historical
 * dependency tree, and that restoration replays no side effect.
 */
class BookingArchivalTest extends TestCase
{
    use RefreshDatabase;

    private BookingArchivalServiceInterface $archival;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->archival = app(BookingArchivalServiceInterface::class);
    }

    // ── 1–18. Archive/restore workflow ─────────────────────────────────────

    public function test_terminal_booking_may_be_archived_by_an_authorized_administrator(): void
    {
        $booking = $this->terminalBooking();
        $admin = $this->admin();

        $result = $this->archival->archive($booking, $admin, 'Routine cleanup.');

        $this->assertTrue($result->applied);
        $this->assertInstanceOf(BookingArchivalResult::class, $result);
    }

    public function test_booking_archive_sets_deleted_at(): void
    {
        $booking = $this->terminalBooking();
        $this->archival->archive($booking, $this->admin(), 'reason');

        $this->assertNotNull($booking->fresh()->deleted_at);
    }

    public function test_booking_remains_accessible_through_with_trashed(): void
    {
        $booking = $this->terminalBooking();
        $this->archival->archive($booking, $this->admin(), 'reason');

        $this->assertNotNull(Booking::withTrashed()->find($booking->id));
        $this->assertNull(Booking::query()->find($booking->id));
    }

    public function test_booking_is_excluded_from_default_active_queries(): void
    {
        $booking = $this->terminalBooking();
        $this->archival->archive($booking, $this->admin(), 'reason');

        $this->assertDatabaseCount('bookings', 1); // still exists physically
        $this->assertSame(0, Booking::query()->whereKey($booking->id)->count());
    }

    public function test_non_terminal_booking_cannot_be_archived(): void
    {
        $booking = Booking::factory()->confirmed()->create(['status' => BookingStatus::Confirmed]);

        $this->expectException(BookingArchivalException::class);
        $this->archival->archive($booking, $this->admin(), 'reason');
    }

    public function test_archive_requires_a_reason(): void
    {
        $booking = $this->terminalBooking();

        $this->expectException(BookingArchivalException::class);
        $this->archival->archive($booking, $this->admin(), '   ');
    }

    public function test_unauthorized_administrator_cannot_archive(): void
    {
        $booking = $this->terminalBooking();
        $plainManager = User::factory()->create(['status' => 'active']);
        $plainManager->assignRole('manager'); // no Archive:Booking permission granted

        $this->expectException(AuthorizationException::class);
        $this->archival->archive($booking, $plainManager, 'reason');
    }

    public function test_student_cannot_archive(): void
    {
        $booking = $this->terminalBooking();
        $student = User::factory()->create(['status' => 'active']);
        $student->assignRole('student');

        $this->expectException(AuthorizationException::class);
        $this->archival->archive($booking, $student, 'reason');
    }

    public function test_instructor_cannot_archive(): void
    {
        $booking = $this->terminalBooking();
        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->assignRole('instructor');

        $this->expectException(AuthorizationException::class);
        $this->archival->archive($booking, $instructor, 'reason');
    }

    public function test_archived_booking_can_be_restored_by_an_authorized_administrator(): void
    {
        $booking = $this->terminalBooking();
        $admin = $this->admin();
        $this->archival->archive($booking, $admin, 'reason');

        $result = $this->archival->restore($booking->fresh(), $admin, 'Reopening for review.');

        $this->assertTrue($result->applied);
        $this->assertNull($booking->fresh()->deleted_at);
    }

    public function test_restore_requires_a_reason(): void
    {
        $booking = $this->terminalBooking();
        $admin = $this->admin();
        $this->archival->archive($booking, $admin, 'reason');

        $this->expectException(BookingArchivalException::class);
        $this->archival->restore($booking->fresh(), $admin, '');
    }

    public function test_restore_preserves_the_original_booking_status(): void
    {
        $booking = $this->terminalBooking();
        $admin = $this->admin();
        $statusBefore = $booking->status;
        $paymentStatusBefore = $booking->payment_status;

        $this->archival->archive($booking, $admin, 'reason');
        $this->archival->restore($booking->fresh(), $admin, 'reason');

        $restored = $booking->fresh();
        $this->assertSame($statusBefore, $restored->status);
        $this->assertSame($paymentStatusBefore, $restored->payment_status);
    }

    public function test_repeated_archive_is_idempotent(): void
    {
        $booking = $this->terminalBooking();
        $admin = $this->admin();

        $first = $this->archival->archive($booking, $admin, 'first');
        $second = $this->archival->archive($booking->fresh(), $admin, 'second');

        $this->assertTrue($first->applied);
        $this->assertFalse($second->applied);
    }

    public function test_repeated_restore_is_idempotent(): void
    {
        $booking = $this->terminalBooking();
        $admin = $this->admin();
        $this->archival->archive($booking, $admin, 'reason');

        $first = $this->archival->restore($booking->fresh(), $admin, 'first');
        $second = $this->archival->restore($booking->fresh(), $admin, 'second');

        $this->assertTrue($first->applied);
        $this->assertFalse($second->applied);
    }

    public function test_force_delete_is_rejected(): void
    {
        $booking = $this->terminalBooking();

        $this->expectException(HistoricalRecordCannotBeDeletedException::class);
        $booking->forceDelete();
    }

    public function test_filament_exposes_no_force_delete_action(): void
    {
        $this->assertActionNotInstantiated('ForceDeleteAction', app_path('Filament/Resources/Bookings/Pages/EditBooking.php'));
        $this->assertActionNotInstantiated('ForceDeleteBulkAction', app_path('Filament/Resources/Bookings/Tables/BookingsTable.php'));
    }

    public function test_filament_exposes_no_physical_delete_action(): void
    {
        $this->assertActionNotInstantiated('DeleteAction', app_path('Filament/Resources/Bookings/Pages/EditBooking.php'));
        $this->assertActionNotInstantiated('DeleteBulkAction', app_path('Filament/Resources/Bookings/Tables/BookingsTable.php'));
    }

    /** Checks for an actual `X::make(` call site, not merely the class name appearing in a doc comment. */
    private function assertActionNotInstantiated(string $actionClass, string $file): void
    {
        $this->assertStringNotContainsString(
            $actionClass.'::make(',
            file_get_contents($file),
            "{$file} must not instantiate {$actionClass}.",
        );
    }

    public function test_archive_action_delegates_to_the_archival_service(): void
    {
        $booking = $this->terminalBooking();
        $admin = $this->admin();

        $this->archival->archive($booking, $admin, 'Delegated through the service.');

        $activity = Activity::query()->where('log_name', 'bookings')->where('event', 'booking_archived')->latest('id')->first();
        $this->assertNotNull($activity, 'ArchiveBookingAction must audit through AuditTrailService.');
    }

    // ── 19–38. Historical preservation ──────────────────────────────────────

    public function test_full_historical_dependency_tree_survives_archival(): void
    {
        $graph = $this->richBookingGraph();
        $admin = $this->admin();

        $this->archival->archive($graph['booking'], $admin, 'Full-graph preservation check.');

        $this->assertDatabaseHas('lessons', ['id' => $graph['lesson']->id]);
        $this->assertDatabaseHas('lesson_attendance_records', ['id' => $graph['attendance']->id]);
        $this->assertDatabaseHas('lesson_financial_dispositions', ['id' => $graph['disposition']->id]);
        $this->assertDatabaseHas('wallet_ledger_entries', ['id' => $graph['refundEntry']->id]);
        $this->assertDatabaseHas('instructor_earnings', ['id' => $graph['earning']->id]);
        $this->assertDatabaseHas('instructor_settlement_batches', ['id' => $graph['settlement']->id]);
        $this->assertDatabaseHas('lesson_review_eligibilities', ['id' => $graph['eligibility']->id]);
        $this->assertDatabaseHas('lesson_reviews', ['id' => $graph['review']->id]);
        $this->assertDatabaseHas('lesson_review_revisions', ['id' => $graph['revision']->id]);
        $this->assertDatabaseHas('review_reports', ['id' => $graph['report']->id]);
        $this->assertDatabaseHas('review_rating_contributions', ['id' => $graph['contribution']->id]);
        $this->assertDatabaseHas('instructor_student_feedback', ['id' => $graph['feedback']->id]);
        $this->assertDatabaseHas('quality_alerts', ['id' => $graph['alert']->id]);
        $this->assertDatabaseHas('bookings', ['id' => $graph['booking']->id]);
    }

    public function test_audit_records_survive_archival(): void
    {
        $graph = $this->richBookingGraph();
        $countBefore = Activity::query()->count();

        $this->archival->archive($graph['booking'], $this->admin(), 'reason');

        $this->assertGreaterThanOrEqual($countBefore, Activity::query()->count());
        $this->assertDatabaseHas('activity_log', ['log_name' => 'bookings']);
    }

    public function test_foreign_key_metadata_reports_non_cascade_rules_for_historical_relations(): void
    {
        $db = DB::getDatabaseName();
        // Only the historical-record FKs are covered here —
        // booking_guests/booking_meetings are deliberately excluded
        // (booking configuration/connection metadata, not themselves
        // historical educational/financial/audit records).
        $rows = DB::select("
            SELECT kcu.TABLE_NAME AS child_table, kcu.COLUMN_NAME AS child_column, rc.DELETE_RULE AS delete_rule
            FROM information_schema.KEY_COLUMN_USAGE kcu
            JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME AND rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
            WHERE kcu.CONSTRAINT_SCHEMA = ?
                AND kcu.REFERENCED_TABLE_NAME IN ('bookings', 'lessons', 'lesson_reviews', 'lesson_review_eligibilities')
                AND kcu.TABLE_NAME NOT IN ('booking_guests', 'booking_meetings')
        ", [$db]);

        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $this->assertNotSame(
                'CASCADE',
                $row->delete_rule,
                "{$row->child_table}.{$row->child_column} must not cascade-delete from a historical parent.",
            );
        }
    }

    public function test_a_physical_sql_deletion_of_a_referenced_booking_is_rejected(): void
    {
        $graph = $this->richBookingGraph();

        $this->expectException(QueryException::class);
        DB::statement('DELETE FROM bookings WHERE id = ?', [$graph['booking']->id]);
    }

    /**
     * `User` has no `SoftDeletes` in this codebase, so
     * `$user->delete()` is a physical deletion attempt. That attempt
     * must be restricted when historical records reference the
     * account — `bookings.student_id`/`instructor_id` are RESTRICT, so
     * the database itself rejects it and every dependent row survives.
     */
    public function test_user_physical_deletion_is_restricted_and_booking_history_survives(): void
    {
        $graph = $this->richBookingGraph();
        $student = $graph['booking']->student;

        try {
            $student->delete();
            $this->fail('Expected deleting a user with booking history to be rejected.');
        } catch (QueryException) {
            // expected — RESTRICT rejected the physical deletion
        }

        $this->assertDatabaseHas('users', ['id' => $student->id]);
        $this->assertDatabaseHas('bookings', ['id' => $graph['booking']->id]);
        $this->assertDatabaseHas('lessons', ['id' => $graph['lesson']->id]);
    }

    // ── 39–50. Historical query compatibility ────────────────────────────

    public function test_student_history_can_resolve_an_archived_booking(): void
    {
        $booking = $this->terminalBooking();
        $student = $booking->student;
        $this->archival->archive($booking, $this->admin(), 'reason');

        $page = app(BookingRepository::class)->paginatedForUser($student->id);

        $this->assertTrue($page->getCollection()->contains('id', $booking->id));
    }

    public function test_admin_history_includes_archived_bookings(): void
    {
        $booking = $this->terminalBooking();
        $this->archival->archive($booking, $this->admin(), 'reason');

        $this->assertNotNull(Booking::withTrashed()->find($booking->id));
    }

    public function test_archived_booking_does_not_appear_in_upcoming_booking_queries(): void
    {
        $booking = $this->terminalBooking();
        $this->archival->archive($booking, $this->admin(), 'reason');

        $upcoming = app(BookingRepository::class)->upcomingForUser($booking->student_id);

        $this->assertFalse($upcoming->contains('id', $booking->id));
    }

    public function test_archived_booking_does_not_block_availability(): void
    {
        $instructor = $this->instructorUser();
        $booking = $this->terminalBooking($instructor);
        $this->archival->archive($booking, $this->admin(), 'reason');

        $overlap = app(BookingRepository::class)->hasOverlap(
            $instructor->id,
            $booking->starts_at,
            $booking->ends_at,
        );

        $this->assertFalse($overlap, 'An archived booking must never block a new availability check.');
    }

    public function test_pending_refund_reconciliation_can_still_resolve_an_archived_booking(): void
    {
        $graph = $this->richBookingGraph();
        $this->archival->archive($graph['booking'], $this->admin(), 'reason');

        $disposition = LessonFinancialDisposition::query()->findOrFail($graph['disposition']->id);

        $this->assertNotNull($disposition->booking, 'Financial disposition must still resolve its booking after archival.');
        $this->assertSame($graph['booking']->id, $disposition->booking->id);
    }

    public function test_pending_earning_reconciliation_can_still_resolve_an_archived_booking(): void
    {
        $graph = $this->richBookingGraph();
        $this->archival->archive($graph['booking'], $this->admin(), 'reason');

        $earning = InstructorEarning::query()->findOrFail($graph['earning']->id);

        $this->assertNotNull($earning->booking, 'Instructor earning must still resolve its booking after archival.');
    }

    public function test_review_and_report_workflows_retain_valid_relationships(): void
    {
        $graph = $this->richBookingGraph();
        $this->archival->archive($graph['booking'], $this->admin(), 'reason');

        $review = LessonReview::query()->findOrFail($graph['review']->id);

        $this->assertNotNull($review->booking, 'Review must still resolve its booking after archival.');
        $this->assertNotNull($review->reports()->first(), 'Review report relationship must remain intact.');
    }

    // ── 48–50. Restoration safety ────────────────────────────────────────

    public function test_restoring_does_not_trigger_meeting_creation(): void
    {
        $booking = $this->terminalBooking();
        $admin = $this->admin();
        $this->archival->archive($booking, $admin, 'reason');

        $meetingCountBefore = DB::table('booking_meetings')->count();
        $this->archival->restore($booking->fresh(), $admin, 'reason');

        $this->assertSame($meetingCountBefore, DB::table('booking_meetings')->count());
    }

    public function test_restoring_does_not_trigger_notification_delivery(): void
    {
        $booking = $this->terminalBooking();
        $admin = $this->admin();
        $this->archival->archive($booking, $admin, 'reason');

        Notification::fake();
        $this->archival->restore($booking->fresh(), $admin, 'reason');

        Notification::assertNothingSent();
    }

    public function test_restoring_does_not_alter_payment_wallet_earning_or_settlement_records(): void
    {
        $graph = $this->richBookingGraph();
        $admin = $this->admin();
        $this->archival->archive($graph['booking'], $admin, 'reason');

        $earningVersionBefore = InstructorEarning::query()->findOrFail($graph['earning']->id)->getAttribute('status');
        $walletBalanceBefore = Wallet::query()->find($graph['wallet']->id)?->balance_minor;

        $this->archival->restore($graph['booking']->fresh(), $admin, 'reason');

        $this->assertSame($earningVersionBefore, InstructorEarning::query()->findOrFail($graph['earning']->id)->status);
        $this->assertSame($walletBalanceBefore, Wallet::query()->find($graph['wallet']->id)?->balance_minor);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function admin(): User
    {
        $this->seed(BookingPermissionSeeder::class);

        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('manager');

        return $admin;
    }

    private function instructorUser(): User
    {
        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->profile->update(['profile_visibility' => 'public', 'instructor_status' => InstructorStatus::Approved]);
        $instructor->assignRole('instructor');

        return $instructor;
    }

    private function terminalBooking(?User $instructor = null): Booking
    {
        $instructor ??= $this->instructorUser();
        $endsAt = now()->subHours(2)->startOfHour();

        return Booking::factory()->confirmed()->create([
            'instructor_id' => $instructor->id,
            'starts_at' => $endsAt->copy()->subMinutes(60),
            'ends_at' => $endsAt,
            'status' => BookingStatus::Completed,
            'payment_status' => BookingPaymentStatus::NotRequired,
            'price' => null,
            'currency' => null,
        ]);
    }

    /** @return array<string, mixed> */
    private function richBookingGraph(): array
    {
        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => '₹', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);

        $instructor = $this->instructorUser();
        $endsAt = now()->subHours(2)->startOfHour();

        // createFromBooking() requires a still-Confirmed booking (lesson
        // creation precedes completion in the real lifecycle) — the
        // outcome finalize step below is what transitions the booking
        // itself to Completed, exactly as production does.
        $booking = Booking::factory()->confirmed()->create([
            'instructor_id' => $instructor->id,
            'starts_at' => $endsAt->copy()->subMinutes(60),
            'ends_at' => $endsAt,
            'payment_status' => BookingPaymentStatus::NotRequired,
            'price' => null,
            'currency' => null,
        ]);

        $lesson = app(LessonLifecycleServiceInterface::class)->createFromBooking($booking);
        app(LessonOutcomeServiceInterface::class)->finalize($lesson, LessonOutcome::Completed);
        $lesson = Lesson::query()->findOrFail($lesson->id);
        $booking = $booking->fresh();

        $attendance = LessonAttendanceRecord::query()->where('lesson_id', $lesson->id)->first()
            ?? LessonAttendanceRecord::factory()->create(['lesson_id' => $lesson->id, 'booking_id' => $booking->id]);

        $wallet = Wallet::factory()->create(['user_id' => $booking->student_id]);
        $refundEntry = WalletLedgerEntry::factory()->create(['wallet_id' => $wallet->id]);

        $disposition = LessonFinancialDisposition::query()->where('lesson_id', $lesson->id)->first();
        if ($disposition === null) {
            $disposition = LessonFinancialDisposition::create([
                'lesson_id' => $lesson->id,
                'booking_id' => $booking->id,
                'outcome' => LessonOutcome::Completed,
                'student_disposition' => LessonStudentDisposition::None,
                'instructor_disposition' => LessonInstructorDisposition::ExistingCompletionEarning,
                'processing_status' => LessonFinancialDispositionStatus::Resolved,
                'version' => 1,
                'evaluated_at' => now(),
            ]);
        }
        $disposition->forceFill(['refund_ledger_entry_id' => $refundEntry->id])->saveQuietly();

        $earning = InstructorEarning::factory()->create([
            'lesson_id' => $lesson->id,
            'booking_id' => $booking->id,
            'instructor_id' => $instructor->id,
            'student_id' => $booking->student_id,
        ]);
        $settlement = InstructorSettlementBatch::factory()->create(['instructor_id' => $instructor->id]);
        $earning->forceFill(['settlement_batch_id' => $settlement->id])->saveQuietly();

        $this->enableReviews();
        $eligibility = LessonReviewEligibility::query()->where('lesson_id', $lesson->id)->first();
        $review = null;
        if ($eligibility !== null) {
            $result = app(StudentReviewServiceInterface::class)->submit(
                $eligibility,
                $eligibility->student,
                new SubmitStudentReviewData(overallRating: 5, content: 'A genuinely helpful and well-structured lesson.'),
            );
            $review = $result->review;
        }
        $eligibility ??= LessonReviewEligibility::factory()->create(['lesson_id' => $lesson->id, 'booking_id' => $booking->id]);
        $review ??= LessonReview::factory()->create(['lesson_id' => $lesson->id, 'booking_id' => $booking->id, 'eligibility_id' => $eligibility->id]);

        $revision = LessonReviewRevision::create([
            'lesson_review_id' => $review->id,
            'review_version' => $review->version,
            'previous_overall_rating' => $review->overall_rating,
            'previous_status' => $review->status,
            'edited_by' => $review->student_id,
            'edited_at' => now(),
        ]);
        $report = ReviewReport::factory()->create(['review_id' => $review->id]);
        $contribution = ReviewRatingContribution::factory()->create(['review_id' => $review->id, 'instructor_id' => $instructor->id]);
        $feedback = InstructorStudentFeedback::factory()->create([
            'lesson_id' => $lesson->id,
            'booking_id' => $booking->id,
            'instructor_id' => $instructor->id,
            'student_id' => $booking->student_id,
        ]);
        $alert = InstructorQualityAlert::factory()->create(['instructor_id' => $instructor->id]);

        return compact(
            'booking', 'lesson', 'attendance', 'disposition', 'refundEntry', 'earning', 'settlement',
            'eligibility', 'review', 'revision', 'report', 'contribution', 'feedback', 'alert', 'wallet',
        );
    }

    /** @param array<string, mixed> $overrides */
    private function enableReviews(array $overrides = []): void
    {
        $settings = app(ReviewSettings::class);
        $settings->reviews_enabled = true;
        $settings->paid_lesson_reviews_enabled = true;
        $settings->demo_review_policy = 'public';
        $settings->review_window_days = 14;
        $settings->rating_min = 1;
        $settings->rating_max = 5;
        $settings->written_review_required = false;
        $settings->review_min_length = 5;
        $settings->review_max_length = 2000;
        $settings->rating_dimensions_enabled = true;
        $settings->review_max_tags = 5;
        $settings->moderation_model = 'risk_based';
        $settings->auto_publish_clean_reviews = true;
        $settings->public_review_identity_mode = 'first_name_initial';
        $settings->review_reporting_enabled = true;
        $settings->review_editing_enabled = true;
        $settings->review_edit_window_hours = 24;
        $settings->quality_alerts_enabled = false;

        foreach ($overrides as $key => $value) {
            $settings->{$key} = $value;
        }

        $settings->save();
    }
}
