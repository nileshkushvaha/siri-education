<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\AvailabilityRepositoryInterface;
use App\Booking\Contracts\AvailabilityServiceInterface;
use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\Contracts\BookingTypeRepositoryInterface;
use App\Booking\Contracts\TeacherAssignmentServiceInterface;
use App\Booking\Contracts\TeacherCandidateRepositoryInterface;
use App\Booking\Contracts\WizardBookingServiceInterface;
use App\Booking\DTOs\AssignmentCriteriaData;
use App\Booking\DTOs\AvailabilityQueryData;
use App\Booking\DTOs\BookingAcademicContextData;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\DTOs\CreateBookingSeriesData;
use App\Booking\DTOs\RecurrenceData;
use App\Booking\DTOs\RecurrencePatternData;
use App\Booking\DTOs\RecurrenceRuleData;
use App\Booking\DTOs\RecurringBookingResult;
use App\Booking\DTOs\SeriesSchedulePreviewData;
use App\Booking\DTOs\TimeSlotData;
use App\Booking\DTOs\WizardBookingData;
use App\Booking\Enums\RecurrenceEndCondition;
use App\Booking\Enums\RecurrenceFrequency;
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\BookingException;
use App\Booking\Support\AcademicFlowCopy;
use App\Booking\Types\FreeDemoType;
use App\Contracts\StudentFinancialVerificationGate;
use App\Country\Enums\CountryFeature;
use App\Curriculum\DTOs\AcademicContextData;
use App\Curriculum\Services\InstructorAcademicEligibilityResolver;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\StudentPackageEntitlement;
use App\Models\User;
use App\Package\Services\PackageBookingEntitlementResolver;
use App\Services\Instructor\InstructorService;
use App\Services\Student\StudentFavoriteInstructorService;
use App\Services\Student\StudentProfileCompletenessService;
use App\Support\Timezone\LocalWallClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Authenticated-student wizard booking flow — every caller is logged
 * in. The auto-assignment capability (pick any eligible teacher, or
 * lock a specific one) is what distinguishes this from
 * StudentBookingServiceInterface, which always requires an explicit
 * teacher choice.
 *
 * Every wizard booking resolves its country-aware academic context
 * BEFORE candidate
 * selection — so an automatically-assigned teacher is drawn from an
 * already-narrowed eligible SET — and again immediately before
 * CreateBookingData is built, so the persisted snapshot always reflects
 * the currently-Published CurriculumVersion and current instructor
 * eligibility, never a value cached earlier in the request/session
 * (§27/§28).
 *
 * Paid booking adds explicit package funding. Two properties of that
 * extension matter:
 *
 *  - Academic selection is mandatory in the frontend wizard, while owning
 *    a compatible package never forces the student to spend it (§31).
 *  - Funding is EXPLICIT. `packageEntitlementId` is only ever what the
 *    student deliberately chose; this service never searches for a
 *    package that happens to match. A chosen entitlement is
 *    re-validated server-side (ownership, instructor, academic
 *    identity, capacity, expiry-vs-lesson-end) before it can reach a
 *    Booking, and the resulting booking's academic snapshot is taken
 *    from the PACKAGE's frozen context rather than a fresh resolve, so
 *    a newly published CurriculumVersion can never retroactively
 *    rewrite what a purchased package bought (§38).
 *
 * Recurring bookings remain outside the package path entirely.
 */
final class WizardBookingService implements WizardBookingServiceInterface
{
    /** Instructor cards offered on the choose-your-instructor step, all groups together. */
    private const int INSTRUCTOR_OPTION_LIMIT = 12;

