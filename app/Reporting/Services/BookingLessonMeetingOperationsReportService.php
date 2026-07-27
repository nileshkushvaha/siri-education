<?php

declare(strict_types=1);

namespace App\Reporting\Services;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use App\Models\Lesson;
use App\Models\User;
use App\Reporting\Contracts\BookingLessonMeetingOperationsReportServiceInterface;
use App\Reporting\Contracts\ReportAccessContextInterface;
use App\Reporting\Contracts\ReportRegistryInterface;
use App\Reporting\DTOs\Operations\ActionableLessonRow;
use App\Reporting\DTOs\Operations\BookingOperationsSummaryData;
use App\Reporting\DTOs\Operations\LessonOutcomeSummaryData;
use App\Reporting\DTOs\Operations\MeetingIssueRow;
use App\Reporting\DTOs\Operations\MeetingOperationsSummaryData;
use App\Reporting\DTOs\Operations\NoShowTechnicalIssueRow;
use App\Reporting\DTOs\Operations\OperationsReportFreshnessData;
use App\Reporting\Enums\ReportDataFreshness;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\Repositories\BookingOperationsRepository;
use App\Reporting\Repositories\LessonOperationsRepository;
use App\Reporting\Repositories\MeetingOperationsRepository;
use App\Reporting\ValueObjects\ReportingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Every public method re-authorizes fresh —
 * a caller must never be able to skip a check by reaching a method
 * directly instead of going through the Filament page. Meeting data is
 * gated by an ADDITIONAL, separate permission on top of base
 * operational/booking-lesson access (never implied by it).
 */
final class BookingLessonMeetingOperationsReportService implements BookingLessonMeetingOperationsReportServiceInterface
{
    private const string REPORT_KEY = 'booking_lesson_meeting_operations';

    public function __construct(
        private readonly BookingOperationsRepository $bookings,
        private readonly LessonOperationsRepository $lessons,
        private readonly MeetingOperationsRepository $meetings,
        private readonly ReportAccessContextInterface $access,
        private readonly ReportRegistryInterface $registry,
    ) {}

    public function bookingSummary(User $user, ReportingPeriod $period, ReportFilters $filters): BookingOperationsSummaryData
    {
        $this->authorizeBookingLesson($user);

        return $this->bookings->summary($period, $this->restrict($filters));
    }

    public function lessonOutcomeSummary(User $user, ReportingPeriod $period, ReportFilters $filters): LessonOutcomeSummaryData
    {
        $this->authorizeBookingLesson($user);

        return $this->lessons->summary($period, $this->restrict($filters));
    }

    public function meetingSummary(User $user, ReportingPeriod $period, ReportFilters $filters): MeetingOperationsSummaryData
    {
        $this->authorizeMeeting($user);

        return $this->meetings->summary($period, $this->restrict($filters));
    }

    public function bookingsBySubject(User $user, ReportingPeriod $period, ReportFilters $filters): array
    {
        $this->authorizeBookingLesson($user);

        return $this->bookings->bySubject($period, $this->restrict($filters));
    }

    public function bookingsByInstructor(User $user, ReportingPeriod $period, ReportFilters $filters): array
    {
        $this->authorizeBookingLesson($user);

        return $this->bookings->byInstructor($period, $this->restrict($filters));
    }

    public function bookingsByCountry(User $user, ReportingPeriod $period, ReportFilters $filters): array
    {
        $this->authorizeBookingLesson($user);

        return $this->bookings->byCountry($period, $this->restrict($filters));
    }

    public function bookingsByDuration(User $user, ReportingPeriod $period, ReportFilters $filters): array
    {
        $this->authorizeBookingLesson($user);

        return $this->bookings->byDuration($period, $this->restrict($filters));
    }

    public function lessonsInPeriod(User $user, ReportingPeriod $period, ReportFilters $filters, int $perPage = 25): LengthAwarePaginator
    {
        $this->authorizeBookingLesson($user);

        $canViewFullIdentity = $this->access->canViewFullStudentIdentity($user);

        return $this->lessons
            ->paginatedLessonsInPeriod($period, $this->restrict($filters), $perPage)
            ->through(fn (Lesson $lesson): ActionableLessonRow => new ActionableLessonRow(
                bookingId: $lesson->booking_id,
                lessonId: $lesson->id,
                bookingReference: $lesson->booking->reference,
                scheduledAtUtc: $lesson->starts_at->utc(),
                bookingTypeLabel: $lesson->booking->type->name,
                studentLabel: $this->studentLabel($lesson->student, $canViewFullIdentity),
                instructorLabel: $lesson->instructor->full_name,
                subjectLabel: $lesson->subject?->name,
                bookingStatusLabel: $lesson->booking->status->label(),
                lessonStatusLabel: $lesson->status->label(),
                lessonOutcomeLabel: $lesson->outcome->label(),
                meetingStatusLabel: $lesson->booking->meeting?->status->label(),
                bookingViewUrl: $this->bookingViewUrl($user, $lesson->booking),
                lessonViewUrl: $this->lessonViewUrl($user, $lesson),
            ));
    }

