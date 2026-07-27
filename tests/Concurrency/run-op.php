<?php

declare(strict_types=1);
use App\Booking\Contracts\BookingPaymentReconciliationServiceInterface;
use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\Contracts\StripeGatewayClient;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\DTOs\CancellationRefundDecision;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\DTOs\RescheduleBookingData;
use App\Booking\Enums\BookingActor;
use App\Earnings\Contracts\InstructorCompensationAgreementServiceInterface;
use App\Earnings\Contracts\InstructorEarningServiceInterface;
use App\Earnings\Contracts\InstructorPayoutExecutionServiceInterface;
use App\Earnings\Contracts\InstructorPayoutMethodServiceInterface;
use App\Earnings\Contracts\InstructorPeriodicCompensationServiceInterface;
use App\Earnings\Contracts\InstructorWithdrawalServiceInterface;
use App\Earnings\Contracts\RazorpayXDestinationProvisioningServiceInterface;
use App\Earnings\DTOs\NormalizedPayoutEvent;
use App\Earnings\Enums\InstructorPayoutAttemptStatus;
use App\Earnings\Exceptions\EarningException;
use App\Earnings\Providers\RazorpayX\RazorpayXPayoutClientInterface;
use App\Homework\Reminders\HomeworkReminderChannelSender;
use App\Homework\Reminders\HomeworkReminderDispatcher;
use App\Http\Middleware\TrackUserSession;
use App\Jobs\Homework\SendHomeworkDueReminderJob;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Currency;
use App\Models\HomeworkAssignment;
use App\Models\HomeworkDueReminder;
use App\Models\InstructorCompensationAgreement;
use App\Models\InstructorPayoutMethod;
use App\Models\InstructorWithdrawalRequest;
use App\Models\Lesson;
use App\Models\LessonFinancialDisposition;
use App\Models\LessonReviewEligibility;
use App\Models\ReferralAttribution;
use App\Models\ReferralCode;
use App\Models\ReferralReward;
use App\Models\TeacherAvailability;
use App\Models\User;
use App\Queue\Services\FailedJobRetryService;
use App\Referral\Contracts\ReferralAttributionServiceInterface;
use App\Referral\Contracts\ReferralCodeServiceInterface;
use App\Referral\Contracts\ReferralEligibilityServiceInterface;
use App\Referral\Contracts\ReferralRewardServiceInterface;
use App\Reviews\Contracts\StudentReviewServiceInterface;
use App\Reviews\DTOs\SubmitStudentReviewData;
use App\Services\Admin\CurrencyStatusService;
use App\Services\Admin\SuperAdminGuardService;
use App\Services\AuditTrailService;
use App\Services\Instructor\InstructorAvailabilityService;
use App\Services\Instructor\InstructorOnboardingService;
use App\Services\Student\StudentFavoriteInstructorService;
use App\Services\Student\StudentLifecycleService;
use App\Settings\RazorpayXPayoutSettings;
use App\Wallet\Actions\ExecuteLessonWalletRefundAction;
use App\Wallet\Contracts\WalletRechargeServiceInterface;
use App\Wallet\Services\WalletRechargeReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\RazorpayConcurrencyFakeClient;
use Tests\Support\RazorpayXConcurrencyFakeClient;
use Tests\Support\StripeConcurrencyFakeClient;

/**
 * Child-process worker for the real-MySQL concurrency tests
 * (tests/Feature/Earnings/Concurrency). Boots the application against
 * the TESTING environment, spins on a shared start barrier so two
 * workers hit the database at the same instant, executes exactly one
 * financial operation through the real service layer, and prints a
 * JSON verdict. Never run against a non-testing environment.
 *
 * Usage: php tests/Concurrency/run-op.php <operation> <json-args> <start-at-unix-ms>
 */
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing')) {
    fwrite(STDERR, 'SAFETY ABORT: not the testing environment.');
    exit(1);
}

[$operation, $args, $startAtMs] = [$argv[1], json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR), (int) $argv[3]];

// Barrier: both workers boot (slow, jittery), then spin until the
// agreed instant so the actual database work overlaps.
while ((int) (microtime(true) * 1000) < $startAtMs) {
    usleep(200);
}