    public function __construct(
        private readonly BookingServiceInterface $bookings,
        private readonly BookingTypeRepositoryInterface $types,
        private readonly TeacherCandidateRepositoryInterface $candidates,
        private readonly TeacherAssignmentServiceInterface $assigner,
        private readonly AvailabilityServiceInterface $availability,
        private readonly StudentFinancialVerificationGate $financialVerification,
        private readonly DemoAvailabilityResolver $demoAvailability,
        private readonly DemoAcademicContextResolver $demoAcademicContext,
        private readonly InstructorAcademicEligibilityResolver $instructorEligibility,
        private readonly BookingAcademicContextResolver $academicContextResolver,
        private readonly PackageBookingEntitlementResolver $packageEntitlements,
        private readonly AvailabilityRepositoryInterface $availabilityRules,
        private readonly StudentProfileCompletenessService $profileCompleteness,
        private readonly BookingSeriesService $series,
        private readonly BookingRepositoryInterface $bookingRecords,
        private readonly StudentFavoriteInstructorService $favorites,
        private readonly InstructorService $instructors,
    ) {}

    public function availableDates(
        string $typeKey,
        string $subject,
        int $grade,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $timezone = 'UTC',
        ?int $teacherId = null,
        ?AcademicContextData $academicContext = null,
    ): Collection {
        $type = $this->types->requireActiveByKey($typeKey);
        $totalDays = (int) $from->startOfDay()->diffInDays($to->endOfDay()) + 1;

        // Stream per teacher and keep only the date strings — never the
        // slot objects — and stop as soon as every day is covered.
        $found = [];

        foreach ($this->eligibleTeachers($typeKey, $subject, $grade, $from, $type->duration_minutes, $teacherId, $academicContext) as $teacher) {
            $slots = $this->availability->slots(
                new AvailabilityQueryData($teacher->id, $typeKey, $from, $to, $timezone),
            );

            foreach ($slots as $slot) {
                $found[$slot->startsAt->toDateString()] = true;
            }

            if (count($found) >= $totalDays) {
                break;
            }
        }

        return collect(array_keys($found))->sort()->values();
    }

    public function availableSlots(
        string $typeKey,
        string $subject,
        int $grade,
        CarbonImmutable $date,
        string $timezone = 'UTC',
        ?int $teacherId = null,
        ?AcademicContextData $academicContext = null,
    ): Collection {
        $from = $date->setTimezone($timezone)->startOfDay();

        return $this
            ->slotsAcrossTeachers($typeKey, $subject, $grade, $from, $from->addDay(), $timezone, $teacherId, $academicContext)
            ->unique(fn (TimeSlotData $slot): int => $slot->startsAt->getTimestamp())
            ->sortBy(fn (TimeSlotData $slot): int => $slot->startsAt->getTimestamp())
            ->values();
    }

    public function book(WizardBookingData $data): Booking
    {
        $this->assertAuthenticated();
        $this->assertProfileComplete();
        $type = $this->types->requireActiveByKey($data->typeKey);
        $this->financialVerification->assertEligible(auth()->user(), $type);

        // First resolve so an auto-assigned teacher is drawn from an
        // already-narrowed eligible candidate SET (§14).
        $academicContext = $this->resolveAcademicContext($data);
        $grade = $academicContext?->normalizedGrade ?? $data->grade;
        $teacherId = $this->resolveTeacher($data, $type, $grade, $academicContext?->toAcademicContextData());

        // Re-resolve immediately before persistence (§27/§28): never
        // trust the context resolved a moment ago for candidate
        // narrowing — the currently-Published CurriculumVersion and
        // instructor eligibility must be current AT booking creation.
        $academicContext = $this->resolveAcademicContext($data);
        $grade = $academicContext?->normalizedGrade ?? $data->grade;

        if ($academicContext !== null) {
            $instructor = User::findOrFail($teacherId);
            $this->instructorEligibility->assertEligible($instructor, $academicContext->toAcademicContextData());
        }

        // Phase 4D — a package-funded booking derives its academic
        // snapshot from the PACKAGE's frozen context, not from this
        // fresh resolve (§38). That is what keeps a booking funded by a
        // package sold under Curriculum v2 recorded as v2 even after v3
        // is published. Whether the instructor can DELIVER the lesson
        // right now is a separate question, still answered by the
        // eligibility/availability checks above — historical package
        // context and current delivery capability never merge.
        $packageEntitlementId = null;

        if ($data->packageEntitlementId !== null) {
            $entitlement = $this->requireEligibleEntitlement($data, $type, $teacherId, $academicContext);

            $packageEntitlementId = (string) $entitlement->id;
            $academicContext = $entitlement->proposal?->academicContext?->toSnapshotData() ?? $academicContext;
            $grade = $academicContext?->normalizedGrade ?? $grade;
        }

        return $this->bookings->request($this->occurrenceData($data, $type, $data->startsAt, $teacherId, $grade, $academicContext, packageEntitlementId: $packageEntitlementId));
    }

