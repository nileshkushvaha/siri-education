<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Booking;

use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\DTOs\RecurrenceData;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\InvalidPaymentWebhookException;
use App\Booking\Payments\RazorpayPaymentProvider;
use App\Booking\Services\BookingWizardService;
use App\Models\Wallet;
use App\Settings\FeatureSettings;
use App\Support\MoneyFormatter;
use App\Wallet\Support\WalletMoneyFormatter;
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
 *
 * Phase 17U.3A: the booking mode (Free Demo / Paid Lesson) is always
 * an explicit choice — never a silent default from array/DB ordering.
 * The step sequence is variable-length: `phases()` computes the
 * ordered list of phases for the current selections (paid types add
 * a Single/Recurring phase, recurring adds a Frequency phase), and
 * `$step` is always that list's 1-indexed position.
 */
final class BookingWizard extends Component
{
    private const array PHASE_LABELS = [
        'mode' => 'Session type',
        'subject' => 'Subject',
        'grade' => 'Grade',
        'billing_mode' => 'Schedule',
        'frequency' => 'Frequency',
        'date' => 'Date',
        'time' => 'Time',
        'review' => 'Review',
        'confirmed' => 'Confirmed',
    ];

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

    public bool $recurring = false;

    public ?string $frequency = null;

    public int $occurrences = 4;

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
     * Display-only — never treated as authoritative. Populated by
     * refreshWalletOption() whenever the payment-awaiting phase is
     * reached or re-rendered; payWithWallet() always re-checks balance
     * and eligibility itself before debiting anything.
     *
     * @var array<string, mixed>
     */
    public array $walletOption = [];

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
        $this->subjects = $this->wizard->subjects()->all();

        $requestedType = request()->query('type');
        if (is_string($requestedType) && collect($this->types)->pluck('key')->contains($requestedType)) {
            $this->type = $requestedType;
        }

        // Subject can only be pre-filled once a valid type was also
        // supplied — subject alone never implies (or skips) a mode choice.
        $requestedSubject = request()->query('subject');
        if ($this->type !== null && is_string($requestedSubject) && in_array($requestedSubject, $this->subjects, true)) {
            $this->subject = $requestedSubject;
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

        // Jump ahead exactly as far as valid query params carry us —
        // never further, never guessed from array/DB ordering.
        if ($this->type !== null) {
            $this->goToPhase($this->subject !== null ? 'grade' : 'subject');
        }
    }

    public function setTimezone(string $timezone): void
    {
        if (in_array($timezone, timezone_identifiers_list(), true)) {
            $this->timezone = $timezone;
        }
    }

    public function selectMode(string $type): void
    {
        if (! collect($this->types)->pluck('key')->contains($type)) {
            return;
        }

        $this->type = $type;
        $this->recurring = false;
        $this->frequency = null;
        $this->resetAvailability();
        $this->goToPhase('subject');
    }

    public function selectSubject(string $subject): void
    {
        $this->subject = $subject;
        $this->grade = null;
        $this->resetAvailability();
        $this->goToPhase('grade');
    }

    public function selectGrade(int $grade): void
    {
        $this->grade = $grade;
        $this->resetAvailability();
        $this->validateSelection(['subject', 'grade']);

        if ($this->isPaidType()) {
            $this->goToPhase('billing_mode');

            return;
        }

        $this->loadDates();
        $this->goToPhase('date');
    }

    public function selectBillingMode(string $mode): void
    {
        if (! in_array($mode, ['single', 'recurring'], true)) {
            return;
        }

        $this->recurring = $mode === 'recurring';

        if ($this->recurring) {
            $this->goToPhase('frequency');

            return;
        }

        $this->frequency = null;
        $this->loadDates();
        $this->goToPhase('date');
    }

