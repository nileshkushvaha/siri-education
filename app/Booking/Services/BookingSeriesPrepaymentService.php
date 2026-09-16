<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\DTOs\SeriesPrepaymentQuoteData;
use App\Booking\DTOs\SeriesPrepaymentResult;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\BookingSeries;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletRecharge;
use App\Payments\DTOs\PaymentCheckoutData;
use App\Settings\BookingSettings;
use App\Support\MoneyFormatter;
use App\Wallet\Enums\WalletRechargeStatus;
use App\Wallet\Exceptions\WalletException;
use App\Wallet\Services\WalletRechargeService;
use App\Wallet\Services\WalletService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Paying for a whole repeating schedule in ONE checkout.
 *
 * Before this, N reserved classes meant N obligations and therefore N
 * trips through a payment gateway. That is not a rounding-error
 * annoyance — a student who booked twelve classes was asked to complete
 * twelve card payments, and the reservation on each one expires
 * independently while they do it.
 *
 * The money moves in one hop through the student's own WALLET rather
 * than through a new multi-booking obligation, and that choice is the
 * whole design:
 *
 *  - `BookingPayment` is documented as the obligation to pay for ONE
 *    booking. Making one obligation span many would mean apportioning a
 *    partial refund across a single captured provider payment when one
 *    class is later cancelled — the hardest problem in the whole area.
 *    Refunds already credit the wallet, so routing the payment through
 *    it means cancelling one class of a batch is EXACTLY the refund
 *    path that already exists and is already tested.
 *  - Every per-class semantic survives untouched: its own price, its own
 *    BookingPayment row, its own reservation, its own invoice, its own
 *    refund decision. Only the number of times the student is asked for
 *    money changes.
 *
 * What is deliberately NOT collected: classes beyond the confirmation
 * horizon. They are not reservations yet, and money is never taken for
 * a class nobody is holding.
 */
final class BookingSeriesPrepaymentService
{
    public function __construct(
        private readonly BookingPaymentServiceInterface $payments,
        private readonly WalletRechargeService $recharges,
        private readonly WalletService $wallets,
        private readonly BookingSeriesService $series,
    ) {}

    /**
     * What the student owes for this schedule's reserved classes, and
     * how much of it their wallet already covers.
     *
     * Display-only. Nothing here is trusted at settlement time — every
     * amount is recomputed from the booking rows under a lock by
     * BookingPaymentService::payWithWallet(), exactly as a single
     * payment already is.
     */
    public function quote(BookingSeries $series, User $student): SeriesPrepaymentQuoteData
    {
        $this->assertOwnership($series, $student);

        $payable = $this->payableBookings($series);
        $planned = max(0, $this->plannedCount($series));

        if ($payable->isEmpty()) {
            return new SeriesPrepaymentQuoteData([], 0, null, 0, 0, $planned);
        }

        $currencies = $payable->pluck('currency')->map(
            static fn (?string $code): string => strtoupper((string) $code),
        )->unique();

        // A batch has to be one currency. In practice a schedule is one
        // instructor at one price so this never differs — but a
        // half-settled batch is a far worse outcome than an explicit
        // refusal, and wallet payment never converts (see
        // BookingPaymentService::resolveMatchingWallet).
        if ($currencies->count() > 1) {
            return new SeriesPrepaymentQuoteData(
                [], 0, null, 0, 0, $planned,
                blockedReason: 'These classes are priced in different currencies, so they cannot be paid for together. Please pay for them individually.',
            );
        }

        $currency = (string) $currencies->first();
        $total = $payable->sum(fn (Booking $booking): int => $this->minorAmount($booking));
        $wallet = $this->walletFor($student);

        if ($wallet !== null && strtoupper($wallet->currency_code) !== $currency) {
            return new SeriesPrepaymentQuoteData(
                [], 0, $currency, 0, 0, $planned,
                blockedReason: 'Your wallet is in a different currency to these classes, so they cannot be paid for together.',
            );
        }

        $balance = (int) ($wallet?->available_balance_minor ?? 0);

        return new SeriesPrepaymentQuoteData(
            bookingIds: $payable->pluck('id')->map(static fn ($id): string => (string) $id)->all(),
            totalMinor: (int) $total,
            currencyCode: $currency,
            walletBalanceMinor: $balance,
            // Exactly the gap, never a rounded-up "convenient" amount:
            // the platform should not end up holding more of a student's
            // money than the thing they are buying costs.
            shortfallMinor: max(0, (int) $total - $balance),
            plannedCount: $planned,
        );
    }