    /**
     * Turns the student's raw, posted entitlement id into a trusted one
     * — or refuses.
     *
     * Every check is server-side and re-run here at submit time: the
     * entitlement must be the AUTHENTICATED student's own, belong to
     * the instructor actually being booked, match the resolved academic
     * context on stable ids, still have available-to-book capacity, and
     * still be valid for a lesson that FINISHES before it expires.
     * PackageBookingEntitlementResolver owns all of those rules so the
     * list the student was shown and the rule their submission is
     * judged by cannot drift apart.
     *
     * @throws BookingException when the chosen package may not fund this booking
     */
    private function requireEligibleEntitlement(WizardBookingData $data, BookingType $type, int $teacherId, ?BookingAcademicContextData $academicContext): StudentPackageEntitlement
    {
        if ($academicContext === null) {
            throw new BookingException('Package lessons require a complete academic selection. Please choose your education system, level, and subject.');
        }

        // Version 1 rule, stated explicitly rather than left implicit:
        //
        //     package-funded booking  → requires a chosen instructor
        //     auto-assigned booking   → ordinary paid booking only
        //
        // This is not a technical limitation, it follows from the
        // product: a personalized package is a contract with ONE
        // instructor, so "which of my packages can fund this?" has no
        // answer until that instructor is known. The alternatives —
        // searching every instructor's packages, or letting entitlement
        // availability pick the instructor — would both make the
        // package, not the student, choose who teaches. If packages ever
        // become transferable across instructors that is a separate
        // commercial feature, designed on its own terms.
        if ($data->teacherId === null) {
            throw new BookingException('Choose your instructor to use a package — a package is tied to the instructor it was created with.');
        }

        $student = auth()->user();
        $endsAt = $data->startsAt->addMinutes((int) $type->duration_minutes);

        if (! $this->packageEntitlements->isEligible($student, $teacherId, $academicContext, $data->packageEntitlementId, $endsAt)) {
            throw new BookingException('The selected package cannot be used for this lesson. Please choose another package or pay for this lesson.');
        }

        return StudentPackageEntitlement::query()
            ->with('proposal.academicContext')
            ->findOrFail($data->packageEntitlementId);
    }

    /**
     * Compatibility entry point: "N occurrences, daily or weekly".
     *
     * Now a thin translation onto the series path rather than its own
     * loop, so this shape and the richer wizard shape cannot drift into
     * two different notions of what a repeating schedule is.
     */
    public function bookRecurring(WizardBookingData $data, RecurrenceData $recurrence): RecurringBookingResult
    {
        return $this->bookSeries($data, new RecurrencePatternData(
            frequency: $recurrence->frequency,
            interval: $recurrence->interval,
            endCondition: RecurrenceEndCondition::AfterCount,
            occurrenceCount: max(1, $recurrence->occurrences),
        ));
    }

