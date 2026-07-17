<?php

declare(strict_types=1);

namespace App\Reporting\Exports;

use App\Models\User;
use App\Reporting\Contracts\BookingLessonMeetingOperationsReportServiceInterface;
use App\Reporting\Contracts\FinancialReportsServiceInterface;
use App\Reporting\Contracts\InstructorPerformanceReportServiceInterface;
use App\Reporting\Contracts\LearningAnalyticsReportServiceInterface;
use App\Reporting\Contracts\ReportAccessContextInterface;
use App\Reporting\Contracts\ReportRegistryInterface;
use App\Reporting\Contracts\StudentEngagementReportServiceInterface;
use App\Reporting\DTOs\ExportRequestContext;
use App\Reporting\Exceptions\ReportExportException;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\Support\ReportExportAuditor;
use App\Reporting\ValueObjects\ReportingPeriod;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 18I — the single permission-controlled CSV export path. Every
 * dataset is code-defined ({@see self::definitions}); every row comes
 * from an EXISTING report-service contract called with the acting user
 * so the source report's own authorization, filter restriction,
 * masking and calculation rules apply unchanged — no export-specific
 * query, column selection or business definition exists.
 *
 * Authorization (§4): report view access + the dataset's specific
 * export permission, both re-checked server-side on every call; the
 * underlying service additionally re-enforces its own view/financial
 * permissions when fetching rows. Full student identity in a CSV
 * additionally requires `ExportSensitiveReports` — without it, person
 * labels are re-masked here even for viewers who see full names
 * on-page. Unauthorized exports execute zero report queries (the
 * permission gate runs before any provider call).
 *
 * Safety (§6): synchronous, streamed, no temp files; UTF-8; ISO-8601
 * timestamps; currency as its own column with minor-unit integers;
 * spreadsheet-formula injection neutralized on every cell; safe
 * filenames (report key + timestamp only). Over-limit exports fail
 * loudly with no partial output (§8). Requested/completed/failed audit
 * events share one correlation reference which is embedded in the CSV
 * preamble (§9).
 */
final class ReportCsvExporter
{
    /** Conservative synchronous limit — narrow the period/filters beyond this. */
    public const int MAX_ROWS = 5000;

    public function __construct(
        private readonly ReportRegistryInterface $registry,
        private readonly ReportAccessContextInterface $access,
        private readonly ReportExportAuditor $auditor,
        private readonly StudentEngagementReportServiceInterface $students,
        private readonly InstructorPerformanceReportServiceInterface $instructors,
        private readonly BookingLessonMeetingOperationsReportServiceInterface $operations,
        private readonly LearningAnalyticsReportServiceInterface $learning,
        private readonly FinancialReportsServiceInterface $finance,
    ) {}

    /** @return array<string, ReportExportDefinition> keyed by export key — the complete Version 1 export catalogue. */
    public static function definitions(): array
    {
        $definitions = [
            new ReportExportDefinition(
                key: 'student_engagement_rows',
                reportKey: 'student_engagement',
                label: 'Student engagement rows',
                exportPermission: 'ExportReports',
                financial: false,
                sensitive: true,
                headers: ['student_id', 'student_label', 'country', 'account_status', 'verified', 'lifetime_bookings', 'bookings_in_period', 'completed_lessons_in_period', 'active_learning_plans', 'last_activity_utc'],
                maxRows: self::MAX_ROWS,
            ),
            new ReportExportDefinition(
                key: 'instructor_performance_rows',
                reportKey: 'instructor_performance',
                label: 'Instructor performance rows',
                exportPermission: 'ExportReports',
                financial: false,
                sensitive: true,
                headers: ['instructor_id', 'instructor_label', 'lifecycle_status', 'country', 'demo_bookings', 'paid_bookings', 'completed_lessons', 'unique_students', 'instructor_no_shows', 'booked_hours', 'average_rating', 'review_count'],
                maxRows: self::MAX_ROWS,
            ),
            new ReportExportDefinition(
                key: 'operations_lesson_rows',
                reportKey: 'booking_lesson_meeting_operations',
                label: 'Booking & lesson operations rows',
                exportPermission: 'ExportReports',
                financial: false,
                sensitive: true,
                headers: ['booking_reference', 'scheduled_at_utc', 'booking_type', 'student_label', 'instructor_label', 'subject', 'booking_status', 'lesson_status', 'lesson_outcome', 'meeting_status'],
                maxRows: self::MAX_ROWS,
            ),
            new ReportExportDefinition(
                key: 'learning_plan_review_rows',
                reportKey: 'learning_progress',
                label: 'Learning Plan review rows',
                exportPermission: 'ExportReports',
                financial: false,
                sensitive: true,
                headers: ['plan_id', 'student_label', 'instructor_label', 'subject', 'plan_status', 'progress_percent', 'milestones_achieved', 'milestones_total', 'started_at_utc', 'target_date', 'last_review_at_utc', 'review_due', 'target_date_passed'],
                maxRows: self::MAX_ROWS,
            ),
            new ReportExportDefinition(
                key: 'wallet_refund_linkage_rows',
                reportKey: 'refund_report',
                label: 'Wallet refund linkage rows',
                exportPermission: 'ExportFinancialReports',
                financial: true,
                sensitive: true,
                headers: ['booking_reference', 'lesson_outcome', 'processing_status', 'reason_code', 'amount_minor', 'currency', 'decided_at_utc', 'executed_at_utc', 'admin_hold', 'masked_payment_reference'],
                maxRows: self::MAX_ROWS,
            ),
            new ReportExportDefinition(
                key: 'payment_reconciliation_rows',
                reportKey: 'payment_outcomes',
                label: 'Payment reconciliation issue rows',
                exportPermission: 'ExportFinancialReports',
                financial: true,
                sensitive: true,
                headers: ['reference', 'provider', 'issue_type', 'severity', 'status', 'amount_minor', 'currency', 'summary', 'first_detected_at_utc'],
                maxRows: self::MAX_ROWS,
            ),
        ];

        return collect($definitions)->keyBy(fn (ReportExportDefinition $d) => $d->key)->all();
    }

