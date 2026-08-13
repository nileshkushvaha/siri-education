<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\BookingTypeRepositoryInterface;
use App\Booking\Contracts\TeacherCandidateRepositoryInterface;
use App\Booking\Contracts\WizardBookingServiceInterface;
use App\Booking\DTOs\RecurrenceData;
use App\Booking\DTOs\RecurringBookingResult;
use App\Booking\DTOs\TimeSlotData;
use App\Booking\DTOs\WizardBookingData;
use App\Booking\Enums\RecurrenceFrequency;
use App\Booking\Types\FreeDemoType;
use App\Curriculum\DTOs\AcademicContextData;
use App\Curriculum\Exceptions\AcademicContextException;
use App\Curriculum\Services\AcademicContextResolver;
use App\Enums\InstructorStatus;
use App\Models\Booking;
use App\Models\Country;
use App\Models\Curriculum;
use App\Models\EducationSystem;
use App\Models\EducationSystemLevel;
use App\Models\Subject;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Phase 3: also exposes the country-aware academic progressive-loading
 * surface BookingWizard needs — always delegating to
 * DemoAcademicContextResolver (which itself composes
 * AcademicContextResolver/InstructorAcademicEligibilityResolver) rather
 * than querying Curriculum-domain models directly. This keeps
 * BookingWizard a thin Livewire component: it only ever calls this
 * service, never a Curriculum-domain resolver directly.
 */
final class BookingWizardService
{
    public function __construct(
        private readonly WizardBookingServiceInterface $bookings,
        private readonly BookingTypeRepositoryInterface $types,
        private readonly TeacherCandidateRepositoryInterface $teachers,
        private readonly DemoAvailabilityResolver $demoAvailability,
        private readonly DemoAcademicContextResolver $demoAcademicContext,
        private readonly AcademicContextResolver $academicContextResolver,
    ) {}

    /**
     * Free Demo is omitted entirely while it isn't effectively available
     * (DemoAvailabilityResolver: global toggle + booking-type active
     * status) — mount()'s query-param preselection and selectMode()
     * both already refuse any key absent from this list, so hiding it
     * here is sufficient to keep a stale `?type=free_demo` link from
     * reaching step 2.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function bookingTypes(): Collection
    {
        return $this->types->allActive()
            ->reject(fn ($type): bool => $type->key === FreeDemoType::KEY && ! $this->demoAvailability->isAvailable())
            ->map(fn ($type): array => [
                'key' => $type->key,
                'name' => $type->name,
                'description' => $type->description,
                'duration_minutes' => $type->duration_minutes,
                'is_paid' => $type->is_paid,
                'requires_approval' => $type->requires_approval,
            ])
            ->values();
    }

    /** @return Collection<int, string> */
    public function subjects(): Collection
    {
        return $this->teachers->availableSubjects()->values();
    }

    /** @return Collection<int, string> */
    public function availableDates(string $typeKey, string $subject, int $grade, CarbonImmutable $from, CarbonImmutable $to, string $timezone, ?int $teacherId = null, ?AcademicContextData $academicContext = null): Collection
    {
        return $this->bookings->availableDates($typeKey, $subject, $grade, $from, $to, $timezone, $teacherId, $academicContext);
    }

    /** @return Collection<int, array<string, mixed>> */
    public function availableSlots(string $typeKey, string $subject, int $grade, CarbonImmutable $date, string $timezone, ?int $teacherId = null, ?AcademicContextData $academicContext = null): Collection
    {
        return $this->bookings
            ->availableSlots($typeKey, $subject, $grade, $date, $timezone, $teacherId, $academicContext)
            ->map(fn (TimeSlotData $slot): array => [
                'starts_at' => $slot->startsAt->toIso8601String(),
                'ends_at' => $slot->endsAt->toIso8601String(),
            ])
            ->values();
    }

    // ── Phase 3 — country-aware academic progressive loading (§7/§9) ────────

    public function academicBookingEnabledGlobally(): bool
    {
        return $this->demoAcademicContext->isEnabledGlobally();
    }

    /** Server-resolved student Country — never trusted from client input (§6). */
    public function studentCountry(User $student): ?Country
    {
        return $this->demoAcademicContext->studentCountry($student);
    }

    public function academicBookingEnabledForCountry(?Country $country): bool
    {
        return $this->demoAcademicContext->isEnabledForCountry($country);
    }

    /** @return list<array{id:string,name:string}> */
    public function educationSystems(Country $country, ?int $lockedInstructorId = null): array
    {
        return $this->demoAcademicContext
            ->educationSystemsFor($country, $this->instructorOrNull($lockedInstructorId))
            ->map(fn (EducationSystem $s): array => ['id' => $s->id, 'name' => $s->name])
            ->values()
            ->all();
    }

    /**
     * Phase 3.1 — the exact, student-selectable levels for this system
     * (§7: no min/max-band synthesis, no 1..12 fallback — an empty
     * array means "not currently configured").
     *
     * @return list<array{id:string,value:string,display_label:string,normalized_grade:?int}>
     */
    public function levels(Country $country, string $educationSystemId): array
    {
        $system = EducationSystem::find($educationSystemId);

        if ($system === null) {
            return [];
        }

        try {
            return $this->demoAcademicContext
                ->levelsFor($country, $system)
                ->map(fn (EducationSystemLevel $l): array => [
                    'id' => $l->id,
                    'value' => $l->value,
                    'display_label' => $l->display_label,
                    'normalized_grade' => $l->normalized_grade,
                ])
                ->values()
                ->all();
        } catch (AcademicContextException) {
            return [];
        }
    }

