<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Tests\TestCase;

/**
 * Phase 18B §21/§23 — permanent guards for the reporting-domain
 * boundary: `App\Reporting` may read other domains' enums/models but
 * must never mutate Booking/Lesson/Wallet/Payments/Earnings, moderate
 * Reviews, or resolve Quality alerts. Source domains must never depend
 * back on Reporting (a one-way read relationship). Uses an explicit
 * allowlist for legitimate read-only imports rather than a blanket
 * prohibition, mirroring `ReviewQualityFeedbackDomainBoundaryTest`.
 */
class ReportingDomainBoundaryTest extends TestCase
{
    // ── Reporting must not mutate other domains ───────────────────────────

    public function test_reporting_domain_does_not_import_mutating_domain_classes(): void
    {
        $allowed = [
            // Read-only source enums the Reporting filter/metric layer
            // references by value — never written to.
            'App\Booking\Enums\BookingPaymentStatus',
            'App\Booking\Enums\BookingStatus',
            'App\Booking\Enums\RecurrenceFrequency',
            'App\Booking\Enums\MeetingStatus',
            'App\Booking\Enums\BookingActivityAction',
            'App\Lessons\Enums\LessonOutcome',
            'App\Lessons\Enums\LessonStatus',
            'App\Wallet\Enums\WalletLedgerEntryType',
            'App\Wallet\Enums\WalletLedgerStatus',
            'App\Earnings\Enums\InstructorEarningStatus',
            'App\Earnings\Enums\InstructorWithdrawalStatus',
            'App\Earnings\Enums\SettlementBatchStatus',
            'App\Reviews\Enums\StudentReviewStatus',
            'App\Reviews\Enums\ReviewReportStatus',
            'App\Quality\Enums\InstructorQualityAlertStatus',
            // Read-only analytics/aggregation contracts that are the
            // EXISTING calculation owners Phase 18D reuses rather than
            // duplicating (demo conversion, platform rating figures) —
            // both are pure read interfaces with no mutating method.
            'App\Booking\Contracts\BookingAnalyticsRepositoryInterface',
            'App\Quality\Contracts\AdminQualityDashboardRepositoryInterface',
            // Read-only existing Filament pages the registry links to —
            // never mutated, only referenced for their class-string/URL.
            'App\Filament\Pages\BookingReports',
            'App\Filament\Pages\ReviewsQualityDashboard',
        ];

        $this->assertNoDisallowedCrossDomainImports(
            base_path('app/Reporting'),
            ['App\Booking\\', 'App\Wallet\\', 'App\Earnings\\', 'App\Lessons\\', 'App\Reviews\\', 'App\Quality\\'],
            $allowed,
        );
    }

    public function test_reporting_domain_never_calls_mutating_methods_on_other_domains(): void
    {
        foreach ($this->phpFilesUnder(base_path('app/Reporting')) as $file) {
            $contents = (string) file_get_contents($file);

            foreach ([
                '->save(', '->update(', '->delete(', '->fill(', '->create(',
                'TransitionReviewStatusAction', 'ReviewModerationService', 'ReviewReportService',
                'InstructorQualityAlertService', 'BookingServiceInterface', 'WalletLedgerService',
                'LessonOutcomeServiceInterface',
            ] as $needle) {
                $this->assertStringNotContainsString($needle, $contents, "{$file} must never mutate another domain (found \"{$needle}\").");
            }
        }
    }

    // ── No source domain depends back on Reporting ────────────────────────

    public function test_no_source_domain_imports_the_reporting_namespace(): void
    {
        foreach (['app/Booking', 'app/Lessons', 'app/Wallet', 'app/Earnings', 'app/Reviews', 'app/Quality', 'app/Feedback'] as $domain) {
            foreach ($this->phpFilesUnder(base_path($domain)) as $file) {
                $contents = (string) file_get_contents($file);
                $this->assertStringNotContainsString('App\Reporting\\', $contents, "{$file} in {$domain} must not depend on App\\Reporting — the relationship is read-only, one way.");
            }
        }
    }

    // ── Reporting is read-only end to end ─────────────────────────────────