    public function find(string $exportKey): ?ReportExportDefinition
    {
        return self::definitions()[$exportKey] ?? null;
    }

    /** UI visibility only — server-side authorization always re-runs inside download(). */
    public function canExport(User $user, string $exportKey): bool
    {
        $definition = $this->find($exportKey);

        if ($definition === null) {
            return false;
        }

        $report = $this->registry->find($definition->reportKey);

        return $report !== null
            && $report->exportAvailable
            && $this->access->canView($user, $report)
            && $this->hasPermission($user, $definition->exportPermission);
    }

    /**
     * @throws AuthorizationException when view or export permission is missing (before ANY report query)
     * @throws ReportExportException when the dataset exceeds the synchronous row limit
     */
    public function download(User $user, string $exportKey, ReportingPeriod $period, ReportFilters $filters): StreamedResponse
    {
        $definition = $this->find($exportKey);

        if ($definition === null || ! $this->canExport($user, $exportKey)) {
            throw new AuthorizationException('You may not export this report dataset.');
        }

        $context = ExportRequestContext::forExport(
            reportKey: $definition->reportKey,
            requestedBy: $user,
            requiredExportPermission: $definition->exportPermission,
            sensitive: $definition->sensitive,
            financial: $definition->financial,
            reportingTimezone: $period->timezone,
            periodStart: $period->startUtc,
            periodEndExclusive: $period->endUtcExclusive,
            safeFilterSummary: $filters->toSafeArray(),
            maxRows: $definition->maxRows,
        );

        $this->auditor->recordRequested($user, $context);

        try {
            [$rows, $total] = $this->rows($user, $definition, $period, $filters);

            if ($total > $definition->maxRows) {
                throw new ReportExportException(sprintf(
                    'This export would contain %d rows — above the %d-row synchronous limit. Narrow the date range or filters.',
                    $total,
                    $definition->maxRows,
                ));
            }
        } catch (\Throwable $e) {
            $this->auditor->recordFailed($user, $context);

            throw $e;
        }

        $this->auditor->recordCompleted($user, $context, count($rows));

        $filename = sprintf('%s-%s.csv', $definition->key, $context->generatedAt->format('Ymd-His'));

        return response()->streamDownload(function () use ($definition, $context, $rows): void {
            $out = fopen('php://output', 'wb');

            // Machine-readable preamble: provenance + the shared audit reference.
            fputcsv($out, ['export_key', 'report_key', 'generated_at', 'reporting_timezone', 'period_start', 'period_end_exclusive', 'audit_reference', 'row_count']);
            fputcsv($out, array_map($this->sanitizeCell(...), [
                $definition->key,
                $definition->reportKey,
                $context->generatedAt->toIso8601String(),
                $context->reportingTimezone,
                $context->periodStart->toIso8601String(),
                $context->periodEndExclusive->toIso8601String(),
                $context->correlationReference,
                (string) count($rows),
            ]));
            fputcsv($out, []);

            fputcsv($out, $definition->headers);

            foreach ($rows as $row) {
                fputcsv($out, array_map($this->sanitizeCell(...), $row));
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ── Row providers (existing report services only) ─────────────────────

    /**
     * @return array{0: list<list<string>>, 1: int} [cell rows, total source rows]
     */
    private function rows(User $user, ReportExportDefinition $definition, ReportingPeriod $period, ReportFilters $filters): array
    {
        $probe = $definition->maxRows + 1;
        $maskPersons = ! $this->hasPermission($user, 'ExportSensitiveReports');

        return match ($definition->key) {
            'student_engagement_rows' => $this->paginated(
                $this->students->engagementRows($user, $period, $filters, $probe),
                fn (object $r): array => [
                    (string) $r->studentId,
                    $this->personLabel($r->studentLabel, $maskPersons),
                    (string) ($r->countryLabel ?? ''),
                    $r->accountStatusLabel,
                    $r->verified ? 'yes' : 'no',
                    (string) $r->lifetimeBookingCount,
                    (string) $r->bookingsInPeriod,
                    (string) $r->completedLessonsInPeriod,
                    (string) $r->activeLearningPlanCount,
                    $r->lastQualifyingActivityUtc?->toIso8601String() ?? '',
                ],
            ),
            'instructor_performance_rows' => $this->paginated(
                $this->instructors->performanceRows($user, $period, $filters, $probe),
                fn (object $r): array => [
                    (string) $r->instructorId,
                    (string) $r->instructorLabel,
                    $r->statusLabel,
                    (string) ($r->countryLabel ?? ''),
                    (string) $r->demoBookings,
                    (string) $r->paidBookings,
                    (string) $r->completedLessons,
                    (string) $r->uniqueStudents,
                    (string) $r->instructorNoShows,
                    (string) $r->bookedHours,
                    $r->averageRating !== null ? (string) $r->averageRating : '',
                    (string) $r->reviewCount,
                ],
            ),
            'operations_lesson_rows' => $this->paginated(
                $this->operations->lessonsInPeriod($user, $period, $filters, $probe),
                fn (object $r): array => [
                    $r->bookingReference,
                    $r->scheduledAtUtc->toIso8601String(),
                    $r->bookingTypeLabel,
                    $this->personLabel($r->studentLabel, $maskPersons),
                    (string) $r->instructorLabel,
                    (string) ($r->subjectLabel ?? ''),
                    $r->bookingStatusLabel,
                    (string) ($r->lessonStatusLabel ?? ''),
                    (string) ($r->lessonOutcomeLabel ?? ''),
                    (string) ($r->meetingStatusLabel ?? ''),
                ],
            ),
            'learning_plan_review_rows' => $this->paginated(
                $this->learning->planReviewTable($user, $period, $filters, $probe),
                fn (object $r): array => [
                    (string) $r->planId,
                    $this->personLabel($r->studentLabel, $maskPersons),
                    (string) ($r->instructorLabel ?? ''),
                    (string) ($r->subjectLabel ?? ''),
                    $r->statusLabel,
                    (string) $r->progressPercent,
                    (string) $r->milestonesAchieved,
                    (string) $r->milestonesTotal,
                    $r->startedAtUtc?->toIso8601String() ?? '',
                    (string) ($r->targetDate ?? ''),
                    $r->lastReviewAtUtc?->toIso8601String() ?? '',
                    $r->reviewDue ? 'yes' : 'no',
                    $r->targetDatePassed ? 'yes' : 'no',
                ],
            ),
            'wallet_refund_linkage_rows' => $this->paginated(
                $this->finance->refundLinkage($user, $period, $filters, $probe),
                fn (object $r): array => [
                    $r->bookingReference,
                    $r->lessonOutcomeLabel,
                    $r->processingStatusLabel,
                    (string) ($r->reasonCode ?? ''),
                    $r->amountMinor !== null ? (string) $r->amountMinor : '',
                    (string) ($r->currency ?? ''),
                    $r->decidedAtUtc->toIso8601String(),
                    $r->executedAtUtc?->toIso8601String() ?? '',
                    $r->adminHold ? 'yes' : 'no',
                    (string) ($r->maskedPaymentReference ?? ''),
                ],
            ),
            'payment_reconciliation_rows' => $this->paginated(
                $this->finance->paymentReconciliationIssues($user, $probe),
                fn (object $r): array => [
                    $r->reference,
                    $r->provider,
                    $r->typeLabel,
                    $r->severityLabel,
                    $r->statusLabel,
                    $r->amountMinor !== null ? (string) $r->amountMinor : '',
                    (string) ($r->currency ?? ''),
                    $r->safeSummary,
                    $r->firstDetectedAtUtc->toIso8601String(),
                ],
            ),
            default => throw new AuthorizationException('Unknown export dataset.'),
        };
    }

    /**
     * @param  callable(object): list<string>  $mapper
     * @return array{0: list<list<string>>, 1: int}
     */
    private function paginated(LengthAwarePaginator $paginator, callable $mapper): array
    {
        $rows = [];

        foreach ($paginator->items() as $item) {
            $rows[] = $mapper($item);
        }

        return [$rows, (int) $paginator->total()];
    }

    /** Full person identity in a CSV requires ExportSensitiveReports — re-masked here even for on-page-unmasked viewers (§4). */
    private function personLabel(string $label, bool $mask): string
    {
        if (! $mask || $label === '' || str_contains($label, '***')) {
            return $label;
        }

        return mb_substr($label, 0, 1).'***';
    }

    /**
     * Spreadsheet-formula injection neutralization (§6): cells starting
     * with =, +, -, @, tab or carriage return are prefixed with a
     * single quote so no spreadsheet ever evaluates them.
     */
    private function sanitizeCell(mixed $value): string
    {
        $cell = (string) $value;

        if ($cell !== '' && in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$cell;
        }

        return $cell;
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
}
