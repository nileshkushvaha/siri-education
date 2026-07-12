<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Student;

use App\Booking\Contracts\AvailabilityServiceInterface;
use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\DTOs\AvailabilityQueryData;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\DTOs\RescheduleBookingData;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingStatus;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\InvalidPaymentWebhookException;
use App\Booking\Payments\RazorpayPaymentProvider;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Settings\MeetingSettings;
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

    public function boot(
        StudentBookingServiceInterface $bookings,
        BookingRepositoryInterface $repository,
        BookingServiceInterface $bookingService,
        AvailabilityServiceInterface $availability,
        BookingPaymentServiceInterface $payments,
        RazorpayPaymentProvider $razorpay,
    ): void {
        $this->bookings = $bookings;
        $this->repository = $repository;
        $this->bookingService = $bookingService;
        $this->availability = $availability;
        $this->payments = $payments;
        $this->razorpay = $razorpay;
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function viewBooking(string $bookingId): void
    {
        $booking = $this->repository->findOrFail($bookingId);

        Gate::authorize('view', $booking);

        $this->selectedBooking = $booking->loadMissing(['type', 'host']);
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
                actor: BookingActor::Attendee,
            ));

            $this->selectedBooking = $updated->loadMissing(['type', 'host']);
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
                cancelledBy: BookingActor::Attendee,
                reason: filled($this->cancelReason) ? $this->cancelReason : null,
            ));

            $this->selectedBooking = $updated->loadMissing(['type', 'host']);
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

        $this->selectedBooking = $booking->loadMissing(['type', 'host']);
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

            $this->selectedBooking = $booking->refresh()->loadMissing(['type', 'host']);
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

            $this->selectedBooking = $booking->refresh()->loadMissing(['type', 'host']);
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

    public function render(): View
    {
        $status = $this->statusFilter !== '' ? BookingStatus::from($this->statusFilter) : null;

        return view('livewire.frontend.student.booking-history', [
            'history' => $this->bookings->bookingHistory(auth()->user(), 10, $status),
            'statuses' => BookingStatus::cases(),
            'joinUrlVisible' => app(MeetingSettings::class)->student_join_url_visible,
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
            hostId: $this->selectedBooking->host_id,
            typeKey: $this->selectedBooking->type->key,
            from: $date,
            to: $date->addDay(),
            timezone: $timezone,
        ))->map(fn ($slot): array => [
            'starts_at' => $slot->startsAt->toIso8601String(),
            'remaining_capacity' => $slot->remainingCapacity,
        ])->values()->all();
    }
}
