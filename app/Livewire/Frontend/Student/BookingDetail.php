<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Student;

use App\Booking\Contracts\AvailabilityServiceInterface;
use App\Booking\Contracts\BookingCheckoutCompletionServiceInterface;
use App\Booking\Contracts\BookingMeetingServiceInterface;
use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\DTOs\AvailabilityQueryData;
use App\Booking\DTOs\BookingCheckoutOutcome;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\DTOs\RescheduleBookingData;
use App\Booking\DTOs\StudentJoinState;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingCheckoutState;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\RecordingPlaybackState;
use App\Booking\Enums\RecurrenceEndCondition;
use App\Booking\Enums\SeriesChangeScope;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\InvalidPaymentWebhookException;
use App\Booking\Exceptions\LessonAlreadyStartedException;
use App\Booking\Payments\RazorpayPaymentProvider;
use App\Booking\Services\BookingSeriesPrepaymentService;
use App\Booking\Services\BookingSeriesService;
use App\Booking\Services\CancellationRefundPolicy;
use App\Booking\Services\RecordingPlaybackAccessResolver;
use App\Booking\Services\RescheduleLimitPolicy;
use App\Booking\Support\FakePaymentSimulator;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\BookingSeries;
use App\Models\Wallet;
use App\Settings\FeatureSettings;
use App\Support\MoneyFormatter;
use App\Wallet\Support\WalletMoneyFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * The student's dedicated booking detail PAGE (`/dashboard/my-bookings/{booking}`).
 *
 * Everything here used to live in a modal inside BookingHistory; the
 * behaviour — authorisation, reschedule, cancel, payment — is unchanged,
 * only its surface is. BookingHistory is now a list, and this component
 * owns a single booking. Authorisation is enforced twice on purpose: the
 * controller authorises before rendering the page, and mount() re-checks
 * on every Livewire request so no state change can be driven against a
 * booking the viewer may not see.
 */
final class BookingDetail extends Component
{
    public string $bookingId = '';

    public ?Booking $booking = null;

    public bool $reschedulePanelOpen = false;

    public bool $cancelPanelOpen = false;

    public string $rescheduleDate = '';

    public ?string $rescheduleSlotStartsAt = null;

    /** @var list<array<string, mixed>> */
    public array $rescheduleSlots = [];

    public string $cancelReason = '';

    /**
     * Which classes a cancellation applies to when this booking belongs
     * to a repeating schedule: this one, this and everything after it,
     * or every remaining class. Defaults to the narrowest choice — a
     * student cancelling one class must never end a whole schedule by
     * accident.
     */
    public string $cancelScope = 'this_only';

    /** Which page of the schedule is being shown. */
    public int $seriesPage = 1;

    public bool $extendPanelOpen = false;

    public int $extendByClasses = 4;

    public string $extendToDate = '';

    public string $banner = '';

    /** @var array<string, mixed> */
    public array $paymentOrder = [];

    private BookingRepositoryInterface $repository;

    private BookingServiceInterface $bookingService;

    private AvailabilityServiceInterface $availability;

    private BookingPaymentServiceInterface $payments;

    private RazorpayPaymentProvider $razorpay;

    private BookingCheckoutCompletionServiceInterface $checkout;

    private CancellationRefundPolicy $refundPolicy;

    private RescheduleLimitPolicy $reschedulePolicy;

    public function boot(
        BookingRepositoryInterface $repository,
        BookingServiceInterface $bookingService,
        AvailabilityServiceInterface $availability,
        BookingPaymentServiceInterface $payments,
        RazorpayPaymentProvider $razorpay,
        CancellationRefundPolicy $refundPolicy,
        RescheduleLimitPolicy $reschedulePolicy,
        BookingCheckoutCompletionServiceInterface $checkout,
    ): void {
        $this->repository = $repository;
        $this->bookingService = $bookingService;
        $this->availability = $availability;
        $this->payments = $payments;
        $this->razorpay = $razorpay;
        $this->checkout = $checkout;
        $this->refundPolicy = $refundPolicy;
        $this->reschedulePolicy = $reschedulePolicy;
    }