    /**
     * Settles every reserved class of the schedule from the student's
     * wallet.
     *
     * Each class goes through the ordinary single-booking wallet path,
     * so each gets its own lock, its own re-validation and its own
     * BookingPayment — this method only decides the ORDER and reports
     * what happened. One class failing (its slot was taken while the
     * student was at the gateway) leaves the others paid and the unspent
     * money in the wallet; nothing is lost and nothing is silently
     * confirmed.
     *
     * Earliest class first, deliberately: if the balance turns out to be
     * short, the classes that survive are the ones happening soonest.
     */
    public function settleFromWallet(BookingSeries $series, User $student): SeriesPrepaymentResult
    {
        $this->assertOwnership($series, $student);

        $paid = new Collection;
        $failures = [];

        foreach ($this->payableBookings($series) as $booking) {
            try {
                $paid->push($this->payments->payWithWallet($booking, $student));
            } catch (BookingException $exception) {
                $failures[$booking->reference] = $exception->getMessage();
            }
        }

        return new SeriesPrepaymentResult(
            paid: $paid,
            failures: $failures,
            remainingWalletMinor: (int) ($this->walletFor($student)?->available_balance_minor ?? 0),
        );
    }

    /**
     * Opens ONE checkout for exactly the shortfall.
     *
     * The recharge carries the schedule and the classes it was raised
     * for, so the settlement listener can finish the job the student
     * started even if they close the tab on the way back. That is not
     * automatic charging: they asked to pay for these classes, and paid
     * this exact amount for them.
     *
     * @throws BookingException
     */
    public function initiateTopUp(BookingSeries $series, User $student): PaymentCheckoutData
    {
        $quote = $this->quote($series, $student);

        if ($quote->blockedReason !== null) {
            throw new BookingException($quote->blockedReason);
        }

        if (! $quote->isPayable()) {
            throw new BookingException('There is nothing left to pay for in this schedule.');
        }

        if ($quote->shortfallMinor <= 0) {
            throw new BookingException('Your balance already covers these classes.');
        }

        try {
            return $this->recharges->initiate(
                $student,
                $quote->shortfallMinor,
                metadata: [
                    'purpose' => self::PURPOSE,
                    'booking_series_id' => (string) $series->id,
                    'booking_ids' => $quote->bookingIds,
                ],
            );
        } catch (WalletException $exception) {
            throw new BookingException($exception->getMessage(), previous: $exception);
        }
    }

    /** Marks a recharge as raised to pay for a schedule's classes. */
    public const string PURPOSE = 'booking_series_prepayment';

    /** How far back the safety-net sweep looks for paid-but-unsettled top-ups. */
    public const int SWEEP_WINDOW_DAYS = 7;

    /**
     * The top-up this student raised for this schedule that is still
     * waiting for its payment — the one a browser return has to verify.
     */
    public function openRechargeFor(BookingSeries $series, User $student): ?WalletRecharge
    {
        $this->assertOwnership($series, $student);

        return $this->prepaymentRecharges($series)
            ->where('user_id', $student->id)
            ->where('status', WalletRechargeStatus::Requested)
            ->latest('created_at')
            ->first();
    }

    /**
     * Second half of "pay for all my classes": once the top-up raised for
     * a schedule has been credited, settle that schedule's classes from
     * the balance.
     *
     * ONE implementation, three callers — the browser's verified return,
     * the queued WalletRechargeSucceeded listener and the scheduled
     * sweep — so whichever arrives first finishes the job and the others
     * find nothing left to pay. Null means "not ours to act on": an
     * ordinary top-up, a recharge that has not succeeded, or a schedule
     * that no longer exists. That line is what keeps this from being
     * automatic charging — only money the student raised FOR these
     * classes is ever spent on them.
     */
    public function settleForRecharge(WalletRecharge $recharge): ?SeriesPrepaymentResult
    {
        $metadata = $recharge->metadata ?? [];

        if (($metadata['purpose'] ?? null) !== self::PURPOSE
            || $recharge->status !== WalletRechargeStatus::Succeeded) {
            return null;
        }

        $series = BookingSeries::query()->find($metadata['booking_series_id'] ?? null);
        $student = $recharge->user;

        if ($series === null || $student === null || (int) $series->student_id !== (int) $student->id) {
            return null;
        }

        // Re-quoted from the live rows, never from the recharge: between
        // the top-up and now, a class may have been cancelled or its
        // reservation may have lapsed. Paying for what is actually
        // outstanding is the only safe reading.
        $result = $this->settleFromWallet($series, $student);

        if (! $result->allPaid()) {
            // Ids and counts only — never the student's identity, the
            // amounts, or anything about the payment instrument.
            Log::warning('Some classes could not be settled from a schedule prepayment.', [
                'booking_series_id' => $series->id,
                'wallet_recharge_id' => $recharge->id,
                'paid' => $result->paidCount(),
                'failed' => count($result->failures),
            ]);
        }

        return $result;
    }

