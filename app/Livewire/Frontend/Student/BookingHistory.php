<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Student;

use App\Booking\Contracts\AvailabilityServiceInterface;
use App\Booking\Contracts\BookingMeetingServiceInterface;
use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\DTOs\AvailabilityQueryData;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\DTOs\RescheduleBookingData;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\InvalidPaymentWebhookException;
use App\Booking\Payments\RazorpayPaymentProvider;
use App\Booking\Services\CancellationRefundPolicy;
use App\Booking\Services\RescheduleLimitPolicy;
use App\Models\Booking;
use App\Models\BookingPayment;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

final class BookingHistory extends Component
{
    use WithPagination;

    public string $statusFilter = '';

    public ?Booking $selectedBooking = null;

    public bool $reschedulePanelOpen = false;

    public bool $cancelPanelOpen = false;

    public string $rescheduleDate = '';

    public ?string $rescheduleSlotStartsAt = null;

    /** @var list<array<string, mixed>> */
    public array $rescheduleSlots = [];

    public string $cancelReason = '';

    public string $modalBanner = '';

    /** @var array<string, mixed> */
    public array $paymentOrder = [];

    private StudentBookingServiceInterface $bookings;

    private BookingRepositoryInterface $repository;

    private BookingServiceInterface $bookingService;

    private AvailabilityServiceInterface $availability;

    private BookingPaymentServiceInterface $payments;

    private RazorpayPaymentProvider $razorpay;

    private CancellationRefundPolicy $refundPolicy;

    private RescheduleLimitPolicy $reschedulePolicy;