    public function mount(string $bookingId): void
    {
        $this->bookingId = $bookingId;

        $booking = $this->repository->findWithTrashedOrFail($bookingId);

        Gate::authorize('view', $booking);

        $this->booking = $booking->loadMissing(['type', 'instructor', 'meeting', 'lesson']);

        // Server-derived: a verified checkout that has not resolved shows
        // the confirming state on a reload, a new device or a fresh
        // login — never a Pay button over money that may have moved.
        if ($this->booking->payment_status->isPayable() && ! $this->booking->status->isTerminal()) {
            $this->applyCheckoutOutcome($this->checkout->currentState($this->booking));
        }
    }

    public function openReschedulePanel(): void
    {
        if (! $this->booking) {
            return;
        }

        if ($this->booking->hasStarted()) {
            $this->banner = LessonAlreadyStartedException::forReschedule()->getMessage();

            return;
        }

        Gate::authorize('reschedule', $this->booking);

        $this->cancelPanelOpen = false;
        $this->reschedulePanelOpen = true;
    }

    public function closeReschedulePanel(): void
    {
        $this->reschedulePanelOpen = false;
        $this->rescheduleDate = '';
        $this->rescheduleSlotStartsAt = null;
        $this->rescheduleSlots = [];
    }

    public function openCancelPanel(): void
    {
        if (! $this->booking) {
            return;
        }

        if ($this->booking->hasStarted()) {
            $this->banner = LessonAlreadyStartedException::forCancellation()->getMessage();

            return;
        }

        Gate::authorize('cancel', $this->booking);

        $this->reschedulePanelOpen = false;
        $this->cancelPanelOpen = true;
    }

    public function closeCancelPanel(): void
    {
        $this->cancelPanelOpen = false;
        $this->cancelReason = '';
    }

    public function updatedRescheduleDate(): void
    {
        $this->loadRescheduleSlots();
    }

    public function selectRescheduleSlot(string $startsAt): void
    {
        $this->rescheduleSlotStartsAt = $startsAt;
    }

    public function confirmReschedule(): void
    {
        if (! $this->booking) {
            return;
        }

        Gate::authorize('reschedule', $this->booking);

        if (! $this->rescheduleSlotStartsAt) {
            return;
        }

        $this->banner = '';

        try {
            $updated = $this->bookingService->reschedule($this->booking, new RescheduleBookingData(
                startsAt: CarbonImmutable::parse($this->rescheduleSlotStartsAt),
                actor: BookingActor::Student,
            ));

            $this->booking = $updated->loadMissing(['type', 'instructor']);
            $this->reschedulePanelOpen = false;
            $this->rescheduleDate = '';
            $this->rescheduleSlotStartsAt = null;
            $this->rescheduleSlots = [];
        } catch (BookingException $exception) {
            $this->banner = $exception->getMessage();
        }
    }

    public function confirmCancel(): void
    {
        if (! $this->booking) {
            return;
        }

        Gate::authorize('cancel', $this->booking);

        $this->banner = '';

        try {
            $scope = SeriesChangeScope::tryFrom($this->cancelScope) ?? SeriesChangeScope::ThisOnly;
            $series = $this->ownedSeries();

            if ($series !== null && $scope !== SeriesChangeScope::ThisOnly) {
                // Ends the schedule going forward — completed classes,
                // their payments and any per-class exceptions are left
                // exactly as they are. Each class still goes through the
                // ordinary cancellation path, so refund policy and
                // notifications behave identically to cancelling one.
                app(BookingSeriesService::class)->cancelFrom(
                    $series,
                    $this->booking,
                    $scope,
                    BookingActor::Student,
                    filled($this->cancelReason) ? $this->cancelReason : null,
                );

                $this->booking = $this->booking->refresh()->loadMissing(['type', 'instructor']);
                $this->cancelPanelOpen = false;

                return;
            }

            $updated = $this->bookingService->cancel($this->booking, new CancelBookingData(
                cancelledBy: BookingActor::Student,
                reason: filled($this->cancelReason) ? $this->cancelReason : null,
            ));

            // The synchronous refund-execution listener
            // mutates payment_status on its own freshly-queried copy of
            // the booking, not this in-memory $updated instance, so a
            // refresh is required or the page would keep showing the
            // pre-refund "Paid" state.
            $this->booking = $updated->refresh()->loadMissing(['type', 'instructor']);
            $this->cancelPanelOpen = false;
        } catch (BookingException $exception) {
            $this->banner = $exception->getMessage();
        }
    }

