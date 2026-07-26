<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\MeetingStatus;
use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Pages\Concerns\ExportsReportCsv;
use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Enums\LessonStatus;
use App\Models\Country;
use App\Models\Subject;
use App\Reporting\Contracts\BookingLessonMeetingOperationsReportServiceInterface;
use App\Reporting\Contracts\ReportAccessContextInterface;
use App\Reporting\Contracts\ReportRegistryInterface;
use App\Reporting\DTOs\Operations\BookingOperationsSummaryData;
use App\Reporting\DTOs\Operations\LabeledCountRow;
use App\Reporting\DTOs\Operations\LessonOutcomeSummaryData;
use App\Reporting\DTOs\Operations\MeetingOperationsSummaryData;
use App\Reporting\DTOs\Operations\OperationsReportFreshnessData;
use App\Reporting\Enums\ReportingBookingType;
use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\Support\ReportingTimezoneResolver;
use App\Reporting\ValueObjects\ReportingPeriod;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The Booking, Lesson & Meeting Operations report (SRS Chapter 19:
 * Operations Dashboard / Booking Report / Meeting
 * Report / No-Show Report). Every figure is delegated to
 * {@see BookingLessonMeetingOperationsReportServiceInterface} — this
 * page holds only filter state and never queries a source domain
 * directly. Deliberately separate from the existing `BookingReports`
 * page (revenue/conversion KPIs, a different — and financial —
 * concern out of scope here) and from `ReviewsQualityDashboard`.
 */
class BookingLessonMeetingOperations extends Page
{
    use ExportsReportCsv;
    use HasCentralizedNavigation;

    protected string $view = 'filament.pages.booking-lesson-meeting-operations';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Booking, Lesson & Meeting Operations';

    protected static ?string $title = 'Booking, Lesson & Meeting Operations';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 3;

    public string $periodPreset = 'last_30_days';

    public ?string $customStart = null;

    public ?string $customEnd = null;

    // Livewire hydrates <select>/<input> values as strings — typed ?int
    // properties throw on assignment ("Cannot assign string to property").
    // Kept as ?string here; cast once, in filters(), never in the view.
    public ?string $countryId = null;

    public ?string $subjectId = null;

    public ?string $instructorId = null;

    public ?string $bookingType = null;

    public ?string $bookingStatus = null;

    public ?string $lessonStatus = null;

    public ?string $lessonOutcome = null;

    public ?string $meetingStatus = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        $definition = app(ReportRegistryInterface::class)->find('booking_lesson_meeting_operations');

