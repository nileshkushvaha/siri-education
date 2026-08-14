<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\AvailabilityServiceInterface;
use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\Contracts\BookingTypeRepositoryInterface;
use App\Booking\Contracts\TeacherAssignmentServiceInterface;
use App\Booking\Contracts\TeacherCandidateRepositoryInterface;
use App\Booking\Contracts\WizardBookingServiceInterface;
use App\Booking\DTOs\AssignmentCriteriaData;
use App\Booking\DTOs\AvailabilityQueryData;
use App\Booking\DTOs\BookingAcademicContextData;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\DTOs\RecurrenceData;
use App\Booking\DTOs\RecurringBookingResult;
use App\Booking\DTOs\TimeSlotData;
use App\Booking\DTOs\WizardBookingData;
use App\Booking\Enums\RecurrenceFrequency;
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
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Authenticated-student wizard booking flow — every caller is logged
 * in. The auto-assignment capability (pick any eligible teacher, or
 * lock a specific one) is what distinguishes this from
 * StudentBookingServiceInterface, which always requires an explicit
 * teacher choice.
 *
 * Phase 3: for `free_demo` only, also resolves the country-aware
 * academic context (DemoAcademicContextResolver) BEFORE candidate
 * selection — so an automatically-assigned teacher is drawn from an
 * already-narrowed eligible SET — and again immediately before
 * CreateBookingData is built, so the persisted snapshot always reflects
 * the currently-Published CurriculumVersion and current instructor
 * eligibility, never a value cached earlier in the request/session
 * (§27/§28).
 *
 * Phase 4D extends that same country-aware resolution to `paid_one_to_one`,
 * gated by CountryFeature::CountryAcademicPackages, and adds explicit
 * package funding. Two properties of that extension matter:
 *
 *  - It is ADDITIVE. A paid booking that sends no structured academic
 *    selections resolves no context and behaves exactly as it did
 *    before — ordinary paid booking is untouched, and owning a
 *    compatible package never forces its use (§31).
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
     * Free Demo never accepts recurrence (BookingException). The teacher
     * for the first occurrence (locked, or auto-assigned) is reused for
     * every later occurrence — a recurring series is with one instructor.
     */
    public function bookRecurring(WizardBookingData $data, RecurrenceData $recurrence): RecurringBookingResult
    {
        $this->assertAuthenticated();
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
        $occurrences = max(2, min($recurrence->occurrences, RecurrenceData::MAX_OCCURRENCES));
        $groupId = (string) Str::uuid();

        $booked = new Collection;
        $failures = [];

        for ($i = 0; $i < $occurrences; $i++) {
            $startsAt = $recurrence->nextStartsAt($data->startsAt, $i);

            try {
                $booked->push($this->bookings->request($this->occurrenceData($data, $type, $startsAt, $teacherId, $data->grade, extraMeta: ['recurring_group' => $groupId], recurrenceFrequency: $recurrence->frequency)));
            } catch (BookingException $e) {
                $failures[$startsAt->toIso8601String()] = $e->getMessage();
            }
        }

        if ($booked->isEmpty()) {
            throw new BookingException('None of the requested sessions could be booked: '.implode(' ', $failures));
        }

        return new RecurringBookingResult($groupId, $booked, $failures);
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

        // Phase 4D — a PAID booking resolves an academic context only
        // when the student is actually taking the country-aware path,
        // i.e. they sent structured selections. An ordinary paid
        // booking sends none and continues to behave exactly as before,
        // with no academic context and no package involvement (§31/§57)
        // — this is deliberately additive, never a redirection of the
        // existing paid flow.
        //
        // Gated by CountryAcademicPackages rather than the demo flow's
        // own feature, so a country that has switched off free demos
        // can still sell and book packages.
        if ($data->educationSystemId === null && $data->educationSystemLevelId === null && $data->subjectId === null) {
            return null;
        }

        return $this->academicContextResolver->resolve(
            student: auth()->user(),
            feature: CountryFeature::CountryAcademicPackages,
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
