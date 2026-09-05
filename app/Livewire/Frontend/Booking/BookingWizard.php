<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Booking;

use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\DTOs\RecurrenceData;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\InvalidPaymentWebhookException;
use App\Booking\Exceptions\NoEligibleTeacherException;
use App\Booking\Exceptions\SlotUnavailableException;
use App\Booking\Payments\RazorpayPaymentProvider;
use App\Booking\Services\BookingWizardService;
use App\Booking\Support\FakePaymentSimulator;
use App\Curriculum\DTOs\AcademicContextData;
use App\Models\Country;
use App\Models\EducationSystem;
use App\Models\User;
use App\Models\Wallet;
use App\Settings\BookingSettings;
use App\Settings\FeatureSettings;
use App\Support\MoneyFormatter;
use App\Support\Timezone\IanaTimezone;
use App\Support\UserTimezoneResolver;
use App\Wallet\Support\WalletMoneyFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The authenticated-student booking-creation wizard —
 * `/book` is auth-gated; renamed from the pre-authenticated-only
 * "guest wizard". Student identity always comes from the session —
 * this component never collects or stores name/email/phone.
 *
 * The booking mode (Free Demo / Paid Lesson) is always
 * an explicit choice — never a silent default from array/DB ordering.
 * The step sequence is variable-length: `phases()` computes the
 * ordered list of phases for the current selections (paid types add
 * a Single/Recurring phase, recurring adds a Frequency phase), and
 * `$step` is always that list's 1-indexed position.
 */
final class BookingWizard extends Component
{
    /**
     * Student-facing stages. Each groups consecutive internal phases;
     * the phase list and `$step` stay the authoritative state machine,
     * stages are how it is presented.
     */
    private const array STAGE_PHASES = [
        'learning' => ['mode', 'level', 'academic_subject', 'curriculum', 'subject', 'grade'],
        'schedule' => ['billing_mode', 'frequency', 'date', 'time'],
        'review' => ['funding', 'review'],
        'outcome' => ['confirmed'],
    ];

    private const array STAGE_LABELS = [
        'learning' => 'Learning details',
        'schedule' => 'Schedule',
        'review' => 'Review',
    ];

    private const string SLOT_TAKEN_MESSAGE = 'That time is no longer available. Please choose another time.';

    public int $step = 1;

    /** @var list<array<string, mixed>> */
    public array $types = [];

    /** @var list<string> */
    public array $subjects = [];

    /**
     * Legacy (non-academic) Demo/Paid flow only — offered when the
     * country-aware academic flow is inactive (feature off globally or
     * for this student's country, per §14). The country-aware flow
     * never reads this array; its selectable levels come exclusively
     * from EducationSystemLevel (see $levels below).
     *
     * @var list<int>
     */
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

    /**
     * TZ-1 (TZ-AUD-013): #[Locked] so a crafted Livewire property
     * update cannot set this directly and bypass setTimezone()'s
     * guards. Previously $timezonePinned was locked but the value it
     * protects was not, which made the pin trivially skippable — the
     * client simply wrote $timezone instead of calling setTimezone().
     *
     * Every legitimate write still works: mount() and setTimezone() are
     * server-side, and #[Locked] only rejects CLIENT-initiated updates.
     */
    #[Locked]
    public string $timezone = 'UTC';

    /** True when the account has its own stored timezone, which browser detection may not override. */
    #[Locked]
    public bool $timezonePinned = false;

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

    // ── Country-aware academic lesson booking (§5-§13) ─────────────────────
    //
    // Country is always server-resolved (§6) — #[Locked] rejects any
    // client-submitted override, exactly like $lockedInstructorId. The
    // three flags below are mutually informative, never redundant:
    // academicFlowActive = "the wizard is walking the mandatory
    // System/Level/Subject/Curriculum chain"; academicFlowBlocked =
    // "the student cannot enter that chain because they have no usable
    // Country — the whole free_demo mode is refused" (§6/§10);
    // academicFlowUnavailable = "feature is in effect and Country is
    // fine, but admin configuration for this Country is incomplete
    // (e.g. no Education Systems mapped yet)" — the flow stays active
    // but shows a configuration-missing message and never falls back to
    // legacy subject/grade (§13/§25).
    public bool $academicFlowActive = false;

    public bool $academicFlowBlocked = false;

    public bool $academicFlowUnavailable = false;

    // ── Phase 4D — package funding (§33) ───────────────────────────────────
    //
    // The student's explicit funding choice. NULL means "pay normally",
    // which is both the default and the behavior when no package
    // qualifies — owning a compatible package never forces its use
    // (§31). Deliberately NOT #[Locked]: unlike Country, this IS a
    // student choice. It is re-validated server-side against ownership,
    // instructor, academic identity, capacity and expiry before it can
    // reach a Booking (§40), so a forged value is rejected there.
    public ?string $packageEntitlementId = null;

    /** @var list<array<string, mixed>> every qualifying package — never auto-narrowed to one (§29) */
    public array $fundingOptions = [];

    /**
     * Display-only price for the current selection from
     * BookingWizardService::pricePreview(); empty until a complete
     * learning selection resolves one. Never submitted — the booking's
     * price is recalculated server-side at creation.
     *
     * @var array<string, mixed>
     */
    public array $pricePreview = [];

    /** True when the learning selection was pre-filled from the student's own history and not yet changed. */
    public bool $prefilledLearning = false;

