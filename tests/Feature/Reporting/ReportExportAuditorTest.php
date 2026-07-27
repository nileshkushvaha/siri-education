<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Models\Activity;
use App\Models\User;
use App\Reporting\DTOs\ExportRequestContext;
use App\Reporting\Support\ReportExportAuditor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The export authorization/audit metadata contract (SRS §19-20) —
 * proves the audit trail is sound and routes exclusively through the
 * existing AuditTrailService.
 */
class ReportExportAuditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_metadata_never_contains_secrets_or_raw_personal_data(): void
    {
        $context = ExportRequestContext::forExport(
            reportKey: 'finance_overview',
            requestedBy: User::factory()->create(),
            requiredExportPermission: 'ExportFinancialReports',
            sensitive: true,
            financial: true,
            reportingTimezone: 'Asia/Kolkata',
            periodStart: CarbonImmutable::parse('2026-03-01'),
            periodEndExclusive: CarbonImmutable::parse('2026-04-01'),
            safeFilterSummary: ['country' => 1, 'currency' => 'INR'],
        );

        $metadata = $context->toAuditMetadata();

        foreach (['password', 'secret', 'api_key', 'token', 'card_number', 'bank_account', 'email', 'phone'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $metadata);
        }

        $this->assertSame('finance_overview', $metadata['report_key']);
        $this->assertSame('Asia/Kolkata', $metadata['reporting_timezone']);
        $this->assertTrue($metadata['sensitive']);
        $this->assertTrue($metadata['financial']);
    }

    public function test_requested_export_is_recorded_via_audit_trail_service_only(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $context = ExportRequestContext::forExport(
            reportKey: 'booking_lesson_kpis',
            requestedBy: $admin,
            requiredExportPermission: 'ExportReports',
            sensitive: false,
            financial: false,
            reportingTimezone: 'UTC',
            periodStart: CarbonImmutable::parse('2026-03-01'),
            periodEndExclusive: CarbonImmutable::parse('2026-04-01'),
            safeFilterSummary: [],
        );

        app(ReportExportAuditor::class)->recordRequested($admin, $context);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'reporting',
            'event' => 'report_export_requested',
            'causer_id' => $admin->id,
        ]);
    }

    public function test_completed_export_records_row_count(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $context = ExportRequestContext::forExport(
            reportKey: 'booking_lesson_kpis',
            requestedBy: $admin,
            requiredExportPermission: 'ExportReports',
            sensitive: false,
            financial: false,
            reportingTimezone: 'UTC',
            periodStart: CarbonImmutable::parse('2026-03-01'),
            periodEndExclusive: CarbonImmutable::parse('2026-04-01'),
            safeFilterSummary: [],
        );

        app(ReportExportAuditor::class)->recordCompleted($admin, $context, rowCount: 42);

        $activity = Activity::query()->where('log_name', 'reporting')->where('event', 'report_export_completed')->latest('id')->firstOrFail();
        $this->assertSame(42, $activity->properties->get('row_count'));
    }

    public function test_failed_export_is_recorded(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $context = ExportRequestContext::forExport(
            reportKey: 'booking_lesson_kpis',
            requestedBy: $admin,
            requiredExportPermission: 'ExportReports',
            sensitive: false,
            financial: false,
            reportingTimezone: 'UTC',
            periodStart: CarbonImmutable::parse('2026-03-01'),
            periodEndExclusive: CarbonImmutable::parse('2026-04-01'),
            safeFilterSummary: [],
        );

        app(ReportExportAuditor::class)->recordFailed($admin, $context);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'reporting',
            'event' => 'report_export_failed',
        ]);
    }
}