    /**
     * Creates a repeating schedule and reserves its classes.
     *
     * Free Demo never accepts recurrence (BookingException). The teacher
     * resolved for the first occurrence — locked, or auto-assigned — is
     * the series' instructor for its whole life: no later occurrence is
     * ever given to somebody else, and a date that instructor cannot
     * teach is reported, never reassigned.
     *
     * Nothing here caps the schedule. How many classes exist AT ONCE is
     * bounded by the confirmation horizon inside BookingSeriesService;
     * how many the student may ask for is bounded only by the rule they
     * chose, which may have no end at all.
     */
    public function bookSeries(WizardBookingData $data, RecurrencePatternData $pattern, array $skippedDates = [], array $timeOverrides = []): RecurringBookingResult
    {
        [$teacherId, $rule, $type] = $this->prepareSeries($data, $pattern);

        // TZ-6 (Product Decision 2 / TZ-AUD-022) preserved exactly: a
        // series containing a wall-clock reading daylight saving makes
        // impossible or doubled is refused OUTRIGHT, before a single
        // class, reservation or payment demand exists. Unlike an
        // availability clash — a fact about one date the student can work
        // around — an unrepresentable time means we do not know what they
        // asked for, and a scheduling platform must not choose for them.
        $this->assertRuleRepresentable($rule, $skippedDates, $timeOverrides);

        $result = $this->series->create(new CreateBookingSeriesData(
            typeKey: $data->typeKey,
            studentId: (int) auth()->id(),
            instructorId: $teacherId,
            rule: $rule,
            durationMinutes: (int) $type->duration_minutes,
            studentTimezone: $data->timezone,
            meta: ['subject' => $data->subject, 'grade' => $data->grade],
            notes: $data->notes,
            createdBy: (int) auth()->id(),
            skippedDates: $skippedDates,
            timeOverrides: $timeOverrides,
        ));

        return $result;
    }

    /**
     * The schedule a pattern would produce, every date inside the
     * confirmation horizon actually checked — the answer the Review step
     * shows before anything is reserved.
     *
     * Advisory by construction: it runs outside the instructor lock, so
     * creation re-checks every occurrence under that lock rather than
     * trusting this. What it guarantees is that the student is never
     * asked to confirm a schedule whose conflicts they have not seen.
     *
     * @param  list<string>  $skippedDates
     */
    public function previewSeries(WizardBookingData $data, RecurrencePatternData $pattern, array $skippedDates = [], int $page = 1, array $timeOverrides = []): SeriesSchedulePreviewData
    {
        [$teacherId, $rule, $type] = $this->prepareSeries($data, $pattern);

        return $this->series->preview(
            rule: $rule,
            instructorId: $teacherId,
            studentId: (int) auth()->id(),
            durationMinutes: (int) $type->duration_minutes,
            bufferMinutes: (int) $type->buffer_minutes,
            skippedDates: $skippedDates,
            page: $page,
            isDemo: ! $type->is_paid,
            timeOverrides: $timeOverrides,
        );
    }

    /**
     * Shared guards plus the anchoring step, so preview and creation can
     * never be judged by different rules.
     *
     * @return array{int, RecurrenceRuleData, BookingType}
     *
     * @throws BookingException
     */
    private function prepareSeries(WizardBookingData $data, RecurrencePatternData $pattern): array
    {
        $this->assertAuthenticated();
        $this->assertProfileComplete();
        $type = $this->types->requireActiveByKey($data->typeKey);

        $this->financialVerification->assertEligible(auth()->user(), $type);

        if (! $type->is_paid) {
            throw new BookingException('Recurring sessions are only available for paid booking types.');
        }

        // Phase 4E.3 (PKG-AUD-007) — an EXPLICIT refusal, never a silent
        // downgrade. Before this, a forged (or merely stale) recurring
        // request carrying a package entitlement was accepted and the
        // entitlement was quietly dropped on the floor: the student
        // chose "use my package" and received N payment demands instead.
        //
        // Version 1 package funding is single-lesson only. Supporting a
        // recurring series would need its own commercial design —
        // reserving N units, partial-reservation failure, a recurrence
        // running past entitlement expiry, cancelling one occurrence,
        // and exhaustion midway — which is a feature, not a bug fix.
        // Refusing here, before any occurrence is attempted, is what
        // guarantees zero bookings, zero reservations and zero payment
        // side effects from the attempt.
        if ($data->packageEntitlementId !== null) {
            throw new BookingException('Package lessons are booked one at a time. Please book these sessions individually to use your package, or continue without it.');
        }

        $teacherId = $this->resolveTeacher($data, $type, $data->grade, null);

        return [$teacherId, $this->anchorRule($data, $pattern, $teacherId), $type];
    }