try {
    $result = match ($operation) {
        'withdraw' => (function () use ($args) {
            $instructor = User::query()->findOrFail($args['instructor_id']);
            $method = InstructorPayoutMethod::query()->findOrFail($args['method_id']);

            $request = app(InstructorWithdrawalServiceInterface::class)
                ->requestWithdrawal($instructor, $method, (int) $args['amount_minor'], null, $args['idempotency_key'] ?? null);

            return ['request_id' => $request->id, 'reference' => $request->reference];
        })(),

        'settle' => (function () use ($args) {
            $batch = app(InstructorEarningServiceInterface::class)
                ->createSettlementBatch((int) $args['instructor_id'], $args['currency_code']);

            return ['batch_id' => $batch->id, 'reference' => $batch->batch_reference];
        })(),

        // Retry variant for the release-vs-settle race: settlement may
        // legitimately lose while the reservation is still live; it must
        // succeed once the release has committed.
        'settle-retry' => (function () use ($args) {
            $service = app(InstructorEarningServiceInterface::class);
            $deadline = microtime(true) + 10;

            while (true) {
                try {
                    $batch = $service->createSettlementBatch((int) $args['instructor_id'], $args['currency_code']);

                    return ['batch_id' => $batch->id, 'reference' => $batch->batch_reference];
                } catch (EarningException $e) {
                    if (microtime(true) > $deadline) {
                        throw $e;
                    }
                    usleep(50_000);
                }
            }
        })(),

        'cancel' => (function () use ($args) {
            $instructor = User::query()->findOrFail($args['instructor_id']);
            $request = InstructorWithdrawalRequest::query()->findOrFail($args['request_id']);

            app(InstructorWithdrawalServiceInterface::class)
                ->cancelByInstructor($request, $instructor);

            return ['cancelled' => true];
        })(),

        'activate-agreement' => (function () use ($args) {
            $admin = User::query()->findOrFail($args['admin_id']);
            $agreement = InstructorCompensationAgreement::query()->findOrFail($args['agreement_id']);

            app(InstructorCompensationAgreementServiceInterface::class)
                ->activate($agreement, $admin, 'Concurrent activation test.');

            return ['agreement_id' => $agreement->id];
        })(),

        'accrue-periodic' => (function () {
            $accrued = app(InstructorPeriodicCompensationServiceInterface::class)
                ->accrueClosedPeriods();

            return ['accrued' => $accrued];
        })(),

        'retry-blocked' => (function () use ($args) {
            $lesson = Lesson::query()->findOrFail($args['lesson_id']);

            $earning = app(InstructorEarningServiceInterface::class)
                ->createFromLesson($lesson);

            return ['earning_id' => $earning?->id];
        })(),

        'set-default' => (function () use ($args) {
            $instructor = User::query()->findOrFail($args['instructor_id']);
            $method = InstructorPayoutMethod::query()->findOrFail($args['method_id']);

            app(InstructorPayoutMethodServiceInterface::class)
                ->setDefault($method, $instructor);

            return ['method_id' => $method->id];
        })(),

        // payout execution races.
        'queue-execution' => (function () use ($args) {
            $withdrawal = InstructorWithdrawalRequest::query()->findOrFail($args['withdrawal_id']);
            $actor = User::query()->findOrFail($args['actor_id']);

            $attempt = app(InstructorPayoutExecutionServiceInterface::class)
                ->queueExecution($withdrawal, $actor);

            return ['attempt_id' => $attempt->id, 'reference' => $attempt->reference, 'sequence' => $attempt->execution_sequence];
        })(),

        'retry-payout' => (function () use ($args) {
            $withdrawal = InstructorWithdrawalRequest::query()->findOrFail($args['withdrawal_id']);
            $actor = User::query()->findOrFail($args['actor_id']);

            $attempt = app(InstructorPayoutExecutionServiceInterface::class)
                ->retry($withdrawal, $actor, $args['reason'] ?? 'Concurrency test retry.');

            return ['attempt_id' => $attempt->id, 'reference' => $attempt->reference, 'sequence' => $attempt->execution_sequence];
        })(),

        'apply-payout-event' => (function () use ($args) {
            $event = new NormalizedPayoutEvent(
                provider: $args['provider'],
                providerEventId: $args['provider_event_id'],
                eventType: $args['event_type'] ?? 'status_changed',
                providerPayoutId: $args['provider_payout_id'],
                attemptStatus: InstructorPayoutAttemptStatus::from($args['status']),
                amountMinor: $args['amount_minor'] ?? null,
                currencyCode: $args['currency_code'] ?? null,
                occurredAt: CarbonImmutable::now(),
                payloadHash: hash('sha256', $args['provider_event_id']),
                signatureValid: true,
            );

            app(InstructorPayoutExecutionServiceInterface::class)->handleNormalizedEvent($event);

            return ['handled' => true];
        })(),

        // booking refund races.
        'refund-to-wallet' => (function () use ($args) {
            $booking = Booking::query()->findOrFail($args['booking_id']);

            $booking = app(BookingPaymentServiceInterface::class)->refundToWallet($booking, 'Concurrency test: wallet path.');

            return ['payment_status' => $booking->payment_status->value];
        })(),

        'refund-via-provider' => (function () use ($args) {
            $booking = Booking::query()->findOrFail($args['booking_id']);
            $actor = User::query()->findOrFail($args['actor_id']);

            $booking = app(BookingPaymentServiceInterface::class)->refundViaProvider($booking, $actor, 'Concurrency test: provider path.');

            return ['payment_status' => $booking->payment_status->value];
        })(),

        // two workers race a free-demo booking for the SAME
        // student+instructor pair (at different slots, so this never
        // collides with the pre-existing duplicate-slot/overlap guards).
        // The instructor-scoped GET_LOCK in BookingRepository::withInstructorLock()
        // plus the freeDemoConsumed() re-check inside that lock must let
        // exactly one caller create a booking; the loser must observe
        // FreeDemoAlreadyUsedException, never a second row.
        'book-free-demo' => (function () use ($args) {
            $booking = app(BookingServiceInterface::class)->request(new CreateBookingData(
                typeKey: 'free_demo',
                studentId: (int) $args['student_id'],
                instructorId: (int) $args['instructor_id'],
                startsAt: CarbonImmutable::parse($args['starts_at']),
                durationMinutes: 30,
            ));

            return ['booking_id' => $booking->id, 'status' => $booking->status->value];
        })(),

        // RazorpayX destination provisioning race. Binds a
        // network-free fake client (Mockery cannot cross a process
        // boundary) — the property under test is the database layer's
        // uniqueness/locking, never this client's behavior.
        'razorpayx-provision' => (function () use ($args) {
            app()->instance(RazorpayXPayoutClientInterface::class, new RazorpayXConcurrencyFakeClient);

            $settings = app(RazorpayXPayoutSettings::class);
            $settings->razorpayx_contact_provisioning_enabled = true;
            $settings->razorpayx_fund_account_provisioning_enabled = true;
            $settings->save();

            $method = InstructorPayoutMethod::query()->findOrFail($args['method_id']);
            $actor = User::query()->findOrFail($args['actor_id']);

            $link = app(RazorpayXDestinationProvisioningServiceInterface::class)->provision($method, $actor);

            return ['link_id' => $link->id, 'status' => $link->status->value, 'contact_id' => $link->provider_contact_id, 'fund_account_id' => $link->provider_fund_account_id];
        })(),

        // Stripe collection concurrency races. Binds a
        // network-free fake client (Mockery cannot cross a process
        // boundary) — the property under test is the database/service
        // layer's uniqueness/locking/idempotency, never this client's
        // behavior.
        'stripe-initiate' => (function () use ($args) {
            app()->instance(StripeGatewayClient::class, new StripeConcurrencyFakeClient(
                simulateTimeoutOnCreate: (bool) ($args['simulate_timeout'] ?? false),
            ));

            $booking = Booking::query()->findOrFail($args['booking_id']);

            $intent = app(BookingPaymentServiceInterface::class)->initiate($booking);

            return ['booking_id' => $booking->id, 'status' => $intent->status];
        })(),

        // Dispatches a genuinely signed Stripe webhook POST through the
        // real HTTP kernel (routing + middleware + BookingPaymentWebhookController),
        // exactly as a real Stripe delivery would arrive — never a direct
        // service call, so this proves the full settlement path is race-safe.
        'stripe-webhook-succeed' => (function () use ($args, $app) {
            $body = json_encode([
                'type' => 'payment_intent.succeeded',
                'data' => [
                    'object' => [
                        'id' => $args['intent_id'],
                        'amount' => $args['amount_minor'],
                        'currency' => $args['currency'],
                        'metadata' => ['booking_reference' => $args['booking_reference']],
                    ],
                ],
            ], JSON_THROW_ON_ERROR);

            $timestamp = time();
            $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $args['webhook_secret']);

            $request = Request::create(
                '/api/webhooks/bookings/payments/stripe',
                'POST',
                [], [], [],
                [
                    'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_ACCEPT' => 'application/json',
                ],
                $body,
            );

            $response = $app->make(HttpKernel::class)->handle($request);

            return ['status_code' => $response->getStatusCode(), 'content' => $response->getContent()];
        })(),

        'reconcile-booking-payment' => (function () use ($args) {
            app()->instance(StripeGatewayClient::class, new StripeConcurrencyFakeClient);

            $payment = BookingPayment::query()->findOrFail($args['payment_id']);

            $updated = app(BookingPaymentReconciliationServiceInterface::class)->reconcileAttempt($payment);

            return ['payment_id' => $updated->id, 'status' => $updated->status->value];
        })(),

        'reconcile-booking-payments-sweep' => (function () use ($args) {
            app()->instance(StripeGatewayClient::class, new StripeConcurrencyFakeClient);

            $examined = app(BookingPaymentReconciliationServiceInterface::class)->reconcileDue((int) ($args['limit'] ?? 200));

            return ['examined' => $examined];
        })(),

        'release-expired-booking-reservations' => (function () {
            Artisan::call('booking:release-expired');

            return ['ran' => true];
        })(),

        // two concurrent finalizers racing the same
        // lesson's outcome. Proves FinalizeLessonOutcomeAction's row
        // lock + idempotent-replay guard serializes real concurrent
        // access rather than merely a sequential simulation: exactly
        // one caller must observe applied=true, the loser must observe
        // applied=false with no exception, and — since a Completed
        // outcome fans out into CreateEarningOnLessonCompleted — exactly
        // one InstructorEarning row must exist afterward.
        'finalize-lesson-outcome' => (function () use ($args) {
            $lesson = Lesson::query()->findOrFail($args['lesson_id']);
            $outcome = LessonOutcome::from($args['outcome']);

            $result = app(LessonOutcomeServiceInterface::class)->finalize($lesson, $outcome);

            return ['applied' => $result->applied, 'lesson_status' => $result->lesson->status->value];
        })(),

        // two concurrent wallet-refund executors
        // racing the same disposition. Proves ExecuteLessonWalletRefundAction's
        // row lock + WalletLedgerService's idempotency-key-guarded credit
        // converge to exactly one Refund ledger entry under genuine
        // cross-connection concurrency, not just the sequential
        // stale-copy simulation in LessonWalletRefundTest.
        'execute-lesson-wallet-refund' => (function () use ($args) {
            $disposition = LessonFinancialDisposition::query()->findOrFail($args['disposition_id']);

            $result = app(ExecuteLessonWalletRefundAction::class)->execute($disposition);

            return ['credited' => $result->credited];
        })(),

        // two concurrent student review submissions
        // racing the same eligibility. Proves the eligibility row lock +
        // idempotent-if-already-Used guard in SubmitLessonReviewAction
        // converge to exactly one LessonReview under genuine
        // cross-connection concurrency, not just the sequential
        // stale-copy simulation in StudentReviewSubmissionTest.
        'submit-lesson-review' => (function () use ($args) {
            $eligibility = LessonReviewEligibility::query()->findOrFail($args['eligibility_id']);
            $student = User::query()->findOrFail($args['student_id']);

            $result = app(StudentReviewServiceInterface::class)->submit($eligibility, $student, new SubmitStudentReviewData(
                overallRating: (int) $args['overall_rating'],
                content: $args['content'] ?? null,
            ));

            return ['applied' => $result->applied, 'review_id' => $result->review->id];
        })(),

        // two workers race the SAME frozen ineligible-refund
        // disposition against the SAME captured payment (a duplicate
        // job/event delivery of one cancellation decision). The captured
        // payment's row lock inside lockedUnresolvedCapturedPayment()
        // must let exactly one caller record the disposition; the loser
        // must see BookingException("already resolved"), never a second
        // metadata write.
        'record-ineligible-cancellation' => (function () use ($args) {
            $booking = Booking::query()->findOrFail($args['booking_id']);

            $decision = new CancellationRefundDecision(
                eligible: false,
                policyCode: 'late_cancellation',
                cutoffAt: CarbonImmutable::parse($args['cutoff_at']),
                windowHours: (int) $args['window_hours'],
                cancelledAt: CarbonImmutable::parse($args['cancelled_at']),
                startsAt: CarbonImmutable::parse($args['starts_at']),
            );

            $booking = app(BookingPaymentServiceInterface::class)->recordIneligibleCancellation($booking, $decision);

            return ['payment_status' => $booking->payment_status->value];
        })(),

        // two workers race a reschedule of the SAME booking
        // when only one allowance remains. The existing instructor-scoped
        // GET_LOCK in BookingRepository::withInstructorLock() (already
        // wrapping BookingService::reschedule()) must serialize the two
        // attempts so the second one re-reads the just-committed
        // successful count and is correctly rejected — never both
        // succeeding, never a count exceeding the configured limit.
        'reschedule-booking' => (function () use ($args) {
            $booking = Booking::query()->findOrFail($args['booking_id']);
            $actor = BookingActor::from($args['actor'] ?? 'student');

            $updated = app(BookingServiceInterface::class)->reschedule($booking, new RescheduleBookingData(
                startsAt: CarbonImmutable::parse($args['starts_at']),
                actor: $actor,
            ));

            return ['booking_id' => $updated->id, 'starts_at' => $updated->starts_at->toIso8601String()];
        })(),

        'cancel-booking-as-user' => (function () use ($args) {
            $booking = Booking::query()->findOrFail($args['booking_id']);
            $actor = User::query()->findOrFail($args['actor_id']);

            app(BookingServiceInterface::class)->cancel($booking, new CancelBookingData(
                BookingActor::forUser($actor, $booking),
                'Concurrency test cancellation.',
            ));

            return ['cancelled' => true];
        })(),

        // two workers race getOrCreateForStudent for the SAME
        // student; the user_id unique index must leave exactly one code
        // and both callers must receive it.
        'referral-generate-code' => (function () use ($args) {
            $student = User::query()->findOrFail($args['student_id']);

            $code = app(ReferralCodeServiceInterface::class)->getOrCreateForStudent($student);

            return ['code_id' => $code->id, 'code' => $code->code];
        })(),

        // two workers race attribution for the SAME referred
        // student with different codes; the referred_student_id unique
        // index must leave exactly one attribution (loser returns null).
        'referral-attribute' => (function () use ($args) {
            $referred = User::query()->findOrFail($args['referred_student_id']);

            $attribution = app(ReferralAttributionServiceInterface::class)
                ->attributeFromRegistration($referred, $args['code'], $args['source'] ?? null);

            return ['attribution_id' => $attribution?->id, 'referrer_id' => $attribution?->referrer_id];
        })(),

        // two workers evaluate lesson(s); unique(lesson_id)
        // and the locked class-cap check must keep rewards exactly-once.
        'referral-evaluate-lesson' => (function () use ($args) {
            $lesson = Lesson::query()->findOrFail($args['lesson_id']);

            $reward = app(ReferralEligibilityServiceInterface::class)
                ->evaluateCompletedLesson($lesson);

            return ['reward_id' => $reward?->id, 'status' => $reward?->status?->value];
        })(),

        // two workers credit the SAME reward; the row lock +
        // ledger idempotency key must post exactly one credit.
        'referral-credit-reward' => (function () use ($args) {
            $reward = ReferralReward::query()->findOrFail($args['reward_id']);

            $result = app(ReferralRewardServiceInterface::class)->creditReward($reward);

            return ['status' => $result->status->value, 'ledger_entry_id' => $result->wallet_ledger_entry_id];
        })(),

        // two workers invalidate the SAME credited reward;
        // exactly one reversal ledger entry may ever exist.
        'referral-reverse-lesson' => (function () use ($args) {
            $lesson = Lesson::query()->findOrFail($args['lesson_id']);
            $actor = User::query()->findOrFail($args['actor_id']);

            $result = app(ReferralRewardServiceInterface::class)
                ->reevaluateLesson($lesson, $actor, 'concurrency_test_invalidation');

            return ['status' => $result?->status?->value, 'reversal_ledger_entry_id' => $result?->reversal_ledger_entry_id];
        })(),

        // racing admin decisions on the same reward. Losers
        // throw ReferralException (ok:false), which IS the correct
        // outcome; the assertions live in the test class.
        'referral-approve-reward' => (function () use ($args) {
            $reward = ReferralReward::query()->findOrFail($args['reward_id']);
            $admin = User::query()->findOrFail($args['admin_id']);

            $result = app(ReferralRewardServiceInterface::class)
                ->approveHeldReward($reward, $admin, 'Concurrency approval.');

            return ['status' => $result->status->value, 'ledger_entry_id' => $result->wallet_ledger_entry_id];
        })(),

        'referral-reject-reward' => (function () use ($args) {
            $reward = ReferralReward::query()->findOrFail($args['reward_id']);
            $admin = User::query()->findOrFail($args['admin_id']);

            $result = app(ReferralRewardServiceInterface::class)
                ->rejectHeldReward($reward, $admin, 'Concurrency rejection.');

            return ['status' => $result->status->value];
        })(),

        'referral-retry-reward' => (function () use ($args) {
            $reward = ReferralReward::query()->findOrFail($args['reward_id']);
            $admin = User::query()->findOrFail($args['admin_id']);

            $result = app(ReferralRewardServiceInterface::class)
                ->retryFailedCredit($reward, $admin, 'Concurrency retry.');

            return ['status' => $result->status->value, 'ledger_entry_id' => $result->wallet_ledger_entry_id];
        })(),

        'referral-complete-reversal' => (function () use ($args) {
            $reward = ReferralReward::query()->findOrFail($args['reward_id']);
            $admin = User::query()->findOrFail($args['admin_id']);

            $result = app(ReferralRewardServiceInterface::class)
                ->completeRequiredReversal($reward, $admin, 'Concurrency reversal.');

            return ['status' => $result->status->value, 'reversal_ledger_entry_id' => $result->reversal_ledger_entry_id];
        })(),

        'referral-correct-attribution' => (function () use ($args) {
            $attribution = ReferralAttribution::query()->findOrFail($args['attribution_id']);
            $newReferrer = User::query()->findOrFail($args['new_referrer_id']);
            $admin = User::query()->findOrFail($args['admin_id']);

            $result = app(ReferralAttributionServiceInterface::class)
                ->correctAttribution($attribution, $newReferrer, $admin, 'Concurrency correction.');

            return ['referrer_id' => $result->referrer_id];
        })(),

        'referral-disable-code' => (function () use ($args) {
            $code = ReferralCode::query()->findOrFail($args['code_id']);
            $admin = User::query()->findOrFail($args['admin_id']);

            $result = app(ReferralCodeServiceInterface::class)
                ->disable($code, $admin, 'Concurrency disable.');

            return ['status' => $result->status->value];
        })(),

        // two workers race deactivating a DIFFERENT one of
        // the last two active Super Admins. SuperAdminGuardService's
        // named lifecycle lock must serialize the two, so whichever
        // commits second re-reads the other's already-committed change
        // and correctly refuses — never both succeeding.
        'deactivate-super-admin' => (function () use ($args) {
            $user = User::query()->findOrFail($args['user_id']);

            app(SuperAdminGuardService::class)->protect($user, fn (User $u) => $u->update(['status' => User::STATUS_INACTIVE]));

            return ['user_id' => $user->id, 'status' => $user->fresh()->status];
        })(),

        // two workers race the idle-expiry check for the SAME
        // tracked session at (or just past) its expiry boundary.
        // TrackUserSession::expireIfIdle()'s row lock must serialize the
        // two, so the session is deleted (expired) exactly once — never
        // twice, and never "revived" by a second request reading a
        // stale, not-yet-deleted row.
        'idle-session-check' => (function () use ($args) {
            CarbonImmutable::setTestNow(CarbonImmutable::parse($args['now']));

            $user = User::query()->findOrFail($args['user_id']);

            $request = Request::create('/dashboard', 'GET');
            $request->setUserResolver(fn () => $user);

            $expired = app(TrackUserSession::class)->expireIfIdle($request, $args['session_id']);

            return ['expired' => $expired];
        })(),

        'instructor-activate' => (function () use ($args) {
            $admin = User::query()->findOrFail($args['admin_id']);
            $instructor = User::query()->findOrFail($args['instructor_id']);

            $profile = app(InstructorOnboardingService::class)->activate($instructor, $admin);

            return ['instructor_status' => $profile->instructor_status->value];
        })(),

        // two workers race suspend() vs. archive() for the
        // SAME student. StudentLifecycleService::transitionStatus()'s
        // row lock must serialize the two, so exactly one commits and
        // the other observes a rejected (stale-current-state) transition.
        'student-suspend' => (function () use ($args) {
            $admin = User::query()->findOrFail($args['admin_id']);
            $student = User::query()->findOrFail($args['student_id']);

            $profile = app(StudentLifecycleService::class)->suspend($student, $admin, 'Concurrency test suspension.');

            return ['student_status' => $profile->student_status->value];
        })(),

        'student-archive' => (function () use ($args) {
            $admin = User::query()->findOrFail($args['admin_id']);
            $student = User::query()->findOrFail($args['student_id']);

            $profile = app(StudentLifecycleService::class)->archive($student, $admin, 'Concurrency test archive.');

            return ['student_status' => $profile->student_status->value];
        })(),

        // a delayed verification/legacy-reconciliation
        // activation racing an admin suspension for the SAME Registered
        // student. Whichever commits first, the row lock in
        // systemActivateFromRegistered()/transitionStatus() means the
        // second caller re-reads the just-committed status: if suspend
        // wins first, alignment sees "not Registered" and silently
        // no-ops; if alignment wins first, suspend still succeeds (Active
        // is a valid FROM-state for suspend too) — either ordering must
        // converge to Suspended, never leave the account resurrected as
        // Active.
        'student-align-legacy' => (function () use ($args) {
            $student = User::query()->findOrFail($args['student_id']);

            $applied = app(StudentLifecycleService::class)->alignLegacyVerifiedStudent($student);

            return ['applied' => $applied, 'student_status' => $student->fresh()->profile->student_status->value];
        })(),

        // an availability reduction (deactivate window,
        // unconfirmed) racing a free-demo booking for the same
        // instructor. Both paths take the same booking:host:%d GET_LOCK,
        // so exactly one order occurs: booking-first → the deactivation
        // observes the fresh confirmed booking and is rejected with
        // requires-confirmation (no mutation); deactivation-first → the
        // booking's ensureAvailable() sees no covering window and fails.
        // Never both succeeding, never a window deactivated with an
        // unacknowledged affected booking.
        'availability-deactivate' => (function () use ($args) {
            $actor = User::query()->findOrFail($args['actor_id']);
            $window = TeacherAvailability::query()->findOrFail($args['availability_id']);

            $updated = app(InstructorAvailabilityService::class)->setActive($window, false, $actor);

            return ['is_active' => (bool) $updated->is_active];
        })(),

        // an interactive student action (favorite) racing
        // an admin suspension of the SAME student. The favorite's
        // in-transaction assertEligibleForStudentAction() takes a
        // lockForUpdate() read on the same profile row suspend locks, so
        // the two serialize: favorite-first → row created, then suspend
        // commits; suspend-first → favorite observes Suspended and is
        // rejected with the generic message, creating no row. Both
        // orderings are valid; what may never happen is a favorite
        // committing on stale pre-suspension state after the suspension
        // is authoritative.
        'student-favorite' => (function () use ($args) {
            $student = User::query()->findOrFail($args['student_id']);
            $instructor = User::query()->findOrFail($args['instructor_id']);

            $favorite = app(StudentFavoriteInstructorService::class)->favorite($student, $instructor);

            return ['favorite_id' => $favorite->id];
        })(),

        // two workers race claiming the SAME homework
        // reminder identity (same assignment/recipient/due-date/offset).
        // The homework_due_reminders composite unique index must let
        // exactly one caller claim (and queue the send); the loser must
        // observe "already_claimed", never a second row or a second
        // queued send.
        'claim-homework-reminder' => (function () use ($args) {
            $assignment = HomeworkAssignment::query()->findOrFail($args['assignment_id']);

            $outcome = app(HomeworkReminderDispatcher::class)->claimAndDispatch($assignment, (int) $args['offset_hours']);

            return ['outcome' => $outcome];
        })(),

        // two workers race retrying the SAME failed job by
        // UUID. FailedJobRetryService's row lock on failed_jobs must let
        // exactly one worker requeue+forget it; the loser's fresh
        // re-read (under the lock) finds the row already gone and
        // returns NotFound — never a duplicate enqueue.
        'retry-failed-job' => (function () use ($args) {
            $actor = User::query()->findOrFail($args['actor_id']);

            $result = app(FailedJobRetryService::class)->retry($actor, $args['uuid']);

            return ['outcome' => $result->outcome->value];
        })(),

        // two workers race a currency-status disable versus
        // a NEW payment-attempt initiation for a booking in that same
        // currency. Both sides lock the same Currency row (initiate()
        // via CurrencyEligibilityPolicy::assertUsable(..., lock: true),
        // the admin path via CurrencyStatusService) inside a DB
        // transaction, so MySQL serializes the two: whichever commits
        // first wins — disable-first rejects the payment attempt with
        // no provider call; initiate-first lets the existing attempt
        // proceed (the disable still commits afterward).
        'disable-currency' => (function () use ($args) {
            $currency = Currency::query()->findOrFail($args['currency_id']);

            app(CurrencyStatusService::class)->update($currency, ['status' => 'inactive']);

            return ['currency_id' => $currency->id, 'status' => 'inactive'];
        })(),

        'fake-initiate' => (function () use ($args) {
            $booking = Booking::query()->findOrFail($args['booking_id']);

            $intent = app(BookingPaymentServiceInterface::class)->initiate($booking);

            return ['booking_id' => $booking->id, 'status' => $intent->status, 'reference' => $intent->reference];
        })(),

        // two workers run the FULL reminder job for the
        // SAME already-claimed reminder at the same instant. Each
        // channel's claim transaction (lockForUpdate + Sending lease)
        // must let exactly one worker actually invoke Notification::sendNow()
        // per channel; the loser observes the row already
        // Sending/Dispatched and skips. Never two database notification
        // rows, never two real sends of the same channel.
        'run-homework-reminder-job' => (function () use ($args) {
            $job = new SendHomeworkDueReminderJob((int) $args['reminder_id']);
            $job->handle(app(AuditTrailService::class), app(HomeworkReminderChannelSender::class));

            $reminder = HomeworkDueReminder::query()->findOrFail($args['reminder_id']);

            return ['status' => $reminder->status->value];
        })(),

        // Wallet recharge (SRS §13.11) collection-side races — mirrors
        // the Stripe collection ops above: a network-free fake client
        // (Mockery cannot cross a process boundary) so the property
        // under test is the database/service layer's locking and
        // idempotency, never this client's behavior.
        'wallet-recharge-initiate' => (function () use ($args) {
            app()->instance(RazorpayGatewayClient::class, new RazorpayConcurrencyFakeClient);
            app()->instance(StripeGatewayClient::class, new StripeConcurrencyFakeClient);

            $student = User::query()->findOrFail($args['student_id']);

            $checkout = app(WalletRechargeServiceInterface::class)->initiate($student, (int) $args['amount_minor']);

            return ['reference' => $checkout->reference, 'provider' => $checkout->provider];
        })(),

        // Dispatches a genuinely signed Razorpay webhook POST through
        // the real HTTP kernel (routing + middleware +
        // WalletRechargeWebhookController), exactly as a real Razorpay
        // delivery would arrive — never a direct service call.
        'wallet-recharge-webhook-succeed' => (function () use ($args, $app) {
            $body = json_encode([
                'event' => 'payment.captured',
                'payload' => [
                    'payment' => [
                        'entity' => [
                            'id' => $args['payment_id'],
                            'order_id' => $args['order_id'],
                            'amount' => $args['amount_minor'],
                            'currency' => $args['currency'],
                            'notes' => ['wallet_recharge_reference' => $args['reference']],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR);

            $signature = hash_hmac('sha256', $body, $args['webhook_secret']);

            $request = Request::create(
                '/api/webhooks/wallets/recharges/razorpay',
                'POST',
                [], [], [],
                [
                    'HTTP_X_RAZORPAY_SIGNATURE' => $signature,
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_ACCEPT' => 'application/json',
                ],
                $body,
            );

            $response = $app->make(HttpKernel::class)->handle($request);

            return ['status_code' => $response->getStatusCode(), 'content' => $response->getContent()];
        })(),

        // The Razorpay Checkout.js browser-verify fast path — raced
        // against wallet-recharge-webhook-succeed above for the SAME
        // recharge; exactly one of the two may ever post the
        // RechargeConfirmed ledger credit.
        'wallet-recharge-verify' => (function () use ($args) {
            $student = User::query()->findOrFail($args['student_id']);

            $recharge = app(WalletRechargeServiceInterface::class)->verifyRazorpayCheckout(
                $student,
                (string) $args['order_id'],
                (string) $args['payment_id'],
                (string) $args['signature'],
            );

            return ['status' => $recharge->status->value];
        })(),

        'wallet-recharge-reconcile' => (function () use ($args) {
            app()->instance(RazorpayGatewayClient::class, new RazorpayConcurrencyFakeClient);
            app()->instance(StripeGatewayClient::class, new StripeConcurrencyFakeClient(
                retrieveAmountReceived: isset($args['stripe_amount_received']) ? (int) $args['stripe_amount_received'] : null,
                retrieveCurrency: $args['stripe_currency'] ?? null,
            ));

            $examined = app(WalletRechargeReconciliationService::class)->reconcileDue((int) ($args['limit'] ?? 200));

            return ['examined' => $examined];
        })(),

        // Dispatches a genuinely signed Stripe webhook POST through the
        // real HTTP kernel — mirrors wallet-recharge-webhook-succeed and
        // stripe-webhook-succeed above for the booking domain.
        'wallet-recharge-stripe-webhook-succeed' => (function () use ($args, $app) {
            $body = json_encode([
                'type' => 'payment_intent.succeeded',
                'data' => [
                    'object' => [
                        'id' => $args['intent_id'],
                        'amount' => $args['amount_minor'],
                        'amount_received' => $args['amount_minor'],
                        'currency' => $args['currency'],
                        'status' => 'succeeded',
                        'metadata' => ['wallet_recharge_reference' => $args['reference']],
                    ],
                ],
            ], JSON_THROW_ON_ERROR);

            $timestamp = time();
            $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $args['webhook_secret']);

            $request = Request::create(
                '/api/webhooks/wallets/recharges/stripe',
                'POST',
                [], [], [],
                [
                    'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_ACCEPT' => 'application/json',
                ],
                $body,
            );

            $response = $app->make(HttpKernel::class)->handle($request);

            return ['status_code' => $response->getStatusCode(), 'content' => $response->getContent()];
        })(),

        default => throw new InvalidArgumentException("Unknown operation: {$operation}"),
    };

    echo json_encode(['ok' => true, 'op' => $operation, 'result' => $result]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'op' => $operation, 'exception' => $e::class, 'message' => $e->getMessage()]);
}
