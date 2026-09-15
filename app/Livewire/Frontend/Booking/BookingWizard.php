<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Booking;

use App\Booking\Contracts\BookingCheckoutCompletionServiceInterface;
use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\DTOs\BookingCheckoutOutcome;
use App\Booking\DTOs\RecurrencePatternData;
use App\Booking\DTOs\RecurrenceRuleData;
use App\Booking\DTOs\TimeSlotData;
use App\Booking\Enums\BookingCheckoutState;
use App\Booking\Enums\RecurrenceEndCondition;
use App\Booking\Enums\RecurrenceFrequency;
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\InvalidPaymentWebhookException;
use App\Booking\Exceptions\NoEligibleTeacherException;
use App\Booking\Exceptions\SlotUnavailableException;
use App\Booking\Payments\RazorpayPaymentProvider;
use App\Booking\Services\BookingSeriesPrepaymentService;
use App\Booking\Services\BookingSeriesService;
use App\Booking\Services\BookingWizardService;
use App\Booking\Support\FakePaymentSimulator;
use App\Curriculum\DTOs\AcademicContextData;
use App\Models\BookingSeries;
use App\Models\Country;
use App\Models\EducationSystem;
use App\Models\Wallet;
use App\Payments\DTOs\PaymentCheckoutData;
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
use InvalidArgumentException;
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
        'schedule' => ['instructor', 'billing_mode', 'date', 'time'],
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

    public int $occurrences = 4;

    /**
     * The days of the week classes repeat on, as Carbon day-of-week
     * numbers in the STUDENT's timezone — the calendar the buttons are
     * drawn in. WizardBookingService translates them into the
     * instructor's calendar, which is what the schedule is anchored to.
     *
     * This is the ENTIRE cadence control. Seven days selected is a daily
     * schedule, which is why there is no Daily/Weekly switch and no
     * "repeat every N weeks": those were a second way of saying
     * something this list already says, and two ways of saying it meant
     * two things that could disagree.
     *
     * Existing series created with a real interval or a Daily frequency
     * keep both — the SERIES model still stores them and still generates
     * from them. Only this form stopped offering them; nothing is
     * rewritten.
     *
     * @var list<int>
     */
    public array $weekdays = [];

    /** 'after_count' | 'on_date' | 'never'. */
    public string $endCondition = 'after_count';

    /** `Y-m-d` in the student's timezone; only read when $endCondition is 'on_date'. */
    public ?string $endDate = null;

    /**
     * Dates the student removed while resolving conflicts, in the
     * SCHEDULE's timezone (the preview reports them that way, and the
     * server keys deviations by them). Survives moving between steps —
     * they are part of the schedule being built, not transient UI state.
     *
     * @var list<string>
     */
    public array $skippedDates = [];

    /**
     * Classes moved to another time on their own date, `Y-m-d => H:i:s`
     * in the schedule's timezone.
     *
     * @var array<string, string>
     */
    public array $movedOccurrences = [];

    /** The current preview page, as display rows. @var list<array<string, mixed>> */
    public array $schedulePreview = [];

    /** Totals and horizon copy for the preview. @var array<string, mixed> */
    public array $previewMeta = [];

    public int $previewPage = 1;

    /** The date currently being moved, if any (schedule-timezone `Y-m-d`). */
    public ?string $movingDate = null;

    /** Alternative times for $movingDate. @var list<array<string, mixed>> */
    public array $moveSlots = [];

    /**
     * The schedule's own calendar and instructor, as the server resolved
     * them. #[Locked] because both are server decisions the client must
     * not be able to rewrite — a writable instructor id here would let a
     * crafted update ask for somebody else's availability.
     */
    #[Locked]
    public ?string $seriesTimezone = null;

    #[Locked]
    public ?int $seriesInstructorId = null;

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
     * True between a verified Razorpay checkout callback and the moment
     * settlement is observed. The callback proves the student completed
     * checkout, not that money moved — that arrives from the signed
     * webhook (seconds) or the reconciliation sweep (minutes). While this
     * is set the payment screen polls checkPaymentStatus() and shows a
     * "confirming" state instead of a Pay button the student would
     * otherwise be tempted to press again.
     */
    public bool $awaitingPaymentConfirmation = false;

    /** When the confirming state began (ISO-8601), so the view can say "taking longer than usual". */
    public ?string $awaitingPaymentSince = null;

    /**
     * BookingCheckoutState value the payment screen renders — derived on
     * the server from the attempt ledger (see
     * BookingCheckoutCompletionService::currentState()), never inferred
     * from a browser event, so it tells "waiting for capture" apart from
     * "provider unreachable" and survives a reload.
     */
    public ?string $paymentConfirmationState = null;

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

    /**
     * The instructor the student chose on a paid booking (null = "any
     * available instructor"). Deliberately NOT #[Locked]: selectInstructor()
     * only accepts an id from the options the server offered, and
     * WizardBookingService re-checks eligibility at submit. A deep-linked
     * (locked) instructor takes precedence and skips the step.
     */
    public ?int $instructorId = null;

    /** Whether the instructor question has been answered ("any" is an answer). */
    public bool $instructorChosen = false;

    /**
     * Scalar instructor cards for the choose-your-instructor step, grouped:
     * previous (this subject, most recent first), favourites, others.
     *
     * @var array{previous: list<array<string, mixed>>, favourites: list<array<string, mixed>>, others: list<array<string, mixed>>}|array{}
     */
    public array $instructorOptions = [];

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

    /**
     * The active subjects the student chose on their profile. Display
     * order and badges on the subject step; the pre-selection rule in
     * applyLearningPrefill() (exactly one offered → chosen for them,
     * several → they must choose).
     *
     * @var list<string>
     */
    public array $preferredSubjectIds = [];

    private BookingWizardService $wizard;

    private BookingRepositoryInterface $bookings;

    private BookingPaymentServiceInterface $payments;

    private RazorpayPaymentProvider $razorpay;

    private BookingCheckoutCompletionServiceInterface $checkout;

    private ?EducationSystem $educationSystemMemo = null;

    public function boot(
        BookingWizardService $wizard,
        BookingRepositoryInterface $bookings,
        BookingPaymentServiceInterface $payments,
        RazorpayPaymentProvider $razorpay,
        BookingCheckoutCompletionServiceInterface $checkout,
    ): void {
        $this->wizard = $wizard;
        $this->bookings = $bookings;
        $this->payments = $payments;
        $this->razorpay = $razorpay;
        $this->checkout = $checkout;
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
        $this->weekdays = [];
        $this->grade = null;
        $this->subject = null;
        $this->resetAvailability();
        $this->resetInstructorChoice();
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
        $this->resetInstructorChoice();
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
        $this->resetInstructorChoice();

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
        $this->resetInstructorChoice();

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
        $this->resetInstructorChoice();

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
        $this->resetInstructorChoice();
        $this->validateSelection(['educationSystemId', 'educationSystemLevelId', 'academicSubjectId', 'curriculumId']);
        $this->refreshPricePreview();

        if ($this->isPaidType()) {
            $this->loadInstructorOptions();
        } else {
            $this->loadDates();
        }

        $this->goToPhase('curriculum');
    }

    /** Legacy (non-academic) flow only — the country-aware flow finalizes its selection in selectCurriculum() instead. */
    public function selectGrade(int $grade): void
    {
        $this->grade = $grade;
        $this->resetAvailability();
        $this->resetInstructorChoice();
        $this->validateSelection(['subject', 'grade']);
        $this->refreshPricePreview();

        if ($this->isPaidType()) {
            $this->loadInstructorOptions();
        } else {
            $this->loadDates();
        }

        $this->goToPhase('grade');
    }

    /**
     * The student's answer to "who would you like to learn with?": an id
     * from the offered options, or null for "any available instructor".
     * Everything downstream of the instructor (dates, times, price,
     * package funding, schedule preview) is recomputed.
     */
    public function selectInstructor(?int $instructorId): void
    {
        if ($instructorId !== null && ! in_array($instructorId, $this->offeredInstructorIds(), true)) {
            return;
        }

        $this->banner = '';

        if ($this->instructorChosen && $this->instructorId === $instructorId) {
            $this->goToPhase($this->billingModeChosenPhase());

            return;
        }

        $this->instructorId = $instructorId;
        $this->instructorChosen = true;
        $this->resetAvailability();
        $this->fundingOptions = [];
        $this->packageEntitlementId = null;
        $this->schedulePreview = [];
        $this->previewMeta = [];
        $this->refreshPricePreview();

        if ($this->billingModeAnswered()) {
            $this->loadDates();
        }

        $this->goToPhase($this->billingModeChosenPhase());
    }

    /** @return list<int> */
    private function offeredInstructorIds(): array
    {
        return collect($this->instructorOptions)
            ->flatten(1)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /** Where to go after the instructor question: straight to the calendar when "how often" was already answered. */
    private function billingModeChosenPhase(): string
    {
        return $this->billingModeAnswered() ? 'date' : 'billing_mode';
    }

    private function billingModeAnswered(): bool
    {
        return $this->recurring || $this->dates !== [] || $this->phaseReached('billing_mode');
    }

    /** The instructor every schedule-stage read is scoped to: the deep-linked lock wins, else the student's choice, else any. */
    private function effectiveInstructorId(): ?int
    {
        return $this->lockedInstructorId ?? $this->instructorId;
    }

    /** A learning-stage change invalidates the offered instructors and the answer. */
    private function resetInstructorChoice(): void
    {
        $this->instructorId = null;
        $this->instructorChosen = false;
        $this->instructorOptions = [];
    }

    private function loadInstructorOptions(): void
    {
        $this->instructorOptions = [];

        $user = Auth::user();

        if ($user === null || ! $this->isPaidType() || $this->lockedInstructorId !== null || ! $this->learningComplete()) {
            return;
        }

        $this->instructorOptions = $this->wizard->instructorOptions(
            (string) $this->type,
            (string) $this->subject,
            (int) $this->grade,
            $this->browsingAcademicContext(),
            $user,
        );
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
            $droppedPackage = $this->packageEntitlementId !== null;

            $this->packageEntitlementId = null;
            $this->fundingOptions = [];

            // Everything a repeating schedule needs now lives in this one
            // step, next to the calendar and the times — there is no
            // separate "set the pattern" detour to walk through and come
            // back from.
            $this->loadDates();

            // Set AFTER loadDates(), which clears the banner: this
            // message is the whole point of the branch and must survive.
            if ($droppedPackage) {
                $this->banner = 'Package lessons are booked one at a time, so your package has not been applied to this repeating schedule. Each class will be charged normally.';
            }

            $this->goToPhase('date');

            return;
        }

        $this->resetRecurrenceSettings();
        $this->loadDates();
        $this->goToPhase('date');
    }

    /**
     * Clears everything that only means something for a repeating
     * schedule. Called when the student switches back to a one-time
     * session, so a later switch to repeating starts from the defaults
     * rather than from half of a schedule they abandoned.
     */
    private function resetRecurrenceSettings(): void
    {
        $this->weekdays = [];
        $this->endCondition = 'after_count';
        $this->endDate = null;
        $this->skippedDates = [];
        $this->movedOccurrences = [];
        $this->schedulePreview = [];
        $this->previewMeta = [];
        $this->previewPage = 1;
        $this->seriesInstructorId = null;
        $this->seriesTimezone = null;
        $this->cancelMove();
    }

    /**
     * Turns one weekday on or off.
     *
     * At least one day must stay selected — a repeating schedule with no
     * days is not a schedule, and silently accepting it would produce an
     * empty preview with nothing to explain it.
     *
     * Changing the days can move the first class, so the anchor is
     * recomputed and the times reloaded for whatever the first class now
     * is; the chosen time of day is carried across when that slot still
     * exists (see reanchorSchedule()).
     */
    public function toggleWeekday(int $day): void
    {
        if ($day < 0 || $day > 6) {
            return;
        }

        $selected = in_array($day, $this->weekdays, true);

        if ($selected && count($this->weekdays) === 1) {
            $this->banner = 'Choose at least one day for your classes.';

            return;
        }

        $this->banner = '';

        $this->weekdays = $selected
            ? array_values(array_diff($this->weekdays, [$day]))
            : [...$this->weekdays, $day];

        sort($this->weekdays);

        $this->reanchorSchedule();
    }

    /**
     * How the schedule ends. Switching away from a condition clears the
     * value that belonged to it, so a stale end date can never survive
     * into an ongoing schedule.
     */
    public function setEndCondition(string $condition): void
    {
        if (RecurrenceEndCondition::tryFrom($condition) === null) {
            return;
        }

        // Refused server-side as well as hidden: an option that is not
        // offered must not be reachable by a crafted Livewire update.
        if ($condition === 'never' && ! app(BookingSeriesService::class)->futureGenerationEnabled()) {
            $this->banner = 'Open-ended schedules are not available just yet. Choose a number of classes or an end date instead.';

            return;
        }

        $this->endCondition = $condition;

        if ($condition !== 'on_date') {
            $this->endDate = null;
        }

        $this->refreshSchedulePreview();
    }

    public function setOccurrences(int $occurrences): void
    {
        $this->occurrences = max(1, $occurrences);
        $this->refreshSchedulePreview();
    }

    public function setEndDate(string $date): void
    {
        $this->endDate = $date !== '' ? $date : null;
        $this->refreshSchedulePreview();
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

    /**
     * For a one-time class this is the class date. For a repeating
     * schedule it is "starting from" — a boundary, not necessarily a
     * class day.
     *
     * The distinction matters: a student who wants Monday and Wednesday
     * classes "from 1 January" should not have to work out that 1
     * January is a Friday. They pick the boundary; firstClassDate()
     * resolves the first day on or after it that they actually chose,
     * and that is what the times are loaded for and what the summary
     * shows. An unselected weekday is never added to make the start date
     * work.
     */
    public function selectDate(string $date): void
    {
        $this->date = $date;
        $this->selectedSlotStartsAt = null;
        $this->validateSelection($this->recurring ? ['date'] : ['subject', 'grade', 'date']);
        $this->loadSlots();

        if ($this->recurring) {
            $this->previewPage = 1;
            $this->cancelMove();
            $this->loadSchedulePreview();
        }

        $this->goToPhase('time');
    }

    /**
     * The first date on or after "starting from" that falls on one of
     * the chosen weekdays, in the student's own timezone.
     *
     * Null until both a start date and at least one weekday exist —
     * there is no honest answer before then, and guessing one would mean
     * scheduling a day the student did not pick.
     */
    public function firstClassDate(): ?string
    {
        if (! $this->recurring || $this->date === null || $this->weekdays === []) {
            return null;
        }

        $date = CarbonImmutable::parse($this->date, $this->timezone)->startOfDay();

        // At most one week of candidates: some day of the week is always
        // selected, so a match is guaranteed inside seven days.
        for ($i = 0; $i < 7; $i++) {
            if (in_array((int) $date->dayOfWeek, $this->weekdays, true)) {
                return $date->toDateString();
            }

            $date = $date->addDay();
        }

        return null;
    }

    /**
     * Re-resolves the first class after a change to the days or the
     * start date, and reloads the times for it.
     *
     * The chosen time of day is carried over whenever the same clock
     * time is still offered on the new first class — changing a day
     * should not silently cost the student the time they already picked
     * — and is cleared honestly when it is not.
     */
    private function reanchorSchedule(): void
    {
        $wanted = $this->selectedSlotStartsAt !== null
            ? CarbonImmutable::parse($this->selectedSlotStartsAt)->setTimezone($this->timezone)->format('H:i')
            : null;

        $this->selectedSlotStartsAt = null;
        $this->loadSlots();

        if ($wanted !== null) {
            foreach ($this->availableSlots as $slot) {
                if (CarbonImmutable::parse($slot['starts_at'])->setTimezone($this->timezone)->format('H:i') === $wanted) {
                    $this->selectedSlotStartsAt = $slot['starts_at'];
                    break;
                }
            }
        }

        $this->previewPage = 1;
        $this->cancelMove();
        $this->loadSchedulePreview();
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

        // A repeating schedule is anchored to the first class, so the
        // preview only becomes meaningful once a concrete instant exists.
        // Choosing a different time re-checks every date against it,
        // rather than leaving a preview built from the previous choice on
        // screen.
        if ($this->recurring) {
            $this->previewPage = 1;
            $this->cancelMove();
            $this->loadSchedulePreview();
        }

        $this->goToPhase('time');
    }

    // ── Repeating schedule preview ─────────────────────────────────────────

    /**
     * The schedule the current settings produce, with every date inside
     * the confirmation horizon checked against the assigned instructor's
     * real availability and the student's own calendar.
     *
     * Entirely server-computed. The browser renders what comes back and
     * never works dates out for itself, so what the student is shown
     * cannot disagree with what confirmation will do.
     */
    public function loadSchedulePreview(): void
    {
        $this->schedulePreview = [];
        $this->previewMeta = [];

        if (! $this->recurring || $this->weekdays === [] || $this->selectedSlotStartsAt === null) {
            return;
        }

        // "Until a date" with no date yet is not an error — it is an
        // unfinished choice, and there is no schedule to describe until
        // it is finished. Asking for one anyway builds a rule that
        // cannot exist (RecurrenceRuleData refuses it, rightly) and the
        // student gets a crash for typing nothing.
        if (! $this->endConditionIsComplete()) {
            $this->previewMeta = ['awaiting' => $this->endConditionPrompt()];

            return;
        }

        try {
            $preview = $this->wizard->previewSeries(
                $this->submissionPayload(),
                $this->recurrencePattern(),
                $this->skippedDates,
                $this->previewPage,
                $this->movedOccurrences,
            );
        } catch (BookingException|NoEligibleTeacherException $exception) {
            // A preview that cannot be built is reported, never faked. The
            // student keeps every selection and can change the pattern.
            $this->previewMeta = ['error' => $exception instanceof BookingException
                ? $exception->getMessage()
                : 'We could not check these dates just now. Please try again.'];

            return;
        } catch (InvalidArgumentException) {
            // The guard above should have caught every incomplete
            // combination. If a new one ever appears, the student sees a
            // prompt rather than an error page.
            $this->previewMeta = ['awaiting' => 'Finish setting how long this schedule runs for.'];

            return;
        }

        $this->seriesTimezone = $preview->timezone;
        $this->seriesInstructorId = $preview->instructorId;

        $this->schedulePreview = array_map(
            fn ($occurrence): array => $occurrence->toDisplayArray($this->timezone),
            $preview->occurrences,
        );

        $this->previewMeta = [
            'total' => $preview->totalScheduled,
            // The release gate: this schedule owes classes we cannot
            // reserve yet, and this deployment cannot yet promise them.
            'blocked' => $preview->isBlockedByFutureGeneration(),
            'requires_future_generation' => $preview->requiresFutureGeneration,
            'ongoing' => $preview->totalScheduled === null,
            'conflicts' => $preview->conflictCount,
            'bookable_now' => $preview->bookableNowCount,
            'planned' => $preview->plannedCount,
            'horizon_days' => $preview->horizonDays,
            'last_date' => $preview->lastLocalDate,
            'has_more' => $preview->hasMore,
            'page' => $this->previewPage,
            'skipped' => count($this->skippedDates),
        ];
    }

    /** Rebuilds the preview after a settings change, but only once one exists. */
    private function refreshSchedulePreview(): void
    {
        if ($this->selectedSlotStartsAt === null) {
            return;
        }

        $this->previewPage = 1;
        $this->cancelMove();
        $this->loadSchedulePreview();
    }

    public function previewNextPage(): void
    {
        if (($this->previewMeta['has_more'] ?? false) === true) {
            $this->previewPage++;
            $this->loadSchedulePreview();
        }
    }

    public function previewPreviousPage(): void
    {
        if ($this->previewPage > 1) {
            $this->previewPage--;
            $this->loadSchedulePreview();
        }
    }

    /**
     * Drops one date from the schedule.
     *
     * Whether that costs a class depends on how the schedule ends, and
     * the preview says so: a schedule of N classes reaches one date
     * further so the student still gets N, while one that ends on a date
     * simply has one class fewer.
     */
    public function skipOccurrence(string $localDate): void
    {
        if (! in_array($localDate, $this->skippedDates, true)) {
            $this->skippedDates[] = $localDate;
            sort($this->skippedDates);
        }

        $this->cancelMove();
        $this->loadSchedulePreview();
    }

    public function restoreOccurrence(string $localDate): void
    {
        $this->skippedDates = array_values(array_diff($this->skippedDates, [$localDate]));
        $this->loadSchedulePreview();
    }

    /**
     * Offers the SAME instructor's other times on that date.
     *
     * Only that instructor's, and only that date's: a repeating schedule
     * is an arrangement with one person, so a class that has to move
     * moves within their calendar or not at all. Nothing here can
     * substitute somebody else.
     */
    public function startMovingOccurrence(string $localDate): void
    {
        $this->movingDate = $localDate;
        $this->moveSlots = [];

        if ($this->seriesInstructorId === null || $this->seriesTimezone === null) {
            return;
        }

        $slots = $this->wizard->availableSlots(
            (string) $this->type,
            (string) $this->subject,
            (int) $this->grade,
            CarbonImmutable::parse($localDate, $this->seriesTimezone)->startOfDay(),
            $this->seriesTimezone,
            $this->seriesInstructorId,
            $this->browsingAcademicContext(),
        );

        $this->moveSlots = $slots
            // The override is keyed by the schedule's own date, so a slot
            // that lands on the following day in that calendar is not a
            // move of THIS class and is not offered as one.
            ->filter(fn (TimeSlotData $slot): bool => $slot->startsAt->setTimezone($this->seriesTimezone)->toDateString() === $localDate)
            ->map(fn (TimeSlotData $slot): array => [
                'local_time' => $slot->startsAt->setTimezone($this->seriesTimezone)->format('H:i:s'),
                'label' => $slot->startsAt->setTimezone($this->timezone)->format('g:i A'),
                'ends_label' => $slot->endsAt->setTimezone($this->timezone)->format('g:i A'),
            ])
            ->values()
            ->all();
    }

    public function moveOccurrenceTo(string $localTime): void
    {
        if ($this->movingDate === null) {
            return;
        }

        if (! in_array($localTime, array_column($this->moveSlots, 'local_time'), true)) {
            return;
        }

        $this->movedOccurrences[$this->movingDate] = $localTime;
        $this->cancelMove();
        $this->loadSchedulePreview();
    }

    public function cancelMove(): void
    {
        $this->movingDate = null;
        $this->moveSlots = [];
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

        // A repeating schedule may not be confirmed while dates in it are
        // known not to work. The student has already been shown each one
        // and can move it, drop it, or change the pattern — confirming
        // over the top of them would mean quietly booking fewer classes
        // than the schedule says.
        if ($this->recurring && ($this->previewMeta['conflicts'] ?? 0) > 0) {
            $this->banner = 'Some dates in your schedule need attention before you can confirm. Move or remove them below, or change the repeat pattern.';
            $this->goToPhase('review');
            $this->announcePanel();

            return;
        }

        $payload = $this->submissionPayload();

        try {
            if ($this->recurring) {
                $result = $this->wizard->bookSeries(
                    $payload,
                    $this->recurrencePattern(),
                    $this->skippedDates,
                    $this->movedOccurrences,
                );
                $this->bookingId = $result->booked->first()?->id;
                $this->result = $this->wizard->recurringResult($result);
                $this->refreshSeriesPrepayment();
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
            if ($this->instructorId !== null && $this->lockedInstructorId === null
                && str_contains($exception->getMessage(), 'not available for the selected subject')) {
                // The instructor they chose changed status mid-session:
                // offer the list again rather than a dead end.
                $this->banner = 'The instructor you chose is no longer available for this lesson. Pick another instructor, or choose Any available instructor.';
                $this->resetInstructorChoice();
                $this->loadInstructorOptions();
                $this->goToPhase('instructor');
                $this->announcePanel();

                return;
            }

            $this->banner = $exception->getMessage();
            $this->announcePanel();
        }
    }

    /**
     * The submission payload, shared by the preview and the real
     * submission so the schedule a student is shown is built from
     * exactly the values that will be sent.
     *
     * @return array<string, mixed>
     */
    private function submissionPayload(): array
    {
        return [
            'type' => $this->type,
            'subject' => $this->subject,
            'grade' => $this->grade,
            'starts_at' => $this->selectedSlotStartsAt,
            'timezone' => $this->timezone,
            'notes' => filled($this->notes) ? $this->notes : null,
            'teacher_id' => $this->effectiveInstructorId(),
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
    }

    /**
     * The student's repeat choices as a pattern the server can anchor.
     *
     * Every value is bounded here as well as server-side; this is a
     * convenience for the UI, not the authority — WizardBookingService
     * and RecurrencePatternData validate the same things again on a
     * request the browser could have crafted by hand.
     */
    private function recurrencePattern(): RecurrencePatternData
    {
        $endCondition = RecurrenceEndCondition::tryFrom($this->endCondition) ?? RecurrenceEndCondition::AfterCount;

        // One cadence, expressed once: the chosen days, every week. Seven
        // days is a daily schedule; there is nothing else to say.
        return new RecurrencePatternData(
            frequency: RecurrenceFrequency::Weekly,
            interval: 1,
            weekdays: array_map(static fn (int $day): Weekday => Weekday::from($day), $this->selectedWeekdays()),
            endCondition: $endCondition,
            endDate: $endCondition === RecurrenceEndCondition::OnDate ? $this->endDate : null,
            occurrenceCount: $endCondition === RecurrenceEndCondition::AfterCount ? max(1, $this->occurrences) : null,
        );
    }

    /**
     * The chosen days, validated and ordered.
     *
     * Nothing is ever added here. The old form forced the start date's
     * own weekday into the set, because the start date WAS the first
     * class; now the start date is only a boundary and the days are the
     * student's alone.
     *
     * @return list<int>
     */
    private function selectedWeekdays(): array
    {
        $days = array_values(array_unique(array_filter(
            $this->weekdays,
            static fn (int $day): bool => $day >= 0 && $day <= 6,
        )));

        sort($days);

        return $days;
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
        // The whole schedule hung off the slot that has just gone, so the
        // preview it produced is no longer about anything. Cleared rather
        // than left on screen going stale.
        $this->schedulePreview = [];
        $this->previewMeta = [];
        $this->cancelMove();
        $this->loadSlots();
        $this->goToPhase('time');
        $this->banner = self::SLOT_TAKEN_MESSAGE;

        // Stays inside the schedule stage, so nothing would have scrolled
        // — but the student needs to see why their time vanished.
        $this->announcePanel();
    }

    /**
     * The one-checkout quote for a repeating schedule's reserved
     * classes. Display only — every amount is recomputed under a lock
     * when the money actually moves.
     *
     * @var array<string, mixed>
     */
    public array $seriesPrepayment = [];

    /** The student's standing permission for THIS schedule; never inferred. */
    public bool $autoSettleEnabled = false;

    /** Whether this deployment offers unattended settlement at all. */
    public bool $autoSettleAvailable = false;

    /**
     * Pays for EVERY reserved class of this schedule in one go.
     *
     * If the wallet already covers the bill nothing external happens at
     * all — the classes are settled and confirmed immediately. Otherwise
     * one checkout is opened for exactly the shortfall, and the classes
     * are settled when it lands (see
     * SettleSeriesPrepaymentOnWalletRechargeSucceeded), so closing the
     * tab on the way back cannot leave them unpaid.
     */
    public function payForAllClasses(): void
    {
        $this->paymentBanner = '';
        $this->paymentOrder = [];

        $series = $this->currentSeries();

        if ($series === null) {
            return;
        }

        if (auth()->user()?->profile?->country_id === null) {
            $this->paymentBanner = 'Please complete your profile (country) before paying for these classes.';

            return;
        }

        try {
            $prepayments = app(BookingSeriesPrepaymentService::class);
            $quote = $prepayments->quote($series, auth()->user());

            if ($quote->coveredByWallet()) {
                $result = $prepayments->settleFromWallet($series, auth()->user());

                $this->paymentBanner = $result->allPaid()
                    ? ''
                    : sprintf(
                        '%d of %d classes were paid. The rest could not be confirmed and the money for them is still in your balance — open My Bookings to sort them out.',
                        $result->paidCount(),
                        $result->paidCount() + count($result->failures),
                    );

                $this->refreshAfterSeriesPayment();

                return;
            }

            $this->openCheckout($prepayments->initiateTopUp($series, auth()->user()));
        } catch (BookingException $exception) {
            $this->paymentBanner = $exception->getMessage();
        }
    }

    /**
     * Records the student's consent to have this schedule's future
     * classes confirmed from their balance.
     *
     * Consent is recorded here and nowhere else — paying for a batch
     * once must never be read as agreeing to it happening again while
     * they are away.
     */
    public function toggleAutoSettle(): void
    {
        $series = $this->currentSeries();

        if ($series === null) {
            return;
        }

        $this->paymentBanner = '';

        try {
            $updated = app(BookingSeriesPrepaymentService::class)->setAutoSettle(
                $series,
                auth()->user(),
                ! $series->auto_settle_from_wallet,
            );

            $this->autoSettleEnabled = (bool) $updated->auto_settle_from_wallet;
        } catch (BookingException $exception) {
            $this->paymentBanner = $exception->getMessage();
        }
    }

    private function currentSeries(): ?BookingSeries
    {
        $id = $this->result['series_id'] ?? null;

        return $id === null ? null : BookingSeries::query()->find($id);
    }

    /**
     * Re-reads what the student now owes, and each class's own state, so
     * the confirmation screen shows what actually happened rather than
     * what it showed before the money moved.
     */
    private function refreshAfterSeriesPayment(): void
    {
        $this->refreshSeriesPrepayment();
        $this->refreshWalletOption();

        if (($this->result['bookings'] ?? []) === []) {
            return;
        }

        // One query for the whole list rather than one per row.
        $bookings = $this->bookings->findManyForResult(
            array_column($this->result['bookings'], 'id'),
        )->keyBy('id');

        $this->result['bookings'] = array_map(
            fn (array $row): array => isset($bookings[$row['id']])
                ? $this->wizard->result($bookings[$row['id']])
                : $row,
            $this->result['bookings'],
        );

        $this->result['requires_payment'] = collect($this->result['bookings'])
            ->contains(static fn (array $row): bool => (bool) ($row['requires_payment'] ?? false));
    }

    /**
     * Snapshot of what is still owed across the schedule. Never
     * authoritative — the service recomputes everything at settlement.
     */
    private function refreshSeriesPrepayment(): void
    {
        $this->seriesPrepayment = [];

        $series = $this->currentSeries();

        if ($series === null || ! app(FeatureSettings::class)->wallet_enabled) {
            return;
        }

        $prepayments = app(BookingSeriesPrepaymentService::class);
        $this->autoSettleAvailable = $prepayments->autoSettleAvailable();
        $this->autoSettleEnabled = (bool) $series->auto_settle_from_wallet;

        $quote = $prepayments->quote($series, auth()->user());

        if (! $quote->isPayable()) {
            $this->seriesPrepayment = $quote->blockedReason === null
                ? []
                : ['blocked' => $quote->blockedReason];

            return;
        }

        $minorUnits = MoneyFormatter::minorUnitsFor((string) $quote->currencyCode);

        $this->seriesPrepayment = [
            'count' => $quote->count(),
            'total_formatted' => MoneyFormatter::format($quote->totalMinor, (string) $quote->currencyCode, $minorUnits),
            'covered_by_wallet' => $quote->coveredByWallet(),
            'shortfall_formatted' => MoneyFormatter::format($quote->shortfallMinor, (string) $quote->currencyCode, $minorUnits),
            'balance_formatted' => MoneyFormatter::format($quote->walletBalanceMinor, (string) $quote->currencyCode, $minorUnits),
            'planned_count' => $quote->plannedCount,
        ];
    }

    /** Hands a gateway-neutral checkout to the browser. */
    private function openCheckout(PaymentCheckoutData $checkout): void
    {
        $payload = $checkout->checkoutPayload;

        if ($checkout->provider === 'razorpay') {
            $this->paymentOrder = $payload;
            $this->dispatch(
                'razorpay-checkout-ready',
                orderId: $payload['order_id'],
                keyId: $payload['key_id'],
                amountMinor: $checkout->amountMinor,
                currency: $checkout->currencyCode,
                name: auth()->user()->name,
                email: auth()->user()->email,
            );

            return;
        }

        if ($checkout->provider === 'stripe') {
            // Secrets travel only in the transient dispatch payload,
            // never on a public Livewire property — same rule as
            // initiatePayment().
            $this->paymentOrder = ['provider' => 'stripe'];
            $this->dispatch(
                'stripe-checkout-ready',
                clientSecret: $payload['client_secret'],
                publishableKey: $payload['publishable_key'],
            );

            return;
        }

        $this->paymentOrder = $payload;
    }

    public function initiatePayment(): void
    {
        $this->paymentBanner = '';
        $this->paymentOrder = [];
        $this->awaitingPaymentConfirmation = false;
        $this->awaitingPaymentSince = null;

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

            // A verified checkout may already be in flight for this booking
            // (the student reloaded, or came back from another tab). Money
            // may have moved: never open a second checkout on top of it.
            $current = $this->checkout->currentState($booking);

            if ($current->confirmationInProgress()) {
                $this->applyCheckoutOutcome($current);

                return;
            }

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

            // Verify the callback, confirm the order with Razorpay and
            // settle through the one settlement path — see
            // BookingCheckoutCompletionService. A captured payment is
            // confirmed before this method returns; an authorized-but-
            // uncaptured one (or an unreachable provider) leaves the
            // booking payable and the confirming state below polls.
            $this->applyCheckoutOutcome(
                $this->checkout->completeRazorpayCheckout($booking, $orderId, $paymentId, $signature),
            );
        } catch (InvalidPaymentWebhookException|BookingException $exception) {
            $this->awaitingPaymentConfirmation = false;
            $this->awaitingPaymentSince = null;
            $this->paymentConfirmationState = null;
            $this->paymentBanner = $exception->getMessage();
        }
    }

    /**
     * Renders a checkout outcome: the fresh booking result plus the
     * confirming state and its message. The "since" stamp is kept across
     * polls so the view can escalate its wording after a while.
     */
    private function applyCheckoutOutcome(BookingCheckoutOutcome $outcome): void
    {
        $this->result = $this->wizard->result($outcome->booking);
        $this->paymentConfirmationState = $outcome->state->value;

        $inProgress = $outcome->confirmationInProgress();

        if ($inProgress && ! $this->awaitingPaymentConfirmation) {
            $this->awaitingPaymentSince = now()->toIso8601String();
        }

        if (! $inProgress) {
            $this->awaitingPaymentSince = null;
        }

        $this->awaitingPaymentConfirmation = $inProgress;

        if ($outcome->state === BookingCheckoutState::Failed) {
            $this->paymentBanner = 'Payment failed. Please try again.';
        } elseif ($outcome->state === BookingCheckoutState::Confirmed) {
            $this->paymentBanner = '';
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

        $booking = $this->bookings->findOrFail($this->bookingId);

        // While confirming, each poll re-reads locally and re-asks the
        // provider at most every PROVIDER_RECHECK_SECONDS. Otherwise the
        // state is still derived on the server, so a checkout verified in
        // another tab is picked up here too.
        $outcome = $this->awaitingPaymentConfirmation
            ? $this->checkout->refreshPendingPayment($booking)
            : $this->checkout->currentState($booking);

        $this->applyCheckoutOutcome($outcome);

        if ($outcome->booking->payment_status->value === 'failed') {
            $this->paymentBanner = 'Payment failed. Please try again.';
        }
    }

    public function restart(): void
    {
        $this->reset([
            'step',
            'dates',
            'availableSlots',
            'instructorId',
            'instructorChosen',
            'instructorOptions',
            'type',
            'subject',
            'grade',
            'recurring',
            'occurrences',
            'weekdays',
            'endCondition',
            'endDate',
            'skippedDates',
            'movedOccurrences',
            'schedulePreview',
            'previewMeta',
            'previewPage',
            'movingDate',
            'moveSlots',
            'seriesInstructorId',
            'seriesTimezone',
            'date',
            'selectedSlotStartsAt',
            'notes',
            'banner',
            'result',
            'bookingId',
            'paymentOrder',
            'paymentBanner',
            'walletOption',
            'seriesPrepayment',
            'autoSettleEnabled',
            'autoSettleAvailable',
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
        $this->preferredSubjectIds = [];

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
            'selectedInstructor' => $this->instructorId !== null
                ? collect($this->instructorOptions)->flatten(1)->firstWhere('id', $this->instructorId)
                : null,
            'instructorName' => $this->lockedInstructorName
                ?? (collect($this->instructorOptions)->flatten(1)->firstWhere('id', $this->instructorId)['name'] ?? null),
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
            'recurrencePatternLabel' => $this->recurrencePatternLabel(),
            'horizonExplainer' => $this->horizonExplainer(),
            'skipPolicyExplainer' => $this->skipPolicyExplainer(),
            'selectedWeekdays' => $this->selectedWeekdays(),
            'firstClassDate' => $this->firstClassDate(),
            'perWeekLabel' => $this->perWeekLabel(),
            'classCountLabel' => $this->classCountLabel(),
            'cadenceLabel' => $this->recurring && $this->weekdays !== [] ? $this->cadenceLabel() : null,
            'scheduleComplete' => $this->scheduleComplete(),
            'scheduleHint' => $this->scheduleHint(),
            // Server-resolved, not a Blade guess: the same flag the
            // service enforces at creation decides whether the option is
            // offered at all.
            'ongoingAvailable' => app(BookingSeriesService::class)->futureGenerationEnabled(),
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
        $this->resetInstructorChoice();
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
        $this->preferredSubjectIds = [];

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

        $prefill = $this->wizard->learningPrefill($user);
        $this->preferredSubjectIds = $prefill['preferred_subject_ids'];
        $this->applyLearningPrefill($prefill);
    }

    /**
     * Pre-selects what the student chose on their profile and last time,
     * as far as those choices are still offered. Each id goes through the
     * same select*() method a click would, so validation and narrowing (a
     * locked instructor, a level with no grade, an archived curriculum)
     * apply unchanged, and the chain simply stops at the first id that is
     * no longer available.
     *
     * The subject follows the student's own preferred subjects first:
     * exactly one of them offered → it is chosen for them; several →
     * nothing is chosen and they pick on the subject step (never a
     * silent guess from their last booking); none → the last booking's
     * subject, as before. A fully pre-filled selection lands the student
     * on the schedule directly; anything partial leaves them on the
     * learning details with the rest still to choose.
     *
     * @param  array{education_system_id:?string,education_system_level_id:?string,subject_id:?string,curriculum_id:?string,academic_level_id:?string,preferred_subject_ids:list<string>}  $prefill
     */
    private function applyLearningPrefill(array $prefill): void
    {
        $levelId = $this->prefillLevelId($prefill);

        if ($levelId === null) {
            return;
        }

        $this->selectLevel($levelId);

        if ($this->educationSystemLevelId !== $levelId) {
            return;
        }

        $offeredPreferred = array_values(array_intersect(
            $prefill['preferred_subject_ids'],
            array_column($this->academicSubjects, 'id'),
        ));

        if (count($offeredPreferred) > 1) {
            // Their choice to make — stay on the subject step.
            $this->prefilledLearning = true;

            return;
        }

        $subjectId = $offeredPreferred[0] ?? $prefill['subject_id'];

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
            $this->effectiveInstructorId(),
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
                ->availableDates($this->type, $this->subject, (int) $this->grade, $from, $to, $this->timezone, $this->effectiveInstructorId(), $this->browsingAcademicContext())
                ->all();
        } catch (BookingException $exception) {
            $this->dates = [];
            $this->banner = $exception->getMessage();
        }
    }

    private function loadSlots(): void
    {
        $this->banner = '';

        // A repeating schedule shares one time across every class, so the
        // times offered are the FIRST CLASS's — not the start
        // boundary's, which may not be a class day at all.
        $slotDate = $this->recurring ? $this->firstClassDate() : $this->date;

        if (! $this->type || ! $this->subject || ! $this->grade || ! $slotDate) {
            $this->availableSlots = [];

            return;
        }

        try {
            $this->availableSlots = $this->wizard
                ->availableSlots($this->type, $this->subject, (int) $this->grade, CarbonImmutable::parse($slotDate, $this->timezone), $this->timezone, $this->effectiveInstructorId(), $this->browsingAcademicContext())
                ->all();
        } catch (BookingException $exception) {
            $this->availableSlots = [];
            $this->banner = $exception->getMessage();
        }
    }

    /**
     * Loads every package that could fund the currently-selected lesson.
     *
     * Requires a known instructor (deep-linked or chosen on the
     * instructor step): a package entitlement belongs to one specific
     * instructor, so "which of my packages apply" is unanswerable while
     * the assignment engine may still pick anyone. An "any available"
     * booking therefore simply offers no packages and proceeds as an
     * ordinary paid booking — a deliberate, fail-closed limit rather
     * than a guess at who will be assigned.
     *
     * Never preselects: `$packageEntitlementId` stays null so "pay
     * normally" remains the default until the student chooses (§33).
     */
    private function loadFundingOptions(): void
    {
        $this->fundingOptions = [];
        $this->packageEntitlementId = null;

        $user = Auth::user();

        if ($user === null || $this->effectiveInstructorId() === null || $this->selectedSlotStartsAt === null) {
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
            (int) $this->effectiveInstructorId(),
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
            // No product cap on the number of classes. The only bound is
            // the enumeration guard the scheduler already needs to walk a
            // calendar safely — a processing limit, not a limit on what a
            // student may ask for.
            'occurrences' => ['required_if:endCondition,after_count', 'integer', 'min:1', 'max:'.RecurrenceRuleData::MAX_ENUMERATED_CANDIDATES],
            'endCondition' => ['required', Rule::enum(RecurrenceEndCondition::class)],
            'endDate' => ['required_if:endCondition,on_date', 'nullable', 'date_format:Y-m-d', 'after_or_equal:'.now($this->timezone)->toDateString()],
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:0,6'],
            // "Starting from" is a boundary, not a class date, so it is
            // not checked against the available-dates list the way a
            // one-time class's date is — firstClassDate() resolves the
            // real class day and the preview checks THAT.
            'startDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now($this->timezone)->toDateString()],
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
            $rules += collect($this->fieldRules())
                ->only(['occurrences', 'endCondition', 'endDate', 'weekdays', 'weekdays.*'])
                ->all();
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
     * a billing-mode choice. A repeating schedule adds no step of its
     * own — its settings live inside the schedule step, beside the
     * calendar and the times.
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
            // Paid lessons ask who to learn with — unless a profile
            // deep-link already locked the instructor. Demos stay
            // auto-assigned.
            if ($this->lockedInstructorId === null) {
                $phases[] = 'instructor';
            }

            $phases[] = 'billing_mode';
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

    /**
     * The one place the wizard's position changes — every Continue, Back,
     * Edit and auto-advancing selection funnels through here, which is why
     * the step-changed event belongs here rather than at each call site.
     *
     * The event exists because a step's height varies wildly (a slot grid
     * is tall, a confirmation is short): without it the browser keeps the
     * old scroll offset and the student is left staring at the page footer
     * with the new step above the fold. The blade listens and brings the
     * step card into view.
     */
    /**
     * Moves to a phase, and scrolls the panel into view ONLY when that
     * changes the stage.
     *
     * A stage change replaces what is on screen, so bringing the panel
     * into view is a courtesy. Moving between phases INSIDE a stage does
     * not: the schedule step is one long panel — days, calendar, times,
     * how long, then the schedule itself — and every pick within it used
     * to yank the student back to the top of the card, away from the
     * control they had just used and the one they were reaching for
     * next. The same is true of the learning step, where answering a
     * question reveals the next one directly below it, already in view.
     *
     * Where a message at the top of the panel genuinely needs reading,
     * the caller asks for the scroll explicitly (announcePanel()).
     */
    private function goToPhase(string $phase): void
    {
        $previousStage = $this->stageOf($this->currentPhase());

        $index = array_search($phase, $this->phases(), true);
        $this->step = $index === false ? 1 : $index + 1;

        if ($this->stageOf($phase) !== $previousStage) {
            $this->announcePanel();
        }
    }

    /**
     * Brings the panel into view because something at the top of it —
     * an error, a changed situation — has to be read.
     */
    private function announcePanel(): void
    {
        $this->dispatch('booking-step-changed');
    }

    private function monthDate(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->month.'-01', $this->timezone)->startOfMonth();
    }

    /** @return list<array<string, mixed>> */
    /**
     * The month grid.
     *
     * The two modes ask the calendar different questions, so it answers
     * different things:
     *
     *  - One-time: "which days can I have a class?" — selectable means
     *    the instructor actually has slots that day.
     *  - Repeating: "when should this start?" — the start is a boundary,
     *    and it may deliberately land on a day that is not a class day
     *    at all, so any day inside the bookable window is selectable.
     *    Days that match the chosen weekdays are marked, and the one
     *    that will actually be the first class is marked distinctly.
     *
     * @return list<array<string, mixed>|null>
     */
    private function calendar(): array
    {
        $month = $this->monthDate();
        $days = [];

        // Monday-first, matching both the grid header and the Mon–Sun
        // class-day buttons. Carbon numbers Sunday as 0, so the offset is
        // shifted rather than used directly.
        for ($i = 0, $lead = ((int) $month->dayOfWeek + 6) % 7; $i < $lead; $i++) {
            $days[] = null;
        }

        $today = CarbonImmutable::now($this->timezone)->startOfDay();
        $lastBookable = $today->addDays(max(1, app(BookingSettings::class)->maximum_advance_booking_days));
        $weekdays = $this->selectedWeekdays();
        $firstClass = $this->firstClassDate();

        for ($day = 1; $day <= $month->daysInMonth; $day++) {
            $date = $month->setDay($day);
            $iso = $date->toDateString();

            $days[] = [
                'day' => $day,
                'iso' => $iso,
                'label' => $date->format('l, F j'),
                'available' => $this->recurring
                    ? ($date->greaterThanOrEqualTo($today) && $date->lessThanOrEqualTo($lastBookable))
                    : in_array($iso, $this->dates, true),
                'is_class_day' => $this->recurring && in_array((int) $date->dayOfWeek, $weekdays, true),
                'is_first_class' => $firstClass === $iso,
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
            'schedule' => $this->scheduleComplete()
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

        if ($this->dates !== [] || $this->recurring || ! $this->isPaidType()) {
            return 'date';
        }

        if (! $this->instructorChosen && $this->lockedInstructorId === null) {
            return 'instructor';
        }

        return 'billing_mode';
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

        if (! $this->recurring || $this->weekdays === []) {
            return $summary;
        }

        return $summary.' • '.$this->weekdayNames().' • '.$this->classCountLabel();
    }

    /**
     * The schedule in one sentence, in the student's own words and
     * their own timezone.
     *
     * Assembled from the same values the pattern is built from, so the
     * sentence and the schedule cannot say different things. An ongoing
     * schedule is described as ongoing and never given a class count.
     */
    private function recurrenceSummary(): ?string
    {
        if (! $this->recurring || $this->weekdays === [] || $this->selectedSlotStartsAt === null) {
            return null;
        }

        $startsAt = CarbonImmutable::parse($this->selectedSlotStartsAt)->timezone($this->timezone);

        return implode(' • ', array_filter([
            $this->cadenceLabel(),
            'at '.$startsAt->format('g:i A'),
            // The FIRST CLASS, not the "starting from" boundary the
            // student picked — those can be different days and only one
            // of them is a class.
            'first class '.$startsAt->format('D, j M Y'),
            $this->endingLabel(),
        ]));
    }

    /**
     * The pattern without a start date — what the collapsed "Repeat" row
     * shows once the schedule stage is behind the student.
     */
    private function recurrencePatternLabel(): ?string
    {
        if (! $this->recurring || $this->weekdays === []) {
            return null;
        }

        return implode(' · ', array_filter([$this->cadenceLabel(), $this->endingLabel()]));
    }

    /**
     * The cadence in one phrase. All seven days is said as "Every day",
     * because that is what the student chose it to mean.
     */
    private function cadenceLabel(): string
    {
        $days = $this->selectedWeekdays();

        if ($days === []) {
            return 'Weekly';
        }

        return count($days) === 7 ? 'Every day' : 'Every '.$this->weekdayNames();
    }

    /** "3 classes per week" — the density, stated plainly. */
    private function perWeekLabel(): ?string
    {
        $count = count($this->selectedWeekdays());

        return $count === 0 ? null : sprintf('%d %s per week', $count, $count === 1 ? 'class' : 'classes');
    }

    /** How many classes the schedule holds, as far as it is knowable. */
    private function classCountLabel(): string
    {
        if ($this->endCondition === 'never') {
            return 'ongoing';
        }

        $total = $this->previewMeta['total'] ?? null;

        if ($total !== null) {
            return $total.' '.($total === 1 ? 'class' : 'classes');
        }

        return $this->endCondition === 'on_date'
            ? 'until '.($this->endDate ?? 'a date')
            : $this->occurrences.' '.($this->occurrences === 1 ? 'class' : 'classes');
    }

    private function endingLabel(): ?string
    {
        return match ($this->endCondition) {
            'on_date' => $this->endDate !== null
                ? 'until '.CarbonImmutable::parse($this->endDate)->format('j M Y')
                : null,
            'never' => 'with no end date — it continues until you cancel it',
            default => sprintf('%d %s', max(1, $this->occurrences), $this->occurrences === 1 ? 'class' : 'classes'),
        };
    }

    /**
     * The selected weekdays as a readable list, in the student's own
     * calendar. Empty until at least one day is chosen.
     */
    private function weekdayNames(): string
    {
        $days = array_map(
            static fn (int $day): string => Weekday::from($day)->label(),
            $this->selectedWeekdays(),
        );

        if ($days === []) {
            return '';
        }

        if (count($days) === 1) {
            return $days[0];
        }

        $last = array_pop($days);

        return implode(', ', $days).' and '.$last;
    }

    /**
     * Whether the schedule step has everything it needs.
     *
     * For a repeating schedule that includes having no unresolved
     * conflicts: the student has been shown each problem date and can
     * move it, drop it, or change the days, and moving on with them
     * unresolved would mean confirming fewer classes than the schedule
     * claims. submit() refuses the same thing independently, so this is
     * the courtesy, not the guarantee.
     */
    private function scheduleComplete(): bool
    {
        if ($this->selectedSlotStartsAt === null) {
            return false;
        }

        if (! $this->recurring) {
            return true;
        }

        if (($this->previewMeta['blocked'] ?? false) === true) {
            return false;
        }

        if (! $this->endConditionIsComplete()) {
            return false;
        }

        return $this->weekdays !== [] && (int) ($this->previewMeta['conflicts'] ?? 0) === 0;
    }

    /**
     * Whether the chosen end condition has the value it needs.
     *
     * Kept separate from "is the schedule valid": an end condition
     * mid-edit is normal, not a validation failure, and must not be
     * reported as one.
     */
    private function endConditionIsComplete(): bool
    {
        return match ($this->endCondition) {
            'on_date' => filled($this->endDate),
            'after_count' => $this->occurrences >= 1,
            default => true,
        };
    }

    private function endConditionPrompt(): string
    {
        return $this->endCondition === 'on_date'
            ? 'Choose the last date for your classes to see your schedule.'
            : 'Choose how many classes to see your schedule.';
    }

    /** Why the schedule step is not finished yet, in the student's words. */
    private function scheduleHint(): string
    {
        if ($this->isPaidType() && $this->lockedInstructorId === null && ! $this->instructorChosen) {
            return 'Choose an instructor, or let us match you';
        }

        if ($this->recurring && $this->weekdays === []) {
            return 'Choose the days your classes repeat on';
        }

        if ($this->selectedSlotStartsAt === null) {
            return $this->recurring ? 'Pick a start date and a class time' : 'Pick a date and time to continue';
        }

        if ($this->recurring && ! $this->endConditionIsComplete()) {
            return $this->endCondition === 'on_date'
                ? 'Choose the last date for your classes'
                : 'Choose how many classes';
        }

        if ($this->recurring && ($this->previewMeta['blocked'] ?? false) === true) {
            return 'Shorten the schedule to continue';
        }

        if ($this->recurring && (int) ($this->previewMeta['conflicts'] ?? 0) > 0) {
            return 'Sort out the dates that need attention below';
        }

        return 'Pick a date and time to continue';
    }

    /**
     * Explains the confirmation horizon in the student's own terms:
     * what is reserved, what is merely scheduled, and that nothing is
     * being charged for the part that is not reserved yet.
     */
    private function horizonExplainer(): ?string
    {
        if (! $this->recurring || $this->previewMeta === [] || isset($this->previewMeta['error'])) {
            return null;
        }

        $planned = (int) ($this->previewMeta['planned'] ?? 0);
        $days = (int) ($this->previewMeta['horizon_days'] ?? 0);

        if ($this->previewMeta['ongoing'] ?? false) {
            return sprintf(
                'This schedule has no end date. We book and hold your classes about %d days ahead at a time, and add the next ones automatically as those dates come around. You are only ever charged for classes that have been booked.',
                $days,
            );
        }

        if ($planned < 1) {
            return 'Every class in this schedule is reserved as soon as you confirm.';
        }

        return sprintf(
            'We book your classes about %d days ahead. The other %d %s in this schedule %s reserved automatically as %s dates come closer — you are only charged for classes that have been booked.',
            $days,
            $planned,
            $planned === 1 ? 'class' : 'classes',
            $planned === 1 ? 'is' : 'are',
            $planned === 1 ? 'its' : 'their',
        );
    }

    /**
     * How a removed date affects the schedule — different by design, and
     * stated before the student confirms rather than discovered after.
     */
    private function skipPolicyExplainer(): ?string
    {
        if (! $this->recurring) {
            return null;
        }

        return match ($this->endCondition) {
            'on_date' => 'Classes you remove are not replaced: the schedule still ends on the date you chose, with one class fewer.',
            'never' => 'Classes you remove are simply not booked; the schedule carries on as normal afterwards.',
            default => 'Classes you remove do not count towards your total: the schedule runs one date further so you still get the number of classes you asked for.',
        };
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
