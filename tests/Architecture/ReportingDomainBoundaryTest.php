<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Reporting\Exports\ReportCsvExporter;
use Tests\TestCase;

/**
 * SRS §21/§23 — permanent guards for the reporting-domain
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
            // EXISTING calculation owners Reporting reuses rather than
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
        // The reporting domain introduces no schema change at all.
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

    // ── Operations report boundaries ──────────────────────────

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

    // ── Student/instructor analytics boundaries ───────────────

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
            // The export contract pair is the one sanctioned
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

    // ── Export boundaries ─────────────────────────────────────

    public function test_export_path_performs_no_source_domain_mutation_or_dynamic_sql(): void
    {
        foreach ($this->phpFilesUnder(base_path('app/Reporting/Exports')) as $file) {
            $contents = (string) file_get_contents($file);

            foreach ([
                'DB::table', 'DB::select', 'DB::statement', 'whereRaw', 'selectRaw',
                '->save(', '->update(', '->delete(', '->create(',
                'dispatch(', 'Notification::', 'Http::', 'Storage::', 'file_put_contents',
                'request()->input', '$request->',
            ] as $needle) {
                $this->assertStringNotContainsString($needle, $contents, "{$file} — the export path must be read-only composition over report services (found \"{$needle}\").");
            }
        }

        $this->addToAssertionCount(1);
    }

    public function test_every_export_definition_has_a_declared_export_permission(): void
    {
        foreach (ReportCsvExporter::definitions() as $definition) {
            $this->assertContains(
                $definition->exportPermission,
                ['ExportReports', 'ExportFinancialReports', 'ExportSensitiveReports'],
                "{$definition->key} must use one of the sanctioned export permissions.",
            );
            $this->assertNotEmpty($definition->headers, "{$definition->key} must declare fixed headers — no dynamic columns.");
            $this->assertGreaterThan(0, $definition->maxRows);
            $this->assertLessThanOrEqual(ReportCsvExporter::MAX_ROWS, $definition->maxRows);
        }
    }

    // ── Marketplace/executive boundaries ──────────────────────

    public function test_marketplace_executive_pages_delegate_all_queries(): void
    {
        foreach ([
            'app/Filament/Pages/MarketplaceSupplyDemand.php',
            'app/Filament/Pages/ExecutiveKpiOverview.php',
        ] as $path) {
            $contents = (string) file_get_contents(base_path($path));

            foreach (['DB::table', 'DB::select', 'Booking::', 'User::query', 'Wallet', 'teacher_availability', 'teacher_subjects'] as $needle) {
                $this->assertStringNotContainsString($needle, $contents, "{$path} must delegate every query to the service (found \"{$needle}\").");
            }

            $this->assertStringContainsString('MarketplaceExecutiveReportServiceInterface', $contents);
        }
    }

    public function test_executive_service_is_a_pure_composer_with_no_own_queries(): void
    {
        // The executive overview must never become a second calculation
        // owner: no query builder, no raw SQL — only calls into the owning
        // report services (the marketplace repository is the single
        // repository dependency, used for the marketplace report only).
        $contents = (string) file_get_contents(base_path('app/Reporting/Services/MarketplaceExecutiveReportService.php'));

        foreach (['DB::table', 'DB::select', 'selectRaw', 'whereRaw', '::query()'] as $needle) {
            $this->assertStringNotContainsString($needle, $contents, "MarketplaceExecutiveReportService must compose owners, never query (found \"{$needle}\").");
        }
    }

    public function test_marketplace_dtos_expose_no_models_and_no_forbidden_kpis(): void
    {
        foreach ($this->phpFilesUnder(base_path('app/Reporting/DTOs/Marketplace')) as $file) {
            $contents = (string) file_get_contents($file);

            $this->assertStringNotContainsString('use App\Models\\', $contents, "{$file} must carry scalars/report DTOs only.");

            foreach (['$revenue', '$margin', '$lifetimeValue', '$retention', '$churn', '$utilization', '$healthScore', '$ranking', '$score'] as $needle) {
                $this->assertStringNotContainsString($needle, $contents, "{$file} must never carry a fabricated KPI (found \"{$needle}\").");
            }
        }

        $this->addToAssertionCount(1);
    }

    // ── Referral/quality/communication boundaries ─────────────

    public function test_communication_reporting_never_imports_mutation_paths(): void
    {
        foreach ($this->phpFilesUnder(base_path('app/Reporting')) as $file) {
            $contents = (string) file_get_contents($file);

            foreach ([
                'use App\Reviews\Services', 'use App\Reviews\Actions',
                'use App\Quality\Services', 'use App\Quality\Actions',
                'use Illuminate\Support\Facades\Notification',
                'Notification::send', '->notify(', '->markAsRead(', '->notifyNow(',
                '->creditReferral(', '->resolveAlert(', '->moderate(',
            ] as $needle) {
                $this->assertStringNotContainsString($needle, $contents, "{$file} — communication reporting is strictly read-only (found \"{$needle}\").");
            }
        }

        $this->addToAssertionCount(1);
    }

    public function test_communication_page_delegates_all_queries_to_the_reporting_service(): void
    {
        $contents = (string) file_get_contents(base_path('app/Filament/Pages/ReferralCommunicationReports.php'));

        foreach (['DB::table', 'DB::select', '::query()', 'WalletLedgerEntry::', 'LessonReview::', 'ReviewReport::', 'QualityAlert::'] as $needle) {
            $this->assertStringNotContainsString($needle, $contents, "ReferralCommunicationReports page must delegate every query (found \"{$needle}\").");
        }

        $this->assertStringContainsString('ReferralCommunicationReportServiceInterface', $contents);
    }

    public function test_communication_dtos_expose_no_models_payloads_or_reporter_identity(): void
    {
        foreach ($this->phpFilesUnder(base_path('app/Reporting/DTOs/Communication')) as $file) {
            $contents = (string) file_get_contents($file);

            $this->assertStringNotContainsString('use App\Models\\', $contents, "{$file} must carry scalars only.");

            // Property-shaped needles: docblocks may legitimately STATE that
            // payloads are excluded; an actual property may not exist.
            foreach (['$payload', '$reporterId', '$reporterName', '$moderationNote', '$referralToken', '$referralCode', '$providerSecret', '$messageBody', '$conversation'] as $needle) {
                $this->assertStringNotContainsString($needle, $contents, "{$file} must never carry payloads, tokens or reporter identity (found \"{$needle}\").");
            }
        }

        $this->addToAssertionCount(1);
    }

    // ── Learning analytics boundaries ─────────────────────────

    public function test_learning_reporting_never_imports_academic_mutation_paths(): void
    {
        // Import-form needles: doc comments may legitimately NAME the source
        // services as calculation owners; an actual `use` import may not exist.
        foreach ($this->phpFilesUnder(base_path('app/Reporting')) as $file) {
            $contents = (string) file_get_contents($file);

            foreach ([
                'use App\Services\Student\LearningPlanService',
                'use App\Services\Student\StudentLearningGoalService',
                'use App\Homework\Services',
                'use App\Homework\Actions',
                'use App\Homework\Contracts',
                '->completeMilestone(', '->completePlan(', '->archivePlan(',
                '->createReview(', '->markReviewDue(', '->submit(', '->adjustPlan(',
            ] as $needle) {
                $this->assertStringNotContainsString($needle, $contents, "{$file} — learning reporting is strictly read-only (found \"{$needle}\").");
            }
        }

        $this->addToAssertionCount(1);
    }

    public function test_learning_page_delegates_all_academic_queries_to_the_reporting_service(): void
    {
        $contents = (string) file_get_contents(base_path('app/Filament/Pages/LearningAnalytics.php'));

        foreach ([
            'DB::table', 'DB::select', 'HomeworkAssignment::', 'StudentLearningPlan::',
            'StudentLearningGoal::', 'LearningPlanMilestone::', 'LearningPlanReview::',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $contents, "LearningAnalytics page must delegate academic queries to the service (found \"{$needle}\").");
        }

        $this->assertStringContainsString('LearningAnalyticsReportServiceInterface', $contents);
    }

    public function test_learning_dtos_expose_no_models_and_no_private_academic_fields(): void
    {
        foreach ($this->phpFilesUnder(base_path('app/Reporting/DTOs/Learning')) as $file) {
            $contents = (string) file_get_contents($file);

            $this->assertStringNotContainsString('use App\Models\\', $contents, "{$file} must carry scalars only.");

            foreach (['submissionText', 'feedbackText', 'progressNotes', 'reviewNarrative', 'assessmentText', 'privateNote'] as $needle) {
                $this->assertStringNotContainsString($needle, $contents, "{$file} must never carry private academic content (found \"{$needle}\").");
            }
        }

        $this->addToAssertionCount(1);
    }

    public function test_learning_reporting_has_no_finance_dependency(): void
    {
        foreach ([
            'app/Reporting/Repositories/LearningPlanAnalyticsRepository.php',
            'app/Reporting/Repositories/HomeworkAnalyticsRepository.php',
            'app/Reporting/Services/LearningAnalyticsReportService.php',
            'app/Reporting/Contracts/LearningAnalyticsReportServiceInterface.php',
        ] as $path) {
            $contents = (string) file_get_contents(base_path($path));

            foreach (['App\Wallet', 'App\Earnings', 'MoneyFormatter', 'DTOs\Finance', 'wallet_ledger', 'booking_payments', 'instructor_earnings'] as $needle) {
                $this->assertStringNotContainsString($needle, $contents, "{$path} — learning reporting must carry no finance dependency (found \"{$needle}\").");
            }
        }

        $this->addToAssertionCount(1);
    }

    public function test_academic_source_domains_do_not_depend_on_reporting(): void
    {
        foreach ([
            base_path('app/Services/Student'),
            base_path('app/Homework'),
        ] as $directory) {
            foreach ($this->phpFilesUnder($directory) as $file) {
                $contents = (string) file_get_contents($file);

                $this->assertStringNotContainsString('use App\Reporting\\', $contents, "{$file} — source academic domains must never depend on Reporting.");
            }
        }

        $this->addToAssertionCount(1);
    }

    // ── Financial reporting boundaries ────────────────────────

    public function test_financial_reporting_never_touches_mutation_services_or_providers(): void
    {
        foreach ($this->phpFilesUnder(base_path('app/Reporting')) as $file) {
            $contents = (string) file_get_contents($file);

            foreach ([
                'WalletLedgerService', 'ExecuteLessonWalletRefundAction', 'LessonWalletRefundService',
                'BookingPaymentServiceInterface', 'RazorpayPaymentProvider', 'StripePaymentProvider',
                'RazorpayX', 'InstructorPayoutExecution', 'InstructorWithdrawalService',
                'TransitionInstructorEarningAction', 'SettlementBatchService',
                '->refund(', '->payout(', '->credit(', '->debit(', '->markPaid(', '->verifyCheckout(',
            ] as $needle) {
                $this->assertStringNotContainsString($needle, $contents, "{$file} — financial reporting is strictly read-only (found \"{$needle}\").");
            }
        }

        $this->addToAssertionCount(1);
    }

    public function test_financial_filament_pages_delegate_all_queries_to_the_reporting_service(): void
    {
        foreach ([
            'app/Filament/Pages/FinanceOverview.php',
            'app/Filament/Pages/WalletRefunds.php',
            'app/Filament/Pages/PaymentsReconciliation.php',
            'app/Filament/Pages/InstructorFinancials.php',
        ] as $path) {
            $contents = (string) file_get_contents(base_path($path));

            foreach (['DB::table', 'DB::select', '::query()', 'Wallet::', 'BookingPayment::', 'InstructorEarning::'] as $needle) {
                $this->assertStringNotContainsString($needle, $contents, "{$path} must delegate every query to FinancialReportsServiceInterface (found \"{$needle}\").");
            }

            $this->assertStringContainsString('FinancialReportsServiceInterface', $contents);
        }
    }

    public function test_finance_dtos_expose_no_models_and_no_secret_fields(): void
    {
        foreach ($this->phpFilesUnder(base_path('app/Reporting/DTOs/Finance')) as $file) {
            $contents = (string) file_get_contents($file);

            $this->assertStringNotContainsString('use App\Models\\', $contents, "{$file} must carry scalars only.");

            foreach (['apiKey', 'webhookSecret', 'cardNumber', 'bankAccount', 'encryptedPayload', 'providerSignature'] as $needle) {
                $this->assertStringNotContainsString($needle, $contents, "{$file} must never carry secret material (found \"{$needle}\").");
            }
        }

        $this->addToAssertionCount(1);
    }

    public function test_no_float_money_arithmetic_in_financial_repositories(): void
    {
        foreach ([
            'app/Reporting/Repositories/WalletFinancialReportRepository.php',
            'app/Reporting/Repositories/PaymentFinancialReportRepository.php',
            'app/Reporting/Repositories/InstructorFinancialReportRepository.php',
        ] as $path) {
            $contents = (string) file_get_contents(base_path($path));

            // Amounts are summed in SQL and cast to int; only display-layer
            // MoneyFormatter (string/integer arithmetic) touches conversion.
            $this->assertStringNotContainsString('(float)', $contents, "{$path} must never cast money to float.");
            $this->assertStringNotContainsString('floatval', $contents);
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
