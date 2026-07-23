<?php

declare(strict_types=1);

namespace App\Services\Instructor;

use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\DTOs\AvailabilityChangeImpact;
use App\Booking\Enums\Weekday;
use App\Enums\InstructorStatus;
use App\Exceptions\Instructor\AvailabilityChangeRequiresConfirmationException;
use App\Models\TeacherAvailability;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Waitlist\Events\InstructorAvailabilityOpened;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InstructorAvailabilityService
{
    public function __construct(
        private readonly AuditTrailService $auditTrail,
        private readonly AvailabilityChangeImpactService $impact,
        private readonly BookingRepositoryInterface $bookings,
    ) {}

    /**
     * @param  array{
     *     teacher_id:int,
     *     day_of_week:int|string|Weekday,
     *     start_time:string,
     *     end_time:string,
     *     timezone?:string|null,
     *     effective_from?:string|null,
     *     effective_until?:string|null,
     *     is_active?:bool
     * }  $data
     */
    public function create(array $data, User $actor): TeacherAvailability
    {
        $availability = DB::transaction(function () use ($data, $actor): TeacherAvailability {
            $teacher = $this->teacher((int) $data['teacher_id']);
            $this->assertCanCreate($actor, $teacher);

            $payload = $this->payload($data, $teacher, $actor);

            $this->assertCanPublish($teacher, (bool) $payload['is_active']);
            $this->assertTimezoneResolved($teacher, $data, (bool) $payload['is_active']);
            $this->assertValidTimeRange($payload['start_time'], $payload['end_time']);
            $this->assertValidEffectiveRange($payload['effective_from'], $payload['effective_until']);
            $this->assertNoOverlap($teacher, $payload);

            $availability = TeacherAvailability::query()->create($payload);

            $this->auditTrail->logUser(
                $actor,
                'teacher_availability',
                'created',
                'Instructor availability window created.',
                $availability,
                $this->auditProperties($availability),
            );

            return $availability;
        });

        // SRS §10.28/§10.33-4 — GAP-018: a newly published (active)
        // window is exactly the "instructor later opens matching
        // availability" trigger. Dispatched after the transaction
        // above commits (ShouldDispatchAfterCommit); a draft/inactive
        // window is not bookable capacity and must not notify anyone.
        if ($availability->is_active) {
            InstructorAvailabilityOpened::dispatch($availability->teacher, 'availability_created', (string) $availability->id);
        }

        return $availability;
    }

    /**
     * @param  array{
     *     teacher_id?:int,
     *     day_of_week?:int|string|Weekday,
     *     start_time?:string,
     *     end_time?:string,
     *     timezone?:string|null,
     *     effective_from?:string|null,
     *     effective_until?:string|null,
     *     is_active?:bool
     * }  $data
     */
    public function update(TeacherAvailability $availability, array $data, User $actor, ?string $impactConfirmation = null): TeacherAvailability
    {
        // Phase 24I — GAP-019: the instructor-scoped booking lock
        // serializes this mutation (and its impact recheck) against
        // booking creation/confirmation for the same instructor, so a
        // booking can never slip in between the final impact check and
        // the availability write. Lock outside, transaction inside —
        // the same ordering BookingService::request() uses.
        return $this->bookings->withInstructorLock($availability->teacher_id, fn (): TeacherAvailability => DB::transaction(function () use ($availability, $data, $actor, $impactConfirmation): TeacherAvailability {
            $teacher = $this->teacher((int) ($data['teacher_id'] ?? $availability->teacher_id));
            $this->assertCanManage($actor, $availability, $teacher, 'update');
            $this->assertStillExists($availability);

            $normalized = [
                'teacher_id' => $teacher->id,
                'day_of_week' => $data['day_of_week'] ?? $availability->day_of_week,
                'start_time' => $data['start_time'] ?? $availability->start_time,
                'end_time' => $data['end_time'] ?? $availability->end_time,
                'timezone' => $data['timezone'] ?? $availability->timezone,
                'effective_from' => array_key_exists('effective_from', $data) ? $data['effective_from'] : $availability->effective_from?->toDateString(),
                'effective_until' => array_key_exists('effective_until', $data) ? $data['effective_until'] : $availability->effective_until?->toDateString(),
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : (bool) $availability->is_active,
            ];
            $payload = $this->payload($normalized, $teacher, $actor);

            $this->assertCanPublish($teacher, (bool) $payload['is_active']);
            $this->assertTimezoneResolved($teacher, $data, (bool) $payload['is_active'], (bool) $availability->is_active);
            $this->assertValidTimeRange($payload['start_time'], $payload['end_time']);
            $this->assertValidEffectiveRange($payload['effective_from'], $payload['effective_until']);
            $this->assertNoOverlap($teacher, $payload, $availability);

            $impact = $this->analyzeWindowMutation($teacher, $availability, $payload, 'window_updated');
            $this->assertImpactAcknowledged($impact, $impactConfirmation);

            $previous = $availability->only(['teacher_id', 'day_of_week', 'start_time', 'end_time', 'timezone', 'effective_from', 'effective_until', 'is_active']);
            $availability->forceFill([
                ...$payload,
                'created_by' => $availability->created_by,
            ])->save();

            $this->auditTrail->logUser(
                $actor,
                'teacher_availability',
                'updated',
                'Instructor availability window updated.',
                $availability,
                [
                    'previous' => $previous,
                    'current' => $this->auditProperties($availability),
                    ...$this->impactAuditProperties($impact),
                ],
            );

            return $availability->refresh();
        }));
    }

    public function delete(TeacherAvailability $availability, User $actor, ?string $impactConfirmation = null): void
    {
        $this->bookings->withInstructorLock($availability->teacher_id, fn () => DB::transaction(function () use ($availability, $actor, $impactConfirmation): void {
            $this->assertCanManage($actor, $availability, null, 'delete');
            $this->assertStillExists($availability);

            $teacher = $this->teacher($availability->teacher_id);
            $impact = $this->analyzeWindowMutation($teacher, $availability, null, 'window_deleted');
            $this->assertImpactAcknowledged($impact, $impactConfirmation);

            $properties = $this->auditProperties($availability);
            $availability->delete();

            $this->auditTrail->logUser(
                $actor,
                'teacher_availability',
                'deleted',
                'Instructor availability window deleted.',
                $availability,
                [...$properties, ...$this->impactAuditProperties($impact)],
            );
        }));
    }

    public function setActive(TeacherAvailability $availability, bool $active, User $actor, ?string $impactConfirmation = null): TeacherAvailability
    {
        return $this->update($availability, ['is_active' => $active], $actor, $impactConfirmation);
    }

    /**
     * Phase 24I — GAP-019: builds the hypothetical after-state window
     * set for this mutation (current active rows with the target row
     * replaced by an unsaved clone carrying the proposed payload, or
     * removed entirely for a delete) and runs the impact analysis.
     * Creating/widening runs through this too and simply yields no
     * affected bookings.
     *
     * @param  array<string, mixed>|null  $payload  null = the row is being removed
     */
    private function analyzeWindowMutation(User $teacher, TeacherAvailability $availability, ?array $payload, string $mutationType): AvailabilityChangeImpact
    {
        $currentRows = TeacherAvailability::query()
            ->active()
            ->forTeacher($availability->teacher_id)
            ->with('teacher.profile')
            ->get();

        $proposedRows = $currentRows->reject(
            fn (TeacherAvailability $row): bool => $row->getKey() === $availability->getKey(),
        )->values();

        if ($payload !== null) {
            $clone = $availability->replicate();
            $clone->forceFill($payload);
            $proposedRows->push($clone);
        }

        $proposal = $payload ?? ['deleted' => $availability->getKey()];
        $proposal['availability_id'] = (string) $availability->getKey();

        return $this->impact->analyzeWindowChange($teacher, $currentRows, $proposedRows, $mutationType, $proposal);
    }

    /**
     * Phase 24I: a duplicate submission (double-click, stale tab) can
     * carry an in-memory model whose row is already gone — re-checked
     * under the instructor lock so it fails safely instead of silently
     * re-mutating and double-auditing.
     */
    private function assertStillExists(TeacherAvailability $availability): void
    {
        if (TeacherAvailability::query()->whereKey($availability->getKey())->doesntExist()) {
            throw ValidationException::withMessages(['availability' => 'This availability window no longer exists.']);
        }
    }

    private function assertImpactAcknowledged(AvailabilityChangeImpact $impact, ?string $impactConfirmation): void
    {
        if (! $impact->requiresConfirmation) {
            return;
        }

        if (! $this->impact->fingerprintMatches($impact, $impactConfirmation)) {
            throw new AvailabilityChangeRequiresConfirmationException($impact);
        }
    }

    /** @return array<string, mixed> */
    private function impactAuditProperties(AvailabilityChangeImpact $impact): array
    {
        return [
            'affected_booking_count' => $impact->affectedCount,
            'had_affected_bookings' => $impact->affectedCount > 0,
            'impact_acknowledged' => $impact->requiresConfirmation,
            'impact_fingerprint' => $impact->fingerprint !== '' ? substr($impact->fingerprint, 0, 16) : null,
        ];
    }

    private function teacher(int $teacherId): User
    {
        return User::query()
            ->with('profile')
            ->whereKey($teacherId)
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(array $data, User $teacher, User $actor): array
    {
        $timezone = $this->timezone($data['timezone'] ?? null, $teacher);

        return [
            'teacher_id' => $teacher->id,
            'day_of_week' => $this->weekday($data['day_of_week'])->value,
            'start_time' => $this->normalizeTime((string) $data['start_time']),
            'end_time' => $this->normalizeTime((string) $data['end_time']),
            'timezone' => $timezone,
            'effective_from' => filled($data['effective_from'] ?? null) ? (string) $data['effective_from'] : null,
            'effective_until' => filled($data['effective_until'] ?? null) ? (string) $data['effective_until'] : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ];
    }

    private function weekday(int|string|Weekday $value): Weekday
    {
        if ($value instanceof Weekday) {
            return $value;
        }

        return Weekday::from((int) $value);
    }

    private function timezone(?string $timezone, User $teacher): string
    {
        $timezone = $timezone ?: $teacher->profile?->timezone ?: config('app.timezone', 'UTC');

        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            throw ValidationException::withMessages(['timezone' => 'Select a valid timezone.']);
        }

        return $timezone;
    }

    private function normalizeTime(string $time): string
    {
        return CarbonImmutable::parse($time)->format('H:i:s');
    }

    private function assertCanPublish(User $teacher, bool $active): void
    {
        if (! $active) {
            return;
        }

        if (! $teacher->isActive() || ! in_array($teacher->profile?->instructor_status, InstructorStatus::bookable(), true)) {
            throw ValidationException::withMessages([
                'teacher_id' => 'Only active approved instructors can publish availability.',
            ]);
        }
    }

    private function assertValidTimeRange(string $startTime, string $endTime): void
    {
        if ($startTime >= $endTime) {
            throw ValidationException::withMessages(['end_time' => 'End time must be after start time.']);
        }
    }

    private function assertValidEffectiveRange(?string $effectiveFrom, ?string $effectiveUntil): void
    {
        if ($effectiveFrom !== null && $effectiveUntil !== null && $effectiveUntil < $effectiveFrom) {
            throw ValidationException::withMessages([
                'effective_until' => 'Effective until date must be on or after the effective from date.',
            ]);
        }
    }

    /**
     * Publishing active availability must never fall back to the app
     * timezone silently: either the instructor profile carries one, or
     * this call must explicitly select one.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertTimezoneResolved(User $teacher, array $data, bool $willBeActive, bool $wasActive = false): void
    {
        if (! $willBeActive || $wasActive) {
            return;
        }

        if (filled($data['timezone'] ?? null) || $teacher->profile?->timezone !== null) {
            return;
        }

        throw ValidationException::withMessages([
            'timezone' => 'Set your profile timezone or choose one explicitly before publishing availability.',
        ]);
    }

    /**
     * Instructors may only manage their own record; anyone else needs
     * the equivalent Shield-style admin permission (see
     * TeacherAvailabilityPolicy). Non-instructor actors (students,
     * public users) cannot self-service regardless of the target id.
     */
    private function assertCanCreate(User $actor, User $teacher): void
    {
        if ($this->isSelfService($actor, $teacher->id)) {
            return;
        }

        if (! $actor->can('create', TeacherAvailability::class)) {
            throw new AuthorizationException;
        }
    }

    private function assertCanManage(User $actor, TeacherAvailability $availability, ?User $newTeacher, string $ability): void
    {
        $ownsExisting = $this->isSelfService($actor, $availability->teacher_id);
        $ownsTarget = $newTeacher === null || $this->isSelfService($actor, $newTeacher->id);

        if ($ownsExisting && $ownsTarget) {
            return;
        }

        if (! $actor->can($ability, $availability)) {
            throw new AuthorizationException;
        }
    }

    private function isSelfService(User $actor, int $teacherId): bool
    {
        return $actor->id === $teacherId && $actor->hasRole('instructor');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertNoOverlap(User $teacher, array $payload, ?TeacherAvailability $ignore = null): void
    {
        $query = TeacherAvailability::query()
            ->forTeacher($teacher->id)
            ->where('day_of_week', $payload['day_of_week'])
            ->where('is_active', true)
            ->where('start_time', '<', $payload['end_time'])
            ->where('end_time', '>', $payload['start_time'])
            ->when($ignore, fn (Builder $query): Builder => $query->whereKeyNot($ignore->id));

        if (! (bool) $payload['is_active']) {
            return;
        }

        $effectiveFrom = $payload['effective_from'];
        $effectiveUntil = $payload['effective_until'];

        if ($effectiveFrom !== null) {
            $query->where(fn (Builder $query): Builder => $query
                ->whereNull('effective_until')
                ->orWhere('effective_until', '>=', $effectiveFrom));
        }

        if ($effectiveUntil !== null) {
            $query->where(fn (Builder $query): Builder => $query
                ->whereNull('effective_from')
                ->orWhere('effective_from', '<=', $effectiveUntil));
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'start_time' => 'This window overlaps an existing active availability window.',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function auditProperties(TeacherAvailability $availability): array
    {
        return [
            'teacher_id' => $availability->teacher_id,
            'day_of_week' => $availability->day_of_week?->value,
            'start_time' => $availability->start_time,
            'end_time' => $availability->end_time,
            'timezone' => $availability->timezone,
            'effective_from' => $availability->effective_from?->toDateString(),
            'effective_until' => $availability->effective_until?->toDateString(),
            'is_active' => $availability->is_active,
        ];
    }
}