        return $definition !== null && app(ReportAccessContextInterface::class)->canView($user, $definition);
    }

    public function resetFilters(): void
    {
        $this->reset([
            'customStart', 'customEnd', 'countryId', 'subjectId', 'instructorId',
            'bookingType', 'bookingStatus', 'lessonStatus', 'lessonOutcome', 'meetingStatus',
        ]);
        $this->periodPreset = 'last_30_days';
    }

    public function period(): ReportingPeriod
    {
        $preset = ReportingPeriodPreset::tryFrom($this->periodPreset) ?? ReportingPeriodPreset::Last30Days;
        $timezone = ReportingTimezoneResolver::resolve();

        if ($preset === ReportingPeriodPreset::Custom && filled($this->customStart) && filled($this->customEnd)) {
            return ReportingPeriod::custom($this->customStart, $this->customEnd, $timezone);
        }

        return ReportingPeriod::forPreset($preset === ReportingPeriodPreset::Custom ? ReportingPeriodPreset::Last30Days : $preset, $timezone);
    }

    private function filters(): ReportFilters
    {
        return new ReportFilters(
            period: $this->period(),
            countryId: $this->intOrNull($this->countryId),
            subjectId: filled($this->subjectId) ? $this->subjectId : null, // subjects use UUID ids
            instructorId: $this->intOrNull($this->instructorId),
            bookingType: filled($this->bookingType) ? ReportingBookingType::tryFrom($this->bookingType) : null,
            bookingStatus: filled($this->bookingStatus) ? BookingStatus::tryFrom($this->bookingStatus) : null,
            lessonStatus: filled($this->lessonStatus) ? LessonStatus::tryFrom($this->lessonStatus) : null,
            lessonOutcome: filled($this->lessonOutcome) ? LessonOutcome::tryFrom($this->lessonOutcome) : null,
            meetingStatus: filled($this->meetingStatus) ? MeetingStatus::tryFrom($this->meetingStatus) : null,
        );
    }

    /** Select/number inputs hydrate as strings ('' when cleared) — normalize to a real id or null. */
    private function intOrNull(?string $value): ?int
    {
        return filled($value) && is_numeric($value) ? (int) $value : null;
    }

    public function canViewBookingLessonSection(): bool
    {
        return app(BookingLessonMeetingOperationsReportServiceInterface::class)->canViewBookingLessonSection(auth()->user());
    }

    public function canViewMeetingSection(): bool
    {
        return app(BookingLessonMeetingOperationsReportServiceInterface::class)->canViewMeetingSection(auth()->user());
    }

    public function bookingSummary(): ?BookingOperationsSummaryData
    {
        return $this->canViewBookingLessonSection()
            ? app(BookingLessonMeetingOperationsReportServiceInterface::class)->bookingSummary(auth()->user(), $this->period(), $this->filters())
            : null;
    }

    public function lessonSummary(): ?LessonOutcomeSummaryData
    {
        return $this->canViewBookingLessonSection()
            ? app(BookingLessonMeetingOperationsReportServiceInterface::class)->lessonOutcomeSummary(auth()->user(), $this->period(), $this->filters())
            : null;
    }

    public function meetingSummary(): ?MeetingOperationsSummaryData
    {
        return $this->canViewMeetingSection()
            ? app(BookingLessonMeetingOperationsReportServiceInterface::class)->meetingSummary(auth()->user(), $this->period(), $this->filters())
            : null;
    }

    /** @return LabeledCountRow[] */
    public function bySubject(): array
    {
        return $this->canViewBookingLessonSection()
            ? app(BookingLessonMeetingOperationsReportServiceInterface::class)->bookingsBySubject(auth()->user(), $this->period(), $this->filters())
            : [];
    }

    /** @return LabeledCountRow[] */
    public function byInstructor(): array
    {
        return $this->canViewBookingLessonSection()
            ? app(BookingLessonMeetingOperationsReportServiceInterface::class)->bookingsByInstructor(auth()->user(), $this->period(), $this->filters())
            : [];
    }

    /** @return LabeledCountRow[] */
    public function byCountry(): array
    {
        return $this->canViewBookingLessonSection()
            ? app(BookingLessonMeetingOperationsReportServiceInterface::class)->bookingsByCountry(auth()->user(), $this->period(), $this->filters())
            : [];
    }

    /** @return LabeledCountRow[] */
    public function byDuration(): array
    {
        return $this->canViewBookingLessonSection()
            ? app(BookingLessonMeetingOperationsReportServiceInterface::class)->bookingsByDuration(auth()->user(), $this->period(), $this->filters())
            : [];
    }

    public function lessonsInPeriod(): ?LengthAwarePaginator
    {
        return $this->canViewBookingLessonSection()
            ? app(BookingLessonMeetingOperationsReportServiceInterface::class)->lessonsInPeriod(auth()->user(), $this->period(), $this->filters(), 10)
            : null;
    }

    public function meetingIssues(): ?LengthAwarePaginator
    {
        return $this->canViewMeetingSection()
            ? app(BookingLessonMeetingOperationsReportServiceInterface::class)->meetingIssues(auth()->user(), $this->period(), $this->filters(), 10)
            : null;
    }

    public function noShowAndTechnicalIssues(): ?LengthAwarePaginator
    {
        return $this->canViewBookingLessonSection()
            ? app(BookingLessonMeetingOperationsReportServiceInterface::class)->noShowAndTechnicalIssueLessons(auth()->user(), $this->period(), $this->filters(), 10)
            : null;
    }

    public function freshness(): OperationsReportFreshnessData
    {
        return app(BookingLessonMeetingOperationsReportServiceInterface::class)->freshness($this->period());
    }

    /** @return Collection<int, ReportingPeriodPreset> */
    public function periodPresets(): Collection
    {
        return collect(ReportingPeriodPreset::cases());
    }

    /** @return Collection<int, BookingStatus> */
    public function bookingStatusOptions(): Collection
    {
        return collect(BookingStatus::cases());
    }

    /** @return Collection<int, LessonStatus> */
    public function lessonStatusOptions(): Collection
    {
        return collect(LessonStatus::cases());
    }

    /** @return Collection<int, LessonOutcome> */
    public function lessonOutcomeOptions(): Collection
    {
        return collect(LessonOutcome::cases())->reject(fn (LessonOutcome $o) => $o === LessonOutcome::Pending);
    }

    /** @return Collection<int, MeetingStatus> */
    public function meetingStatusOptions(): Collection
    {
        return collect(MeetingStatus::cases());
    }

    /** @return Collection<int, ReportingBookingType> */
    public function bookingTypeOptions(): Collection
    {
        return collect(ReportingBookingType::cases());
    }

    /**
     * Bounded, searchable — never every country/subject loaded for
     * every render; only the handful actually configured (small,
     * finite reference tables in this application).
     *
     * @return Collection<int, Country>
     */
    public function countryOptions(): Collection
    {
        return Country::query()->orderBy('name')->get(['id', 'name']);
    }

    /** @return Collection<int, Subject> */
    public function subjectOptions(): Collection
    {
        return Subject::query()->orderBy('name')->get(['id', 'name']);
    }
}
