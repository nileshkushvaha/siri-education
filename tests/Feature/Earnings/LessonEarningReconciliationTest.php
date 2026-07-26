<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings;

use App\Booking\Enums\BookingPaymentStatus;
use App\Earnings\Actions\ExecuteLessonEarningReconciliationAction;
use App\Earnings\Contracts\InstructorEarningServiceInterface;
use App\Earnings\Contracts\LessonEarningReconciliationServiceInterface;
use App\Earnings\Contracts\LessonFinancialDispositionServiceInterface;
use App\Earnings\Enums\InstructorEarningStatus;
use App\Earnings\Enums\LessonFinancialDispositionStatus;
use App\Earnings\Exceptions\EarningException;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\InstructorCompensationAgreement;
use App\Models\InstructorEarning;
use App\Models\Lesson;
use App\Models\LessonFinancialDisposition;
use App\Models\User;
use Database\Seeders\LessonPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ManagesFinancialSettings;
use Tests\TestCase;

/**
 * Instructor earning reconciliation: creation for approved
 * compensation, hold/restore-release/reverse corrections, settled-money
 * protection, idempotency/concurrency, and the guarantee that no
 * student-side records change.
 */
class LessonEarningReconciliationTest extends TestCase
{
    use ManagesFinancialSettings;
    use RefreshDatabase;

    private const int AGREEMENT_RATE_MINOR = 80000;

    private LessonOutcomeServiceInterface $outcomes;

    private LessonEarningReconciliationServiceInterface $reconciliation;

