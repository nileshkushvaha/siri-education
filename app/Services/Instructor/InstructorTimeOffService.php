<?php

declare(strict_types=1);

namespace App\Services\Instructor;

use App\Models\TeacherUnavailability;
use App\Models\User;
use App\Services\AuditTrailService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InstructorTimeOffService
{
    public function __construct(
        private readonly AuditTrailService $auditTrail,
    ) {}

    /**
     * @param  array{
     *     teacher_id:int,
     *     starts_at:string|\DateTimeInterface,
     *     ends_at:string|\DateTimeInterface,
     *     timezone?:string|null,
     *     reason?:string|null
     * }  $data
     */
    public function create(array $data, User $actor): TeacherUnavailability
    {
        return DB::transaction(function () use ($data, $actor): TeacherUnavailability {
            $teacher = $this->teacher((int) $data['teacher_id']);
            $payload = $this->payload($data, $teacher, $actor);

            $this->assertValidRange($payload['starts_at'], $payload['ends_at']);

            $leave = TeacherUnavailability::query()->create($payload);

            $this->auditTrail->logUser(
                $actor,
                'teacher_unavailability',
                'created',
                'Instructor time off created.',
                $leave,
                $this->auditProperties($leave),
            );

            return $leave;
        });
    }

    /**
     * @param  array{
     *     teacher_id?:int,
     *     starts_at?:string|\DateTimeInterface,
     *     ends_at?:string|\DateTimeInterface,
     *     timezone?:string|null,
     *     reason?:string|null
     * }  $data
     */
    public function update(TeacherUnavailability $leave, array $data, User $actor): TeacherUnavailability
    {
        return DB::transaction(function () use ($leave, $data, $actor): TeacherUnavailability {
            $teacher = $this->teacher((int) ($data['teacher_id'] ?? $leave->teacher_id));
            $payload = $this->payload([
                'teacher_id' => $teacher->id,
                'starts_at' => $data['starts_at'] ?? $leave->starts_at,
                'ends_at' => $data['ends_at'] ?? $leave->ends_at,
                'timezone' => $data['timezone'] ?? $leave->timezone,
                'reason' => array_key_exists('reason', $data) ? $data['reason'] : $leave->reason,
            ], $teacher, $actor);

            $this->assertValidRange($payload['starts_at'], $payload['ends_at']);

            $previous = $leave->only(['teacher_id', 'starts_at', 'ends_at', 'timezone', 'reason']);
            $leave->forceFill([
                ...$payload,
                'created_by' => $leave->created_by,
            ])->save();

            $this->auditTrail->logUser(
                $actor,
                'teacher_unavailability',
                'updated',
                'Instructor time off updated.',
                $leave,
                ['previous' => $previous, 'current' => $this->auditProperties($leave)],
            );

            return $leave->refresh();
        });
    }

    public function delete(TeacherUnavailability $leave, User $actor): void
    {
        DB::transaction(function () use ($leave, $actor): void {
            $properties = $this->auditProperties($leave);
            $leave->delete();

            $this->auditTrail->logUser(
                $actor,
                'teacher_unavailability',
                'deleted',
                'Instructor time off deleted.',
                $leave,
                $properties,
            );
        });
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
            'starts_at' => CarbonImmutable::parse($data['starts_at'], $timezone)->utc(),
            'ends_at' => CarbonImmutable::parse($data['ends_at'], $timezone)->utc(),
            'timezone' => $timezone,
            'reason' => filled($data['reason'] ?? null) ? (string) $data['reason'] : null,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ];
    }

    private function timezone(?string $timezone, User $teacher): string
    {
        $timezone = $timezone ?: $teacher->profile?->timezone ?: config('app.timezone', 'UTC');

        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            throw ValidationException::withMessages(['timezone' => 'Select a valid timezone.']);
        }

        return $timezone;
    }

    private function assertValidRange(CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
        if ($startsAt->greaterThanOrEqualTo($endsAt)) {
            throw ValidationException::withMessages(['ends_at' => 'End time must be after start time.']);
        }
    }

    /** @return array<string, mixed> */
    private function auditProperties(TeacherUnavailability $leave): array
    {
        return [
            'teacher_id' => $leave->teacher_id,
            'starts_at' => $leave->starts_at?->toIso8601String(),
            'ends_at' => $leave->ends_at?->toIso8601String(),
            'timezone' => $leave->timezone,
            'reason_present' => filled($leave->reason),
        ];
    }
}