    /** @return list<array{id:string,name:string}> */
    public function academicSubjects(Country $country, string $educationSystemId, string $educationSystemLevelId): array
    {
        $system = EducationSystem::find($educationSystemId);
        $level = EducationSystemLevel::find($educationSystemLevelId);

        if ($system === null || $level === null) {
            return [];
        }

        try {
            return $this->demoAcademicContext
                ->subjectsFor($country, $system, $level)
                ->map(fn (Subject $s): array => ['id' => $s->id, 'name' => $s->name])
                ->values()
                ->all();
        } catch (AcademicContextException) {
            return [];
        }
    }

    /** @return list<array{id:string,name:string}> */
    public function curricula(Country $country, string $educationSystemId, string $educationSystemLevelId, string $academicSubjectId, ?int $lockedInstructorId = null): array
    {
        $system = EducationSystem::find($educationSystemId);
        $level = EducationSystemLevel::find($educationSystemLevelId);
        $subject = Subject::find($academicSubjectId);

        if ($system === null || $level === null || $subject === null) {
            return [];
        }

        try {
            return $this->demoAcademicContext
                ->curriculaFor($country, $system, $level, $subject, $this->instructorOrNull($lockedInstructorId))
                ->map(fn (Curriculum $c): array => ['id' => $c->id, 'name' => $c->name])
                ->values()
                ->all();
        } catch (AcademicContextException) {
            return [];
        }
    }

    /**
     * Non-throwing, listing-purpose resolution used only to narrow the
     * candidate teacher SET while browsing dates/slots (§7/§10) — the
     * authoritative, throwing resolution that actually gates Booking
     * creation is DemoAcademicContextResolver::resolveForDemo(), called
     * again (never trusted from here) inside WizardBookingService::book().
     */
    public function resolveAcademicContextForBrowsing(Country $country, ?string $educationSystemId, ?string $educationSystemLevelId, ?string $subjectId, ?string $curriculumId): ?AcademicContextData
    {
        if ($educationSystemId === null || $educationSystemLevelId === null || $subjectId === null || $curriculumId === null) {
            return null;
        }

        $system = EducationSystem::find($educationSystemId);
        $level = EducationSystemLevel::find($educationSystemLevelId);
        $subject = Subject::find($subjectId);
        $curriculum = Curriculum::find($curriculumId);

        if ($system === null || $level === null || $subject === null || $curriculum === null) {
            return null;
        }

        try {
            return $this->academicContextResolver->resolveContextForLevel($country, $system, $level, $subject, $curriculum);
        } catch (AcademicContextException) {
            return null;
        }
    }

    private function instructorOrNull(?int $id): ?User
    {
        return $id !== null ? User::find($id) : null;
    }

    /** @param array<string, mixed> $data */
    public function book(array $data): Booking
    {
        return $this->bookings->book($this->wizardBookingData($data));
    }

    /** @param array<string, mixed> $data */
    public function bookRecurring(array $data, string $frequency, int $occurrences): RecurringBookingResult
    {
        return $this->bookings->bookRecurring(
            $this->wizardBookingData($data),
            new RecurrenceData($occurrences, RecurrenceFrequency::from($frequency)),
        );
    }

    /** @param array<string, mixed> $data */
    private function wizardBookingData(array $data): WizardBookingData
    {
        return new WizardBookingData(
            typeKey: $data['type'],
            subject: $data['subject'],
            grade: (int) $data['grade'],
            startsAt: CarbonImmutable::parse($data['starts_at'], $data['timezone']),
            timezone: $data['timezone'],
            notes: $data['notes'] ?? null,
            teacherId: $data['teacher_id'] ?? null,
            educationSystemId: $data['education_system_id'] ?? null,
            educationSystemLevelId: $data['education_system_level_id'] ?? null,
            subjectId: $data['academic_subject_id'] ?? null,
            curriculumId: $data['curriculum_id'] ?? null,
        );
    }

    /** @return array{id:int,name:string}|null */
    public function lockedInstructor(string $slug): ?array
    {
        $instructor = User::query()
            ->where('slug', $slug)
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('profile', fn ($query) => $query
                ->whereIn('instructor_status', InstructorStatus::bookableValues())
                ->where('profile_visibility', 'public'))
            ->first();

        if (! $instructor) {
            return null;
        }

        return [
            'id' => $instructor->id,
            'name' => $instructor->name,
        ];
    }

    /** @return array<string, mixed> */
    public function result(Booking $booking): array
    {
        $booking->loadMissing('type');

        return [
            'id' => $booking->id,
            'reference' => $booking->reference,
            'status' => $booking->status->value,
            'status_label' => $booking->status->label(),
            'requires_payment' => $booking->payment_status->isPayable(),
            'payment_status' => $booking->payment_status->value,
            'type' => [
                'key' => $booking->type->key,
                'name' => $booking->type->name,
            ],
            'starts_at' => $booking->starts_at->timezone($booking->timezone)->toIso8601String(),
            'ends_at' => $booking->ends_at->timezone($booking->timezone)->toIso8601String(),
            'timezone' => $booking->timezone,
            'subject' => $booking->meta['subject'] ?? null,
            'grade' => $booking->meta['grade'] ?? null,
            'my_bookings_url' => route('dashboard.my-bookings'),
        ];
    }

    /** @return array<string, mixed> */
    public function recurringResult(RecurringBookingResult $result): array
    {
        $bookings = $result->booked->map(fn (Booking $booking): array => $this->result($booking))->values();

        return [
            'recurring' => true,
            'group_id' => $result->groupId,
            'bookings' => $bookings->all(),
            'failures' => $result->failures,
            'requires_payment' => $bookings->contains(fn (array $b): bool => $b['requires_payment']),
            'my_bookings_url' => route('dashboard.my-bookings'),
        ];
    }
}
