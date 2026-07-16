<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings;

use App\Booking\Enums\BookingPaymentStatus;
use App\Earnings\Contracts\InstructorEarningServiceInterface;
use App\Earnings\Contracts\LessonFinancialDispositionServiceInterface;
use App\Earnings\Enums\InstructorEarningStatus;
use App\Earnings\Enums\LessonFinancialDispositionStatus;
use App\Earnings\Enums\LessonInstructorDisposition;
use App\Earnings\Enums\LessonStudentDisposition;
use App\Earnings\Exceptions\EarningException;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Events\LessonDisputed;
use App\Listeners\Earnings\SyncEarningOnLessonDisputed;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\InstructorCompensationAgreement;
use App\Models\InstructorEarning;
use App\Models\Lesson;
use App\Models\LessonFinancialDisposition;
use App\Models\User;
use Database\Seeders\LessonPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ManagesFinancialSettings;
use Tests\TestCase;

/**
 * Phase 17E — the financial-disposition bridge: classification matrix,
 * earning holds + settlement exclusion, override re-evaluation,
 * settled-money protection, admin resolution, and the guarantee that
 * no money moves in this phase.
 */
class LessonFinancialDispositionTest extends TestCase
{
    use ManagesFinancialSettings;
    use RefreshDatabase;

    private LessonOutcomeServiceInterface $outcomes;

