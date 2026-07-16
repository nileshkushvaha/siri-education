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
