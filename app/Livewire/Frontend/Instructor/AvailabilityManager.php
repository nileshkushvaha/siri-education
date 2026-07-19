<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Instructor;

use App\Booking\Enums\Weekday;
use App\Enums\InstructorStatus;
use App\Exceptions\Instructor\AvailabilityChangeRequiresConfirmationException;
use App\Models\TeacherAvailability;
use App\Models\TeacherUnavailability;
use App\Services\Instructor\InstructorAvailabilityService;
use App\Services\Instructor\InstructorTimeOffService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

final class AvailabilityManager extends Component
{
    /** @var array<int, array{id:string, day:string, time:string, timezone:string, active:bool, effective:string}> */
    public array $windows = [];

    /** @var array<int, array{id:string, range:string, timezone:string, reason:?string}> */
    public array $timeOff = [];

    /** @var array<int, string> */
    public array $weekdays = [];

    public ?string $timezone = null;

    public bool $hasProfileTimezone = true;

    /** Phase 23M — read-only status banner; the Vacation Mode page is the single owner of the toggle. */
    public bool $isOnVacation = false;

    public int $dayOfWeek = 1;

    public string $startTime = '09:00';

    public string $endTime = '17:00';

    public ?string $effectiveFrom = null;

    public ?string $effectiveUntil = null;

    public ?string $timeOffStartsAt = null;

    public ?string $timeOffEndsAt = null;

    public ?string $timeOffReason = null;