    /**
     * Adds more classes to a finite schedule without touching any that
     * already exist.
     *
     * The rule's end moves and the ordinary generation pass fills in the
     * new dates; every booking already made keeps its id, reference,
     * payment and meeting. Nothing is recreated, so an extension can
     * never disturb a class that has been paid for.
     */
    public function extendSeries(): void
    {
        $series = $this->ownedSeries();

        if ($series === null) {
            return;
        }

        $this->banner = '';

        try {
            $seriesService = app(BookingSeriesService::class);

            $seriesService->extend(
                $series,
                additionalClasses: $series->end_condition === RecurrenceEndCondition::AfterCount ? $this->extendByClasses : null,
                newEndDate: $series->end_condition === RecurrenceEndCondition::OnDate ? ($this->extendToDate ?: null) : null,
            );

            // Fill whatever the extension brought inside the horizon now,
            // rather than making the student wait for the hourly sweep to
            // show them anything.
            $seriesService->generate($series->refresh());

            $this->extendPanelOpen = false;
            $this->seriesPage = 1;
        } catch (BookingException $exception) {
            $this->banner = $exception->getMessage();
        }
    }

    public function openExtendPanel(): void
    {
        $this->extendPanelOpen = true;
        $this->banner = '';

        $series = $this->ownedSeries();

        if ($series?->end_date !== null) {
            $this->extendToDate = $series->end_date->addMonth()->toDateString();
        }
    }

    public function closeExtendPanel(): void
    {
        $this->extendPanelOpen = false;
    }

    /**
     * Asks the platform to try a class it could not book earlier.
     *
     * The background sweep will not do this by itself — the date sits
     * behind the series' generation watermark and is never revisited,
     * which is what makes the watermark cheap. So a date that failed
     * while the instructor was away stays lost until someone asks, and
     * this is that ask.
     */
    /**
     * Withdraws — or grants — the standing permission for this schedule's
     * future classes to be confirmed from the student's balance.
     *
     * Turning it OFF is deliberately never refused. A control that stops
     * money moving must work even when the platform capability has since
     * been disabled or the schedule has ended; anything else strands a
     * student inside a permission they want out of.
     */
    public function toggleSeriesAutoSettle(): void
    {
        $series = $this->ownedSeries();

        if ($series === null) {
            return;
        }

        $this->banner = '';

        try {
            app(BookingSeriesPrepaymentService::class)->setAutoSettle(
                $series,
                auth()->user(),
                ! $series->auto_settle_from_wallet,
            );
        } catch (BookingException $exception) {
            $this->banner = $exception->getMessage();
        }
    }

    public function retrySeriesOccurrence(string $localDate): void
    {
        $series = $this->ownedSeries();

        if ($series === null) {
            return;
        }

        $this->banner = '';

        try {
            $booking = app(BookingSeriesService::class)->retryOccurrence($series, $localDate);

            $this->banner = $booking !== null
                ? 'That class has been booked.'
                : 'That time is still unavailable. You can try again later, or cancel this class from the schedule.';
        } catch (BookingException $exception) {
            $this->banner = $exception->getMessage();
        }
    }

    public function seriesNextPage(): void
    {
        $this->seriesPage++;
    }