    public function meetingIssues(User $user, ReportingPeriod $period, ReportFilters $filters, int $perPage = 25): LengthAwarePaginator
    {
        $this->authorizeMeeting($user);

        $canViewFullIdentity = $this->access->canViewFullStudentIdentity($user);

        return $this->meetings
            ->paginatedMeetingIssues($period, $this->restrict($filters), $perPage)
            ->through(fn (Booking $booking): MeetingIssueRow => new MeetingIssueRow(
                bookingId: $booking->id,
                bookingReference: $booking->reference,
                scheduledAtUtc: $booking->starts_at->utc(),
                instructorLabel: $booking->instructor->full_name,
                studentLabel: $this->studentLabel($booking->student, $canViewFullIdentity),
                issueLabel: $booking->meeting === null ? 'Meeting missing' : 'Meeting creation failed',
                meetingStatusLabel: $booking->meeting?->status->label(),
                bookingViewUrl: $this->bookingViewUrl($user, $booking),
            ));
    }

    public function noShowAndTechnicalIssueLessons(User $user, ReportingPeriod $period, ReportFilters $filters, int $perPage = 25): LengthAwarePaginator
    {
        $this->authorizeBookingLesson($user);

        $canViewFullIdentity = $this->access->canViewFullStudentIdentity($user);

        return $this->lessons
            ->paginatedNoShowAndTechnicalIssueLessons($period, $this->restrict($filters), $perPage)
            ->through(fn (Lesson $lesson): NoShowTechnicalIssueRow => new NoShowTechnicalIssueRow(
                lessonId: $lesson->id,
                bookingId: $lesson->booking_id,
                scheduledAtUtc: $lesson->starts_at->utc(),
                studentLabel: $this->studentLabel($lesson->student, $canViewFullIdentity),
                instructorLabel: $lesson->instructor->full_name,
                subjectLabel: $lesson->subject?->name,
                outcomeLabel: $lesson->outcome->label(),
                lessonStatusLabel: $lesson->status->label(),
                lessonViewUrl: $this->lessonViewUrl($user, $lesson),
                bookingViewUrl: $this->bookingViewUrl($user, $lesson->booking),
            ));
    }

    public function freshness(ReportingPeriod $period): OperationsReportFreshnessData
    {
        return new OperationsReportFreshnessData(
            freshness: ReportDataFreshness::Live,
            generatedAt: CarbonImmutable::now(),
            reportingTimezone: $period->timezone,
            periodLabel: $period->label,
        );
    }

    public function canViewBookingLessonSection(User $user): bool
    {
        try {
            $this->authorizeBookingLesson($user);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    public function canViewMeetingSection(User $user): bool
    {
        try {
            $this->authorizeMeeting($user);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function restrict(ReportFilters $filters): ReportFilters
    {
        $definition = $this->registry->find(self::REPORT_KEY);

        return $filters->restrictedTo($definition?->supportedFilters ?? []);
    }

    /** @throws AuthorizationException */
    private function authorizeBookingLesson(User $user): void
    {
        $definition = $this->registry->find(self::REPORT_KEY);

        if ($definition === null || ! $this->access->canView($user, $definition)) {
            throw new AuthorizationException('You may not view booking and lesson operations reporting.');
        }

        if (! $this->hasPermission($user, 'ViewBookingLessonReports')) {
            throw new AuthorizationException('You may not view booking and lesson operations reporting.');
        }
    }

    /** @throws AuthorizationException */
    private function authorizeMeeting(User $user): void
    {
        $this->authorizeBookingLesson($user);

        if (! $this->hasPermission($user, 'ViewMeetingReports')) {
            throw new AuthorizationException('You may not view meeting operations reporting.');
        }
    }

    private function hasPermission(User $user, string $permission): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        try {
            return $user->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    /** Never a hidden field in serialized state — computed fresh, never stored. */
    private function studentLabel(User $student, bool $canViewFullIdentity): string
    {
        if ($canViewFullIdentity) {
            return $student->full_name;
        }

        $first = trim((string) $student->first_name);

        return $first === '' ? 'Student' : mb_substr($first, 0, 1).'***';
    }

    /**
     * `BookingResource` currently exposes no read-only "view" page —
     * only `edit`. Gating on the `update` ability (rather than `view`)
     * means the link only ever renders when the destination will
     * actually admit the user, never a link that then 403s.
     */
    private function bookingViewUrl(User $user, Booking $booking): ?string
    {
        if (! Gate::forUser($user)->allows('update', $booking)) {
            return null;
        }

        return BookingResource::getUrl('edit', ['record' => $booking]);
    }

    /**
     * `LessonResource` currently exposes no per-record admin page at
     * all (list only) — there is nothing to link to, so this always
     * returns null rather than fabricate a broken URL.
     */
    private function lessonViewUrl(User $user, Lesson $lesson): ?string
    {
        return null;
    }
}