    /**
     * Turns the student's choices into the series' own rule.
     *
     * TZ-6 (Product Decision 1): the series is anchored to the
     * INSTRUCTOR'S availability clock, not the student's.
     *
     * The series exists because the instructor publishes "Mondays at
     * 19:00" — that rule is theirs, and it must keep meaning 19:00 to
     * them all year. Anchoring to the student instead (the behaviour
     * characterised in TZ-2A) held the STUDENT's clock still and
     * therefore walked the instructor's teaching slot by an hour for the
     * weeks when the two countries' DST dates differ, pushing lessons
     * outside the very availability window that created them.
     *
     * Students are unaffected in the sense that matters: every
     * occurrence is still stored as a UTC instant and still rendered in
     * their own timezone (TZ-4).
     *
     * The weekdays the student ticked are in THEIR calendar, so they are
     * translated by the day offset between the two calendars at the
     * chosen slot — a Monday-evening class for a student in New York can
     * be a Tuesday for an instructor in Kolkata, and the rule has to say
     * Tuesday or it would schedule the wrong day.
     */
    private function anchorRule(WizardBookingData $data, RecurrencePatternData $pattern, int $teacherId): RecurrenceRuleData
    {
        $recurrenceTimezone = $this->availabilityRules->calendarTimezoneFor($teacherId);
        $anchor = $data->startsAt->setTimezone($recurrenceTimezone);
        $studentAnchor = $data->startsAt->setTimezone($data->timezone);

        $dayOffset = (int) CarbonImmutable::parse($studentAnchor->toDateString())
            ->diffInDays(CarbonImmutable::parse($anchor->toDateString()));

        $weekdays = array_map(
            static fn (Weekday $day): Weekday => Weekday::from((($day->value + $dayOffset) % 7 + 7) % 7),
            $pattern->weekdays,
        );

        return new RecurrenceRuleData(
            frequency: $pattern->frequency,
            startDate: $anchor->toDateString(),
            timeOfDay: $anchor->format('H:i:s'),
            timezone: $recurrenceTimezone,
            interval: $pattern->interval,
            weekdays: $weekdays,
            endCondition: $pattern->endCondition,
            // The student picked a calendar date in their own timezone;
            // it bounds the series' calendar, so it is read as the end of
            // that day and re-expressed in the series' timezone.
            endDate: $pattern->endDate === null
                ? null
                : CarbonImmutable::parse($pattern->endDate, $data->timezone)
                    ->endOfDay()
                    ->setTimezone($recurrenceTimezone)
                    ->toDateString(),
            occurrenceCount: $pattern->occurrenceCount,
        );
    }

    /**
     * @param  list<string>  $skippedDates
     *
     * @throws BookingException
     */
    private function assertRuleRepresentable(RecurrenceRuleData $rule, array $skippedDates, array $timeOverrides = []): void
    {
        // A finite schedule is checked end to end; an ongoing one has no
        // end to check, so it is checked as far as classes are actually
        // reserved. Either way the student is told about a date rather
        // than silently given a different time.
        $occurrences = $rule->endCondition->isFinite()
            ? $this->series->occurrencesFor($rule, $skippedDates, $timeOverrides)
            : $this->series->occurrencesWithinHorizon($rule, $skippedDates, $timeOverrides);

        foreach ($occurrences as $occurrence) {
            if ($occurrence->isRepresentable()) {
                continue;
            }

            throw new BookingException(sprintf(
                'This repeating time cannot be scheduled on %s. %s',
                CarbonImmutable::parse($occurrence->localDate)->format('j M Y'),
                LocalWallClock::reason($occurrence->wallClock, $rule->timezone),
            ));
        }
    }