    public function seriesPreviousPage(): void
    {
        $this->seriesPage = max(1, $this->seriesPage - 1);
    }

    /**
     * This booking's schedule, but only when it belongs to the
     * authenticated student.
     *
     * Ownership is re-checked here rather than inferred from the
     * booking's own policy check: a series action changes OTHER
     * bookings, so "may view this one" is not the question being asked.
     */
    public function ownedSeries(): ?BookingSeries
    {
        $series = $this->booking?->loadMissing('series')->series;

        if ($series === null) {
            return null;
        }

        return (int) $series->student_id === (int) auth()->id() ? $series : null;
    }

    /** Verified checkout awaiting the provider's capture confirmation — the page polls while true. */
    public bool $awaitingPaymentConfirmation = false;

    /** BookingCheckoutState value rendered by the payment block; see BookingWizard::$paymentConfirmationState. */
    public ?string $paymentConfirmationState = null;

    public function initiatePayment(): void
    {
        if (! $this->booking) {
            return;
        }

        Gate::authorize('pay', $this->booking);

        $this->banner = '';
        $this->paymentOrder = [];

        // A verified checkout may already be in flight (reload, other
        // tab). Money may have moved: never open a second checkout.
        $current = $this->checkout->currentState($this->booking);

        if ($current->confirmationInProgress()) {
            $this->applyCheckoutOutcome($current);

            return;
        }

        $this->awaitingPaymentConfirmation = false;
        $this->paymentConfirmationState = null;

        // A resolvable billing country is required before
        // checkout — PaymentProviderResolver's country-aware routing
        // would otherwise silently fall through to the platform
        // default, which is correct for *routing* but not a substitute for
        // asking the student to complete their profile first. Checked here
        // (the UI entry point), not inside BookingPaymentService::initiate()
        // itself, which many other callers (webhooks, direct service tests)
        // also use for concerns unrelated to profile completeness.
        if (auth()->user()?->profile?->country_id === null) {
            $this->banner = 'Please complete your profile (country) before paying for this booking.';

            return;
        }

        try {
            $this->payments->initiate($this->booking);
            $payload = $this->payments->checkoutPayload($this->booking);

            // Gateway-neutral: backend decides the provider, frontend only
            // reacts to it. Only Razorpay has a client-side checkout step
            // today — Stripe Elements/Checkout is intentionally deferred
            // (see docs/architecture/phase-10-razorpay-checkout-payment-capture.md),
            // and the fake provider has no real checkout UI
            // at all, only the "Simulate payment" controls below.
            if (($payload['provider'] ?? null) === 'razorpay') {
                $this->paymentOrder = $payload;
                $this->dispatch(
                    'razorpay-checkout-ready',
                    orderId: $payload['order_id'],
                    keyId: $payload['key_id'],
                    amountMinor: $payload['amount_minor'],
                    currency: $payload['currency'],
                    name: auth()->user()?->name ?? '',
                    email: auth()->user()?->email ?? '',
                );
            } elseif (($payload['provider'] ?? null) === 'stripe') {
                // client_secret/publishable_key travel only in the transient
                // dispatch payload, never stored on $paymentOrder (a public,
                // client-hydrated property): real, sensitive gateway data
                // with no reason to round-trip through server-rendered
                // state. The frontend mounts Stripe's Payment Element and
                // calls stripe.confirmPayment() directly with Stripe; this
                // component never receives that outcome back — only a
                // signed webhook may settle the booking (see
                // checkPaymentStatus(), which only ever reads state).
                $this->paymentOrder = ['provider' => 'stripe'];
                $this->dispatch(
                    'stripe-checkout-ready',
                    clientSecret: $payload['client_secret'],
                    publishableKey: $payload['publishable_key'],
                );
            } else {
                $this->paymentOrder = $payload;
            }
        } catch (BookingException $exception) {
            $this->banner = $exception->getMessage();
        }
    }

