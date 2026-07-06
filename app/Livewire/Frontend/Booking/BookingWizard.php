<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Booking;

use App\Booking\Exceptions\BookingException;
use App\Booking\Services\BookingWizardService;
use App\Rules\TurnstileToken;
use App\Settings\BookingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

final class BookingWizard extends Component
{
    public int $step = 1;

    /** @var list<array<string, mixed>> */
    public array $types = [];

    /** @var list<string> */
    public array $subjects = [];

    /** @var list<int> */
    public array $grades = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];

    /** @var list<string> */
    public array $dates = [];

    /** @var list<array<string, mixed>> */
    public array $availableSlots = [];

    public ?string $type = null;

    public ?string $subject = null;

    public ?int $grade = null;

    public string $month = '';

    public ?string $date = null;

    public ?string $selectedSlotStartsAt = null;

    public string $timezone = 'UTC';

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $notes = '';

    public string $website = '';

    public string $cfTurnstileResponse = '';

    public bool $turnstileEnabled = false;

    public ?string $turnstileSiteKey = null;

    public string $banner = '';

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    public ?int $lockedInstructorId = null;

    public ?string $lockedInstructorName = null;

    private BookingWizardService $wizard;

    public function boot(BookingWizardService $wizard): void
    {
        $this->wizard = $wizard;
    }

    public function mount(): void
    {
        $this->timezone = config('app.timezone', 'UTC');
        $this->month = now($this->timezone)->format('Y-m');
        $this->types = $this->wizard->bookingTypes()->all();
        $this->type = collect($this->types)->first()['key'] ?? null;
        $this->subjects = $this->wizard->subjects()->all();

        $requestedType = request()->query('type');
        if (is_string($requestedType) && collect($this->types)->pluck('key')->contains($requestedType)) {
            $this->type = $requestedType;
        }

        $requestedSubject = request()->query('subject');
        if (is_string($requestedSubject) && in_array($requestedSubject, $this->subjects, true)) {
            $this->subject = $requestedSubject;
            $this->step = 2;
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

        $settings = app(BookingSettings::class);
        $this->turnstileEnabled = (bool) $settings->captcha_enabled;
        $this->turnstileSiteKey = $settings->turnstile_site_key;
    }

    public function setTimezone(string $timezone): void
    {
        if (in_array($timezone, timezone_identifiers_list(), true)) {
            $this->timezone = $timezone;
        }
    }

    public function selectSubject(string $subject): void
    {
        $this->subject = $subject;
        $this->grade = null;
        $this->resetAvailability();
        $this->goTo(2);
    }

    public function selectGrade(int $grade): void
    {
        $this->grade = $grade;
        $this->resetAvailability();
        $this->validateSelection(['subject', 'grade']);
        $this->loadDates();
        $this->goTo(3);
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
        $this->goTo(4);
    }

    public function selectSlot(string $startsAt): void
    {
        $this->selectedSlotStartsAt = $startsAt;
        $this->validateSelection(['selectedSlotStartsAt']);
        $this->goTo(5);
    }

    public function review(): void
    {
        $this->validate($this->rulesForStep(5), [], $this->validationAttributes());
        $this->goTo(6);
    }

    public function submit(): void
    {
        $this->banner = '';
        $this->validate($this->rulesForStep(5), [], $this->validationAttributes());

        try {
            $booking = $this->wizard->book([
                'type' => $this->type,
                'subject' => $this->subject,
                'grade' => $this->grade,
                'starts_at' => $this->selectedSlotStartsAt,
                'timezone' => $this->timezone,
                'name' => $this->name,
                'email' => $this->email,
                'phone' => filled($this->phone) ? $this->phone : null,
                'notes' => filled($this->notes) ? $this->notes : null,
                'teacher_id' => $this->lockedInstructorId,
            ]);

            $this->result = $this->wizard->result($booking);
            $this->goTo(7);
        } catch (BookingException $exception) {
            $this->banner = $exception->getMessage();
        }
    }

    public function back(): void
    {
        if ($this->step > 1 && $this->step < 7) {
            $this->step--;
        }
    }

    public function restart(): void
    {
        $defaultType = collect($this->types)->first()['key'] ?? null;

        $this->reset([
            'step',
            'dates',
            'availableSlots',
            'type',
            'subject',
            'grade',
            'date',
            'selectedSlotStartsAt',
            'name',
            'email',
            'phone',
            'notes',
            'website',
            'cfTurnstileResponse',
            'banner',
            'result',
        ]);

        $this->step = 1;
        $this->type = $defaultType;
        $this->month = now($this->timezone)->format('Y-m');
    }

    public function render(): View
    {
        return view('livewire.frontend.booking.booking-wizard', [
            'selectedType' => collect($this->types)->firstWhere('key', $this->type),
            'selectedSlot' => collect($this->availableSlots)->firstWhere('starts_at', $this->selectedSlotStartsAt),
            'calendar' => $this->calendar(),
            'steps' => $this->steps(),
            'canGoPreviousMonth' => $this->monthDate()->greaterThan(now($this->timezone)->startOfMonth()),
            'canGoNextMonth' => $this->monthDate()->lessThan(now($this->timezone)->addDays(90)->startOfMonth()),
        ]);
    }

    /** @param list<string> $fields */
    private function validateSelection(array $fields): void
    {
        $rules = [];

        foreach ($fields as $field) {
            $rules[$field] = $this->rulesForStep(match ($field) {
                'subject' => 1,
                'grade' => 2,
                'date' => 3,
                'selectedSlotStartsAt' => 4,
                default => 6,
            })[$field];
        }

        $this->validate($rules, [], $this->validationAttributes());
    }

    private function loadDates(): void
    {
        $this->banner = '';

        if (! $this->type || ! $this->subject || ! $this->grade) {
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
                ->availableDates($this->type, $this->subject, (int) $this->grade, $from, $to, $this->timezone, $this->lockedInstructorId)
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
                ->availableSlots($this->type, $this->subject, (int) $this->grade, CarbonImmutable::parse($this->date, $this->timezone), $this->timezone, $this->lockedInstructorId)
                ->all();
        } catch (BookingException $exception) {
            $this->availableSlots = [];
            $this->banner = $exception->getMessage();
        }
    }

    /** @return array<string, mixed> */
    private function rulesForStep(int $step): array
    {
        return match ($step) {
            1 => ['subject' => ['required', 'string', Rule::in($this->subjects)]],
            2 => ['grade' => ['required', 'integer', 'min:1', 'max:12']],
            3 => ['date' => ['required', 'date_format:Y-m-d', Rule::in($this->dates)]],
            4 => ['selectedSlotStartsAt' => ['required', 'string', Rule::in(collect($this->availableSlots)->pluck('starts_at')->all())]],
            default => [
                'type' => ['required', 'string', Rule::in(collect($this->types)->pluck('key')->all())],
                'subject' => ['required', 'string', Rule::in($this->subjects)],
                'grade' => ['required', 'integer', 'min:1', 'max:12'],
                'date' => ['required', 'date_format:Y-m-d', Rule::in($this->dates)],
                'selectedSlotStartsAt' => ['required', 'string', Rule::in(collect($this->availableSlots)->pluck('starts_at')->all())],
                'timezone' => ['required', 'timezone'],
                'name' => ['required', 'string', 'min:2', 'max:100'],
                'email' => ['required', 'email:rfc', 'max:255'],
                'phone' => ['nullable', 'string', 'regex:/^[+0-9 ().-]{7,30}$/'],
                'notes' => ['nullable', 'string', 'max:1000'],
                'website' => ['prohibited'],
                'cfTurnstileResponse' => [new TurnstileToken],
            ],
        };
    }

    /** @return array<string, string> */
    private function validationAttributes(): array
    {
        return [
            'type' => 'session type',
            'selectedSlotStartsAt' => 'available slot',
            'cfTurnstileResponse' => 'security check',
            'name' => 'full name',
        ];
    }

    private function goTo(int $step): void
    {
        $this->step = $step;
    }

    private function resetAvailability(): void
    {
        $this->dates = [];
        $this->availableSlots = [];
        $this->date = null;
        $this->selectedSlotStartsAt = null;
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

    /** @return list<array{number: int, label: string}> */
    private function steps(): array
    {
        return [
            ['number' => 1, 'label' => 'Subject'],
            ['number' => 2, 'label' => 'Grade'],
            ['number' => 3, 'label' => 'Date'],
            ['number' => 4, 'label' => 'Available Slots'],
            ['number' => 5, 'label' => 'Student Details'],
            ['number' => 6, 'label' => 'Review'],
            ['number' => 7, 'label' => 'Success'],
        ];
    }
}
