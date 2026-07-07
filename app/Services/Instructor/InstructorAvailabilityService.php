<?php

declare(strict_types=1);

namespace App\Services\Instructor;

use App\Booking\Enums\Weekday;
use App\Enums\InstructorStatus;
use App\Models\TeacherAvailability;
use App\Models\User;
use App\Services\AuditTrailService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InstructorAvailabilityService
{
    public function __construct(
        private readonly AuditTrailService $auditTrail,
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
        return DB::transaction(function () use ($data, $actor): TeacherAvailability {
            $teacher = $this->teacher((int) $data['teacher_id']);
            $payload = $this->payload($data, $teacher, $actor);

            $this->assertCanPublish($teacher, (bool) $payload['is_active']);
            $this->assertValidTimeRange($payload['start_time'], $payload['end_time']);
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
    public function update(TeacherAvailability $availability, array $data, User $actor): TeacherAvailability
    {
        return DB::transaction(function () use ($availability, $data, $actor): TeacherAvailability {
            $teacher = $this->teacher((int) ($data['teacher_id'] ?? $availability->teacher_id));
            $payload = $this->payload([
                'teacher_id' => $teacher->id,
                'day_of_week' => $data['day_of_week'] ?? $availability->day_of_week,
                'start_time' => $data['start_time'] ?? $availability->start_time,
                'end_time' => $data['end_time'] ?? $availability->end_time,
                'timezone' => $data['timezone'] ?? $availability->timezone,
                'effective_from' => array_key_exists('effective_from', $data) ? $data['effective_from'] : $availability->effective_from?->toDateString(),
                'effective_until' => array_key_exists('effective_until', $data) ? $data['effective_until'] : $availability->effective_until?->toDateString(),
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : (bool) $availability->is_active,
            ], $teacher, $actor);

            $this->assertCanPublish($teacher, (bool) $payload['is_active']);
            $this->assertValidTimeRange($payload['start_time'], $payload['end_time']);
            $this->assertNoOverlap($teacher, $payload, $availability);

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
                ['previous' => $previous, 'current' => $this->auditProperties($availability)],
            );

            return $availability->refresh();
        });
    }

    public function delete(TeacherAvailability $availability, User $actor): void
    {
        DB::transaction(function () use ($availability, $actor): void {
            $properties = $this->auditProperties($availability);
            $availability->delete();

            $this->auditTrail->logUser(
                $actor,
                'teacher_availability',
                'deleted',
                'Instructor availability window deleted.',
                $availability,
                $properties,
            );
        });
    }

    public function setActive(TeacherAvailability $availability, bool $active, User $actor): TeacherAvailability
    {
        return $this->update($availability, ['is_active' => $active], $actor);
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