    /**
     * Pays this booking directly from the student's wallet — no
     * gateway, no redirect, settles in this one request. See
     * BookingWizard::payWithWallet() for the identical pattern.
     */
    public function payWithWallet(): void
    {
        if (! $this->booking) {
            return;
        }

        Gate::authorize('pay', $this->booking);

        $this->banner = '';

        try {
            $booking = $this->payments->payWithWallet($this->booking, auth()->user());

            $this->booking = $booking->refresh()->loadMissing(['type', 'instructor']);
        } catch (BookingException $exception) {
            $this->banner = $exception->getMessage();
        }
    }

    /**
     * Polled by the Stripe Payment Element partial after
     * stripe.confirmPayment() returns client-side — never trusted as
     * settlement itself, only a signal to re-check what the server
     * already knows. Only a signed webhook
     * ever calls markPaid()/markFailed() for Stripe; this method makes
     * no state change of its own, it only re-reads and re-renders.
     */
    public function checkPaymentStatus(): void
    {
        if (! $this->booking) {
            return;
        }

        Gate::authorize('pay', $this->booking);

        $outcome = $this->awaitingPaymentConfirmation
            ? $this->checkout->refreshPendingPayment($this->booking)
            : $this->checkout->currentState($this->booking);

        $this->applyCheckoutOutcome($outcome);

        if ($outcome->booking->payment_status->value === 'failed') {
            $this->banner = 'Payment failed. Please try again.';
        }
    }

    /** Renders a checkout outcome: fresh booking, confirming flag and state. */
    private function applyCheckoutOutcome(BookingCheckoutOutcome $outcome): void
    {
        $this->booking = $outcome->booking->loadMissing(['type', 'instructor']);
        $this->paymentConfirmationState = $outcome->state->value;
        $this->awaitingPaymentConfirmation = $outcome->confirmationInProgress();

        if ($outcome->state === BookingCheckoutState::Failed) {
            $this->banner = 'Payment failed. Please try again.';
        } elseif ($outcome->state === BookingCheckoutState::Confirmed) {
            $this->banner = '';
        }
    }

    public function verifyPayment(string $orderId, string $paymentId, string $signature): void
    {
        if (! $this->booking) {
            return;
        }

        Gate::authorize('pay', $this->booking);

        $this->banner = '';

        try {
            // Verify, confirm with Razorpay, settle — see
            // BookingCheckoutCompletionService. Captured → confirmed now;
            // otherwise the confirming state below polls.
            $this->applyCheckoutOutcome(
                $this->checkout->completeRazorpayCheckout($this->booking, $orderId, $paymentId, $signature),
            );
        } catch (InvalidPaymentWebhookException|BookingException $exception) {
            $this->awaitingPaymentConfirmation = false;
            $this->paymentConfirmationState = null;
            $this->banner = $exception->getMessage();
        }
    }

    /**
     * Local/testing-only convenience: the fake provider has no real
     * checkout UI to complete, so this is the only way to exercise the
     * "success"/"failure" paths from the browser without real gateway
     * credentials. Mirrors PaymentProviderResolver's own environment
     * guard rather than trusting the button being hidden — the button
     * not rendering in production is a UX nicety, not the safety
     * boundary.
     */
    public function simulateFakePayment(bool $success): void
    {
        if (! $this->booking || ! app(FakePaymentSimulator::class)->isAvailable()) {
            return;
        }

        Gate::authorize('pay', $this->booking);

        $this->banner = '';

        try {
            $booking = $this->booking->refresh();

            // Same settlement path a signed webhook takes — see
            // FakePaymentSimulator.
            app(FakePaymentSimulator::class)->simulate($booking, $success);

            $this->booking = $booking->refresh()->loadMissing(['type', 'instructor']);
        } catch (BookingException $exception) {
            $this->banner = $exception->getMessage();
        }
    }