    #[Locked]
    public ?int $studentCountryId = null;

    #[Locked]
    public ?string $studentCountryName = null;

    /** @var list<array{id:string,name:string}> */
    public array $educationSystems = [];

    /**
     * Phase 3.1 — the exact, student-selectable levels under the chosen
     * Education System (Class 6..12 / Grade 6..12 / Year 6..12, ...).
     * Replaces the old academicLevels/grade two-step choice entirely —
     * see selectLevel(). Never synthesized from min/max bands or a
     * hardcoded 1..12 fallback (§7/§39): an empty array after selecting
     * a system means the flow is unavailable for it.
     *
     * @var list<array{id:string,value:string,display_label:string,normalized_grade:?int}>
     */
    public array $levels = [];

    /** @var list<array{id:string,name:string}> */
    public array $academicSubjects = [];

    /** @var list<array{id:string,name:string}> */
    public array $curricula = [];

    public ?string $educationSystemId = null;

    /** The single student-facing level choice — implies both academic_level_id and normalized_grade once resolved server-side (§12). */
    public ?string $educationSystemLevelId = null;

    public ?string $academicSubjectId = null;

    public ?string $curriculumId = null;

    private BookingWizardService $wizard;

    private BookingRepositoryInterface $bookings;

    private BookingPaymentServiceInterface $payments;

    private RazorpayPaymentProvider $razorpay;