    private LessonFinancialDispositionServiceInterface $dispositions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outcomes = app(LessonOutcomeServiceInterface::class);
        $this->reconciliation = app(LessonEarningReconciliationServiceInterface::class);
        $this->dispositions = app(LessonFinancialDispositionServiceInterface::class);

        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);

        $this->setFinancialSettings([
            'earnings_enabled' => true,
            'financial_disposition_enabled' => true,
            'earning_reconciliation_execution_enabled' => true,
        ]);
    }

    // ── 1–2. Missing / existing completed earnings ───────────────────

    public function test_missing_earning_for_completed_paid_lesson_is_created_once(): void
    {
        // Earnings were disabled when the lesson completed → no earning.
        $this->setFinancialSettings(['earnings_enabled' => false]);
        $lesson = $this->paidLesson();
        $this->outcomes->finalize($lesson, LessonOutcome::Completed);
        $this->assertSame(0, InstructorEarning::query()->count());

        $this->setFinancialSettings(['earnings_enabled' => true]);
        $this->approveReconciliation($lesson);

        $this->artisan('lessons:process-earning-reconciliation')
            ->expectsOutputToContain('Applied 1 earning reconciliation(s).')->assertSuccessful();
        $this->artisan('lessons:process-earning-reconciliation')
            ->expectsOutputToContain('Applied 0 earning reconciliation(s).')->assertSuccessful();

        $earning = InstructorEarning::query()->where('lesson_id', $lesson->id)->firstOrFail();
        $this->assertSame(self::AGREEMENT_RATE_MINOR, $earning->earning_amount_minor);
        $this->assertSame(1, InstructorEarning::query()->count());

        $disposition = $this->dispositionFor($lesson);
        $this->assertSame(LessonFinancialDispositionStatus::Resolved, $disposition->processing_status);
        $this->assertSame($earning->id, $disposition->instructor_earning_id);
    }

    public function test_existing_completed_lesson_earning_is_not_duplicated(): void
    {
        $lesson = $this->paidLesson();
        $this->outcomes->finalize($lesson, LessonOutcome::Completed);
        $this->assertSame(1, InstructorEarning::query()->count());

        $this->approveReconciliation($lesson);
        $this->reconciliation->processReady();

        $this->assertSame(1, InstructorEarning::query()->count());
        $this->assertSame('earning_reconciled_earning_intact', $this->dispositionFor($lesson)->reason_code);
    }

    // ── 3–8. Creation eligibility matrix ─────────────────────────────

    public function test_approved_student_no_show_creates_normal_compensation(): void
    {
        $this->setFinancialSettings(['student_no_show_compensation_policy' => 'normal_earning']);
        $lesson = $this->paidLesson();

        $this->outcomes->finalize($lesson, LessonOutcome::StudentNoShow);
        // The policy routes straight to Ready with the reconciliation reason.
        $this->assertSame('student_no_show_earning_reconciliation_required', $this->dispositionFor($lesson)->reason_code);

        $this->assertSame(1, $this->reconciliation->processReady());

        $earning = InstructorEarning::query()->where('lesson_id', $lesson->id)->firstOrFail();
        $this->assertSame(self::AGREEMENT_RATE_MINOR, $earning->earning_amount_minor);
        $this->assertSame(InstructorEarningStatus::PendingHold, $earning->status);
    }

    public function test_student_no_show_under_manual_review_policy_creates_nothing(): void
    {
        $lesson = $this->paidLesson(); // default policy: manual_review
        $this->outcomes->finalize($lesson, LessonOutcome::StudentNoShow);

        $this->assertSame(0, $this->reconciliation->processReady());
        $this->assertSame(0, InstructorEarning::query()->count());
        $this->assertSame(LessonFinancialDispositionStatus::ManualReview, $this->dispositionFor($lesson)->processing_status);
    }

    public function test_instructor_no_show_never_creates_earning(): void
    {
        $lesson = $this->paidLesson();
        $this->outcomes->finalize($lesson, LessonOutcome::InstructorNoShow);

        // Even a (mistaken) explicit approval cannot compensate it.
        $this->approveReconciliation($lesson);
        $this->reconciliation->processReady();

        $this->assertSame(0, InstructorEarning::query()->count());
        $disposition = $this->dispositionFor($lesson);
        $this->assertSame(LessonFinancialDispositionStatus::ManualReview, $disposition->processing_status);
        $this->assertSame('outcome_not_compensable', $disposition->reason_code);
    }

    public function test_approved_both_absent_compensation_creates_earning(): void
    {
        $lesson = $this->paidLesson();
        $this->outcomes->finalize($lesson, LessonOutcome::BothAbsent);

        $this->approveReconciliation($lesson);
        $this->assertSame(1, $this->reconciliation->processReady());

        $this->assertSame(1, InstructorEarning::query()->where('lesson_id', $lesson->id)->count());
    }

    public function test_demo_lesson_creates_no_earning(): void
    {
        $lesson = $this->demoLesson();
        $this->outcomes->finalize($lesson, LessonOutcome::Completed);

        $this->approveReconciliation($lesson);
        $this->reconciliation->processReady();

        $this->assertSame(0, InstructorEarning::query()->count());
        $this->assertSame('earning_reconciled_no_earning_required', $this->dispositionFor($lesson)->reason_code);
    }

    public function test_unpaid_occurrence_creates_no_earning(): void
    {
        $lesson = $this->paidLesson();
        $this->outcomes->finalize($lesson, LessonOutcome::BothAbsent);
        $this->approveReconciliation($lesson);

        // The payment fell out of the settled state before execution.
        $lesson->booking->update(['payment_status' => BookingPaymentStatus::Pending]);

        $this->reconciliation->processReady();

        $this->assertSame(0, InstructorEarning::query()->count());
        $disposition = $this->dispositionFor($lesson);
        $this->assertSame(LessonFinancialDispositionStatus::ManualReview, $disposition->processing_status);
        $this->assertSame('compensation_unresolvable', $disposition->reason_code);
    }

    // ── 9–11. Compensation semantics ─────────────────────────────────

    public function test_recurring_occurrences_reconcile_independently(): void
    {
        $this->setFinancialSettings(['student_no_show_compensation_policy' => 'normal_earning']);
        $instructor = User::factory()->create();
        $this->agreementFor($instructor->id);

        $first = $this->paidLesson(instructor: $instructor, withAgreement: false);
        $second = $this->paidLesson(instructor: $instructor, withAgreement: false);

        $this->outcomes->finalize($first, LessonOutcome::StudentNoShow);
        $this->outcomes->finalize($second, LessonOutcome::StudentNoShow);

        $this->assertSame(2, $this->reconciliation->processReady());
        $this->assertSame(1, InstructorEarning::query()->where('lesson_id', $first->id)->count());
        $this->assertSame(1, InstructorEarning::query()->where('lesson_id', $second->id)->count());
    }

    public function test_compensation_uses_the_agreement_not_student_price(): void
    {
        $this->setFinancialSettings(['student_no_show_compensation_policy' => 'normal_earning']);
        $lesson = $this->paidLesson(priceMajor: '9999.00'); // student price ≠ agreement

        $this->outcomes->finalize($lesson, LessonOutcome::StudentNoShow);
        $this->reconciliation->processReady();

        $earning = InstructorEarning::query()->firstOrFail();
        $this->assertSame(self::AGREEMENT_RATE_MINOR, $earning->earning_amount_minor);
        $this->assertNotSame(999900, $earning->earning_amount_minor);
        $this->assertSame('INR', $earning->currency_code);
    }

    public function test_calculation_snapshot_remains_immutable_through_corrections(): void
    {
        $lesson = $this->paidLesson();
        $admin = $this->admin();
        $this->outcomes->finalize($lesson, LessonOutcome::Completed);

        $earning = InstructorEarning::query()->firstOrFail();
        $snapshotBefore = $earning->metadata;

        $this->outcomes->override($lesson->refresh(), $admin, LessonOutcome::TechnicalIssue, 'Disputed.');
        $this->outcomes->override($lesson->refresh(), $admin, LessonOutcome::Completed, 'Resolved for instructor.');
        $this->approveReconciliation($lesson);
        $this->reconciliation->processReady();

        $this->assertSame($snapshotBefore, $earning->refresh()->metadata);
        $this->assertSame(self::AGREEMENT_RATE_MINOR, $earning->earning_amount_minor);
    }

    // ── 12–15. Corrections on existing earnings ──────────────────────

    public function test_technical_issue_holds_an_unsettled_earning(): void
    {
        $lesson = $this->paidLesson();
        $admin = $this->admin();
        $this->outcomes->finalize($lesson, LessonOutcome::Completed);
        $earning = InstructorEarning::query()->firstOrFail();

        $this->outcomes->override($lesson->refresh(), $admin, LessonOutcome::TechnicalIssue, 'Broken meeting.');
        $this->approveReconciliation($lesson);
        $this->reconciliation->processReady();

        $this->assertSame(InstructorEarningStatus::DisputedHold, $earning->refresh()->status);
        $this->assertSame('earning_reconciled_held', $this->dispositionFor($lesson)->reason_code);

        // Held earnings stay excluded from settlement.
        $this->expectException(EarningException::class);
        app(InstructorEarningServiceInterface::class)->createSettlementBatch($lesson->instructor_id, 'INR');
    }

    public function test_instructor_favour_resolution_releases_a_held_earning(): void
    {
        $lesson = $this->paidLesson();
        $admin = $this->admin();
        $this->outcomes->finalize($lesson, LessonOutcome::Completed);
        $earning = InstructorEarning::query()->firstOrFail();

        $this->outcomes->override($lesson->refresh(), $admin, LessonOutcome::TechnicalIssue, 'Disputed.');
        $this->assertSame(InstructorEarningStatus::DisputedHold, $earning->refresh()->status);

        // Resolved in the instructor's favour: outcome corrected back.
        $this->outcomes->override($lesson->refresh(), $admin, LessonOutcome::Completed, 'Instructor proved delivery.');
        $this->approveReconciliation($lesson);
        $this->reconciliation->processReady();

        $this->assertSame(InstructorEarningStatus::Releasable, $earning->refresh()->status);
        $this->assertSame('earning_reconciled_restored_released', $this->dispositionFor($lesson)->reason_code);
    }

    public function test_student_favour_resolution_reverses_an_unsettled_earning(): void
    {
        $lesson = $this->paidLesson();
        $admin = $this->admin();
        $this->outcomes->finalize($lesson, LessonOutcome::Completed);
        $earning = InstructorEarning::query()->firstOrFail();

        $this->outcomes->override($lesson->refresh(), $admin, LessonOutcome::StudentNoShow, 'Student proved absence report was wrong.');
        $this->approveReconciliation($lesson);
        $this->reconciliation->processReady();

        $earning->refresh();
        $this->assertSame(InstructorEarningStatus::Reversed, $earning->status);
        $this->assertNotNull($earning->reversed_at);

        // Reversed earnings can never settle.
        $this->expectException(EarningException::class);
        app(InstructorEarningServiceInterface::class)->createSettlementBatch($lesson->instructor_id, 'INR');
    }

    public function test_settled_earning_is_not_changed_and_requires_manual_recovery(): void
    {
        $lesson = $this->paidLesson();
        $admin = $this->admin();
        $this->outcomes->finalize($lesson, LessonOutcome::Completed);
        $earning = InstructorEarning::query()->firstOrFail();
        $earning->forceFill(['status' => InstructorEarningStatus::Settled, 'settled_at' => now()])->save();

        $this->outcomes->override($lesson->refresh(), $admin, LessonOutcome::StudentNoShow, 'Late complaint.');
        $this->approveReconciliation($lesson);
        $this->reconciliation->processReady();

        $this->assertSame(InstructorEarningStatus::Settled, $earning->refresh()->status);
        $disposition = $this->dispositionFor($lesson);
        $this->assertSame(LessonFinancialDispositionStatus::ManualReview, $disposition->processing_status);
        $this->assertSame('settled_earning_manual_recovery', $disposition->reason_code);
        $this->assertTrue($disposition->admin_hold);
    }

    // ── 16–18. Idempotency, concurrency, failure ─────────────────────

    public function test_duplicate_and_concurrent_execution_adjust_once(): void
    {
        $this->setFinancialSettings(['student_no_show_compensation_policy' => 'normal_earning']);
        $lesson = $this->paidLesson();
        $this->outcomes->finalize($lesson, LessonOutcome::StudentNoShow);

        $this->assertSame(1, $this->reconciliation->processReady());
        $this->assertSame(0, $this->reconciliation->processReady());

        // A stale copy racing the settled row conflicts, never re-applies.
        try {
            app(ExecuteLessonEarningReconciliationAction::class)
                ->execute(LessonFinancialDisposition::query()->firstOrFail());
            $this->fail('Expected the settled disposition to conflict.');
        } catch (EarningException) {
            // expected
        }

        $this->assertSame(1, InstructorEarning::query()->count());
    }

    public function test_failed_execution_does_not_mark_the_disposition_resolved(): void
    {
        $lesson = $this->paidLesson(withAgreement: false); // no covering agreement
        $this->outcomes->finalize($lesson, LessonOutcome::BothAbsent);
        $this->approveReconciliation($lesson);

        $this->reconciliation->processReady();

        $disposition = $this->dispositionFor($lesson);
        $this->assertNotSame(LessonFinancialDispositionStatus::Resolved, $disposition->processing_status);
        $this->assertSame(LessonFinancialDispositionStatus::ManualReview, $disposition->processing_status);
        $this->assertSame(0, InstructorEarning::query()->count());
    }

    // ── 19–20. Audit & isolation ─────────────────────────────────────

    public function test_audit_history_records_amount_currency_actor_and_reason(): void
    {
        $lesson = $this->paidLesson();
        $admin = $this->admin();
        $this->outcomes->finalize($lesson, LessonOutcome::Completed);
        $earning = InstructorEarning::query()->firstOrFail();

        $this->outcomes->override($lesson->refresh(), $admin, LessonOutcome::StudentNoShow, 'Corrected.');
        $this->approveReconciliation($lesson, $admin);
        $this->reconciliation->execute($this->dispositionFor($lesson), $admin);

        $activity = Activity::query()
            ->where('log_name', 'instructor_earnings')
            ->where('event', 'lesson_earning_reconciled')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame('reversed', $activity->properties->get('action'));
        $this->assertSame($earning->earning_amount_minor, $activity->properties->get('amount_minor'));
        $this->assertSame('INR', $activity->properties->get('currency'));
        // The 17E override listener had already parked the earning on
        // DisputedHold — reconciliation reversed it from there.
        $this->assertSame('disputed_hold', $activity->properties->get('previous_earning_status'));
        $this->assertSame('reversed', $activity->properties->get('new_earning_status'));
        $this->assertSame($lesson->id, $activity->properties->get('lesson_id'));
    }

    public function test_no_wallet_ledger_or_student_payment_record_changes(): void
    {
        $this->setFinancialSettings(['student_no_show_compensation_policy' => 'normal_earning']);
        $lesson = $this->paidLesson();
        $this->outcomes->finalize($lesson, LessonOutcome::StudentNoShow);
        $this->reconciliation->processReady();

        $this->assertSame(0, DB::table('wallets')->count());
        $this->assertSame(0, DB::table('wallet_ledger_entries')->count());
        $this->assertSame(BookingPaymentStatus::Paid, $lesson->booking->refresh()->payment_status);
    }

    public function test_disabled_execution_processes_nothing(): void
    {
        $this->setFinancialSettings([
            'student_no_show_compensation_policy' => 'normal_earning',
            'earning_reconciliation_execution_enabled' => false,
        ]);
        $lesson = $this->paidLesson();
        $this->outcomes->finalize($lesson, LessonOutcome::StudentNoShow);

        $this->artisan('lessons:process-earning-reconciliation')
            ->expectsOutputToContain('Applied 0 earning reconciliation(s).')->assertSuccessful();

        $this->assertSame(0, InstructorEarning::query()->count());
        $this->assertSame(LessonFinancialDispositionStatus::Ready, $this->dispositionFor($lesson)->processing_status);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function paidLesson(?User $instructor = null, bool $withAgreement = true, string $priceMajor = '4999.00'): Lesson
    {
        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create(array_filter([
            'starts_at' => $endsAt->copy()->subMinutes(60),
            'ends_at' => $endsAt,
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => $priceMajor,
            'currency' => 'INR',
            'instructor_id' => $instructor?->id,
        ]));

        $lesson = app(LessonLifecycleServiceInterface::class)->createFromBooking($booking);

        if ($withAgreement) {
            $this->agreementFor($lesson->instructor_id);
        }

        return $lesson;
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

        $lesson = app(LessonLifecycleServiceInterface::class)->createFromBooking($booking);
        $this->agreementFor($lesson->instructor_id);

        return $lesson;
    }

    private function agreementFor(int $instructorId): void
    {
        InstructorCompensationAgreement::factory()->active()->create([
            'instructor_id' => $instructorId,
            'amount_minor' => self::AGREEMENT_RATE_MINOR,
            'currency_code' => 'INR',
            'currency_id' => Currency::query()->where('code', 'INR')->value('id'),
            'effective_from' => now()->subMonth(),
        ]);
    }

    private function approveReconciliation(Lesson $lesson, ?User $admin = null): LessonFinancialDisposition
    {
        return $this->dispositions->markReadyForEarningReconciliation(
            $this->dispositionFor($lesson),
            $admin ?? $this->admin(),
            'Approved for earning reconciliation.',
        );
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