    /**
     * Whether this booking's payment was recovered as a wallet
     * credit rather than actively refunded —
     * cheap, safe metadata check, no sensitive payload exposed.
     */
    public function paymentWasCreditedToWallet(): bool
    {
        if (! $this->booking) {
            return false;
        }

        return BookingPayment::query()
            ->where('booking_id', $this->booking->id)
            ->whereNotNull('metadata->wallet_ledger_entry_id')
            ->exists();
    }

    /**
     * The student-facing reschedule allowance for this
     * booking. Purely informational: BookingService::reschedule()
     * re-derives and enforces the same decision under the instructor
     * lock, so a stale render here can never let a student bypass the
     * configured limit.
     *
     * @return array{allowed: bool, remaining: int}|null
     */
    public function rescheduleAllowance(): ?array
    {
        if (! $this->booking || $this->booking->status->isTerminal()) {
            return null;
        }

        $decision = $this->reschedulePolicy->decide($this->booking, BookingActor::Student);

        return ['allowed' => $decision->allowed, 'remaining' => $decision->remaining()];
    }

    /**
     * Pre-confirmation preview only, shown while the cancel
     * panel is open on a still-paid booking. Deliberately uses now(): no
     * commitment has happened yet, so there is nothing frozen to read
     * back yet — this is exactly the one place a live recalculation is
     * correct. Returns null when there is nothing to refund (free demo,
     * unpaid/failed booking) so the view can omit the section entirely.
     *
     * @return array{eligible: bool, cutoff_at: ?CarbonImmutable}|null
     */
    public function cancellationRefundPreview(): ?array
    {
        if (! $this->booking || $this->booking->payment_status !== BookingPaymentStatus::Paid) {
            return null;
        }

        $decision = $this->refundPolicy->decide($this->booking, BookingActor::Student, CarbonImmutable::now());

        return ['eligible' => $decision->eligible, 'cutoff_at' => $decision->cutoffAt];
    }

    /**
     * The actual FROZEN outcome after cancellation, read
     * back from the payment's own metadata (the same durable record
     * BookingPaymentService wrote at cancellation time) — never a
     * fresh policy recalculation, so this always matches what actually
     * happened even if the setting has since changed.
     */
    public function cancellationOutcomeMessage(): ?string
    {
        if (! $this->booking || $this->booking->status !== BookingStatus::Cancelled) {
            return null;
        }

        // Builder::value('metadata->refund_resolution') would silently
        // return null here: Eloquent resolves a value() column back to
        // an attribute via Str::afterLast($column, '.'), which only
        // understands dot-paths, not MySQL's -> JSON operator — so the
        // full row is read instead and the cast array is used directly.
        $resolution = BookingPayment::query()
            ->where('booking_id', $this->booking->id)
            ->latest('created_at')
            ->first()
            ?->metadata['refund_resolution'] ?? null;

        return match ($resolution) {
            'wallet_credited' => 'The amount paid has been credited to your wallet.',
            'not_eligible_late_cancellation' => 'This cancellation was outside the refund window, so no refund was issued.',
            'manual_resolution_required' => 'Your refund is being reviewed by our team.',
            default => null,
        };
    }

    /**
     * Display-only wallet-balance snapshot for the payment-awaiting
     * section — never authoritative; payWithWallet() re-validates
     * balance and eligibility itself before debiting anything. Reads
     * the wallet if one already exists but never creates one merely
     * from viewing this screen.
     *
     * @return array{available: bool, sufficient?: bool, balance_formatted?: string}|null
     */
    public function walletOption(): ?array
    {
        if (! $this->booking || ! app(FeatureSettings::class)->wallet_enabled) {
            return null;
        }

        if (! $this->booking->payment_status->isPayable()) {
            return null;
        }

        $wallet = Wallet::query()
            ->forUser((int) auth()->id())
            ->where('currency_code', $this->booking->currency)
            ->with('currency')
            ->first();

        if ($wallet === null) {
            return ['available' => false];
        }

        $minorUnits = MoneyFormatter::minorUnitsFor((string) $this->booking->currency);
        $amountMinor = (int) round(((float) $this->booking->price) * (10 ** $minorUnits));

        return [
            'available' => true,
            'sufficient' => $wallet->available_balance_minor >= $amountMinor,
            'balance_formatted' => WalletMoneyFormatter::format($wallet->available_balance_minor, $wallet->currency, $wallet->currency_code),
        ];
    }

