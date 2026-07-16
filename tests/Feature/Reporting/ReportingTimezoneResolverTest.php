<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Reporting\Exceptions\InvalidReportingTimezoneException;
use App\Reporting\Support\ReportingTimezoneResolver;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phase 18B §7 — the single authoritative reporting-timezone resolver. */
class ReportingTimezoneResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_timezone_is_used_when_provided(): void
    {
        $this->assertSame('Asia/Kolkata', ReportingTimezoneResolver::resolve('Asia/Kolkata'));
    }

    public function test_configured_default_is_used_when_no_explicit_timezone_given(): void
    {
        $settings = app(GeneralSettings::class);
        $settings->default_timezone = 'Europe/London';
        $settings->save();

        $this->assertSame('Europe/London', ReportingTimezoneResolver::resolve(null));
    }

    public function test_platform_fallback_is_utc(): void
    {
        $this->assertSame('UTC', ReportingTimezoneResolver::PLATFORM_FALLBACK);
    }

    public function test_invalid_explicit_timezone_is_rejected_not_silently_replaced(): void
    {
        $settings = app(GeneralSettings::class);
        $settings->default_timezone = 'Asia/Kolkata';
        $settings->save();

        $this->expectException(InvalidReportingTimezoneException::class);

        ReportingTimezoneResolver::resolve('Not/ARealZone');
    }

    public function test_resolution_is_deterministic(): void
    {
        $first = ReportingTimezoneResolver::resolve('Asia/Kolkata');
        $second = ReportingTimezoneResolver::resolve('Asia/Kolkata');

        $this->assertSame($first, $second);
    }

    public function test_is_valid_accepts_utc(): void
    {
        $this->assertTrue(ReportingTimezoneResolver::isValid('UTC'));
    }

    public function test_is_valid_rejects_garbage(): void
    {
        $this->assertFalse(ReportingTimezoneResolver::isValid('not-a-timezone'));
    }
}