    private ?EducationSystem $educationSystemMemo = null;

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
        // The student's OWN stored timezone (seeded from their country at
        // registration) is authoritative — the same resolution order every
        // scheduled notification uses. `config('app.timezone')` is the
        // server's storage timezone (UTC) and must never be shown as "your
        // local timezone"; browser detection only fills in when the account
        // has no stored timezone of its own.
        //
        // TZ-1: the pin tracks a valid EXPLICIT profile timezone, not
        // merely a resolved one. A student whose timezone comes from the
        // Country fallback has still never actually told us where they
        // are — for a multi-timezone country that default may well be
        // wrong — so browser detection is allowed to refine it. Only a
        // choice the student made themselves is protected. A stored
        // value that is invalid does not pin either: it is not a usable
        // choice, and the resolver has already fallen past it.
        $user = Auth::user();
        $this->timezonePinned = IanaTimezone::isValid($user?->profile?->timezone);
        $this->timezone = $user !== null
            ? UserTimezoneResolver::resolve($user)
            : UserTimezoneResolver::PLATFORM_FALLBACK;

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
        // never further, never guessed from array/DB ordering. A
        // preselected ?subject= is legacy-only (free-text) and never
        // bypasses the country-aware academic selection.
        if ($this->type !== null) {
            $this->initializeAcademicFlow();

            if (! $this->academicFlowBlocked && $this->academicFlowActive && $this->step === 1) {
                $this->goToPhase('level');
            }
        }
    }

    /**
     * Browser-detected timezone, used only when the account has no
     * explicit timezone of its own. A device reporting UTC (or a VPN,
     * or travel) must not silently override the timezone the student
     * actually set.
     *
     * TZ-1: this is now the ONLY way a client can influence
     * $this->timezone — the property itself is #[Locked]. The value is
     * checked against the canonical IANA list, so an unparseable
     * string, a bare offset or a legacy abbreviation is ignored and the
     * server-resolved timezone stands. Nothing here writes to the
     * profile: a detected timezone shapes this wizard session only and
     * never becomes a permanent stored value behind the student's back.
     */
    public function setTimezone(string $timezone): void
    {
        if ($this->timezonePinned) {
            return;
        }

        if (IanaTimezone::isValid($timezone)) {
            $this->timezone = $timezone;
            $this->month = now($this->timezone)->format('Y-m');
        }
    }

    public function selectMode(string $type): void
    {
        if (! collect($this->types)->pluck('key')->contains($type)) {
            return;
        }

        $this->banner = '';
        $this->step = 1;
        $this->type = $type;
        $this->recurring = false;
        $this->frequency = null;
        $this->grade = null;
        $this->subject = null;
        $this->resetAvailability();
        $this->resetAcademicSelection();

        $this->initializeAcademicFlow();

        if ($this->academicFlowBlocked) {
            // Stay on the mode step — banner already carries the
            // actionable message (§6/§10: never fall back silently).
            return;
        }

        if ($this->academicFlowActive && $this->step === 1) {
            $this->goToPhase('level');
        }
    }

    public function selectSubject(string $subject): void
    {
        $this->subject = $subject;
        $this->grade = null;
        $this->pricePreview = [];
        $this->resetAvailability();
        $this->goToPhase('grade');
    }

    // ── Phase 3 — progressive academic selection (§7/§8) ─────────────────

    public function selectEducationSystem(string $educationSystemId): void
    {
        if (! collect($this->educationSystems)->pluck('id')->contains($educationSystemId)) {
            return;
        }

        $this->educationSystemId = $educationSystemId;
        $this->educationSystemLevelId = null;
        $this->academicSubjectId = null;
        $this->curriculumId = null;
        $this->levels = [];
        $this->academicSubjects = [];
        $this->curricula = [];
        $this->pricePreview = [];
        $this->resetAvailability();

        $country = $this->currentCountry();

        if ($country === null) {
            return;
        }

        $this->levels = $this->wizard->levels($country, $educationSystemId);
        $this->goToPhase('level');
    }

    /**
     * The single student-facing level choice (§12) — replaces the old
     * separate Academic Level + Grade phases entirely. Selecting a
     * level implies both academic_level_id and normalized_grade; a
     * level with no normalized_grade is currently unsupported for lesson
     * booking (§9 — no invented subject-only fallback) and is refused
     * here with the same message DemoAcademicContextResolver would
     * throw at submit time.
     */
    public function selectLevel(string $educationSystemLevelId): void
    {
        $selected = collect($this->levels)->firstWhere('id', $educationSystemLevelId);

        if ($selected === null) {
            return;
        }

        if ($selected['normalized_grade'] === null) {
            $this->banner = 'This level is not currently supported for booking. Please select a different level.';

            return;
        }

        if ($this->educationSystemLevelId === $educationSystemLevelId && $this->academicSubjects !== []) {
            $this->goToPhase($this->furthestLearningPhase());

            return;
        }

        $this->prefilledLearning = false;
        $this->educationSystemLevelId = $educationSystemLevelId;
        $this->grade = (int) $selected['normalized_grade'];
        $this->academicSubjectId = null;
        $this->curriculumId = null;
        $this->academicSubjects = [];
        $this->curricula = [];
        $this->pricePreview = [];
        $this->resetAvailability();

        $country = $this->currentCountry();

        if ($country === null || $this->educationSystemId === null) {
            return;
        }

        $this->academicSubjects = $this->wizard->academicSubjects($country, $this->educationSystemId, $educationSystemLevelId);
        $this->goToPhase('academic_subject');
    }

    public function selectAcademicSubject(string $academicSubjectId): void
    {
        if (! collect($this->academicSubjects)->pluck('id')->contains($academicSubjectId)) {
            return;
        }

        if ($this->academicSubjectId === $academicSubjectId && $this->curricula !== []) {
            $this->goToPhase($this->furthestLearningPhase());

            return;
        }

        $this->prefilledLearning = false;
        $this->academicSubjectId = $academicSubjectId;
        $this->curriculumId = null;
        $this->curricula = [];
        $this->pricePreview = [];
        $this->resetAvailability();

        // Legacy-compat: $subject (the free-text field TeacherSubject /
        // meta.subject / candidate matching already reads) is derived
        // from the validated Subject master, never trusted as its own
        // client input (§20 — legacy fields stay populated but are
        // never authoritative for the new academic history).
        $this->subject = collect($this->academicSubjects)->firstWhere('id', $academicSubjectId)['name'] ?? null;

        $country = $this->currentCountry();

        if ($country === null || $this->educationSystemId === null || $this->educationSystemLevelId === null) {
            return;
        }

        $this->curricula = $this->wizard->curricula($country, $this->educationSystemId, $this->educationSystemLevelId, $academicSubjectId, $this->lockedInstructorId);
        $this->goToPhase('curriculum');
    }

    /** Finalizes the country-aware academic selection. */
    public function selectCurriculum(string $curriculumId): void
    {
        if (! collect($this->curricula)->pluck('id')->contains($curriculumId)) {
            return;
        }

        if ($this->curriculumId === $curriculumId) {
            $this->goToPhase('curriculum');

            return;
        }

        $this->prefilledLearning = false;
        $this->curriculumId = $curriculumId;
        $this->resetAvailability();
        $this->validateSelection(['educationSystemId', 'educationSystemLevelId', 'academicSubjectId', 'curriculumId']);
        $this->refreshPricePreview();

        if (! $this->isPaidType()) {
            $this->loadDates();
        }

        $this->goToPhase('curriculum');
    }

    /** Legacy (non-academic) flow only — the country-aware flow finalizes its selection in selectCurriculum() instead. */
    public function selectGrade(int $grade): void
    {
        $this->grade = $grade;
        $this->resetAvailability();
        $this->validateSelection(['subject', 'grade']);
        $this->refreshPricePreview();

        if (! $this->isPaidType()) {
            $this->loadDates();
        }

        $this->goToPhase('grade');
    }

    public function selectBillingMode(string $mode): void
    {
        if (! in_array($mode, ['single', 'recurring'], true)) {
            return;
        }

        if ($this->recurring !== ($mode === 'recurring')) {
            $this->resetAvailability();
        }

        $this->recurring = $mode === 'recurring';

        if ($this->recurring) {
            // Phase 4E.3 — package funding is single-lesson only in
            // Version 1. Switching to recurring therefore CLEARS an
            // already-made package choice and says so, rather than
            // carrying it to a service that would quietly ignore it and
            // bill the student instead (PKG-AUD-007). A commercial
            // choice must never be discarded silently.
            if ($this->packageEntitlementId !== null) {
                $this->banner = 'Package lessons are booked one at a time, so your package has not been applied to this recurring series. Each session will be charged normally.';
            }

            $this->packageEntitlementId = null;
            $this->fundingOptions = [];

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

        // Phase 4D — funding options depend on the chosen SLOT, not just
        // the academic context: an entitlement is only offered when the
        // lesson would finish before it expires (§26). They are therefore
        // loaded here, once a concrete instant exists.
        $this->loadFundingOptions();

        $this->goToPhase('time');
    }

    /**
     * Records the student's EXPLICIT funding choice.
     *
     * '' means "pay normally" and is the default — a compatible package
     * is never preselected and never auto-applied (§31/§33). A posted id
     * is re-validated server-side at submit; nothing here is trusted.
     */
    public function selectFunding(string $entitlementId): void
    {
        $this->packageEntitlementId = $entitlementId !== '' ? $entitlementId : null;
        $this->goToPhase('review');
    }

    public function submit(): void
    {
        if ($this->result !== null) {
            return;
        }

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
            // Phase 3/3.1 (§14) — these raw ids are re-resolved and
            // re-validated server-side (WizardBookingService ->
            // DemoAcademicContextResolver -> AcademicContextResolver)
            // immediately before persistence; nothing here is trusted
            // as-is. Null for every legacy/paid submission.
            'education_system_id' => $this->academicFlowActive ? $this->educationSystemId : null,
            'education_system_level_id' => $this->academicFlowActive ? $this->educationSystemLevelId : null,
            'academic_subject_id' => $this->academicFlowActive ? $this->academicSubjectId : null,
            'curriculum_id' => $this->academicFlowActive ? $this->curriculumId : null,
            // Phase 4D (§40) — the student's explicit choice, raw and
            // untrusted. WizardBookingService re-checks it against their
            // own ownership, the instructor, the resolved academic
            // identity, available-to-book capacity and expiry before it
            // can fund anything. Null for every ordinary paid booking.
            'package_entitlement_id' => $this->packageEntitlementId,
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
        } catch (SlotUnavailableException|NoEligibleTeacherException) {
            $this->returnToTimeSelection();
        } catch (BookingException $exception) {
            $this->banner = $exception->getMessage();
        }
    }

    /**
     * The chosen slot was taken between selection and confirmation.
     * Every other selection is still valid, so only the slot is cleared
     * and the (freshly reloaded) times for the same day are offered
     * again — the student is not sent back to the start.
     */
    private function returnToTimeSelection(): void
    {
        $this->selectedSlotStartsAt = null;
        $this->fundingOptions = [];
        $this->packageEntitlementId = null;
        $this->loadSlots();
        $this->goToPhase('time');
        $this->banner = self::SLOT_TAKEN_MESSAGE;
    }

    public function initiatePayment(): void
    {
        $this->paymentBanner = '';
        $this->paymentOrder = [];

        if ($this->bookingId === null) {
            return;
        }

        // See BookingHistory::initiatePayment() for the
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
        if ($this->bookingId === null || ! app(FakePaymentSimulator::class)->isAvailable()) {
            return;
        }

        $this->paymentBanner = '';

        try {
            $booking = $this->bookings->findOrFail($this->bookingId)->refresh();

            // Routed through the real settlement service, not markPaid():
            // see FakePaymentSimulator for why a direct markPaid() left
            // the ledger uncaptured and silently skipped the receipt.
            app(FakePaymentSimulator::class)->simulate($booking, $success);

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

            // verifyCheckout() proves the signature and that this order
            // belongs to this booking, and records provider_payment_id on
            // the attempt. It settles NOTHING, and neither does this
            // method: the browser is not evidence that money moved.
            //
            // This used to call markPaid() here. That confirmed the
            // booking straight from the callback while leaving the
            // obligation and attempt uncaptured, so the receipt and
            // notifications — which resolve a CAPTURED obligation —
            // silently never fired. A student ended up with a confirmed
            // lesson, no receipt and no email, and a replayed callback
            // could confirm a lesson that was never paid for.
            //
            // Settlement arrives from the signed payment.captured
            // webhook, or from the reconciliation sweep if that webhook
            // is lost. Until then the UI shows a confirming state.
            $this->razorpay->verifyCheckout($booking, $orderId, $paymentId, $signature);

            $this->result = $this->wizard->result($booking->refresh());
        } catch (InvalidPaymentWebhookException|BookingException $exception) {
            $this->paymentBanner = $exception->getMessage();
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
        if ($this->bookingId === null) {
            return;
        }

        $booking = $this->bookings->findOrFail($this->bookingId)->refresh();
        $this->result = $this->wizard->result($booking);

        if ($booking->payment_status->value === 'paid') {
            $this->paymentBanner = '';
        } elseif ($booking->payment_status->value === 'failed') {
            $this->paymentBanner = 'Payment failed. Please try again.';
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
            'walletOption',
            'fundingOptions',
            'packageEntitlementId',
            'pricePreview',
            'prefilledLearning',
        ]);

        $this->resetAcademicSelection();
        $this->academicFlowActive = false;
        $this->academicFlowBlocked = false;
        $this->academicFlowUnavailable = false;
        $this->studentCountryId = null;
        $this->studentCountryName = null;

        $this->step = 1;
        $this->occurrences = 4;
        $this->month = now($this->timezone)->format('Y-m');
    }

    public function render(): View
    {
        $phases = $this->phases();

        $currentPhase = $phases[$this->step - 1] ?? 'mode';

        return view('livewire.frontend.booking.booking-wizard', [
            'currentPhase' => $currentPhase,
            'currentStage' => $this->stageOf($currentPhase),
            'stages' => $this->stages($currentPhase),
            'selectedType' => collect($this->types)->firstWhere('key', $this->type),
            'selectedSlot' => collect($this->availableSlots)->firstWhere('starts_at', $this->selectedSlotStartsAt),
            'selectedLevel' => collect($this->levels)->firstWhere('id', $this->educationSystemLevelId),
            'selectedCurriculum' => collect($this->curricula)->firstWhere('id', $this->curriculumId),
            'selectedFunding' => collect($this->fundingOptions)->firstWhere('id', $this->packageEntitlementId),
            'calendar' => $this->calendar(),
            'slotGroups' => $this->slotGroups(),
            'canGoPreviousMonth' => $this->monthDate()->greaterThan(now($this->timezone)->startOfMonth()),
            'canGoNextMonth' => $this->monthDate()->lessThan(now($this->timezone)->addDays(90)->startOfMonth()),
            'levelTermSingular' => $this->levelTermSingular(),
            'levelTermPlural' => $this->levelTermPlural(),
            'timezoneLabel' => $this->timezoneLabel(),
            'learningSummary' => $this->learningSummary(),
            'scheduleSummary' => $this->scheduleSummary(),
            'recurrenceSummary' => $this->recurrenceSummary(),
            'billingModeChosen' => $this->recurring || $this->phaseIndex($currentPhase) > $this->phaseIndex('billing_mode'),
            'learningComplete' => $this->learningComplete(),
            'policy' => [
                'cancellation_window_hours' => max(0, app(BookingSettings::class)->cancellation_window_hours),
                'reschedule_limit' => max(0, app(BookingSettings::class)->reschedule_limit),
            ],
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

    /**
     * Phase 3 (§8) — clears every selection that depends on a phase the
     * student is (re)entering. Called on selectMode() (fresh start) and
     * restart(); the individual select*() methods above additionally
     * clear their own narrower downstream slice (System change clears
     * Level/Subject/Curriculum, Level change clears Subject/Curriculum,
     * Subject change clears Curriculum) so a stale, incompatible
     * selection can never survive an upstream change.
     */
    private function resetAcademicSelection(): void
    {
        $this->educationSystemId = null;
        $this->educationSystemLevelId = null;
        $this->academicSubjectId = null;
        $this->curriculumId = null;
        $this->educationSystems = [];
        $this->levels = [];
        $this->academicSubjects = [];
        $this->curricula = [];
        $this->pricePreview = [];
        $this->prefilledLearning = false;
        $this->educationSystemMemo = null;
    }

    /**
     * Phase 3 (§5/§6/§13) — the single entry point that decides, for the
     * current authenticated student. Every lesson booking uses country-aware
     * academics and is blocked when the student's country is unusable.
     * Always re-derives the student's Country
     * server-side (never trusts prior component state).
     */
    private function initializeAcademicFlow(): void
    {
        $this->academicFlowActive = false;
        $this->academicFlowBlocked = false;
        $this->academicFlowUnavailable = false;
        $this->studentCountryId = null;
        $this->studentCountryName = null;

        $user = Auth::user();
        $country = $user !== null ? $this->wizard->studentCountry($user) : null;

        if ($country === null || $country->status !== 'active') {
            $this->academicFlowBlocked = true;
            $this->banner = 'Please complete your profile country before booking a lesson.';

            return;
        }

        $this->studentCountryId = $country->id;
        $this->studentCountryName = $country->name;

        $this->academicFlowActive = true;
        $this->educationSystems = $this->wizard->educationSystems($country, $this->lockedInstructorId);

        if (empty($this->educationSystems)) {
            // §13/§25: enabled but not yet configured for this country —
            // never fall back to legacy, show an unavailable state.
            $this->academicFlowUnavailable = true;

            return;
        }

        // Country mapping is authoritative. The first active mapping follows
        // its configured display order, so students do not need a redundant
        // education-system step merely to confirm their own country.
        $this->selectEducationSystem((string) $this->educationSystems[0]['id']);
        $this->applyLearningPrefill($user);
    }

    /**
     * Pre-selects what the student chose last time, as far as those
     * choices are still offered. Each id goes through the same select*()
     * method a click would, so validation and narrowing (a locked
     * instructor, a level with no grade, an archived curriculum) apply
     * unchanged, and the chain simply stops at the first id that is no
     * longer available. A fully pre-filled selection lands the student on
     * the schedule directly; anything partial leaves them on the learning
     * details with the rest still to choose.
     */
    private function applyLearningPrefill(User $user): void
    {
        $prefill = $this->wizard->learningPrefill($user);
        $levelId = $this->prefillLevelId($prefill);

        if ($levelId === null) {
            return;
        }

        $this->selectLevel($levelId);

        if ($this->educationSystemLevelId !== $levelId) {
            return;
        }

        $subjectId = $prefill['subject_id'];

        if ($subjectId === null || ! collect($this->academicSubjects)->contains('id', $subjectId)) {
            $this->prefilledLearning = true;

            return;
        }

        $this->selectAcademicSubject($subjectId);

        $curriculumId = $prefill['curriculum_id'];

        if ($curriculumId === null || ! collect($this->curricula)->contains('id', $curriculumId)) {
            $this->prefilledLearning = true;

            return;
        }

        $this->selectCurriculum($curriculumId);
        $this->prefilledLearning = true;
        $this->continueStage();
    }

    /**
     * The level to pre-select: the exact level of the student's last
     * booking when it is still offered under the auto-selected system,
     * otherwise the single offered level mapped to the academic level on
     * their profile. Ambiguity (several levels under one academic level)
     * means no pre-selection at all.
     *
     * @param  array{education_system_id:?string,education_system_level_id:?string,subject_id:?string,curriculum_id:?string,academic_level_id:?string}  $prefill
     */
    private function prefillLevelId(array $prefill): ?string
    {
        $levels = collect($this->levels)->where('normalized_grade', '!==', null);

        if ($prefill['education_system_id'] === $this->educationSystemId
            && $prefill['education_system_level_id'] !== null
            && $levels->contains('id', $prefill['education_system_level_id'])) {
            return $prefill['education_system_level_id'];
        }

        if ($prefill['academic_level_id'] === null) {
            return null;
        }

        $matches = $levels->where('academic_level_id', $prefill['academic_level_id']);

        return $matches->count() === 1 ? (string) $matches->first()['id'] : null;
    }

    private function currentCountry(): ?Country
    {
        if ($this->studentCountryId === null) {
            return null;
        }

        return Country::find($this->studentCountryId);
    }

    /** The selected EducationSystem's configured level term ("Class"/"Grade"/"Year"), or the generic "Level" fallback (§13). */
    private function levelTermSingular(): string
    {
        return $this->currentEducationSystem()?->levelTermSingular() ?? 'Level';
    }

    private function levelTermPlural(): string
    {
        return $this->currentEducationSystem()?->levelTermPlural() ?? 'Levels';
    }

    private function currentEducationSystem(): ?EducationSystem
    {
        if ($this->educationSystemId === null) {
            return null;
        }

        if ($this->educationSystemMemo?->id !== $this->educationSystemId) {
            $this->educationSystemMemo = EducationSystem::find($this->educationSystemId);
        }

        return $this->educationSystemMemo;
    }

    private function refreshPricePreview(): void
    {
        $this->pricePreview = [];
        $user = Auth::user();

        if ($user === null || $this->type === null || ! $this->learningComplete()) {
            return;
        }

        $this->pricePreview = $this->wizard->pricePreview(
            $user,
            $this->type,
            $this->subject,
            $this->grade,
            $this->lockedInstructorId,
            collect($this->levels)->firstWhere('id', $this->educationSystemLevelId)['academic_level_id'] ?? null,
        ) ?? [];
    }

    private function loadDates(): void
    {
        $this->banner = '';

        if (! $this->type || ! $this->subject || ! $this->grade) {
            $this->dates = [];

            return;
        }

        if ($this->academicFlowActive && ($this->curriculumId === null)) {
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
                ->availableDates($this->type, $this->subject, (int) $this->grade, $from, $to, $this->timezone, $this->lockedInstructorId, $this->browsingAcademicContext())
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
                ->availableSlots($this->type, $this->subject, (int) $this->grade, CarbonImmutable::parse($this->date, $this->timezone), $this->timezone, $this->lockedInstructorId, $this->browsingAcademicContext())
                ->all();
        } catch (BookingException $exception) {
            $this->availableSlots = [];
            $this->banner = $exception->getMessage();
        }
    }

    /**
     * Loads every package that could fund the currently-selected lesson.
     *
     * Requires a locked instructor: a package entitlement belongs to one
     * specific instructor, so "which of my packages apply" is
     * unanswerable while the assignment engine may still pick anyone.
     * An auto-assigned booking therefore simply offers no packages and
     * proceeds as an ordinary paid booking — a deliberate, fail-closed
     * limit rather than a guess at who will be assigned.
     *
     * Never preselects: `$packageEntitlementId` stays null so "pay
     * normally" remains the default until the student chooses (§33).
     */
    private function loadFundingOptions(): void
    {
        $this->fundingOptions = [];
        $this->packageEntitlementId = null;

        $user = Auth::user();

        if ($user === null || $this->lockedInstructorId === null || $this->selectedSlotStartsAt === null) {
            return;
        }

        if (! $this->isPaidType()) {
            return;
        }

        // Phase 4E.3 — Version 1 package funding covers a single lesson,
        // so a recurring series is never offered the choice at all. The
        // funding step is skipped entirely rather than shown and then
        // ignored (PKG-AUD-007); WizardBookingService refuses the
        // combination server-side regardless of what the browser sends.
        if ($this->recurring) {
            return;
        }

        $this->fundingOptions = $this->wizard->fundingOptions(
            $user,
            $this->lockedInstructorId,
            $this->educationSystemId,
            $this->educationSystemLevelId,
            $this->academicSubjectId,
            $this->curriculumId,
            CarbonImmutable::parse($this->selectedSlotStartsAt, $this->timezone),
            (string) $this->type,
        );
    }

    /**
     * Non-authoritative narrowing context for date/slot browsing only
     * (§7/§10) — never what actually gates Booking creation. See
     * BookingWizardService::resolveAcademicContextForBrowsing()'s
     * docblock.
     */
    private function browsingAcademicContext(): ?AcademicContextData
    {
        if (! $this->academicFlowActive) {
            return null;
        }

        $country = $this->currentCountry();

        if ($country === null) {
            return null;
        }

        return $this->wizard->resolveAcademicContextForBrowsing($country, $this->educationSystemId, $this->educationSystemLevelId, $this->academicSubjectId, $this->curriculumId);
    }

    /** @return array<string, mixed> */
    private function fieldRules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(collect($this->types)->pluck('key')->all())],
            // Phase 3 (§20): under the academic flow, $subject is
            // derived server-side from the validated Subject master
            // (selectAcademicSubject()) rather than chosen from the
            // legacy free-text list — it is still required, but not
            // checked against $subjects, which only ever lists legacy
            // TeacherSubject free-text values.
            'subject' => $this->academicFlowActive
                ? ['required', 'string']
                : ['required', 'string', Rule::in($this->subjects)],
            // Legacy (non-academic) Demo/Paid flow only — the
            // country-aware flow never submits 'grade' as an
            // independent field; it derives from educationSystemLevelId
            // instead and is never checked against a hardcoded 1-12
            // bound here (§34 cleanup).
            'grade' => ['required', 'integer', 'min:1', 'max:12'],
            'date' => ['required', 'date_format:Y-m-d', Rule::in($this->dates)],
            'selectedSlotStartsAt' => ['required', 'string', Rule::in(collect($this->availableSlots)->pluck('starts_at')->all())],
            'timezone' => ['required', 'timezone'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'frequency' => ['required', Rule::in(['daily', 'weekly'])],
            'occurrences' => ['required', 'integer', 'between:2,'.RecurrenceData::MAX_OCCURRENCES],
            'educationSystemId' => ['required', 'string', Rule::in(collect($this->educationSystems)->pluck('id')->all())],
            'educationSystemLevelId' => ['required', 'string', Rule::in(collect($this->levels)->pluck('id')->all())],
            'academicSubjectId' => ['required', 'string', Rule::in(collect($this->academicSubjects)->pluck('id')->all())],
            'curriculumId' => ['required', 'string', Rule::in(collect($this->curricula)->pluck('id')->all())],
        ];
    }

    /** @return array<string, mixed> */
    private function rulesForSubmit(): array
    {
        $rules = collect($this->fieldRules())->only([
            'type', 'date', 'selectedSlotStartsAt', 'timezone', 'notes',
        ])->all();

        if ($this->academicFlowActive) {
            $rules += collect($this->fieldRules())->only(['educationSystemId', 'educationSystemLevelId', 'academicSubjectId', 'curriculumId'])->all();
        } else {
            $rules += collect($this->fieldRules())->only(['subject', 'grade'])->all();
        }

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
        // Until the student chooses a booking type, future steps are not yet
        // knowable (paid adds scheduling/payment while demo does not). Showing
        // only the current decision avoids flashing the obsolete legacy path.
        if ($this->type === null) {
            return ['mode'];
        }

        $phases = $this->academicFlowActive
            ? ['mode', 'level', 'academic_subject', 'curriculum']
            : ['mode', 'subject', 'grade'];

        if ($this->isPaidType()) {
            $phases[] = 'billing_mode';

            if ($this->recurring) {
                $phases[] = 'frequency';
            }
        }

        $phases[] = 'date';
        $phases[] = 'time';

        // Phase 4D — the funding step exists only when the student
        // actually has a qualifying package for this exact lesson.
        // Nobody is asked "how would you like to pay?" when there is
        // only one answer.
        if ($this->fundingOptions !== []) {
            $phases[] = 'funding';
        }

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

    // ── Stage presentation ─────────────────────────────────────────────────

    /** Moves the wizard forward from the current stage once it is complete. */
    public function continueStage(): void
    {
        $this->banner = '';

        match ($this->stageOf($this->currentPhase())) {
            'learning' => $this->learningComplete() ? $this->goToPhase($this->resumeSchedulePhase()) : null,
            'schedule' => $this->selectedSlotStartsAt !== null && ! ($this->recurring && $this->frequency === null)
                ? $this->goToPhase($this->fundingOptions === [] ? 'review' : 'funding')
                : null,
            default => null,
        };
    }

    /** Returns to an earlier stage with every selection intact. */
    public function editStage(string $stage): void
    {
        $order = array_keys(self::STAGE_PHASES);
        $current = array_search($this->stageOf($this->currentPhase()), $order, true);
        $target = array_search($stage, $order, true);

        if ($target === false || $current === false || $target > $current || $current === array_search('outcome', $order, true)) {
            return;
        }

        $this->banner = '';

        match ($stage) {
            'learning' => $this->goToPhase($this->furthestLearningPhase()),
            'schedule' => $this->goToPhase($this->resumeSchedulePhase()),
            'review' => $this->goToPhase($this->fundingOptions === [] ? 'review' : 'funding'),
            default => null,
        };
    }

    /** Re-opens one already-answered question inside the current stage. */
    public function editPhase(string $phase): void
    {
        if ($this->stageOf($phase) !== $this->stageOf($this->currentPhase()) || ! $this->phaseReached($phase)) {
            return;
        }

        $this->banner = '';
        $this->goToPhase($phase);
    }

    public function backStage(): void
    {
        $order = array_keys(self::STAGE_PHASES);
        $index = array_search($this->stageOf($this->currentPhase()), $order, true);

        if ($index === false || $index === 0) {
            return;
        }

        $this->editStage($order[$index - 1]);
    }

    private function currentPhase(): string
    {
        return $this->phases()[$this->step - 1] ?? 'mode';
    }

    private function stageOf(string $phase): string
    {
        foreach (self::STAGE_PHASES as $stage => $phases) {
            if (in_array($phase, $phases, true)) {
                return $stage;
            }
        }

        return 'learning';
    }

    private function phaseIndex(string $phase): int
    {
        $index = array_search($phase, $this->phases(), true);

        return $index === false ? PHP_INT_MAX : $index;
    }

    /** True once the student has passed (or is on) this phase, so its section may be re-opened. */
    private function phaseReached(string $phase): bool
    {
        return $this->phaseIndex($phase) < $this->step;
    }

    private function learningComplete(): bool
    {
        if ($this->type === null || $this->academicFlowBlocked) {
            return false;
        }

        return $this->academicFlowActive
            ? $this->curriculumId !== null
            : $this->subject !== null && $this->grade !== null;
    }

    private function furthestLearningPhase(): string
    {
        if (! $this->academicFlowActive) {
            return $this->subject === null ? ($this->type === null ? 'mode' : 'subject') : 'grade';
        }

        return match (true) {
            $this->curriculumId !== null => 'curriculum',
            $this->academicSubjectId !== null => 'curriculum',
            $this->educationSystemLevelId !== null => 'academic_subject',
            default => 'level',
        };
    }

    /** The schedule phase to show on entry: as far as the existing selections already carry the student. */
    private function resumeSchedulePhase(): string
    {
        if ($this->selectedSlotStartsAt !== null || ($this->date !== null && $this->availableSlots !== [])) {
            return 'time';
        }

        if ($this->recurring && $this->frequency === null) {
            return 'frequency';
        }

        if ($this->dates !== [] || ! $this->isPaidType()) {
            return 'date';
        }

        return $this->recurring ? 'frequency' : 'billing_mode';
    }

    /** @return list<array{key:string,label:string,number:int,state:string,summary:?string}> */
    private function stages(string $currentPhase): array
    {
        $order = array_keys(self::STAGE_PHASES);
        $currentIndex = (int) array_search($this->stageOf($currentPhase), $order, true);

        return collect($order)
            ->map(fn (string $stage, int $index): array => [
                'key' => $stage,
                'number' => $index + 1,
                'label' => $stage === 'outcome' ? $this->finalPhaseLabel() : self::STAGE_LABELS[$stage],
                'state' => match (true) {
                    $index < $currentIndex => 'complete',
                    $index === $currentIndex => 'current',
                    default => 'upcoming',
                },
                'summary' => $index < $currentIndex ? match ($stage) {
                    'learning' => $this->learningSummary(),
                    'schedule' => $this->scheduleSummary(),
                    default => null,
                } : null,
            ])
            ->all();
    }

    private function learningSummary(): ?string
    {
        $parts = array_filter([
            $this->subject !== null ? ucfirst(str_replace(['_', '-'], ' ', $this->subject)) : null,
            $this->academicFlowActive
                ? (collect($this->levels)->firstWhere('id', $this->educationSystemLevelId)['display_label'] ?? null)
                : ($this->grade !== null ? 'Grade '.$this->grade : null),
            $this->academicFlowActive ? $this->currentEducationSystem()?->name : null,
        ]);

        return $parts === [] ? null : implode(' • ', $parts);
    }

    private function scheduleSummary(): ?string
    {
        if ($this->selectedSlotStartsAt === null) {
            return null;
        }

        $startsAt = CarbonImmutable::parse($this->selectedSlotStartsAt)->timezone($this->timezone);
        $summary = $startsAt->format('D, j M').' • '.$startsAt->format('g:i A');

        return $this->recurring && $this->frequency !== null
            ? $summary.' • '.ucfirst((string) $this->frequency).' × '.$this->occurrences
            : $summary;
    }

    private function recurrenceSummary(): ?string
    {
        if (! $this->recurring || $this->frequency === null || $this->selectedSlotStartsAt === null) {
            return null;
        }

        $startsAt = CarbonImmutable::parse($this->selectedSlotStartsAt)->timezone($this->timezone);
        $cadence = $this->frequency === 'weekly' ? 'Every '.$startsAt->format('l') : 'Every day';

        return sprintf('%s at %s • Starting %s • %d sessions', $cadence, $startsAt->format('g:i A'), $startsAt->format('j F'), $this->occurrences);
    }

    private function timezoneLabel(): string
    {
        return sprintf('%s (GMT%s)', $this->timezone, CarbonImmutable::now($this->timezone)->format('P'));
    }

    /** @return list<array{label:string,slots:list<array<string, mixed>>}> */
    private function slotGroups(): array
    {
        $groups = ['Morning' => [], 'Afternoon' => [], 'Evening' => []];

        foreach ($this->availableSlots as $slot) {
            $startsAt = CarbonImmutable::parse($slot['starts_at'])->timezone($this->timezone);
            $group = match (true) {
                $startsAt->hour < 12 => 'Morning',
                $startsAt->hour < 17 => 'Afternoon',
                default => 'Evening',
            };

            $groups[$group][] = [
                ...$slot,
                'label' => $startsAt->format('g:i A'),
                'ends_label' => CarbonImmutable::parse($slot['ends_at'])->timezone($this->timezone)->format('g:i A'),
            ];
        }

        return collect($groups)
            ->filter()
            ->map(fn (array $slots, string $label): array => ['label' => $label, 'slots' => $slots])
            ->values()
            ->all();
    }

    private function finalPhaseLabel(): string
    {
        if (! $this->isPaidType()) {
            return 'Confirmed';
        }

        if ($this->result !== null && ! ($this->result['requires_payment'] ?? false)) {
            return 'Confirmed';
        }

        return 'Payment';
    }
}