    public function render(): View
    {
        return view('livewire.frontend.student.booking-detail', [
            // The join URL comes exclusively from
            // the authoritative BookingMeetingService::studentJoinUrlFor()
            // (ownership + strict Active lifecycle on a fresh read +
            // visibility setting + booking/meeting status) — this
            // component renders only what the domain service releases,
            // and the blade never reads meeting->join_url directly. A
            // stale request after suspension therefore receives HTML
            // with no provider URL. Boundary: a URL already copied
            // externally cannot be revoked without provider integration;
            // this controls what the application serves.
            // What the page renders is the SIRI join link (the
            // authenticated gateway), shown only when the authoritative
            // decision would release the provider URL to this viewer;
            // the provider URL itself is only ever a server-side redirect.
            // The one join decision, plus the window edges it was made
            // from, so the page can say "opens at" / "closes at" without
            // recomputing the rule. Polled while the window is live, and
            // again while completion is pending so the page flips to
            // Completed without a reload.
            ...$this->joinState(),
            // Same discipline for the recording: the blade renders only
            // the state RecordingPlaybackAccessResolver releases for the
            // authenticated viewer (playback setting, ownership, lifecycle,
            // withholding) and never inspects the recording row itself.
            // The whole schedule, paginated — a long series must never
            // try to render every class at once.
            'series' => $this->ownedSeries(),
            'autoSettleAvailable' => app(BookingSeriesPrepaymentService::class)->autoSettleAvailable(),
            'seriesSchedule' => ($series = $this->ownedSeries()) !== null
                ? app(BookingSeriesService::class)->scheduleFor($series, $this->seriesPage)
                : null,
            'recordingState' => $this->booking !== null
                ? app(RecordingPlaybackAccessResolver::class)
                    ->stateFor($this->booking->loadMissing('recording'), auth()->user())
                : RecordingPlaybackState::Hidden,
        ]);
    }

    /**
     * @return array{joinState: StudentJoinState, pollJoinState: bool, awaitingCompletion: bool}
     */
    private function joinState(): array
    {
        if ($this->booking === null) {
            return ['joinState' => StudentJoinState::unavailable(), 'pollJoinState' => false, 'awaitingCompletion' => false];
        }

        // The same authoritative decision every student surface renders
        // (ownership + strict lifecycle + visibility + the one window).
        $joinState = app(BookingMeetingServiceInterface::class)->studentJoinStateFor($this->booking, auth()->user());
        $awaitingCompletion = $this->booking->isAwaitingCompletion();

        return [
            'joinState' => $joinState,
            'pollJoinState' => $awaitingCompletion || $joinState->poll,
            // Ended, not yet marked complete: nothing about the recording
            // can be said until the lesson outcome is finalised.
            'awaitingCompletion' => $awaitingCompletion,
        ];
    }

    private function loadRescheduleSlots(): void
    {
        $this->rescheduleSlotStartsAt = null;

        if (! $this->booking || ! $this->rescheduleDate) {
            $this->rescheduleSlots = [];

            return;
        }

        $timezone = $this->booking->timezone;
        $date = CarbonImmutable::parse($this->rescheduleDate, $timezone)->startOfDay();

        $this->rescheduleSlots = $this->availability->slots(new AvailabilityQueryData(
            instructorId: $this->booking->instructor_id,
            typeKey: $this->booking->type->key,
            from: $date,
            to: $date->addDay(),
            timezone: $timezone,
        ))->map(fn ($slot): array => [
            'starts_at' => $slot->startsAt->toIso8601String(),
        ])->values()->all();
    }
}
