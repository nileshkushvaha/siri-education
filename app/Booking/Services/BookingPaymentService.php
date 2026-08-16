<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\BookingPaymentReconciliationServiceInterface;
use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\Contracts\PaymentCollectionEligibilityServiceInterface;
use App\Booking\Contracts\PaymentProviderInterface;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\DTOs\CancellationRefundDecision;
use App\Booking\DTOs\PaymentIntentData;
use App\Booking\DTOs\PaymentStatusResult;
use App\Booking\Enums\BookingActivityAction;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingPaymentReconciliationIssueType;
use App\Booking\Enums\BookingPaymentReconciliationSeverity;
use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Events\BookingPaymentSucceeded;
use App\Booking\Exceptions\BookingException;
use App\Country\Enums\CountryFeature;
use App\Country\Services\CountryFeatureResolver;
use App\Country\Services\CountryResolver;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\User;
use App\Models\Wallet;
use App\Payments\DTOs\PaymentCheckoutData;
use App\Payments\Services\PaymentCheckoutService;
use App\Services\AuditTrailService;
use App\Services\Student\StudentLifecycleService;
use App\Support\Financial\CurrencyEligibilityPolicy;
use App\Support\Financial\Exceptions\CurrencyNotUsableException;
use App\Support\Financial\FinancialOperation;
use App\Support\MoneyFormatter;
use App\Wallet\Enums\WalletLedgerEntryType;
use App\Wallet\Exceptions\InsufficientBalanceException;
use App\Wallet\Exceptions\WalletNotUsableException;
use App\Wallet\Services\WalletLedgerService;
use App\Wallet\Services\WalletService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Provider-agnostic payment workflow + booking/payment status sync:
 *
 *   reservation (pending/pending) → initiate → provider
 *   success  → paid   + reservation cleared + auto-confirmable → Confirmed
 *   failure  → failed + reservation holds until expiry (retry allowed)
 *   refund   → refunded + active booking cancelled
 *   cancel paid booking → refund (via SyncPaymentOnCancellation)
 *   late success on a terminal booking → Option B (see handleLateTerminalPayment())
 */
final class BookingPaymentService implements BookingPaymentServiceInterface
{
    public function __construct(
        private readonly BookingRepositoryInterface $bookings,
        private readonly BookingServiceInterface $bookingService,
        private readonly PaymentProviderResolver $providers,
        private readonly PaymentCheckoutService $checkout,
        private readonly BookingPaymentRefundService $refunds,
        private readonly AuditTrailService $audit,
        private readonly WalletService $wallets,
        private readonly WalletLedgerService $walletLedger,
        private readonly CurrencyEligibilityPolicy $currencyEligibility,
        private readonly StudentLifecycleService $studentLifecycle,
        private readonly CountryFeatureResolver $countryFeatures,
        private readonly CountryResolver $countryResolver,
        private readonly PaymentCollectionEligibilityServiceInterface $collectionEligibility,
    ) {}

    public function initiate(Booking $booking): PaymentIntentData
    {
        // A booking can go terminal (cancelled/expired reservation) while
        // payment_status is still Pending — CancelBookingAction never
        // touches payment_status (see handleLateTerminalPayment()). Without
        // this check, a student could still initiate a *new* payment order
        // for a booking that can no longer be confirmed; Option B would
        // recover the money as a wallet credit if they went through with
        // it, but that is a bad-UX safety net, not a substitute for
        // blocking the attempt up front.
        if ($booking->status->isTerminal()) {
            throw new BookingException(sprintf(
                'Booking %s is %s and cannot accept a new payment.',
                $booking->reference,
                $booking->status->label(),
            ));
        }

        if (! $booking->payment_status->isPayable()) {
            throw new BookingException(sprintf(
                'Booking %s does not await payment (status: %s).',
                $booking->reference,
                $booking->payment_status->label(),
            ));
        }

        // Locked claim: two concurrent initiate() calls for the same
        // booking (double-click, retried request) must agree on one
        // reference. Reading payment_reference unlocked would let both
        // racers see null, mint their own random reference, and each
        // successfully create its own BookingPayment row — a real
        // duplicate-attempt bug a genuine MySQL race can surface. Locking
        // the row before reading forces the loser to observe the
        // winner's committed reference and reuse it instead.
        //
        // The Currency row is ALSO locked here (inside the same
        // transaction), so a concurrent admin disable is serialized
        // against this decision — whichever commits first wins; the
        // loser observes the other's already-committed state. A stale
        // browser page cannot initiate payment after an admin disables
        // the currency: this re-checks Active status at the final
        // internal boundary, never relying on the booking's earlier
        // price-resolution check alone.
        // Provider SELECTION happens before the lock and before any
        // write: it consults settings and country routing only, and a
        // disabled/misconfigured provider must fail without having
        // created an obligation.
        $providerKey = $this->assertCollectionAllowed($booking);

        [$booking, $obligation] = DB::transaction(function () use ($booking, $providerKey): array {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            try {
                $this->currencyEligibility->assertUsable((string) $locked->currency, FinancialOperation::NewInitiation, lock: true);
            } catch (CurrencyNotUsableException $e) {
                throw new BookingException($e->getMessage());
            }

            $reference = $locked->payment_reference ?? 'PAY-'.strtoupper(Str::random(12));

            // A retry after failure keeps the same reference: it is the
            // OBLIGATION's identity, not an attempt's.
            $locked = $this->bookings->updatePaymentStatus($locked, BookingPaymentStatus::Pending, $reference);

            return [$locked, $this->obligationFor($locked, $providerKey)];
        });

        // Deliberately OUTSIDE the transaction above: the gateway call
        // lives inside PaymentCheckoutService, and a database
        // transaction must never be held open across provider HTTP.
        $checkout = $this->checkout->start($obligation, $providerKey);

        return $this->intentFrom($booking, $obligation, $checkout);
    }