    /**
     * Server-side twin of the EnsureStudentProfileComplete middleware: the
     * wizard is Livewire, so its submissions never pass route middleware.
     */
    private function assertProfileComplete(): void
    {
        /** @var User $student */
        $student = auth()->user();

        if (! $this->profileCompleteness->isComplete($student)) {
            throw new BookingException('Please complete your profile (country, mobile number and terms) before booking. Open Complete your profile from your dashboard.');
        }
    }

    private function assertAuthenticated(): void
    {
        // Defense-in-depth: the route itself already requires 'auth', but
        // this service is the single chokepoint every wizard submission
        // funnels through — it must refuse gracefully even if some future
        // caller reaches it without going through that middleware, rather
        // than crash on the non-nullable CreateBookingData::$studentId.
        if (! auth()->check()) {
            throw new BookingException('Please log in or create an account to book a lesson.');
        }
    }

    private function resolveTeacher(WizardBookingData $data, BookingType $type, int $grade, ?AcademicContextData $academicContext): int
    {
        $criteria = new AssignmentCriteriaData(
            typeKey: $data->typeKey,
            subject: $data->subject,
            grade: $grade,
            startsAt: $data->startsAt,
            durationMinutes: $type->duration_minutes,
            timezone: $data->timezone,
            academicContext: $academicContext,
            // Continuity for "any available instructor": the engine
            // prefers the instructor this student had last time.
            studentId: Auth::id(),
        );

        if ($data->teacherId !== null) {
            if (! $this->candidates->isEligible($data->teacherId, $criteria)) {
                throw new BookingException('This instructor is not available for the selected subject and grade.');
            }

            return $data->teacherId;
        }

        return $this->assigner->assign($criteria)->id;
    }

    /**
     * Resolves the country-aware academic context for a Free Demo
     * request, or null for every other type / legacy student — see
     * DemoAcademicContextResolver's class docblock for the full gating
     * rules (§10/§26/§41). Never caches its own result across calls —
     * intentionally re-run at each call site in book() so a race
     * (Education System deactivated, Curriculum archived, version
     * superseded, etc.) between UI render and submit is caught (§28).
     */
    private function resolveAcademicContext(WizardBookingData $data): ?BookingAcademicContextData
    {
        if ($data->typeKey === FreeDemoType::KEY) {
            return $this->demoAcademicContext->resolveForDemo(
                auth()->user(),
                $data->educationSystemId,
                $data->educationSystemLevelId,
                $data->subjectId,
                $data->curriculumId,
            );
        }

        // Paid wizard bookings use the same permanent country-aware booking
        // context as demos. CountryAcademicPackages remains specific to
        // package proposal/entitlement availability, not lesson taxonomy.
        return $this->academicContextResolver->resolve(
            student: auth()->user(),
            feature: CountryFeature::CountryAcademicBooking,
            copy: AcademicFlowCopy::forPackageBooking(),
            educationSystemId: $data->educationSystemId,
            educationSystemLevelId: $data->educationSystemLevelId,
            subjectId: $data->subjectId,
            curriculumId: $data->curriculumId,
            autoResolveCurriculum: $data->curriculumId === null,
        );
    }

    /** @param array<string, mixed> $extraMeta */
    private function occurrenceData(WizardBookingData $data, BookingType $type, CarbonImmutable $startsAt, int $teacherId, int $grade, ?BookingAcademicContextData $academicContext = null, array $extraMeta = [], ?RecurrenceFrequency $recurrenceFrequency = null, ?string $packageEntitlementId = null): CreateBookingData
    {
        return new CreateBookingData(
            typeKey: $data->typeKey,
            studentId: auth()->id(),
            instructorId: $teacherId,
            startsAt: $startsAt,
            durationMinutes: $type->duration_minutes,
            timezone: $data->timezone,
            notes: $data->notes,
            // §21/§24: legacy meta.subject/meta.grade continue to be
            // written for every booking (existing readers must not
            // break) — for a country-aware Demo, subject is derived from
            // the validated Subject master and grade from the resolved
            // EducationSystemLevel.normalized_grade, never the raw
            // client-submitted strings.
            meta: ['subject' => $academicContext?->subjectName ?? $data->subject, 'grade' => $grade, ...$extraMeta],
            recurrenceFrequency: $recurrenceFrequency,
            academicContext: $academicContext,
            packageEntitlementId: $packageEntitlementId,
        );
    }