    public function test_reporting_domain_contains_no_migration(): void
    {
        // Phase 18B introduces no schema change at all.
        $this->assertFileDoesNotExist(base_path('app/Reporting/Migrations'));
    }

    public function test_report_and_metric_registries_never_query_the_database(): void
    {
        foreach (['ReportRegistry.php', 'MetricRegistry.php'] as $file) {
            $contents = (string) file_get_contents(base_path("app/Reporting/Registry/{$file}"));

            foreach (['::query()', 'DB::table', 'DB::select'] as $needle) {
                $this->assertStringNotContainsString($needle, $contents, "{$file} must build its catalogue from plain code-defined data, never a query.");
            }
        }
    }

    // ── Phase 18C — operations report boundaries ──────────────────────────

    public function test_reporting_domain_never_dispatches_events_or_notifications(): void
    {
        foreach ($this->phpFilesUnder(base_path('app/Reporting')) as $file) {
            $contents = (string) file_get_contents($file);

            foreach (['::dispatch(', 'event(', 'Notification::send', '->notify('] as $needle) {
                $this->assertStringNotContainsString($needle, $contents, "{$file} is read-only reporting — it must never dispatch an event or send a notification (found \"{$needle}\").");
            }
        }

        $this->addToAssertionCount(1);
    }

    public function test_operations_filament_page_delegates_all_domain_queries_to_the_reporting_service(): void
    {
        $contents = (string) file_get_contents(base_path('app/Filament/Pages/BookingLessonMeetingOperations.php'));

        // Reference-table filter options (Country/Subject) are the page's
        // only permitted direct reads — never a Booking/Lesson/Meeting query.
        foreach (['Booking::query', 'Lesson::query', 'BookingMeeting::query', 'BookingActivity::query', 'LessonAttendanceRecord::query', 'DB::table', 'DB::select'] as $needle) {
            $this->assertStringNotContainsString($needle, $contents, "BookingLessonMeetingOperations must delegate domain queries to the Reporting service (found \"{$needle}\").");
        }

        $this->assertStringContainsString('BookingLessonMeetingOperationsReportServiceInterface', $contents);
    }

    public function test_operations_dtos_expose_no_eloquent_models(): void
    {
        foreach ($this->phpFilesUnder(base_path('app/Reporting/DTOs/Operations')) as $file) {
            $contents = (string) file_get_contents($file);

            $this->assertStringNotContainsString('use App\Models\\', $contents, "{$file} must carry scalars/enums/value objects only — never a hydrated Eloquent model.");
            $this->assertStringNotContainsString('extends Model', $contents);
        }

        $this->addToAssertionCount(1);
    }

    public function test_recurrence_classification_never_infers_from_timestamps_or_audit_text(): void
    {
        $contents = (string) file_get_contents(base_path('app/Reporting/Support/RecurrenceClassifier.php'));

        // The classifier may read only the two durable provenance signals.
        $this->assertStringContainsString('recurrence_frequency', $contents);
        $this->assertStringContainsString('recurring_group', $contents);

        foreach (['starts_at', 'diffInDays', 'description', 'audit', 'notification'] as $needle) {
            $this->assertStringNotContainsString($needle, $contents, "RecurrenceClassifier must never infer recurrence from \"{$needle}\" — only durable provenance fields.");
        }
    }

    // ── Phase 18D — student/instructor analytics boundaries ───────────────

    public function test_analytics_report_services_never_depend_on_financial_domains(): void
    {
        foreach ([
            'app/Reporting/Services/StudentEngagementReportService.php',
            'app/Reporting/Services/InstructorPerformanceReportService.php',
            'app/Reporting/Repositories/StudentEngagementRepository.php',
            'app/Reporting/Repositories/InstructorPerformanceRepository.php',
        ] as $path) {
            $contents = (string) file_get_contents(base_path($path));

            foreach (['App\Wallet\\', 'App\Earnings\\', 'Payment', 'Settlement', 'Payout', 'Withdrawal', 'wallet_ledger', 'instructor_earnings', 'Compensation'] as $needle) {
                $this->assertStringNotContainsString($needle, $contents, "{$path} must never reference financial domains (found \"{$needle}\").");
            }
        }
    }

