<?php

declare(strict_types=1);

namespace App\Reporting\Services;

use App\Enums\StudentStatus;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Reporting\Contracts\ReportAccessContextInterface;
use App\Reporting\Contracts\ReportRegistryInterface;
use App\Reporting\Contracts\StudentEngagementReportServiceInterface;
use App\Reporting\DTOs\Engagement\StudentEngagementRow;
use App\Reporting\DTOs\Engagement\StudentEngagementSummaryData;
use App\Reporting\DTOs\Operations\OperationsReportFreshnessData;
use App\Reporting\Enums\ReportDataFreshness;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\Repositories\StudentEngagementRepository;
use App\Reporting\ValueObjects\ReportingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/** See the interface for the contract. */
final class StudentEngagementReportService implements StudentEngagementReportServiceInterface
{
    private const string REPORT_KEY = 'student_engagement';

    public function __construct(
        private readonly StudentEngagementRepository $repository,
        private readonly ReportAccessContextInterface $access,
        private readonly ReportRegistryInterface $registry,
    ) {}

    public function summary(User $user, ReportingPeriod $period, ReportFilters $filters): StudentEngagementSummaryData
    {
        $this->authorize($user);

        return $this->repository->summary($period, $this->restrict($filters));
    }

    public function byCountry(User $user, ReportingPeriod $period, ReportFilters $filters): array
    {
        $this->authorize($user);

        return $this->repository->byCountry($this->restrict($filters));
    }

    public function byAcademicLevel(User $user, ReportingPeriod $period, ReportFilters $filters): array
    {
        $this->authorize($user);

        return $this->repository->byAcademicLevel($this->restrict($filters));
    }

    public function byPreferredSubject(User $user, ReportingPeriod $period, ReportFilters $filters): array
    {
        $this->authorize($user);

        return $this->repository->byPreferredSubject($this->restrict($filters));
    }

    public function byBookedSubject(User $user, ReportingPeriod $period, ReportFilters $filters): array
    {
        $this->authorize($user);

        return $this->repository->byBookedSubject($period, $this->restrict($filters));
    }

    public function registrationTrend(User $user, ReportingPeriod $period, ReportFilters $filters): array
    {
        $this->authorize($user);

        return $this->repository->registrationTrend($period, $this->restrict($filters));
    }

    public function engagementRows(User $user, ReportingPeriod $period, ReportFilters $filters, int $perPage = 25): LengthAwarePaginator
    {
        $this->authorize($user);

        $canViewFullIdentity = $this->access->canViewFullStudentIdentity($user);

        return $this->repository
            ->paginatedEngagementRows($period, $this->restrict($filters), $perPage)
            ->through(fn (User $student): StudentEngagementRow => new StudentEngagementRow(
                studentId: $student->id,
                studentLabel: $this->studentLabel($student, $canViewFullIdentity),
                countryLabel: $student->profile?->country?->name,
                accountStatusLabel: ($student->profile?->student_status ?? StudentStatus::Registered)->label(),
                verified: $student->email_verified_at !== null,
                lifetimeBookingCount: (int) $student->getAttribute('lifetime_booking_count'),
                bookingsInPeriod: (int) $student->getAttribute('bookings_in_period'),
                completedLessonsInPeriod: (int) $student->getAttribute('completed_lessons_in_period'),
                activeLearningPlanCount: (int) $student->getAttribute('active_learning_plan_count'),
                lastQualifyingActivityUtc: $student->getAttribute('last_booking_in_period_at') !== null
                    ? CarbonImmutable::parse($student->getAttribute('last_booking_in_period_at'), 'UTC')
                    : null,
                drillDownUrl: $this->drillDownUrl($user, $student),
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

    public function canView(User $user): bool
    {
        try {
            $this->authorize($user);

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
    private function authorize(User $user): void
    {
        $definition = $this->registry->find(self::REPORT_KEY);

        if ($definition === null || ! $this->access->canView($user, $definition)) {
            throw new AuthorizationException('You may not view student engagement reporting.');
        }

        if (! $this->hasPermission($user, 'ViewStudentReports')) {
            throw new AuthorizationException('You may not view student engagement reporting.');
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

    /** Masked unless the viewer holds the explicit full-identity permission — computed fresh, never stored. */
    private function studentLabel(User $student, bool $canViewFullIdentity): string
    {
        if ($canViewFullIdentity) {
            return $student->full_name;
        }

        $first = trim((string) $student->first_name);

        return $first === '' ? 'Student' : mb_substr($first, 0, 1).'***';
    }

    private function drillDownUrl(User $viewer, User $student): ?string
    {
        if (! Gate::forUser($viewer)->allows('view', $student)) {
            return null;
        }

        return UserResource::getUrl('view', ['record' => $student]);
    }
}