    /**
     * Phase 24I — GAP-019: pending impact-confirmation state. When a
     * reduction affects confirmed upcoming lessons, the first submission
     * changes nothing and this warning state is populated instead:
     * the action to re-run, the safe lesson summaries, and the opaque
     * impact fingerprint required as explicit acknowledgment.
     *
     * @var array{action: string, arguments: array<string, mixed>, count: int, summaries: array<int, array{reference: string, starts_at: string}>, token: string}|null
     */
    public ?array $pendingImpact = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasRole('instructor'), 403);

        $profileTimezone = auth()->user()->profile?->timezone;
        $this->hasProfileTimezone = filled($profileTimezone);
        $this->isOnVacation = auth()->user()->profile?->instructor_status === InstructorStatus::Vacation;
        // Missing profile timezone: leave blank so the instructor must
        // explicitly choose one — never silently publish on the app timezone.
        $this->timezone = $profileTimezone;
        $this->weekdays = collect(Weekday::cases())
            ->mapWithKeys(fn (Weekday $day): array => [$day->value => $day->label()])
            ->all();

        $this->refreshRows();
    }

    public function addWindow(): void
    {
        $this->validate([
            'dayOfWeek' => ['required', 'integer', Rule::in(array_keys($this->weekdays))],
            'startTime' => ['required', 'date_format:H:i'],
            'endTime' => ['required', 'date_format:H:i', 'after:startTime'],
            'timezone' => ['required', 'timezone:all'],
            'effectiveFrom' => ['nullable', 'date'],
            'effectiveUntil' => ['nullable', 'date', 'after_or_equal:effectiveFrom'],
        ]);

        app(InstructorAvailabilityService::class)->create([
            'teacher_id' => auth()->id(),
            'day_of_week' => $this->dayOfWeek,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'timezone' => $this->timezone,
            'effective_from' => $this->effectiveFrom,
            'effective_until' => $this->effectiveUntil,
            'is_active' => true,
        ], auth()->user());

        $this->reset(['effectiveFrom', 'effectiveUntil']);
        $this->dispatch('notify', type: 'success', message: 'Availability window added.');
        $this->refreshRows();
    }

    public function toggleWindow(string $id, ?string $impactConfirmation = null): void
    {
        $window = TeacherAvailability::query()
            ->where('teacher_id', auth()->id())
            ->findOrFail($id);

        try {
            app(InstructorAvailabilityService::class)->setActive($window, ! $window->is_active, auth()->user(), $impactConfirmation);
        } catch (AvailabilityChangeRequiresConfirmationException $exception) {
            $this->capturePendingImpact('toggleWindow', ['id' => $id], $exception);

            return;
        }

        $this->pendingImpact = null;
        $this->refreshRows();
    }

    public function deleteWindow(string $id, ?string $impactConfirmation = null): void
    {
        $window = TeacherAvailability::query()
            ->where('teacher_id', auth()->id())
            ->findOrFail($id);

        try {
            app(InstructorAvailabilityService::class)->delete($window, auth()->user(), $impactConfirmation);
        } catch (AvailabilityChangeRequiresConfirmationException $exception) {
            $this->capturePendingImpact('deleteWindow', ['id' => $id], $exception);

            return;
        }

        $this->pendingImpact = null;
        $this->dispatch('notify', type: 'success', message: 'Availability window removed.');
        $this->refreshRows();
    }

    /** Explicit acknowledgment: re-runs the pending action carrying the impact fingerprint. A stale fingerprint simply re-surfaces a refreshed warning. */
    public function confirmPendingImpact(): void
    {
        if ($this->pendingImpact === null) {
            return;
        }

        $pending = $this->pendingImpact;

        match ($pending['action']) {
            'toggleWindow' => $this->toggleWindow($pending['arguments']['id'], $pending['token']),
            'deleteWindow' => $this->deleteWindow($pending['arguments']['id'], $pending['token']),
            'addTimeOff' => $this->addTimeOff($pending['token']),
            default => null,
        };
    }

    public function cancelPendingImpact(): void
    {
        $this->pendingImpact = null;
    }

    private function capturePendingImpact(string $action, array $arguments, AvailabilityChangeRequiresConfirmationException $exception): void
    {
        $this->pendingImpact = [
            'action' => $action,
            'arguments' => $arguments,
            'count' => $exception->impact->affectedCount,
            'summaries' => $exception->impact->affectedSummaries,
            'token' => $exception->impact->fingerprint,
        ];
    }

    public function addTimeOff(?string $impactConfirmation = null): void
    {
        $this->validate([
            'timeOffStartsAt' => ['required', 'date'],
            'timeOffEndsAt' => ['required', 'date', 'after:timeOffStartsAt'],
            'timezone' => ['required', 'timezone:all'],
            'timeOffReason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            app(InstructorTimeOffService::class)->create([
                'teacher_id' => auth()->id(),
                'starts_at' => $this->timeOffStartsAt,
                'ends_at' => $this->timeOffEndsAt,
                'timezone' => $this->timezone,
                'reason' => $this->timeOffReason,
            ], auth()->user(), $impactConfirmation);
        } catch (AvailabilityChangeRequiresConfirmationException $exception) {
            // Keep the proposed form values so the acknowledged proposal
            // is exactly what gets re-submitted.
            $this->capturePendingImpact('addTimeOff', [], $exception);

            return;
        }

        $this->pendingImpact = null;
        $this->reset(['timeOffStartsAt', 'timeOffEndsAt', 'timeOffReason']);
        $this->dispatch('notify', type: 'success', message: 'Time off added.');
        $this->refreshRows();
    }

    public function deleteTimeOff(string $id): void
    {
        $leave = TeacherUnavailability::query()
            ->where('teacher_id', auth()->id())
            ->findOrFail($id);

        app(InstructorTimeOffService::class)->delete($leave, auth()->user());

        $this->dispatch('notify', type: 'success', message: 'Time off removed.');
        $this->refreshRows();
    }

    public function render(): View
    {
        return view('livewire.frontend.instructor.availability-manager');
    }

    private function refreshRows(): void
    {
        $this->windows = TeacherAvailability::query()
            ->where('teacher_id', auth()->id())
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get()
            ->map(fn (TeacherAvailability $window): array => [
                'id' => $window->id,
                'day' => $window->day_of_week->label(),
                'time' => substr((string) $window->start_time, 0, 5).' - '.substr((string) $window->end_time, 0, 5),
                'timezone' => $window->timezone ?: $this->timezone,
                'active' => (bool) $window->is_active,
                'effective' => collect([
                    $window->effective_from?->toDateString(),
                    $window->effective_until?->toDateString(),
                ])->filter()->join(' to ') ?: 'Always',
            ])
            ->all();

        $this->timeOff = TeacherUnavailability::query()
            ->where('teacher_id', auth()->id())
            ->where('ends_at', '>=', now()->subDay())
            ->orderBy('starts_at')
            ->get()
            ->map(fn (TeacherUnavailability $leave): array => [
                'id' => $leave->id,
                'range' => $leave->starts_at->timezone($leave->timezone ?: $this->timezone)->format('M j, Y g:i A')
                    .' - '.$leave->ends_at->timezone($leave->timezone ?: $this->timezone)->format('M j, Y g:i A'),
                'timezone' => $leave->timezone ?: $this->timezone,
                'reason' => $leave->reason,
            ])
            ->all();
    }
}
