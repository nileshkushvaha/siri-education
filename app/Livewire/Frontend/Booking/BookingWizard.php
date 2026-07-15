<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Booking;

use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\InvalidPaymentWebhookException;
use App\Booking\Payments\RazorpayPaymentProvider;
use App\Booking\Services\BookingWizardService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The authenticated-student booking-creation wizard (Phase 17U.3 —
 * `/book` is auth-gated; renamed from the pre-authenticated-only
 * "guest wizard"). Student identity always comes from the session —
 * this component never collects or stores name/email/phone.
 */
final class BookingWizard extends Component
{
    public int $step = 1;

    /** @var list<array<string, mixed>> */
    public array $types = [];

    /** @var list<string> */
    public array $subjects = [];

    /** @var list<int> */
    public array $grades = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];

    /** @var list<string> */
    public array $dates = [];

    /** @var list<array<string, mixed>> */
    public array $availableSlots = [];

    public ?string $type = null;

    public ?string $subject = null;

    public ?int $grade = null;

    public string $month = '';

    public ?string $date = null;

    public ?string $selectedSlotStartsAt = null;

    public string $timezone = 'UTC';

    public string $notes = '';

    public string $banner = '';

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    #[Locked]
    public ?string $bookingId = null;

    /** @var array<string, mixed> */
    public array $paymentOrder = [];

    public string $paymentBanner = '';

    /**
     * Set once in mount() from a server-validated slug lookup and never
     * again — #[Locked] rejects any client-submitted update, so a crafted
     * Livewire request cannot swap the marketplace-locked instructor.
     */
    #[Locked]
    public ?int $lockedInstructorId = null;

    #[Locked]
    public ?string $lockedInstructorName = null;

    private BookingWizardService $wizard;

    private BookingRepositoryInterface $bookings;

    private BookingPaymentServiceInterface $payments;

    private RazorpayPaymentProvider $razorpay;

    public function boot(
        BookingWizardService $wizard,
        BookingRepositoryInterface $bookings,
        BookingPaymentServiceInterface $payments,
        RazorpayPaymentProvider $razorpay,
    ): void {
        $this->wizard = $wizard;
        $this->bookings = $bookings;
        $this->payments = $payments;
        $this->razorpay = $razorpay;
    }

    public function mount(): void
    {
        $this->timezone = config('app.timezone', 'UTC');
        $this->month = now($this->timezone)->format('Y-m');
        $this->types = $this->wizard->bookingTypes()->all();
        $this->type = collect($this->types)->first()['key'] ?? null;
        $this->subjects = $this->wizard->subjects()->all();

        $requestedType = request()->query('type');
        if (is_string($requestedType) && collect($this->types)->pluck('key')->contains($requestedType)) {
            $this->type = $requestedType;
        }

        $requestedSubject = request()->query('subject');
        if (is_string($requestedSubject) && in_array($requestedSubject, $this->subjects, true)) {
            $this->subject = $requestedSubject;
            $this->step = 2;
        }

        $requestedInstructor = request()->query('instructor');
        if (is_string($requestedInstructor) && filled($requestedInstructor)) {
            $lockedInstructor = $this->wizard->lockedInstructor($requestedInstructor);

            if ($lockedInstructor) {
                $this->lockedInstructorId = $lockedInstructor['id'];
                $this->lockedInstructorName = $lockedInstructor['name'];
            } else {
                $this->banner = 'This instructor is not available for public booking right now.';
            }
        }
    }

    public function setTimezone(string $timezone): void
    {
        if (in_array($timezone, timezone_identifiers_list(), true)) {
            $this->timezone = $timezone;
        }
    }

    public function selectSubject(string $subject): void
    {
        $this->subject = $subject;
        $this->grade = null;
        $this->resetAvailability();
        $this->goTo(2);
    }

    public function selectGrade(int $grade): void
    {
        $this->grade = $grade;
        $this->resetAvailability();
        $this->validateSelection(['subject', 'grade']);
        $this->loadDates();
        $this->goTo(3);
    }

    public function previousMonth(): void
    {
        $this->month = $this->monthDate()->subMonthNoOverflow()->format('Y-m');
        $this->loadDates();
    }

    public function nextMonth(): void
    {
        $this->month = $this->monthDate()->addMonthNoOverflow()->format('Y-m');
        $this->loadDates();
    }

    public function selectDate(string $date): void
    {
        $this->date = $date;
        $this->selectedSlotStartsAt = null;
        $this->validateSelection(['subject', 'grade', 'date']);
        $this->loadSlots();
        $this->goTo(4);
    }

    public function selectSlot(string $startsAt): void
    {
        $this->selectedSlotStartsAt = $startsAt;
        $this->validateSelection(['selectedSlotStartsAt']);
        $this->goTo(5);
    }

    public function submit(): void
    {
        $this->banner = '';
        $this->validate($this->rulesForStep(5), [], $this->validationAttributes());

        try {
            $booking = $this->wizard->book([
                'type' => $this->type,
                'subject' => $this->subject,
                'grade' => $this->grade,
                'starts_at' => $this->selectedSlotStartsAt,
                'timezone' => $this->timezone,
                'notes' => filled($this->notes) ? $this->notes : null,
                'teacher_id' => $this->lockedInstructorId,
            ]);

            $this->bookingId = $booking->id;
            $this->result = $this->wizard->result($booking);
            $this->goTo(6);
        } catch (BookingException $exception) {
            $this->banner = $exception->getMessage();
        }
    }

    public function initiatePayment(): void
    {
        $this->paymentBanner = '';
        $this->paymentOrder = [];

        if ($this->bookingId === null) {
            return;
        }

        // Phase 10.2C-Fix: see BookingHistory::initiatePayment() for the
        // identical check and rationale (billing country required before
        // checkout, enforced at the UI entry point rather than inside
        // BookingPaymentService::initiate() itself).
        if (auth()->user()?->profile?->country_id === null) {
            $this->paymentBanner = 'Please complete your profile (country) before paying for this booking.';

            return;
        }

        try {
            $booking = $this->bookings->findOrFail($this->bookingId);
            $this->payments->initiate($booking);
            $payload = $this->payments->checkoutPayload($booking);

            // Gateway-neutral: backend decides the provider. See
            // BookingHistory::initiatePayment() for the identical pattern
            // and rationale (Stripe/fake have no client checkout step here).
            if (($payload['provider'] ?? null) === 'razorpay') {
                $this->paymentOrder = $payload;
                $this->dispatch(
                    'razorpay-checkout-ready',
                    orderId: $payload['order_id'],
                    keyId: $payload['key_id'],
                    amountMinor: $payload['amount_minor'],
                    currency: $payload['currency'],
                    name: auth()->user()->name,
                    email: auth()->user()->email,
                );
            } elseif (($payload['provider'] ?? null) === 'stripe') {
                // client_secret/publishable_key travel only in the transient
                // dispatch payload, never stored on $paymentOrder (a public,
                // client-hydrated Livewire property) — see
                // BookingHistory::initiatePayment() for the identical
                // rationale. The frontend mounts Stripe's Payment Element
                // and calls stripe.confirmPayment() directly with Stripe;
                // this component never receives the outcome back from that
                // call — only a signed webhook may settle the booking (see
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
            $this->paymentBanner = $exception->getMessage();
        }
    }

    /** Local/testing-only — see BookingHistory::simulateFakePayment() for the identical rationale. */
    public function simulateFakePayment(bool $success): void
    {
        if ($this->bookingId === null || ! app()->environment(['local', 'testing'])) {
            return;
        }

        $this->paymentBanner = '';

        try {
            $booking = $this->bookings->findOrFail($this->bookingId)->refresh();
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

            $this->result = $this->wizard->result($booking->refresh());
        } catch (BookingException $exception) {
            $this->paymentBanner = $exception->getMessage();
        }
    }

    public function verifyPayment(string $orderId, string $paymentId, string $signature): void
    {
        $this->paymentBanner = '';

        if ($this->bookingId === null) {
            return;
        }

        try {
            $booking = $this->bookings->findOrFail($this->bookingId);
            $this->razorpay->verifyCheckout($booking, $orderId, $paymentId, $signature);

            // Reload before the state check: a concurrent webhook delivery
            // may have already settled this booking.
            $booking->refresh();

            if ($booking->payment_status->isPayable()) {
                $this->payments->markPaid($booking, (string) $booking->payment_reference);
            }

            $this->result = $this->wizard->result($booking->refresh());
        } catch (InvalidPaymentWebhookException|BookingException $exception) {
            $this->paymentBanner = $exception->getMessage();
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
        if ($this->bookingId === null) {
            return;
        }

        $booking = $this->bookings->findOrFail($this->bookingId)->refresh();

        if ($booking->payment_status->value === 'paid') {
            $this->paymentBanner = '';
            $this->result = $this->wizard->result($booking);
        } elseif ($booking->payment_status->value === 'failed') {
            $this->paymentBanner = 'Payment failed. Please try again.';
        }
    }

    public function back(): void
    {
        if ($this->step > 1 && $this->step < 6) {
            $this->step--;
        }
    }

    public function restart(): void
    {
        $defaultType = collect($this->types)->first()['key'] ?? null;

        $this->reset([
            'step',
            'dates',
            'availableSlots',
            'type',
            'subject',
            'grade',
            'date',
            'selectedSlotStartsAt',
            'notes',
            'banner',
            'result',
            'bookingId',
            'paymentOrder',
            'paymentBanner',
        ]);

        $this->step = 1;
        $this->type = $defaultType;
        $this->month = now($this->timezone)->format('Y-m');
    }

    public function render(): View
    {
        return view('livewire.frontend.booking.booking-wizard', [
            'selectedType' => collect($this->types)->firstWhere('key', $this->type),
            'selectedSlot' => collect($this->availableSlots)->firstWhere('starts_at', $this->selectedSlotStartsAt),
            'calendar' => $this->calendar(),
            'steps' => $this->steps(),
            'canGoPreviousMonth' => $this->monthDate()->greaterThan(now($this->timezone)->startOfMonth()),
            'canGoNextMonth' => $this->monthDate()->lessThan(now($this->timezone)->addDays(90)->startOfMonth()),
        ]);
    }

    /** @param list<string> $fields */
    private function validateSelection(array $fields): void
    {
        $rules = [];

        foreach ($fields as $field) {
            $rules[$field] = $this->rulesForStep(match ($field) {
                'subject' => 1,
                'grade' => 2,
                'date' => 3,
                'selectedSlotStartsAt' => 4,
                default => 5,
            })[$field];
        }

        $this->validate($rules, [], $this->validationAttributes());
    }

    private function loadDates(): void
    {
        $this->banner = '';

        if (! $this->type || ! $this->subject || ! $this->grade) {
            $this->dates = [];

            return;
        }

        $month = $this->monthDate();
        $from = $month->greaterThan(now($this->timezone)) ? $month : CarbonImmutable::now($this->timezone);
        $to = $month->endOfMonth()->min(CarbonImmutable::now($this->timezone)->addDays(90));

        if ($from->greaterThan($to)) {
            $this->dates = [];

            return;
        }

        try {
            $this->dates = $this->wizard
                ->availableDates($this->type, $this->subject, (int) $this->grade, $from, $to, $this->timezone, $this->lockedInstructorId)
                ->all();
        } catch (BookingException $exception) {
            $this->dates = [];
            $this->banner = $exception->getMessage();
        }
    }

    private function loadSlots(): void
    {
        $this->banner = '';

        if (! $this->type || ! $this->subject || ! $this->grade || ! $this->date) {
            $this->availableSlots = [];

            return;
        }

        try {
            $this->availableSlots = $this->wizard
                ->availableSlots($this->type, $this->subject, (int) $this->grade, CarbonImmutable::parse($this->date, $this->timezone), $this->timezone, $this->lockedInstructorId)
                ->all();
        } catch (BookingException $exception) {
            $this->availableSlots = [];
            $this->banner = $exception->getMessage();
        }
    }

    /** @return array<string, mixed> */
    private function rulesForStep(int $step): array
    {
        return match ($step) {
            1 => ['subject' => ['required', 'string', Rule::in($this->subjects)]],
            2 => ['grade' => ['required', 'integer', 'min:1', 'max:12']],
            3 => ['date' => ['required', 'date_format:Y-m-d', Rule::in($this->dates)]],
            4 => ['selectedSlotStartsAt' => ['required', 'string', Rule::in(collect($this->availableSlots)->pluck('starts_at')->all())]],
            default => [
                'type' => ['required', 'string', Rule::in(collect($this->types)->pluck('key')->all())],
                'subject' => ['required', 'string', Rule::in($this->subjects)],
                'grade' => ['required', 'integer', 'min:1', 'max:12'],
                'date' => ['required', 'date_format:Y-m-d', Rule::in($this->dates)],
                'selectedSlotStartsAt' => ['required', 'string', Rule::in(collect($this->availableSlots)->pluck('starts_at')->all())],
                'timezone' => ['required', 'timezone'],
                'notes' => ['nullable', 'string', 'max:1000'],
            ],
        };
    }

    /** @return array<string, string> */
    private function validationAttributes(): array
    {
        return [
            'type' => 'session type',
            'selectedSlotStartsAt' => 'available slot',
        ];
    }

    private function goTo(int $step): void
    {
        $this->step = $step;
    }

    private function resetAvailability(): void
    {
        $this->dates = [];
        $this->availableSlots = [];
        $this->date = null;
        $this->selectedSlotStartsAt = null;
    }

    private function monthDate(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->month.'-01', $this->timezone)->startOfMonth();
    }

    /** @return list<array<string, mixed>> */
    private function calendar(): array
    {
        $month = $this->monthDate();
        $days = [];

        for ($i = 0; $i < $month->dayOfWeek; $i++) {
            $days[] = null;
        }

        for ($day = 1; $day <= $month->daysInMonth; $day++) {
            $date = $month->setDay($day);
            $iso = $date->toDateString();

            $days[] = [
                'day' => $day,
                'iso' => $iso,
                'label' => $date->format('l, F j'),
                'available' => in_array($iso, $this->dates, true),
                'selected' => $this->date === $iso,
            ];
        }

        return $days;
    }

    /** @return list<array{number: int, label: string}> */
    private function steps(): array
    {
        return [
            ['number' => 1, 'label' => 'Subject'],
            ['number' => 2, 'label' => 'Grade'],
            ['number' => 3, 'label' => 'Date'],
            ['number' => 4, 'label' => 'Time'],
            ['number' => 5, 'label' => 'Review'],
            ['number' => 6, 'label' => 'Confirmed'],
        ];
    }
}