    /** @return Collection<int, TimeSlotData> */
    private function slotsAcrossTeachers(
        string $typeKey,
        string $subject,
        int $grade,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $timezone,
        ?int $teacherId = null,
        ?AcademicContextData $academicContext = null,
    ): Collection {
        $type = $this->types->requireActiveByKey($typeKey);

        return $this
            ->eligibleTeachers($typeKey, $subject, $grade, $from, $type->duration_minutes, $teacherId, $academicContext)
            ->flatMap(fn (User $teacher): Collection => $this->availability->slots(
                new AvailabilityQueryData($teacher->id, $typeKey, $from, $to, $timezone),
            ));
    }

    public function instructorOptions(string $typeKey, string $subject, int $grade, ?AcademicContextData $academicContext, User $student): array
    {
        $criteria = new AssignmentCriteriaData($typeKey, $subject, $grade, CarbonImmutable::now()->addDay(), 60, academicContext: $academicContext);
        $candidates = $this->candidates->eligible($criteria)->keyBy('id');

        if ($candidates->isEmpty()) {
            return ['previous' => [], 'favourites' => [], 'others' => []];
        }

        $previousIds = $this->bookingRecords->previousInstructorIdsForStudent($student->id, $subject)
            ->filter(fn (int $id): bool => $candidates->has($id))
            ->values();
        $favouriteIds = $this->favorites->bookableFavorites($student)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $candidates->has($id) && ! $previousIds->contains($id))
            ->values();

        $remaining = max(0, self::INSTRUCTOR_OPTION_LIMIT - $previousIds->count() - $favouriteIds->count());
        $otherIds = $candidates->keys()
            ->map(fn ($id): int => (int) $id)
            ->reject(fn (int $id): bool => $previousIds->contains($id) || $favouriteIds->contains($id))
            ->take($remaining)
            ->values();

        $cards = $this->instructors->bookingChoiceCards(
            $candidates->only([...$previousIds->all(), ...$favouriteIds->all(), ...$otherIds->all()])->values(),
        );

        $pick = fn (Collection $ids, string $badge): array => $ids
            ->map(fn (int $id): ?array => isset($cards[$id]) ? [...$cards[$id], 'badge' => $badge] : null)
            ->filter()
            ->values()
            ->all();

        return [
            'previous' => $pick($previousIds, 'previous'),
            'favourites' => $pick($favouriteIds, 'favourite'),
            'others' => $pick($otherIds, ''),
        ];
    }

    /**
     * The one place both availableDates() and availableSlots() (via
     * slotsAcrossTeachers()) funnel through
     * before any teacher-eligibility query or availability expansion.
     * An explicit free-demo scheduling request must never return
     * teachers/dates/slots implying a new demo can be created while the
     * platform-wide feature is unavailable — and returning empty here,
     * before eligibility/availability queries run, is also what keeps
     * this from doing unnecessary work once demos are disabled.
     *
     * @return Collection<int, User>
     */
    private function eligibleTeachers(string $typeKey, string $subject, int $grade, CarbonImmutable $startsAt, int $duration, ?int $teacherId = null, ?AcademicContextData $academicContext = null): Collection
    {
        if ($typeKey === FreeDemoType::KEY && ! $this->demoAvailability->isAvailable()) {
            return new Collection;
        }

        $criteria = new AssignmentCriteriaData($typeKey, $subject, $grade, $startsAt, $duration, academicContext: $academicContext);

        if ($teacherId === null) {
            return $this->candidates->eligible($criteria);
        }

        if (! $this->candidates->isEligible($teacherId, $criteria)) {
            return new Collection;
        }

        return User::query()
            ->whereKey($teacherId)
            ->with('profile')
            ->get();
    }
}