    public function selectFrequency(string $frequency, int $occurrences): void
    {
        if (! in_array($frequency, ['daily', 'weekly'], true)) {
            return;
        }

        $this->frequency = $frequency;
        $this->occurrences = max(2, min($occurrences, RecurrenceData::MAX_OCCURRENCES));
        $this->loadDates();
        $this->goToPhase('date');
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
        $this->goToPhase('time');
    }

    public function selectSlot(string $startsAt): void
    {
        $this->selectedSlotStartsAt = $startsAt;
        $this->validateSelection(['selectedSlotStartsAt']);
        $this->goToPhase('review');
    }

    public function submit(): void
    {
        $this->banner = '';
        $this->validate($this->rulesForSubmit(), [], $this->validationAttributes());

        $payload = [
            'type' => $this->type,
            'subject' => $this->subject,
            'grade' => $this->grade,
            'starts_at' => $this->selectedSlotStartsAt,
            'timezone' => $this->timezone,
            'notes' => filled($this->notes) ? $this->notes : null,
            'teacher_id' => $this->lockedInstructorId,
        ];

        try {
            if ($this->recurring) {
                $result = $this->wizard->bookRecurring($payload, (string) $this->frequency, $this->occurrences);
                $this->bookingId = $result->booked->first()?->id;
                $this->result = $this->wizard->recurringResult($result);
            } else {
                $booking = $this->wizard->book($payload);
                $this->bookingId = $booking->id;
                $this->result = $this->wizard->result($booking);
                $this->refreshWalletOption();
            }

            $this->goToPhase('confirmed');
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

    /**
     * Pays the reserved booking directly from the student's wallet — no
     * gateway, no redirect, settles in this one request. See
     * BookingHistory::payWithWallet() for the identical pattern.
     */
    public function payWithWallet(): void
    {
        $this->paymentBanner = '';

        if ($this->bookingId === null) {
            return;
        }

        try {
            $booking = $this->bookings->findOrFail($this->bookingId);
            $booking = $this->payments->payWithWallet($booking, auth()->user());

            $this->result = $this->wizard->result($booking);
        } catch (BookingException $exception) {
            $this->paymentBanner = $exception->getMessage();
        }

        $this->refreshWalletOption();
    }

    /**
     * Display-only wallet-balance snapshot for the payment-awaiting
     * screen — never authoritative; payWithWallet() re-validates
     * everything itself. Reads the wallet if one already exists but
     * never creates one merely from viewing this screen.
     */
    private function refreshWalletOption(): void
    {
        $this->walletOption = [];

        if ($this->bookingId === null || ! app(FeatureSettings::class)->wallet_enabled) {
            return;
        }

        $booking = $this->bookings->findOrFail($this->bookingId)->refresh();

        if (! $booking->payment_status->isPayable()) {
            return;
        }

        $wallet = Wallet::query()
            ->forUser((int) auth()->id())
            ->where('currency_code', $booking->currency)
            ->with('currency')
            ->first();

        if ($wallet === null) {
            $this->walletOption = ['available' => false];

            return;
        }

        $minorUnits = MoneyFormatter::minorUnitsFor((string) $booking->currency);
        $amountMinor = (int) round(((float) $booking->price) * (10 ** $minorUnits));

        $this->walletOption = [
            'available' => true,
            'sufficient' => $wallet->available_balance_minor >= $amountMinor,
            'balance_formatted' => WalletMoneyFormatter::format($wallet->available_balance_minor, $wallet->currency, $wallet->currency_code),
        ];
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
        if ($this->step > 1 && $this->step < count($this->phases())) {
            $this->step--;
        }
    }

    public function restart(): void
    {
        $this->reset([
            'step',
            'dates',
            'availableSlots',
            'type',
            'subject',
            'grade',
            'recurring',
            'frequency',
            'occurrences',
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
        $this->occurrences = 4;
        $this->month = now($this->timezone)->format('Y-m');
    }

    public function render(): View
    {
        $phases = $this->phases();

        return view('livewire.frontend.booking.booking-wizard', [
            'currentPhase' => $phases[$this->step - 1] ?? 'mode',
            'selectedType' => collect($this->types)->firstWhere('key', $this->type),
            'selectedSlot' => collect($this->availableSlots)->firstWhere('starts_at', $this->selectedSlotStartsAt),
            'calendar' => $this->calendar(),
            'steps' => $this->steps(),
            'canGoPreviousMonth' => $this->monthDate()->greaterThan(now($this->timezone)->startOfMonth()),
            'canGoNextMonth' => $this->monthDate()->lessThan(now($this->timezone)->addDays(90)->startOfMonth()),
        ]);
    }

    private function isPaidType(): bool
    {
        return (bool) (collect($this->types)->firstWhere('key', $this->type)['is_paid'] ?? false);
    }

    /** @param list<string> $fields */
    private function validateSelection(array $fields): void
    {
        $rules = [];

        foreach ($fields as $field) {
            $rules[$field] = $this->fieldRules()[$field];
        }

        $this->validate($rules, [], $this->validationAttributes());
    }

    private function resetAvailability(): void
    {
        $this->dates = [];
        $this->availableSlots = [];
        $this->date = null;
        $this->selectedSlotStartsAt = null;
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
    private function fieldRules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(collect($this->types)->pluck('key')->all())],
            'subject' => ['required', 'string', Rule::in($this->subjects)],
            'grade' => ['required', 'integer', 'min:1', 'max:12'],
            'date' => ['required', 'date_format:Y-m-d', Rule::in($this->dates)],
            'selectedSlotStartsAt' => ['required', 'string', Rule::in(collect($this->availableSlots)->pluck('starts_at')->all())],
            'timezone' => ['required', 'timezone'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'frequency' => ['required', Rule::in(['daily', 'weekly'])],
            'occurrences' => ['required', 'integer', 'between:2,'.RecurrenceData::MAX_OCCURRENCES],
        ];
    }

    /** @return array<string, mixed> */
    private function rulesForSubmit(): array
    {
        $rules = collect($this->fieldRules())->only([
            'type', 'subject', 'grade', 'date', 'selectedSlotStartsAt', 'timezone', 'notes',
        ])->all();

        if ($this->recurring) {
            $rules += collect($this->fieldRules())->only(['frequency', 'occurrences'])->all();
        }

        return $rules;
    }

    /** @return array<string, string> */
    private function validationAttributes(): array
    {
        return [
            'type' => 'session type',
            'selectedSlotStartsAt' => 'available slot',
        ];
    }

    /**
     * The ordered phase list for the current selections. Paid types add
     * a billing-mode choice; a recurring choice adds a frequency step.
     * Free Demo and single-session Paid Lesson skip both.
     *
     * @return list<string>
     */
    private function phases(): array
    {
        $phases = ['mode', 'subject', 'grade'];

        if ($this->isPaidType()) {
            $phases[] = 'billing_mode';

            if ($this->recurring) {
                $phases[] = 'frequency';
            }
        }

        $phases[] = 'date';
        $phases[] = 'time';
        $phases[] = 'review';
        $phases[] = 'confirmed';

        return $phases;
    }

    private function goToPhase(string $phase): void
    {
        $index = array_search($phase, $this->phases(), true);
        $this->step = $index === false ? 1 : $index + 1;
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
        return collect($this->phases())
            ->values()
            ->map(fn (string $phase, int $i): array => [
                'number' => $i + 1,
                'label' => $phase === 'confirmed' ? $this->finalPhaseLabel() : self::PHASE_LABELS[$phase],
            ])
            ->all();
    }

    private function finalPhaseLabel(): string
    {
        if (! $this->isPaidType()) {
            return self::PHASE_LABELS['confirmed'];
        }

        if ($this->result !== null && ! ($this->result['requires_payment'] ?? false)) {
            return self::PHASE_LABELS['confirmed'];
        }

        return 'Payment';
    }
}