    public function boot(
        StudentBookingServiceInterface $bookings,
        BookingRepositoryInterface $repository,
        BookingServiceInterface $bookingService,
        AvailabilityServiceInterface $availability,
        BookingPaymentServiceInterface $payments,
        RazorpayPaymentProvider $razorpay,
        CancellationRefundPolicy $refundPolicy,
        RescheduleLimitPolicy $reschedulePolicy,
    ): void {
        $this->bookings = $bookings;
        $this->repository = $repository;
        $this->bookingService = $bookingService;
        $this->availability = $availability;
        $this->payments = $payments;
        $this->razorpay = $razorpay;
        $this->refundPolicy = $refundPolicy;
        $this->reschedulePolicy = $reschedulePolicy;
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function viewBooking(string $bookingId): void
    {
        $booking = $this->repository->findOrFail($bookingId);

        Gate::authorize('view', $booking);

        $this->selectedBooking = $booking->loadMissing(['type', 'instructor']);
        $this->reschedulePanelOpen = false;
        $this->cancelPanelOpen = false;
        $this->rescheduleDate = '';
        $this->rescheduleSlotStartsAt = null;
        $this->rescheduleSlots = [];
        $this->cancelReason = '';
        $this->modalBanner = '';

        $this->dispatch('open-modal', id: 'booking-detail-modal');
    }

    public function openReschedulePanel(): void
    {
        if (! $this->selectedBooking) {
            return;
        }

        Gate::authorize('reschedule', $this->selectedBooking);

        $this->cancelPanelOpen = false;
        $this->reschedulePanelOpen = true;
    }

    public function openCancelPanel(): void
    {
        if (! $this->selectedBooking) {
            return;
        }

        Gate::authorize('cancel', $this->selectedBooking);

        $this->reschedulePanelOpen = false;
        $this->cancelPanelOpen = true;
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
        if (! $this->selectedBooking) {
            return;
        }

        Gate::authorize('reschedule', $this->selectedBooking);

        if (! $this->rescheduleSlotStartsAt) {
            return;
        }

        $this->modalBanner = '';

        try {
            $updated = $this->bookingService->reschedule($this->selectedBooking, new RescheduleBookingData(
                startsAt: CarbonImmutable::parse($this->rescheduleSlotStartsAt),
                actor: BookingActor::Student,
            ));

            $this->selectedBooking = $updated->loadMissing(['type', 'instructor']);
            $this->reschedulePanelOpen = false;
            $this->rescheduleDate = '';
            $this->rescheduleSlotStartsAt = null;
            $this->rescheduleSlots = [];
        } catch (BookingException $exception) {
            $this->modalBanner = $exception->getMessage();
        }
    }

    public function confirmCancel(): void
    {
        if (! $this->selectedBooking) {
            return;
        }

        Gate::authorize('cancel', $this->selectedBooking);

        $this->modalBanner = '';

        try {
            $updated = $this->bookingService->cancel($this->selectedBooking, new CancelBookingData(
                cancelledBy: BookingActor::Student,
                reason: filled($this->cancelReason) ? $this->cancelReason : null,
            ));

            // Phase 24C — the synchronous refund-execution listener
            // mutates payment_status on its own freshly-queried copy of
            // the booking, not this in-memory $updated instance, so a
            // refresh is required or the modal would keep showing the
            // pre-refund "Paid" state.
            $this->selectedBooking = $updated->refresh()->loadMissing(['type', 'instructor']);
            $this->cancelPanelOpen = false;
        } catch (BookingException $exception) {
            $this->modalBanner = $exception->getMessage();
        }
    }

    public function initiatePayment(): void
    {
        if (! $this->selectedBooking) {
            return;
        }

        Gate::authorize('pay', $this->selectedBooking);

        $this->modalBanner = '';
        $this->paymentOrder = [];

        // Phase 10.2C-Fix: a resolvable billing country is required before
        // checkout — PaymentProviderResolver's country-aware routing (Phase
        // 10.2B) would otherwise silently fall through to the platform
        // default, which is correct for *routing* but not a substitute for
        // asking the student to complete their profile first. Checked here
        // (the UI entry point), not inside BookingPaymentService::initiate()
        // itself, which many other callers (webhooks, direct service tests)
        // also use for concerns unrelated to profile completeness.
        if (auth()->user()?->profile?->country_id === null) {
            $this->modalBanner = 'Please complete your profile (country) before paying for this booking.';

            return;
        }

        try {
            $this->payments->initiate($this->selectedBooking);
            $payload = $this->payments->checkoutPayload($this->selectedBooking);

            // Gateway-neutral: backend decides the provider, frontend only
            // reacts to it. Only Razorpay has a client-side checkout step
            // today — Stripe Elements/Checkout is intentionally deferred
            // (see docs/architecture/phase-10-razorpay-checkout-payment-capture.md,
            // Phase 10.2C), and the fake provider has no real checkout UI
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
            $this->modalBanner = $exception->getMessage();
        }
    }

    /**
     * Polled by the Stripe Payment Element partial after
     * stripe.confirmPayment() returns client-side — never trusted as
     * settlement itself, only a signal to re-check what the server
     * already knows. Only a signed webhook (StripePaymentProvider::parseWebhook())
     * ever calls markPaid()/markFailed() for Stripe; this method makes
     * no state change of its own, it only re-reads and re-renders.
     */
    public function checkPaymentStatus(): void
    {
        if (! $this->selectedBooking) {
            return;
        }

        Gate::authorize('pay', $this->selectedBooking);

        $booking = $this->selectedBooking->refresh();

        if ($booking->payment_status->value === 'paid') {
            $this->modalBanner = '';
        } elseif ($booking->payment_status->value === 'failed') {
            $this->modalBanner = 'Payment failed. Please try again.';
        }

        $this->selectedBooking = $booking->loadMissing(['type', 'instructor']);
    }

    public function verifyPayment(string $orderId, string $paymentId, string $signature): void
    {
        if (! $this->selectedBooking) {
            return;
        }

        Gate::authorize('pay', $this->selectedBooking);

        $this->modalBanner = '';

        try {
            $this->razorpay->verifyCheckout($this->selectedBooking, $orderId, $paymentId, $signature);

            $booking = $this->selectedBooking->refresh();

            if ($booking->payment_status->isPayable()) {
                $this->payments->markPaid($booking, (string) $booking->payment_reference);
            }

            $this->selectedBooking = $booking->refresh()->loadMissing(['type', 'instructor']);
        } catch (InvalidPaymentWebhookException|BookingException $exception) {
            $this->modalBanner = $exception->getMessage();
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
        if (! $this->selectedBooking || ! app()->environment(['local', 'testing'])) {
            return;
        }

        Gate::authorize('pay', $this->selectedBooking);

        $this->modalBanner = '';

        try {
            $booking = $this->selectedBooking->refresh();
            $reference = (string) $booking->payment_reference;

            if ($success) {
                if ($booking->payment_status->isPayable()) {
                    $this->payments->markPaid($booking, $reference);
                }
            } else {
                if ($booking->payment_status->isPayable()) {
                    $this->payments->markFailed($booking, $reference, 'Simulated failure (fake provider).');
                }
            }

            $this->selectedBooking = $booking->refresh()->loadMissing(['type', 'instructor']);
        } catch (BookingException $exception) {
            $this->modalBanner = $exception->getMessage();
        }
    }

    /**
     * Whether the selected booking's payment was recovered as a wallet
     * credit (Phase 10.2B Option B) rather than actively refunded —
     * cheap, safe metadata check, no sensitive payload exposed.
     */
    public function paymentWasCreditedToWallet(): bool
    {
        if (! $this->selectedBooking) {
            return false;
        }

        return BookingPayment::query()
            ->where('booking_id', $this->selectedBooking->id)
            ->whereNotNull('metadata->wallet_ledger_entry_id')
            ->exists();
    }

    /**
     * Phase 24D — the student-facing reschedule allowance for the
     * selected booking. Purely informational: BookingService::reschedule()
     * re-derives and enforces the same decision under the instructor
     * lock, so a stale render here can never let a student bypass the
     * configured limit.
     *
     * @return array{allowed: bool, remaining: int}|null
     */
    public function rescheduleAllowance(): ?array
    {
        if (! $this->selectedBooking || $this->selectedBooking->status->isTerminal()) {
            return null;
        }

        $decision = $this->reschedulePolicy->decide($this->selectedBooking, BookingActor::Student);

        return ['allowed' => $decision->allowed, 'remaining' => $decision->remaining()];
    }

    /**
     * Phase 24C — pre-confirmation preview only, shown while the cancel
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
        if (! $this->selectedBooking || $this->selectedBooking->payment_status !== BookingPaymentStatus::Paid) {
            return null;
        }

        $decision = $this->refundPolicy->decide($this->selectedBooking, BookingActor::Student, CarbonImmutable::now());

        return ['eligible' => $decision->eligible, 'cutoff_at' => $decision->cutoffAt];
    }

    /**
     * Phase 24C — the actual FROZEN outcome after cancellation, read
     * back from the payment's own metadata (the same durable record
     * BookingPaymentService wrote at cancellation time) — never a
     * fresh policy recalculation, so this always matches what actually
     * happened even if the setting has since changed.
     */
    public function cancellationOutcomeMessage(): ?string
    {
        if (! $this->selectedBooking || $this->selectedBooking->status !== BookingStatus::Cancelled) {
            return null;
        }

        // Builder::value('metadata->refund_resolution') would silently
        // return null here: Eloquent resolves a value() column back to
        // an attribute via Str::afterLast($column, '.'), which only
        // understands dot-paths, not MySQL's -> JSON operator — so the
        // full row is read instead and the cast array is used directly.
        $resolution = BookingPayment::query()
            ->where('booking_id', $this->selectedBooking->id)
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

    public function render(): View
    {
        $status = $this->statusFilter !== '' ? BookingStatus::from($this->statusFilter) : null;

        return view('livewire.frontend.student.booking-history', [
            'history' => $this->bookings->bookingHistory(auth()->user(), 10, $status),
            'statuses' => BookingStatus::cases(),
            // Phase 24H.2A — GAP-013: the join URL comes exclusively from
            // the authoritative BookingMeetingService::studentJoinUrlFor()
            // (ownership + strict Active lifecycle on a fresh read +
            // visibility setting + booking/meeting status) — this
            // component renders only what the domain service releases,
            // and the blade never reads meeting->join_url directly. A
            // stale request after suspension therefore receives HTML
            // with no provider URL. Boundary: a URL already copied
            // externally cannot be revoked without provider integration;
            // this controls what the application serves.
            'joinUrl' => $this->selectedBooking !== null
                ? app(BookingMeetingServiceInterface::class)
                    ->studentJoinUrlFor($this->selectedBooking, auth()->user())
                : null,
        ]);
    }

    private function loadRescheduleSlots(): void
    {
        $this->rescheduleSlotStartsAt = null;

        if (! $this->selectedBooking || ! $this->rescheduleDate) {
            $this->rescheduleSlots = [];

            return;
        }

        $timezone = $this->selectedBooking->timezone;
        $date = CarbonImmutable::parse($this->rescheduleDate, $timezone)->startOfDay();

        $this->rescheduleSlots = $this->availability->slots(new AvailabilityQueryData(
            instructorId: $this->selectedBooking->instructor_id,
            typeKey: $this->selectedBooking->type->key,
            from: $date,
            to: $date->addDay(),
            timezone: $timezone,
        ))->map(fn ($slot): array => [
            'starts_at' => $slot->startsAt->toIso8601String(),
        ])->values()->all();
    }
}