    /**
     * Safety net behind the event: finds top-ups that were raised for a
     * schedule, have been credited, and whose classes are still unpaid,
     * and settles them.
     *
     * The queued listener is the normal path, but a queue that is down,
     * a lost job, or three failed tries would otherwise strand a student
     * who has paid — with the money in their wallet and their classes
     * expiring. Idempotent: a recharge whose classes are all paid (or
     * all gone) settles nothing and costs one query.
     *
     * @return int classes paid by this pass
     */
    public function settleOutstandingRecharges(int $limit = 200): int
    {
        $paid = 0;

        $recharges = WalletRecharge::query()
            ->where('status', WalletRechargeStatus::Succeeded)
            ->where('metadata->purpose', self::PURPOSE)
            ->where('succeeded_at', '>=', now()->subDays(self::SWEEP_WINDOW_DAYS))
            ->with('user')
            ->latest('succeeded_at')
            ->limit(max(1, $limit))
            ->get();

        foreach ($recharges as $recharge) {
            $series = BookingSeries::query()->find($recharge->metadata['booking_series_id'] ?? null);

            if ($series === null || $this->payableBookings($series)->isEmpty()) {
                continue;
            }

            $paid += $this->settleForRecharge($recharge)?->paidCount() ?? 0;
        }

        return $paid;
    }

    private function prepaymentRecharges(BookingSeries $series): Builder
    {
        return WalletRecharge::query()
            ->where('metadata->purpose', self::PURPOSE)
            ->where('metadata->booking_series_id', (string) $series->id);
    }

    /**
     * Records — or withdraws — the student's consent to have this
     * schedule's future classes confirmed from their balance.
     *
     * Withdrawal is deliberately unconditional: turning OFF something
     * that spends your money must never be refusable, even when the
     * platform capability is currently disabled or the schedule has
     * ended.
     *
     * @throws BookingException
     */
    public function setAutoSettle(BookingSeries $series, User $student, bool $enabled): BookingSeries
    {
        $this->assertOwnership($series, $student);

        if ($enabled && ! $this->autoSettleAvailable()) {
            throw new BookingException('Confirming future classes from your balance is not available just yet.');
        }

        $series->forceFill(['auto_settle_from_wallet' => $enabled])->save();

        return $series->refresh();
    }

    /** Whether this deployment offers unattended settlement at all. */
    public function autoSettleAvailable(): bool
    {
        return app(BookingSettings::class)->recurring_wallet_auto_settle_enabled;
    }

    /**
     * Confirms newly generated classes from the balance the student
     * already put there for exactly this.
     *
     * Runs with nobody present, so the guards are the feature:
     *
     *  - The platform capability AND the student's own per-schedule
     *    consent must both be on. Either one off means nothing happens.
     *  - It spends ONLY money already in the wallet. It never opens a
     *    checkout, never tops up, and never touches a card. A short
     *    balance simply leaves the class payment-due, exactly as it is
     *    today — that is the line between spending what a student
     *    deposited for this and charging them.
     *  - Only this schedule's own classes, through the ordinary
     *    single-booking wallet path, so every lock, re-validation and
     *    receipt behaves identically to the student pressing the button.
     *
     * The student is told each time: settlement dispatches
     * BookingPaymentSucceeded, which already notifies them.
     */
    public function autoSettle(BookingSeries $series): SeriesPrepaymentResult
    {
        $student = $series->student;

        if (! $series->auto_settle_from_wallet
            || ! $this->autoSettleAvailable()
            || $student === null) {
            return new SeriesPrepaymentResult(new Collection, []);
        }

        $quote = $this->quote($series, $student);

        // Nothing owed, or not payable as a batch (mixed currency, wallet
        // in another currency) — never guess, just leave it alone.
        if (! $quote->isPayable()) {
            return new SeriesPrepaymentResult(new Collection, []);
        }

        // The balance must already cover the whole bill. Settling part of
        // it would drain the wallet to zero and still leave classes
        // unpaid, which is the worst of both outcomes.
        if (! $quote->coveredByWallet()) {
            return new SeriesPrepaymentResult(new Collection, []);
        }

        return $this->settleFromWallet($series, $student);
    }

    /**
     * The classes money can actually be collected for: reserved, not
     * terminal, and genuinely awaiting payment.
     *
     * @return Collection<int, Booking>
     */
    private function payableBookings(BookingSeries $series): Collection
    {
        return $series->bookings()
            ->whereIn('status', [BookingStatus::Pending, BookingStatus::Confirmed])
            ->where('payment_status', BookingPaymentStatus::Pending)
            ->whereNotNull('price')
            ->orderBy('starts_at')
            ->get();
    }

    private function plannedCount(BookingSeries $series): int
    {
        $total = $this->series->scheduleFor($series, 1)->totalScheduled;

        return $total === null ? 0 : max(0, $total - $series->liveBookingsCount());
    }

    private function walletFor(User $student): ?Wallet
    {
        return Wallet::query()->where('user_id', $student->id)->first();
    }

    private function minorAmount(Booking $booking): int
    {
        $minorUnits = MoneyFormatter::minorUnitsFor((string) $booking->currency);

        return (int) round(((float) $booking->price) * (10 ** $minorUnits));
    }

    /** @throws BookingException */
    private function assertOwnership(BookingSeries $series, User $student): void
    {
        if ((int) $series->student_id !== (int) $student->id) {
            throw new BookingException('You may only pay for your own classes.');
        }
    }
}