    private LessonFinancialDispositionServiceInterface $dispositions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outcomes = app(LessonOutcomeServiceInterface::class);
        $this->dispositions = app(LessonFinancialDispositionServiceInterface::class);

        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);
    }

    // ── 1–5. Classification matrix ───────────────────────────────────

    public function test_completed_paid_lesson_links_to_the_existing_earning_path(): void
    {
        $this->enableBridge();
        $lesson = $this->makePaidLesson(withAgreement: true);

        $this->outcomes->finalize($lesson, LessonOutcome::Completed);

        $disposition = $this->dispositionFor($lesson);
        $earning = InstructorEarning::query()->where('lesson_id', $lesson->id)->firstOrFail();

        $this->assertSame(LessonStudentDisposition::None, $disposition->student_disposition);
        $this->assertSame(LessonInstructorDisposition::ExistingCompletionEarning, $disposition->instructor_disposition);
        $this->assertSame(LessonFinancialDispositionStatus::NoAction, $disposition->processing_status);
        $this->assertSame($earning->id, $disposition->instructor_earning_id);
        $this->assertSame('completed_paid', $disposition->reason_code);
        // The Phase 14 pipeline created exactly one earning — untouched.
        $this->assertSame(InstructorEarningStatus::PendingHold, $earning->status);
    }

    public function test_completed_demo_produces_no_financial_action(): void
    {
        $this->enableBridge();
        $lesson = $this->makeDemoLesson();

        $this->outcomes->finalize($lesson, LessonOutcome::Completed);

        $disposition = $this->dispositionFor($lesson);
        $this->assertSame(LessonStudentDisposition::None, $disposition->student_disposition);
        $this->assertSame(LessonInstructorDisposition::NoEarning, $disposition->instructor_disposition);
        $this->assertSame(LessonFinancialDispositionStatus::NoAction, $disposition->processing_status);
        $this->assertNull($disposition->instructor_earning_id);
    }

    public function test_student_no_show_defaults_to_no_refund_and_compensation_review(): void
    {
        $this->enableBridge();
        $lesson = $this->makePaidLesson();

        $this->outcomes->finalize($lesson, LessonOutcome::StudentNoShow);

        $disposition = $this->dispositionFor($lesson);
        $this->assertSame(LessonStudentDisposition::None, $disposition->student_disposition);
        $this->assertSame(LessonInstructorDisposition::CompensationReviewRequired, $disposition->instructor_disposition);
        $this->assertSame(LessonFinancialDispositionStatus::ManualReview, $disposition->processing_status);
    }

    public function test_instructor_no_show_requires_full_wallet_refund_and_no_earning(): void
    {
        $this->enableBridge();
        $lesson = $this->makePaidLesson();

        $this->outcomes->finalize($lesson, LessonOutcome::InstructorNoShow);

        $disposition = $this->dispositionFor($lesson);
        $this->assertSame(LessonStudentDisposition::FullWalletRefundRequired, $disposition->student_disposition);
        $this->assertSame(LessonInstructorDisposition::NoEarning, $disposition->instructor_disposition);
        $this->assertSame(LessonFinancialDispositionStatus::Ready, $disposition->processing_status);
        $this->assertSame($lesson->booking->payment_reference, $disposition->payment_reference);

        // Classification only — nothing was refunded and no earning exists.
        $this->assertSame(BookingPaymentStatus::Paid, $lesson->booking->refresh()->payment_status);
        $this->assertSame(0, InstructorEarning::query()->count());
    }

    public function test_both_absent_requires_manual_review(): void
    {
        $this->enableBridge();
        $lesson = $this->makePaidLesson();

        $this->outcomes->finalize($lesson, LessonOutcome::BothAbsent);

        $disposition = $this->dispositionFor($lesson);
        $this->assertSame(LessonStudentDisposition::PolicyReviewRequired, $disposition->student_disposition);
        $this->assertSame(LessonInstructorDisposition::CompensationReviewRequired, $disposition->instructor_disposition);
        $this->assertSame(LessonFinancialDispositionStatus::ManualReview, $disposition->processing_status);
    }

    // ── 6–7. Holds & settlement exclusion ────────────────────────────

    public function test_technical_issue_places_an_unsettled_earning_on_hold(): void
    {
        $this->enableBridge();
        $lesson = $this->makePaidLesson(withAgreement: true);
        $admin = $this->admin();

        $this->outcomes->finalize($lesson, LessonOutcome::Completed);
        $earning = InstructorEarning::query()->where('lesson_id', $lesson->id)->firstOrFail();

        $this->outcomes->override($lesson->refresh(), $admin, LessonOutcome::TechnicalIssue, 'Meeting audio was broken.');

        $this->assertSame(InstructorEarningStatus::DisputedHold, $earning->refresh()->status);

        $disposition = $this->dispositionFor($lesson);
        $this->assertSame(LessonInstructorDisposition::HoldExistingEarning, $disposition->instructor_disposition);
        $this->assertSame(LessonFinancialDispositionStatus::Held, $disposition->processing_status);
    }

    /**
     * Phase 17U.4 — DisputedHold is not a terminal earning status (a hold
     * later resolves back to Releasable, or on to Reversed/Cancelled), so
     * SyncEarningOnLessonDisputed's isTerminal()-only guard did not catch
     * a redelivered LessonDisputed event for an earning already on hold:
     * DisputedHold -> DisputedHold is not an allowed transition and threw
     * instead of no-opping. A duplicate queue delivery must be harmless.
     */
    public function test_duplicate_lesson_disputed_delivery_does_not_throw(): void
    {
        $this->setFinancialSettings(['earnings_enabled' => true]);
        $lesson = $this->makePaidLesson(withAgreement: true);
        $admin = $this->admin();

        $this->outcomes->finalize($lesson, LessonOutcome::Completed);
        $earning = InstructorEarning::query()->where('lesson_id', $lesson->id)->firstOrFail();

        app(LessonLifecycleServiceInterface::class)->dispute($lesson->refresh(), $admin, 'Reported by student.');
        $this->assertSame(InstructorEarningStatus::DisputedHold, $earning->refresh()->status);

        // Simulate a duplicate queue delivery of the already-processed
        // LessonDisputed event — must be a safe no-op, not an exception.
        app(SyncEarningOnLessonDisputed::class)->handle(new LessonDisputed($lesson->refresh()));

        $this->assertSame(InstructorEarningStatus::DisputedHold, $earning->refresh()->status);
    }

    public function test_held_earning_cannot_enter_settlement(): void
    {
        $this->enableBridge();
        $lesson = $this->makePaidLesson(withAgreement: true);
        $admin = $this->admin();

        $this->outcomes->finalize($lesson, LessonOutcome::Completed);
        $earning = InstructorEarning::query()->where('lesson_id', $lesson->id)->firstOrFail();
        app(InstructorEarningServiceInterface::class)->release($earning, override: true);
        $this->assertSame(InstructorEarningStatus::Releasable, $earning->refresh()->status);

        $this->outcomes->override($lesson->refresh(), $admin, LessonOutcome::TechnicalIssue, 'Disputed by student.');
        $this->assertSame(InstructorEarningStatus::DisputedHold, $earning->refresh()->status);

        $this->expectException(EarningException::class);
        $this->expectExceptionMessageMatches('/No releasable/');

        app(InstructorEarningServiceInterface::class)->createSettlementBatch($lesson->instructor_id, 'INR');
    }

    // ── 8–9. Cancellation & idempotency ──────────────────────────────

    public function test_cancelled_lesson_does_not_duplicate_cancellation_refunds(): void
    {
        $this->enableBridge();
        $lesson = $this->makePaidLesson();

        $this->outcomes->finalize($lesson, LessonOutcome::Cancelled);

        $disposition = $this->dispositionFor($lesson);
        $this->assertSame(LessonStudentDisposition::ExistingCancellationFlow, $disposition->student_disposition);
        $this->assertSame(LessonInstructorDisposition::ExistingCancellationFlow, $disposition->instructor_disposition);
        $this->assertSame(LessonFinancialDispositionStatus::NoAction, $disposition->processing_status);
        // The bridge never initiates a second refund — payment untouched.
        $this->assertSame(BookingPaymentStatus::Paid, $lesson->booking->refresh()->payment_status);
        $this->assertSame(0, DB::table('wallet_ledger_entries')->count());
    }

    public function test_duplicate_outcome_events_create_one_disposition(): void
    {
        $this->enableBridge();
        $lesson = $this->makePaidLesson();

        $this->outcomes->finalize($lesson, LessonOutcome::BothAbsent);
        // A replayed listener delivery classifies again — same single row.
        $this->dispositions->classify($lesson->refresh(), LessonOutcome::BothAbsent);

        $this->assertSame(1, LessonFinancialDisposition::query()->where('lesson_id', $lesson->id)->count());
        $this->assertSame(1, $this->dispositionFor($lesson)->version);
    }

    /**
     * Phase 17U.4 — classify()'s lockForUpdate() locks nothing when no
     * row exists yet, so two truly concurrent callers can both pass the
     * existence check before either inserts; the loser's insert then
     * collides on the unique lesson_id constraint. This confirms that
     * constraint is real and raises exactly the exception type classify()
     * now catches (a duplicate insert attempted from *outside* classify()'s
     * own transaction, so — unlike nesting a competing insert inside the
     * same transaction/savepoint tree — this row genuinely survives to be
     * checked, the same way a separate concurrent transaction's committed
     * row would).
     */
    public function test_duplicate_lesson_id_insert_raises_the_exception_classify_recovers_from(): void
    {
        $this->enableBridge();
        $lesson = $this->makePaidLesson();

        $disposition = $this->dispositions->classify($lesson, LessonOutcome::Completed);
        $this->assertNotNull($disposition);

        $this->expectException(UniqueConstraintViolationException::class);

        LessonFinancialDisposition::query()->create([
            'lesson_id' => $lesson->id,
            'booking_id' => $lesson->booking_id,
            'outcome' => LessonOutcome::Completed,
            'student_disposition' => LessonStudentDisposition::None,
            'instructor_disposition' => LessonInstructorDisposition::ExistingCompletionEarning,
            'processing_status' => LessonFinancialDispositionStatus::NoAction,
            'reason_code' => 'completed_paid',
            'version' => 1,
            'evaluated_at' => now(),
        ]);
    }

    /**
     * classify() itself must not let that same exception escape when it
     * is the one racing against an already-existing row — proves the
     * catch path's fallback query returns the disposition rather than
     * throwing, for the case lockForUpdate() *does* find (a row that
     * already committed before this call started, i.e. the ordinary
     * redelivery case already covered above, re-asserted here against
     * the exception type specifically).
     */
    public function test_classify_does_not_throw_when_a_disposition_already_exists(): void
    {
        $this->enableBridge();
        $lesson = $this->makePaidLesson();

        $this->dispositions->classify($lesson, LessonOutcome::Completed);

        $disposition = $this->dispositions->classify($lesson->refresh(), LessonOutcome::Completed);

        $this->assertNotNull($disposition);
        $this->assertSame(1, LessonFinancialDisposition::query()->where('lesson_id', $lesson->id)->count());
    }

    // ── 10–13. Overrides ─────────────────────────────────────────────

    public function test_outcome_override_preserves_previous_history(): void
    {
        $this->enableBridge();
        $lesson = $this->makePaidLesson(withAgreement: true);
        $admin = $this->admin();

        $this->outcomes->finalize($lesson, LessonOutcome::Completed);
        $this->outcomes->override($lesson->refresh(), $admin, LessonOutcome::StudentNoShow, 'Student never joined.');

        $disposition = $this->dispositionFor($lesson);
        $this->assertSame(2, $disposition->version);
        $this->assertCount(1, $disposition->history);
        $this->assertSame('completed', $disposition->history[0]['outcome']);
        $this->assertSame('completed_paid', $disposition->history[0]['reason_code']);
    }

    /**
     * Phase 17V closure re-audit — a redelivered LessonOutcomeOverridden
     * event (or a directly-repeated override call for the identical
     * target outcome) used to append another near-duplicate history
     * entry and bump version again on every replay. reevaluate() now
     * short-circuits when the disposition's current outcome already
     * equals the target — cosmetic version/history bloat only, no
     * financial effect, per the closure audit's classification.
     */
    public function test_redelivered_identical_override_does_not_bump_version_or_duplicate_history(): void
    {
        $this->enableBridge();
        $lesson = $this->makePaidLesson(withAgreement: true);
        $admin = $this->admin();

        $this->outcomes->finalize($lesson, LessonOutcome::Completed);
        $this->outcomes->override($lesson->refresh(), $admin, LessonOutcome::StudentNoShow, 'Student never joined.');

        $afterFirstOverride = $this->dispositionFor($lesson);
        $this->assertSame(2, $afterFirstOverride->version);
        $this->assertCount(1, $afterFirstOverride->history);

        // Directly replay the identical reevaluation the listener would
        // apply on a redelivered event for the same override.
        $this->dispositions->reevaluate($lesson->refresh(), LessonOutcome::Completed, LessonOutcome::StudentNoShow, 'Student never joined.');
        $this->dispositions->reevaluate($lesson->refresh(), LessonOutcome::Completed, LessonOutcome::StudentNoShow, 'Student never joined.');

        $afterReplay = $this->dispositionFor($lesson->fresh());
        $this->assertSame(2, $afterReplay->version, 'A replayed identical override must not bump version again.');
        $this->assertCount(1, $afterReplay->history, 'A replayed identical override must not append another history entry.');
    }

    public function test_completed_to_no_show_override_holds_an_existing_earning(): void
    {
        $this->enableBridge();
        $lesson = $this->makePaidLesson(withAgreement: true);
        $admin = $this->admin();

        $this->outcomes->finalize($lesson, LessonOutcome::Completed);
        $earning = InstructorEarning::query()->where('lesson_id', $lesson->id)->firstOrFail();

        $this->outcomes->override($lesson->refresh(), $admin, LessonOutcome::StudentNoShow, 'Provider data was wrong.');

        $this->assertSame(InstructorEarningStatus::DisputedHold, $earning->refresh()->status);
        $disposition = $this->dispositionFor($lesson);
        $this->assertSame(LessonInstructorDisposition::HoldExistingEarning, $disposition->instructor_disposition);
        // The hold is placed AND the compensation-policy question still
        // needs a human — manual review outranks a plain hold.
        $this->assertSame(LessonFinancialDispositionStatus::ManualReview, $disposition->processing_status);
    }

    public function test_settled_earning_conflict_requires_manual_review(): void
    {
        $this->enableBridge();
        $lesson = $this->makePaidLesson(withAgreement: true);
        $admin = $this->admin();

        $this->outcomes->finalize($lesson, LessonOutcome::Completed);
        $earning = InstructorEarning::query()->where('lesson_id', $lesson->id)->firstOrFail();
        // Money already paid out — simulated settled state.
        $earning->forceFill(['status' => InstructorEarningStatus::Settled, 'settled_at' => now()])->save();

        $this->outcomes->override($lesson->refresh(), $admin, LessonOutcome::StudentNoShow, 'Late complaint.');

        $disposition = $this->dispositionFor($lesson);
        $this->assertTrue($disposition->admin_hold);
        $this->assertSame(LessonFinancialDispositionStatus::ManualReview, $disposition->processing_status);
        $this->assertSame('settled_earning_conflict', $disposition->reason_code);
        // Settled money is never altered silently.
        $this->assertSame(InstructorEarningStatus::Settled, $earning->refresh()->status);
    }

    public function test_existing_wallet_and_earning_records_are_not_deleted_or_rewritten(): void
    {
        $this->enableBridge();
        $lesson = $this->makePaidLesson(withAgreement: true);
        $admin = $this->admin();

        $this->outcomes->finalize($lesson, LessonOutcome::Completed);
        $earning = InstructorEarning::query()->where('lesson_id', $lesson->id)->firstOrFail();
        $originalAmount = $earning->earning_amount_minor;

        $this->outcomes->override($lesson->refresh(), $admin, LessonOutcome::TechnicalIssue, 'Disputed.');

        $earning->refresh();
        $this->assertSame($originalAmount, $earning->earning_amount_minor);
        $this->assertSame(1, InstructorEarning::query()->count());
        $this->assertSame(0, DB::table('wallets')->count());
        $this->assertSame(0, DB::table('wallet_ledger_entries')->count());
    }

    // ── 14–15. Admin resolution ──────────────────────────────────────

    public function test_unauthorized_admin_resolution_is_rejected(): void
    {
        $this->enableBridge();
        $lesson = $this->makePaidLesson();
        $this->outcomes->finalize($lesson, LessonOutcome::BothAbsent);

        $this->expectException(AuthorizationException::class);

        $this->dispositions->approve($this->dispositionFor($lesson), User::factory()->create(), 'approved');
    }

    public function test_resolution_requires_a_reason_and_creates_an_audit_entry(): void
    {
        $this->enableBridge();
        $lesson = $this->makePaidLesson();
        $admin = $this->admin();
        $this->outcomes->finalize($lesson, LessonOutcome::BothAbsent);
        $disposition = $this->dispositionFor($lesson);

        try {
            $this->dispositions->markReadyForRefund($disposition, $admin, '   ');
            $this->fail('Expected a missing reason to be rejected.');
        } catch (EarningException) {
            // expected
        }

        $resolved = $this->dispositions->markReadyForRefund($disposition, $admin, 'Student evidence verified — refund approved for execution phase.');

        $this->assertSame(LessonFinancialDispositionStatus::Ready, $resolved->processing_status);
        $this->assertSame(LessonStudentDisposition::FullWalletRefundRequired, $resolved->student_disposition);
        $this->assertSame($admin->id, $resolved->resolved_by);

        $activity = Activity::query()
            ->where('log_name', 'instructor_earnings')
            ->where('event', 'lesson_financial_disposition_refund_ready')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame('manual_review', $activity->properties->get('previous_status'));
        $this->assertSame('ready', $activity->properties->get('new_status'));
    }

    // ── 16–17. Kill switch & no money movement ───────────────────────

    public function test_feature_disabled_processing_causes_no_financial_changes(): void
    {
        // financial_disposition_enabled stays at its default (false).
        $this->setFinancialSettings(['earnings_enabled' => true]);
        $lesson = $this->makePaidLesson(withAgreement: true);
        $admin = $this->admin();

        $this->outcomes->finalize($lesson, LessonOutcome::Completed);
        $earning = InstructorEarning::query()->where('lesson_id', $lesson->id)->firstOrFail();

        $this->outcomes->override($lesson->refresh(), $admin, LessonOutcome::TechnicalIssue, 'Disputed.');

        $this->assertSame(0, LessonFinancialDisposition::query()->count());
        // Without the bridge, no hold is placed by this phase.
        $this->assertSame(InstructorEarningStatus::PendingHold, $earning->refresh()->status);
    }

    public function test_no_wallet_credit_refund_compensation_earning_or_reversal_is_executed(): void
    {
        $this->enableBridge();
        $lesson = $this->makePaidLesson();
        $admin = $this->admin();

        $this->outcomes->finalize($lesson, LessonOutcome::InstructorNoShow);
        $this->dispositions->approve($this->dispositionFor($lesson), $admin, 'Refund path confirmed for later execution.');

        $this->assertSame(0, DB::table('wallets')->count());
        $this->assertSame(0, DB::table('wallet_ledger_entries')->count());
        $this->assertSame(0, InstructorEarning::query()->count());
        $this->assertSame(BookingPaymentStatus::Paid, $lesson->booking->refresh()->payment_status);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function enableBridge(): void
    {
        $this->setFinancialSettings([
            'earnings_enabled' => true,
            'financial_disposition_enabled' => true,
        ]);
    }

    private function makePaidLesson(bool $withAgreement = false): Lesson
    {
        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'starts_at' => $endsAt->copy()->subMinutes(60),
            'ends_at' => $endsAt,
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => '499.00',
            'currency' => 'INR',
            'payment_reference' => 'PAY-17E-TEST',
        ]);

        $lesson = app(LessonLifecycleServiceInterface::class)->createFromBooking($booking);

        if ($withAgreement) {
            InstructorCompensationAgreement::factory()->active()->create([
                'instructor_id' => $lesson->instructor_id,
                'amount_minor' => 80000,
                'currency_code' => 'INR',
                'currency_id' => Currency::query()->where('code', 'INR')->value('id'),
                'effective_from' => now()->subMonth(),
            ]);
        }

        return $lesson;
    }

    private function makeDemoLesson(): Lesson
    {
        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'starts_at' => $endsAt->copy()->subMinutes(60),
            'ends_at' => $endsAt,
            'payment_status' => BookingPaymentStatus::NotRequired,
            'price' => null,
            'currency' => null,
        ]);

        return app(LessonLifecycleServiceInterface::class)->createFromBooking($booking);
    }

    private function dispositionFor(Lesson $lesson): LessonFinancialDisposition
    {
        return LessonFinancialDisposition::query()->where('lesson_id', $lesson->id)->firstOrFail();
    }

    private function admin(): User
    {
        $this->seed(LessonPermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('manager');

        return $admin;
    }
}