    public function test_analytics_filament_pages_delegate_all_domain_queries_to_reporting_services(): void
    {
        foreach (['app/Filament/Pages/StudentEngagement.php', 'app/Filament/Pages/InstructorPerformance.php'] as $path) {
            $contents = (string) file_get_contents(base_path($path));

            // Reference-table filter options (Country/Subject/AcademicLevel)
            // are the pages' only permitted direct reads.
            foreach (['Booking::query', 'Lesson::query', 'User::query', 'StudentLearningPlan::query', 'HomeworkAssignment::query', 'DB::table', 'DB::select'] as $needle) {
                $this->assertStringNotContainsString($needle, $contents, "{$path} must delegate domain queries to the Reporting service (found \"{$needle}\").");
            }
        }
    }

    public function test_engagement_dtos_expose_no_eloquent_models(): void
    {
        foreach ($this->phpFilesUnder(base_path('app/Reporting/DTOs/Engagement')) as $file) {
            $contents = (string) file_get_contents($file);

            $this->assertStringNotContainsString('use App\Models\\', $contents, "{$file} must carry scalars/enums only — never a hydrated Eloquent model.");
        }

        $this->addToAssertionCount(1);
    }

    public function test_only_the_export_auditor_touches_the_audit_service_inside_reporting(): void
    {
        foreach ($this->phpFilesUnder(base_path('app/Reporting')) as $file) {
            // The Phase 18B export contract pair is the one sanctioned
            // consumer (the DTO only names the service in a docblock).
            if (str_ends_with($file, 'ReportExportAuditor.php') || str_ends_with($file, 'ExportRequestContext.php')) {
                continue;
            }

            $contents = (string) file_get_contents($file);
            $this->assertStringNotContainsString('AuditTrailService', $contents, "{$file} — ordinary report views must never write audit entries.");
        }

        $this->addToAssertionCount(1);
    }

    public function test_no_retention_or_risk_scoring_concept_exists_in_reporting(): void
    {
        foreach ($this->phpFilesUnder(base_path('app/Reporting')) as $file) {
            $contents = (string) file_get_contents($file);

            foreach (['retentionRate', 'retention_rate', 'riskScore', 'risk_score', 'churnPrediction', 'lifetimeValue', 'lifetime_value'] as $needle) {
                $this->assertStringNotContainsString($needle, $contents, "{$file} must not introduce a retention/risk/LTV concept (found \"{$needle}\") — §6.4/§6.7 left these unavailable.");
            }
        }

        $this->addToAssertionCount(1);
    }

    // ── Export/audit contract routes only through AuditTrailService ──────

    public function test_report_export_auditor_uses_only_the_existing_audit_trail_service(): void
    {
        $contents = (string) file_get_contents(base_path('app/Reporting/Support/ReportExportAuditor.php'));

        $this->assertStringContainsString('AuditTrailService', $contents);
        $this->assertStringNotContainsString('activity(', $contents, 'ReportExportAuditor must never call activity() directly.');
    }

    // ── Helpers (mirrors ReviewQualityFeedbackDomainBoundaryTest) ─────────

    /** @param list<string> $prefixes @param list<string> $allowed */
    private function assertNoDisallowedCrossDomainImports(string $directory, array $prefixes, array $allowed): void
    {
        foreach ($this->phpFilesUnder($directory) as $file) {
            foreach (file((string) $file) ?: [] as $line) {
                if (! preg_match('/^use\s+([A-Za-z0-9\\\\]+);/', trim($line), $matches)) {
                    continue;
                }

                $imported = $matches[1];

                foreach ($prefixes as $prefix) {
                    if (str_starts_with($imported, $prefix) && ! in_array($imported, $allowed, true)) {
                        $this->fail("{$file} imports {$imported}, which is not in the read-only allowlist for cross-domain access.");
                    }
                }
            }
        }

        $this->addToAssertionCount(1);
    }

    /** @return list<string> */
    private function phpFilesUnder(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        $files = [];

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
