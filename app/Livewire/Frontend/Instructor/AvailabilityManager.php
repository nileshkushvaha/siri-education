<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Instructor;

use App\Booking\Enums\Weekday;
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

    public int $dayOfWeek = 1;

    public string $startTime = '09:00';

    public string $endTime = '17:00';

    public ?string $effectiveFrom = null;

    public ?string $effectiveUntil = null;

    public ?string $timeOffStartsAt = null;

    public ?string $timeOffEndsAt = null;

    public ?string $timeOffReason = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasRole('instructor'), 403);

        $profileTimezone = auth()->user()->profile?->timezone;
        $this->hasProfileTimezone = filled($profileTimezone);
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

    public function toggleWindow(string $id): void
    {
        $window = TeacherAvailability::query()
            ->where('teacher_id', auth()->id())
            ->findOrFail($id);

        app(InstructorAvailabilityService::class)->setActive($window, ! $window->is_active, auth()->user());

        $this->refreshRows();
    }

    public function deleteWindow(string $id): void
    {
        $window = TeacherAvailability::query()
            ->where('teacher_id', auth()->id())
            ->findOrFail($id);

        app(InstructorAvailabilityService::class)->delete($window, auth()->user());

        $this->dispatch('notify', type: 'success', message: 'Availability window removed.');
        $this->refreshRows();
    }

    public function addTimeOff(): void
    {
        $this->validate([
            'timeOffStartsAt' => ['required', 'date'],
            'timeOffEndsAt' => ['required', 'date', 'after:timeOffStartsAt'],
            'timezone' => ['required', 'timezone:all'],
            'timeOffReason' => ['nullable', 'string', 'max:255'],
        ]);

        app(InstructorTimeOffService::class)->create([
            'teacher_id' => auth()->id(),
            'starts_at' => $this->timeOffStartsAt,
            'ends_at' => $this->timeOffEndsAt,
            'timezone' => $this->timezone,
            'reason' => $this->timeOffReason,
        ], auth()->user());

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