    /**
     * The single commercial obligation for this booking.
     *
     * One row per booking, created once and reused for every subsequent
     * attempt — a student who fails twice and succeeds on the third try
     * owed one amount, not three. Retry history lives on the Payment
     * attempt ledger; nothing about a failed attempt is written here.
     *
     * Called inside the booking row lock, so two concurrent checkout
     * requests cannot both create one.
     */
    private function obligationFor(Booking $locked, string $providerKey): BookingPayment
    {
        $existing = BookingPayment::query()->where('booking_id', $locked->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $minorUnits = MoneyFormatter::minorUnitsFor((string) $locked->currency);

        return BookingPayment::query()->create([
            'booking_id' => $locked->id,
            'user_id' => $locked->student_id,
            'provider' => $providerKey,
            'amount_minor' => (int) round(((float) $locked->price) * (10 ** $minorUnits)),
            'currency_code' => (string) $locked->currency,
            'status' => BookingPaymentRecordStatus::Pending,
            'idempotency_key' => (string) $locked->payment_reference,
            'metadata' => ['obligation_reference' => $locked->payment_reference],
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * Maps the generic kernel's checkout data onto the Booking DTO the
     * frontend already consumes, so the cutover is invisible to the
     * checkout UI.
     */
    private function intentFrom(Booking $booking, BookingPayment $obligation, PaymentCheckoutData $checkout): PaymentIntentData
    {
        $payload = $checkout->checkoutPayload;

        return new PaymentIntentData(
            bookingId: $booking->id,
            reference: (string) $booking->payment_reference,
            amount: (string) $booking->price,
            currency: (string) $obligation->currency_code,
            status: BookingPaymentRecordStatus::Pending->value,
            checkoutUrl: null,
            publicKey: isset($payload['publishable_key']) ? (string) $payload['publishable_key'] : (isset($payload['key_id']) ? (string) $payload['key_id'] : null),
            clientSecret: isset($payload['client_secret']) ? (string) $payload['client_secret'] : null,
        );
    }

    public function markPaid(Booking $booking, string $reference): Booking
    {
        // Locked, re-validated read: the webhook controller and
        // applyProviderStatus() (reconciliation/retry-verification) can
        // both reach markPaid() for the SAME booking concurrently.
        // assertReference() checking the caller-supplied $booking object
        // (fetched before either process started) let two racers both
        // observe "still Pending" and both proceed — locking the row
        // here and re-reading from it closes that gap; the loser now
        // reliably sees the winner's already-committed transition.
        [$path, $booking] = DB::transaction(function () use ($booking, $reference): array {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            $this->assertReference($locked, $reference, expected: BookingPaymentStatus::Pending);

            // A booking can go terminal (cancelled/expired/completed/no_show)
            // while its payment_status is still Pending — CancelBookingAction
            // never touches payment_status, so a genuinely authentic,
            // signature-verified gateway success can still arrive here after
            // the booking itself no longer represents a lesson anyone can
            // attend. The charge is real, so it is preserved and redirected
            // to the student's wallet rather than rejected outright or
            // silently confirming a dead booking.
            if ($locked->status->isTerminal()) {
                return ['late_terminal', $this->handleLateTerminalPayment($locked, $reference)];
            }

            return ['normal', $this->finalizeSuccessfulPayment($locked, $reference)];
        });

        // After commit only — and never for Option B's late-terminal path,
        // which returned above without settling this booking. A duplicate
        // webhook cannot re-fire this: assertReference() already rejected
        // any delivery that finds payment_status !== Pending.
        if ($path === 'normal') {
            BookingPaymentSucceeded::dispatch($booking);
        }

        return $booking;
    }

    /**
     * The successful-payment mutation shared by every settlement path
     * (gateway markPaid(), and payWithWallet() below): payment_status
     * → Paid, reservation cleared, activity logged, and — unless the
     * type needs approval — the booking auto-confirms. $locked must
     * already be a row-locked, non-terminal booking; callers own their
     * own transaction and reference/precondition checks.
     */
    private function finalizeSuccessfulPayment(Booking $locked, string $reference): Booking
    {
        $locked = $this->bookings->updatePaymentStatus($locked, BookingPaymentStatus::Paid, $reference);
        $locked = $this->bookings->clearReservation($locked);

        $this->logPayment($locked, BookingPaymentStatus::Paid, ['payment_reference' => $reference]);

        if ($locked->status === BookingStatus::Pending && ! $locked->type->requires_approval) {
            $locked = $this->bookingService->confirm($locked);
        }

        return $locked;
    }

    /**
     * Wallet checkout: no gateway, no redirect, no webhook — the whole
     * operation is one synchronous request, so unlike initiate()/
     * markPaid() there is no separate "create order" step and no
     * external reference to correlate. $student must be the booking's
     * own student; there is deliberately no admin-pays-on-behalf-of
     * path here (unlike BookingPolicy::pay(), which also allows an
     * Update:Booking permission holder for gateway checkout).
     *
     * The wallet debit and the booking/payment finalization commit as
     * one transaction: a debit can never be persisted without the
     * booking finalizing, and the booking can never finalize without
     * the debit. A retried/duplicate call is made safe by the row lock
     * below (see its own comment) rather than an idempotency key alone
     * — the debit is still given one (keyed on the fresh BookingPayment
     * attempt's id), matching every other WalletLedgerService caller's
     * convention, but the lock is the actual defense here.
     */
    public function payWithWallet(Booking $booking, User $student): Booking
    {
        $booking = DB::transaction(function () use ($booking, $student): Booking {
            // Locked, re-validated read — identical discipline to
            // markPaid()/initiate(). A concurrent second call for the
            // same booking blocks here until the first commits, then
            // observes payment_status no longer Pending and is rejected
            // by assertWalletPaymentAllowed() below: no separate
            // idempotency bookkeeping is needed, the lock plus this
            // precondition together make a double-debit impossible.
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            $this->assertWalletPaymentAllowed($locked, $student);

            $wallet = $this->resolveMatchingWallet($locked, $student);
            $payment = $this->createWalletPaymentAttempt($locked, $student);

            try {
                $this->walletLedger->debit(
                    $wallet,
                    $payment->amount_minor,
                    WalletLedgerEntryType::BookingPayment,
                    $student,
                    idempotencyKey: $this->walletDebitIdempotencyKey($payment),
                    description: sprintf('Payment for booking %s.', $locked->reference),
                    sourceType: BookingPayment::class,
                    sourceId: (string) $payment->id,
                );
            } catch (InsufficientBalanceException) {
                throw new BookingException('Your wallet balance is not sufficient to pay for this booking.');
            } catch (WalletNotUsableException) {
                throw new BookingException('Your wallet is not currently usable. Please contact support.');
            }

            $payment->forceFill([
                'status' => BookingPaymentRecordStatus::Captured,
                'paid_at' => now(),
            ])->save();

            return $this->finalizeSuccessfulPayment($locked, $payment->idempotency_key);
        });

        // Same after-commit discipline as markPaid(): the notification/
        // meeting-creation pipeline must only ever react to a genuinely
        // committed payment.
        BookingPaymentSucceeded::dispatch($booking);

        return $booking;
    }

    /**
     * Every precondition a wallet payment must satisfy, re-checked
     * against the just-locked row — never the caller-supplied $booking,
     * which may already be stale.
     */
    private function assertWalletPaymentAllowed(Booking $locked, User $student): void
    {
        if ($student->id !== $locked->student_id) {
            throw new BookingException('You may only pay for your own booking.');
        }

        $this->studentLifecycle->assertEligibleForStudentAction($student);

        // Wallet lesson payment requires both Wallet and Paid Bookings to
        // be effectively enabled for the student's country — composed
        // here rather than as its own registry feature, since no distinct
        // global switch for "wallet lesson payment" exists.
        $country = $this->countryResolver->forStudent($student);

        if (! $this->countryFeatures->isEnabled(CountryFeature::Wallet, $country)
            || ! $this->countryFeatures->isEnabled(CountryFeature::PaidBookings, $country)) {
            throw new BookingException('Wallet payments are not currently enabled.');
        }

        if ($locked->status->isTerminal()) {
            throw new BookingException(sprintf(
                'Booking %s is %s and cannot accept a new payment.',
                $locked->reference,
                $locked->status->label(),
            ));
        }

        if (! $locked->payment_status->isPayable()) {
            throw new BookingException(sprintf(
                'Booking %s does not await payment (status: %s).',
                $locked->reference,
                $locked->payment_status->label(),
            ));
        }
    }

    /**
     * The wallet must already exist in exactly the booking's currency —
     * wallet payment never creates a wallet in a different currency
     * than the student's own (single-currency, SRS §13.7), and never
     * silently converts. A student with no wallet yet gets one lazily
     * created in their OWN resolved default currency (never the
     * booking's), then compared the same as if it already existed.
     */
    private function resolveMatchingWallet(Booking $locked, User $student): Wallet
    {
        try {
            $wallet = $this->wallets->getOrCreateWallet($student, null, $student);
        } catch (CurrencyNotUsableException|ValidationException) {
            // WalletService::resolveCurrency() translates an inactive
            // currency into a ValidationException, not
            // CurrencyNotUsableException — both must map to the same
            // safe message here, since money is never earned/re-thrown
            // as a raw framework validation error to the payer.
            throw new BookingException('Your wallet currency is not currently active.');
        }

        if (strtoupper($wallet->currency_code) !== strtoupper((string) $locked->currency)) {
            throw new BookingException('Your wallet currency does not match this booking\'s currency.');
        }

        try {
            $this->currencyEligibility->assertUsable((string) $locked->currency, FinancialOperation::NewInitiation, lock: true);
        } catch (CurrencyNotUsableException) {
            throw new BookingException('This booking\'s currency is not currently active.');
        }

        return $wallet;
    }

    /**
     * A fresh BookingPayment attempt for this wallet payment. No
     * firstOrCreate()-style reuse is needed: this only ever runs after
     * assertWalletPaymentAllowed() has confirmed the booking still
     * awaits payment, inside the same transaction/row-lock as the debit
     * and finalization below — either everything here commits together,
     * or (on any failure) the whole attempt, including this row, rolls
     * back, so a failed try leaves nothing behind to reuse or collide
     * with a subsequent one. The idempotency_key is wallet-prefixed and
     * unambiguous — never a value that could be mistaken for a real
     * gateway reference (see FakePaymentProvider::createPayment() for
     * the 'PAY-' convention this deliberately does not imitate).
     */
    private function createWalletPaymentAttempt(Booking $locked, User $student): BookingPayment
    {
        $minorUnits = MoneyFormatter::minorUnitsFor((string) $locked->currency);
        $amountMinor = (int) round(((float) $locked->price) * (10 ** $minorUnits));

        return BookingPayment::query()->create([
            'booking_id' => $locked->id,
            'user_id' => $student->id,
            'provider' => 'wallet',
            'amount_minor' => $amountMinor,
            'currency_code' => (string) $locked->currency,
            'status' => BookingPaymentRecordStatus::Pending,
            'idempotency_key' => 'WALLET-'.strtoupper(Str::random(12)),
            'metadata' => ['wallet_payment' => true],
        ]);
    }

    private function walletDebitIdempotencyKey(BookingPayment $payment): string
    {
        return sprintf('booking-payment:wallet:%s', $payment->id);
    }

    /**
     * The single financial-effect path a fetchStatus() poll (manual
     * "retry verification", scheduled reconciliation sweep) is ever
     * allowed to apply — deliberately reuses markPaid()/markFailed(),
     * never a separate settlement code path, so reconciliation and
     * webhook processing can never disagree about what "success" does
     * (same discipline as InstructorPayoutExecutionService::applyProviderStatus()
     * on the payout side).
     *
     * Idempotent: a payment row already in a terminal status is left
     * untouched (only last_synced_at advances) — reconciliation
     * confirms an already-settled outcome, it never re-applies one. A
     * markPaid()/markFailed() call losing a race to a webhook that
     * settled the same booking first is swallowed the same way the
     * webhook controller itself swallows it (BookingException from
     * assertReference() means "already handled," not a real failure).
     */
    public function applyProviderStatus(BookingPayment $payment, PaymentStatusResult $status): BookingPayment
    {
        $payment = BookingPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

        if ($payment->status->isTerminal()) {
            $payment->forceFill(['last_synced_at' => now()])->save();

            return $payment;
        }

        // PAY-1 (PAY-AUD-005): the webhook path has always compared the
        // provider's amount/currency against this booking payment and
        // refused settlement on a mismatch. The RECONCILIATION path did
        // not — it read the provider's status, ignored the money, and
        // settled. A payment recovered by the scheduled sweep, or by an
        // operator pressing "retry verification", therefore skipped the
        // one check that proves we collected what we asked for.
        //
        // A mismatch is never failed and never captured: it becomes
        // ResolutionRequired (a status that existed for exactly this and
        // had no producer) and raises an operator incident. No booking
        // is confirmed, no lesson or meeting is created, no invoice is
        // issued, and no wallet movement happens — the money is real but
        // what it bought is now a human decision.
        if ($status->recordStatus === BookingPaymentRecordStatus::Captured
            && ! $this->providerMoneyMatches($payment, $status)) {
            return $this->refuseMismatchedSettlement($payment, $status);
        }

        $payment->forceFill([
            'status' => $status->recordStatus,
            'provider_payment_id' => $status->providerPaymentId ?? $payment->provider_payment_id,
            'last_synced_at' => now(),
            ...($status->recordStatus === BookingPaymentRecordStatus::Captured ? ['paid_at' => now()] : []),
            ...($status->recordStatus === BookingPaymentRecordStatus::Failed ? ['failed_at' => now()] : []),
        ])->save();

        $booking = $this->bookings->find($payment->booking_id);

        if ($booking !== null && $status->recordStatus === BookingPaymentRecordStatus::Captured) {
            try {
                $this->markPaid($booking, $payment->idempotency_key);
            } catch (BookingException) {
                // Already settled by a concurrent webhook — no-op.
            } catch (Throwable $e) {
                // The BookingPayment row above is already committed as
                // Captured, so at this point the provider's money is ours
                // and the booking is NOT financially settled. Anything
                // other than a BookingException means local settlement
                // genuinely broke rather than losing a benign race.
                //
                // Swallowed on purpose: rethrowing would abort the sweep
                // and leave the state invisible anyway. It becomes an
                // operator incident instead — the Booking-side analogue
                // of the package flow's SettlementFailed.
                $this->raiseCollectionIssue(
                    $payment,
                    BookingPaymentReconciliationIssueType::ProviderSuccessLocalIncomplete,
                    BookingPaymentReconciliationSeverity::Critical,
                    'The provider confirmed this payment but the booking could not be settled: '.$e->getMessage(),
                );
            }
        } elseif ($booking !== null && $status->recordStatus === BookingPaymentRecordStatus::Failed) {
            try {
                $this->markFailed($booking, $payment->idempotency_key, $status->safeReason);
            } catch (BookingException) {
                // Already settled by a concurrent webhook — no-op.
            }
        }

        return BookingPayment::query()->whereKey($payment->id)->firstOrFail();
    }

    /**
     * Raises a Booking collection incident without taking a constructor
     * dependency on the reconciliation service — which already depends on
     * THIS service, so injecting it would close a cycle.
     *
     * Deliberately swallows its own failures: an incident is a
     * notification about a financial state, never a reason to abort the
     * financial path that produced it. Losing the incident is bad;
     * rolling back a settled payment because we could not file paperwork
     * would be far worse.
     */
    private function raiseCollectionIssue(
        BookingPayment $payment,
        BookingPaymentReconciliationIssueType $type,
        BookingPaymentReconciliationSeverity $severity,
        string $safeSummary,
    ): void {
        try {
            app(BookingPaymentReconciliationServiceInterface::class)->raiseIssue($payment, $type, $severity, $safeSummary);
        } catch (Throwable $e) {
            // Intentionally ignored — see the docblock above. Logged so a
            // swallowed incident is still traceable rather than silent.
            report($e);
        }
    }

    /**
     * Does the money the provider reports match the obligation this
     * booking payment snapshotted at checkout?
     *
     * Compared against the BookingPayment row, never against today's
     * pricing matrix: the obligation is what the student agreed to when
     * checkout opened, and re-deriving it now would make the check
     * self-confirming after any price change.
     *
     * A provider that reports no money at all cannot be said to match.
     */
    private function providerMoneyMatches(BookingPayment $payment, PaymentStatusResult $status): bool
    {
        if (! $status->reportsMoney()) {
            return false;
        }

        return $status->verifiedAmountMinor === (int) $payment->amount_minor
            && strtoupper((string) $status->verifiedCurrency) === strtoupper((string) $payment->currency_code);
    }

    /**
     * Parks a verified-success-but-wrong-money attempt for an operator.
     *
     * Deliberately raises the reconciliation issue types that already
     * existed on the Booking queue and had never had a producer —
     * AmountMismatch and CurrencyMismatch — rather than inventing new
     * ones. Currency is reported in preference to amount when both
     * differ: a wrong currency explains a wrong amount, and one incident
     * describing the real problem beats two describing symptoms.
     */
    private function refuseMismatchedSettlement(BookingPayment $payment, PaymentStatusResult $status): BookingPayment
    {
        $currencyMismatched = strtoupper((string) $status->verifiedCurrency) !== strtoupper((string) $payment->currency_code);

        $payment->forceFill([
            'status' => BookingPaymentRecordStatus::ResolutionRequired,
            'provider_payment_id' => $status->providerPaymentId ?? $payment->provider_payment_id,
            'last_synced_at' => now(),
        ])->save();

        $this->raiseCollectionIssue(
            $payment,
            $currencyMismatched
                ? BookingPaymentReconciliationIssueType::CurrencyMismatch
                : BookingPaymentReconciliationIssueType::AmountMismatch,
            BookingPaymentReconciliationSeverity::Critical,
            sprintf(
                'The provider reported %s %s but this booking payment expects %s %s. Settlement was refused.',
                $status->verifiedAmountMinor ?? 'an unknown amount',
                $status->verifiedCurrency ?? '(no currency)',
                $payment->amount_minor,
                $payment->currency_code,
            ),
        );

        return BookingPayment::query()->whereKey($payment->id)->firstOrFail();
    }

    public function markFailed(Booking $booking, string $reference, ?string $reason = null): Booking
    {
        // Same locked-read discipline as markPaid() — see its docblock.
        return DB::transaction(function () use ($booking, $reference, $reason): Booking {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            $this->assertReference($locked, $reference, expected: BookingPaymentStatus::Pending);

            $locked = $this->bookings->updatePaymentStatus($locked, BookingPaymentStatus::Failed, $reference);

            $this->logPayment($locked, BookingPaymentStatus::Failed, array_filter(['reason' => $reason]));

            return $locked;
        });
    }

    /**
     * The default, normal-path refund never touches the gateway. It
     * locks the booking (the serialization point shared with
     * refundViaProvider — whichever path's transaction commits first
     * wins; the loser sees payment_status no longer Paid or the
     * resolution already tagged), credits the student's wallet in the
     * payment's original currency, and finalizes in the same
     * transaction — there is no external call here to hold the lock
     * open for.
     */
    public function refundToWallet(Booking $booking, ?string $reason = null, ?CancellationRefundDecision $decision = null): Booking
    {
        return DB::transaction(function () use ($booking, $reason, $decision): Booking {
            $booking = $this->lockedPaidBooking($booking);
            $payment = $this->lockedUnresolvedCapturedPayment($booking);

            $student = $booking->student;
            // tryCreditWalletForRefund() mutates $payment's own metadata
            // (wallet_ledger_entry_id) on success before we add the
            // resolution tag here, so it is never overwritten below.
            $credited = $student !== null && $this->tryCreditWalletForRefund($booking, $payment, $student);
            $decisionMeta = $decision?->toMeta() ?? [];

            if (! $credited && $student !== null) {
                // The platform owes this student their money back and the
                // automatic route failed. A guest booking with no account
                // is deliberately excluded — there is no wallet to credit,
                // which is a product limitation resolved through
                // refundViaProvider(), not a system failure to page on.
                $this->raiseCollectionIssue(
                    $payment,
                    BookingPaymentReconciliationIssueType::WalletCreditFailed,
                    BookingPaymentReconciliationSeverity::Critical,
                    'A cancellation refund was approved but the wallet credit failed; the student is owed money.',
                );
            }

            $payment->forceFill([
                'metadata' => $credited
                    ? [...($payment->metadata ?? []), 'refund_resolution' => 'wallet_credited', 'refund_reason' => $reason, ...$decisionMeta]
                    : [
                        ...($payment->metadata ?? []),
                        'refund_resolution' => 'manual_resolution_required',
                        'manual_resolution_required' => true,
                        'manual_resolution_reason' => $student === null
                            ? 'Guest booking has no user account to hold a wallet credit — use refundViaProvider() or resolve manually.'
                            : 'Automatic wallet credit failed — needs manual admin/support resolution.',
                        'refund_reason' => $reason,
                        ...$decisionMeta,
                    ],
            ])->save();

            return $this->finalizeRefundedBooking($booking, $reason);
        });
    }

    /**
     * SRS 11.24/6.8: a late student cancellation is not refund-eligible.
     * The booking is already Cancelled by the time
     * this runs (BookingService::cancel() already committed); this
     * only records the frozen decision on the payment for
     * traceability. payment_status deliberately stays Paid — the
     * platform retained the charge, nothing was refunded, so
     * "Refunded" would misrepresent what happened (and would
     * incorrectly count against revenue in reporting). No ledger
     * entry, no gateway call. lockedUnresolvedCapturedPayment() is the
     * same idempotency guard refundToWallet() relies on: a duplicate
     * event delivery finds the payment already resolved and no-ops.
     */
    public function recordIneligibleCancellation(Booking $booking, CancellationRefundDecision $decision): Booking
    {
        return DB::transaction(function () use ($booking, $decision): Booking {
            $booking = $this->lockedPaidBooking($booking);
            $payment = $this->lockedUnresolvedCapturedPayment($booking);

            $meta = ['refund_resolution' => 'not_eligible_late_cancellation', ...$decision->toMeta()];

            $payment->forceFill(['metadata' => [...($payment->metadata ?? []), ...$meta]])->save();

            $this->bookings->logActivity($booking, BookingActivityAction::PaymentStatusChanged, BookingActor::System, meta: $meta);
            $this->audit->logSystem(
                'payments',
                'cancellation_not_refunded',
                sprintf('Booking %s cancelled outside the refund window; no refund issued.', $booking->reference),
                $booking,
                $meta,
            );

            return $booking;
        });
    }

    /** @throws never — see tryCreditStudentWallet()'s docblock for why every failure mode is caught here too. */
    private function tryCreditWalletForRefund(Booking $booking, BookingPayment $payment, User $student): bool
    {
        try {
            $wallet = $this->wallets->getOrCreateWalletForExistingObligation($student, $payment->currency_code);

            $entry = $this->walletLedger->credit(
                $wallet,
                $payment->amount_minor,
                WalletLedgerEntryType::Refund,
                $student,
                idempotencyKey: sprintf('cancellation-refund:%s', $payment->id),
                description: sprintf('Booking %s cancelled; amount credited to wallet.', $booking->reference),
                sourceType: BookingPayment::class,
                sourceId: (string) $payment->id,
            );
        } catch (Throwable) {
            return false;
        }

        $payment->forceFill(['metadata' => [...($payment->metadata ?? []), 'wallet_ledger_entry_id' => $entry->id]])->save();

        return true;
    }

    /**
     * Exception-path refund. The gateway call happens with no database
     * lock held (the same rule as instructor payout execution): a short
     * transaction claims the payment first, the network call happens
     * outside it, then a second short transaction finalizes.
     */
    public function refundViaProvider(Booking $booking, User $actor, string $reason): Booking
    {
        if (trim($reason) === '') {
            throw new BookingException('A reason is required for a direct provider refund.');
        }

        $payment = DB::transaction(function () use ($booking): BookingPayment {
            $booking = $this->lockedPaidBooking($booking);
            $payment = $this->lockedUnresolvedCapturedPayment($booking);

            $payment->forceFill([
                'metadata' => [...($payment->metadata ?? []), 'refund_resolution' => 'provider_refund_pending'],
            ])->save();

            return $payment;
        });

        try {
            $this->refunds->refund($booking);
        } catch (Throwable $e) {
            // The claim already committed — clear it so a retry (or the
            // wallet-credit path) is not permanently locked out by a
            // provider call that never actually moved money.
            DB::transaction(function () use ($payment): void {
                $payment = BookingPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
                $metadata = $payment->metadata ?? [];
                unset($metadata['refund_resolution']);
                $payment->forceFill(['metadata' => $metadata])->save();
            });

            throw $e;
        }

        return DB::transaction(function () use ($booking, $payment, $actor, $reason): Booking {
            $payment = BookingPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            $payment->forceFill([
                'metadata' => [
                    ...($payment->metadata ?? []),
                    'refund_resolution' => 'provider_refunded',
                    'refund_reason' => $reason,
                    'refunded_by' => $actor->id,
                ],
            ])->save();

            $booking = $this->finalizeRefundedBooking($booking, $reason);

            $this->audit->logOverride(
                $actor,
                'payments',
                'payment_refunded_via_provider',
                sprintf('Booking %s payment refunded directly via provider.', $booking->reference),
                $reason,
                $booking,
            );

            return $booking;
        });
    }

    public function recordRefund(Booking $booking, ?string $reason = null): Booking
    {
        $this->assertPaid($booking);

        return DB::transaction(function () use ($booking, $reason): Booking {
            // Best-effort resolution tag for dashboard consistency — this
            // path is a provider-initiated notification (e.g. a dashboard
            // refund), so a matching captured row may already be tagged
            // by refundViaProvider(), already resolved, or (in older
            // fixtures/tests) absent entirely; none of those block the
            // booking-status sync below, which is this method's contract.
            $payment = BookingPayment::query()
                ->where('booking_id', $booking->id)
                ->where('status', BookingPaymentRecordStatus::Captured)
                ->latest('created_at')
                ->lockForUpdate()
                ->first();

            if ($payment !== null && ($payment->metadata['refund_resolution'] ?? null) === null) {
                $payment->forceFill(['metadata' => [...($payment->metadata ?? []), 'refund_resolution' => 'provider_refunded_externally']])->save();
            }

            return $this->finalizeRefundedBooking($booking, $reason);
        });
    }

    /** Shared status-sync tail for every refund path: Refunded + cancel if still active. */
    private function finalizeRefundedBooking(Booking $booking, ?string $reason): Booking
    {
        $booking = $this->bookings->updatePaymentStatus($booking, BookingPaymentStatus::Refunded);

        $this->logPayment($booking, BookingPaymentStatus::Refunded, array_filter(['reason' => $reason]));

        if (! $booking->status->isTerminal()) {
            $booking = $this->bookingService->cancel($booking, new CancelBookingData(
                BookingActor::forUser(Auth::user(), $booking),
                $reason ?? 'Payment refunded',
            ));
        }

        return $booking;
    }

    private function lockedPaidBooking(Booking $booking): Booking
    {
        $booking = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

        if ($booking->payment_status !== BookingPaymentStatus::Paid) {
            throw new BookingException(sprintf('Booking %s is not paid — nothing to refund.', $booking->reference));
        }

        return $booking;
    }

    /** The same amount can never be refunded to both wallet and provider — this is the guard. */
    private function lockedUnresolvedCapturedPayment(Booking $booking): BookingPayment
    {
        $payment = BookingPayment::query()
            ->where('booking_id', $booking->id)
            ->where('status', BookingPaymentRecordStatus::Captured)
            ->latest('created_at')
            ->lockForUpdate()
            ->first();

        if ($payment === null) {
            throw new BookingException(sprintf('Booking %s has no captured payment to refund.', $booking->reference));
        }

        if (($payment->metadata['refund_resolution'] ?? null) !== null) {
            throw new BookingException(sprintf('Booking %s payment was already resolved (%s).', $booking->reference, $payment->metadata['refund_resolution']));
        }

        return $payment;
    }

    /**
     * Gateway-neutral frontend checkout payload for a booking that has
     * already called initiate(). Never returns a secret — see each
     * provider's checkoutPayload() for its exact (provider-specific)
     * shape.
     *
     * @return array<string, mixed>
     *
     * @throws BookingException when the configured provider cannot be used, or no pending payment exists
     */
    public function checkoutPayload(Booking $booking): array
    {
        return $this->provider($booking)->checkoutPayload($booking);
    }

    /**
     * Country-aware provider selection: resolves the payer's country
     * from the student's profile when one exists and lets
     * PaymentProviderResolver apply its routing order
     * (Country::payment_routing → default_provider → legacy
     * BookingSettings::payment_provider). Passing null (the $booking-less
     * call form) skips country resolution entirely.
     */
    private function provider(?Booking $booking = null): PaymentProviderInterface
    {
        return $this->providers->current($booking !== null ? $this->resolveCountryIso2($booking) : null);
    }

    private function resolveCountryIso2(Booking $booking): ?string
    {
        return $booking->student?->profile?->country?->iso2;
    }

    /**
     * The market gate for a real payment, and the reason
     * PaymentCollectionEligibilityService is no longer advisory.
     *
     * It used to be that `initiate()` called the resolver directly, so
     * `payment_collection_rollout_scope` gated nothing that moved money —
     * it only shaped a read-only preview. Anything that can be set to
     * "india only" while a US student still checks out is not a control.
     * Now the same service decides both, so the preview and the payment
     * can never disagree.
     *
     * Order matters: this runs BEFORE the booking-row transaction and
     * before any obligation or attempt is written, so a refusal leaves
     * no money state behind. The resolver's own currency assertion is
     * kept as a second, narrower guard — eligibility answers "is this
     * market open", `assertSupportsCurrency()` answers "can this account
     * actually collect this currency", and the second is the one that
     * must never be skipped.
     *
     * @return string the resolved provider key
     *
     * @throws BookingException when this country/currency may not be collected right now
     */
    private function assertCollectionAllowed(Booking $booking): string
    {
        $currency = (string) $booking->currency;

        $eligibility = $this->collectionEligibility->resolve(
            $this->resolveCountryIso2($booking),
            $currency,
            'booking_payment',
        );

        if (! $eligibility->isEligible || $eligibility->provider === null) {
            // Safe messages only — never the raw resolver text, which can
            // name providers and credential state.
            throw new BookingException(
                $eligibility->summary() !== ''
                    ? $eligibility->summary()
                    : 'Payments are not available for your country yet.',
            );
        }

        $providerKey = $eligibility->provider;

        $this->providers->assertKeyUsable($providerKey);
        $this->providers->assertSupportsCurrency($providerKey, $currency);

        return $providerKey;
    }

    /**
     * Option B: the payment is authentic (signature, amount, and
     * currency were already verified by the provider before markPaid()
     * was ever called) but the booking can no longer be confirmed for
     * it. The charge is preserved, never silently discarded or left
     * ambiguous:
     *
     *   - every booking has an authenticated student → credited to
     *     their wallet, exactly once (WalletLedgerService::credit()'s
     *     own idempotency key guards a second delivery of the same
     *     event; the already-terminal booking_payments row check below
     *     is the first, cheaper guard).
     *   - wallet credit itself fails (e.g. the student's wallet was
     *     administratively closed) → falls back to the same
     *     manual-resolution flag rather than losing track of the money
     *     or raising an uncaught exception through the webhook
     *     controller (WalletException is not a BookingException and
     *     would otherwise surface as a raw 500).
     *
     * Never: confirms the booking, clears its reservation, creates a
     * meeting, or marks it Paid (Paid means "this booking's charge is
     * good and this booking is going ahead" — neither is true here).
     * `Booking.payment_status` becomes Refunded only when money was
     * actually redirected to a wallet — the closest existing enum
     * value to "this charge was not retained as this booking's
     * revenue" — and is left untouched (Pending) whenever nothing was
     * actually resolved yet, so the state never claims more than what
     * happened.
     */
    private function handleLateTerminalPayment(Booking $booking, string $reference): Booking
    {
        return DB::transaction(function () use ($booking, $reference): Booking {
            $payment = BookingPayment::query()
                ->where('booking_id', $booking->id)
                ->where('idempotency_key', $reference)
                ->lockForUpdate()
                ->first();

            if ($payment !== null && ($payment->metadata['late_terminal_handled'] ?? false) === true) {
                // Already processed by an earlier delivery of this event.
                return $booking;
            }

            if ($payment === null) {
                // No known payment attempt to safely attribute an amount
                // to — do not guess, do not credit, do not mutate status.
                $this->logLateTerminalEvent($booking, 'payment_late_terminal_unattributed', [
                    'booking_status' => $booking->status->value,
                ]);

                return $booking;
            }

            $payment->forceFill([
                'metadata' => [...($payment->metadata ?? []), 'late_terminal_handled' => true],
            ])->save();

            $credited = $this->tryCreditStudentWallet($booking, $payment, $reference);

            if ($credited) {
                $booking = $this->bookings->updatePaymentStatus($booking, BookingPaymentStatus::Refunded, $reference);

                $this->logPayment($booking, BookingPaymentStatus::Refunded, [
                    'late_terminal' => true,
                    'wallet_credited' => true,
                ]);

                return $booking;
            }

            // The charge is real, the booking is dead, and the money
            // could not be moved to the student's wallet. Previously
            // this was a metadata flag and an audit line — true, but
            // nothing anybody watches. It is a held-money incident.
            $this->raiseCollectionIssue(
                $payment,
                BookingPaymentReconciliationIssueType::LateSuccessResolutionFailed,
                BookingPaymentReconciliationSeverity::Critical,
                'A payment arrived after this booking ended and could not be credited to the student\'s wallet.',
            );

            $payment->forceFill([
                'metadata' => [
                    ...($payment->metadata ?? []),
                    'manual_resolution_required' => true,
                    'manual_resolution_reason' => 'Automatic wallet credit failed — needs manual admin/support resolution.',
                ],
            ])->save();

            $this->logLateTerminalEvent($booking, 'payment_late_terminal_manual_resolution', [
                'booking_status' => $booking->status->value,
            ]);

            return $booking;
        });
    }

    /**
     * @throws never — any failure (WalletException for a closed wallet,
     *               ValidationException from WalletService::resolveCurrency()
     *               when the booking's currency has no active
     *               Currency row, or anything else) is caught and
     *               converted to a "not credited" result so the
     *               caller falls back to manual resolution instead of
     *               an uncaught exception reaching the webhook
     *               controller as a raw 500 — the wallet subsystem's
     *               failure modes are heterogeneous and not all of
     *               them extend WalletException.
     */
    private function tryCreditStudentWallet(Booking $booking, BookingPayment $payment, string $reference): bool
    {
        $student = $booking->student;

        if ($student === null) {
            return false;
        }

        try {
            $wallet = $this->wallets->getOrCreateWalletForExistingObligation($student, $payment->currency_code);

            $entry = $this->walletLedger->credit(
                $wallet,
                $payment->amount_minor,
                WalletLedgerEntryType::LatePaymentCredit,
                $student,
                idempotencyKey: sprintf(
                    'late-payment-credit:%s:%s',
                    $payment->id,
                    $payment->provider_payment_id ?? $reference,
                ),
                description: 'Payment received after booking expiry/cancellation; credited to wallet.',
                sourceType: BookingPayment::class,
                sourceId: (string) $payment->id,
            );
        } catch (Throwable) {
            return false;
        }

        $payment->forceFill([
            'metadata' => [...($payment->metadata ?? []), 'wallet_ledger_entry_id' => $entry->id],
        ])->save();

        return true;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function logLateTerminalEvent(Booking $booking, string $event, array $meta): void
    {
        $this->bookings->logActivity(
            $booking,
            BookingActivityAction::PaymentStatusChanged,
            BookingActor::System,
            meta: $meta,
        );

        $this->audit->logSystem(
            'payments',
            $event,
            sprintf('Late payment success on terminal booking %s (%s).', $booking->reference, $booking->status->label()),
            $booking,
            $meta,
        );
    }

    private function assertReference(Booking $booking, string $reference, BookingPaymentStatus $expected): void
    {
        if ($booking->payment_status !== $expected) {
            throw new BookingException(sprintf(
                'Booking %s is not in the "%s" payment state.',
                $booking->reference,
                $expected->label(),
            ));
        }

        if ($booking->payment_reference === null || ! hash_equals($booking->payment_reference, $reference)) {
            throw new BookingException('Payment reference does not match this booking.');
        }
    }

    private function assertPaid(Booking $booking): void
    {
        if ($booking->payment_status !== BookingPaymentStatus::Paid) {
            throw new BookingException(sprintf('Booking %s is not paid — nothing to refund.', $booking->reference));
        }
    }

    /**
     * Writes to both the per-booking business timeline (booking_activities,
     * via BookingRepository::logActivity) and the unified, searchable
     * Activity Log (via AuditTrailService) — financial state changes must
     * be traceable centrally, not only from inside one booking's history.
     *
     * @param  array<string, mixed>  $meta
     */
    private function logPayment(Booking $booking, BookingPaymentStatus $to, array $meta = []): void
    {
        $this->bookings->logActivity(
            $booking,
            BookingActivityAction::PaymentStatusChanged,
            BookingActor::forUser(Auth::user(), $booking),
            Auth::id(),
            meta: ['payment_status' => $to->value, ...$meta],
        );

        $description = sprintf('Booking %s payment %s', $booking->reference, $to->label());
        $properties = ['payment_status' => $to->value, ...$meta];

        if ($user = Auth::user()) {
            $this->audit->logUser($user, 'payments', 'payment_'.$to->value, $description, $booking, $properties);

            return;
        }

        $this->audit->logSystem('payments', 'payment_'.$to->value, $description, $booking, $properties);
    }
}
