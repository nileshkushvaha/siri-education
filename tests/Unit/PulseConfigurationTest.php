<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Laravel\Pulse\Recorders\CacheInteractions;
use Laravel\Pulse\Recorders\Exceptions;
use Laravel\Pulse\Recorders\Queues;
use Laravel\Pulse\Recorders\Servers;
use Laravel\Pulse\Recorders\SlowJobs;
use Laravel\Pulse\Recorders\SlowOutgoingRequests;
use Laravel\Pulse\Recorders\SlowQueries;
use Laravel\Pulse\Recorders\SlowRequests;
use Laravel\Pulse\Recorders\UserJobs;
use Laravel\Pulse\Recorders\UserRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Config-level checks that don't require a
 * database connection. See PulseMigrationHashCompatibilityTest for the
 * MySQL-MD5-incompatibility/SHA2-replacement verification and
 * docs/pulse-monitoring.md for the full rationale.
 */
class PulseConfigurationTest extends TestCase
{
    public function test_installed_pulse_version_is_known(): void
    {
        $installed = json_decode(file_get_contents(base_path('vendor/composer/installed.json')), true);

        $pulse = collect($installed['packages'])->firstWhere('name', 'laravel/pulse');

        $this->assertNotNull($pulse, 'laravel/pulse is not installed.');
        $this->assertSame('v1.7.4', $pulse['version']);
    }

    public function test_pulse_migration_is_in_the_normal_discoverable_path(): void
    {
        // The migration is customized (SHA2-based key_hash for
        // MySQL — see docs/pulse-monitoring.md) but lives in the normal
        // path, same as any other migration — no withheld/pending-fix state.
        $this->assertFileExists(base_path('database/migrations/2026_07_20_150026_create_pulse_tables.php'));
        $this->assertDirectoryDoesNotExist(base_path('database/migrations/.pending-env-fix'));
    }

    public function test_pulse_route_is_registered(): void
    {
        $this->assertTrue(Route::has('pulse'));
    }

    public function test_all_expected_recorders_are_registered(): void
    {
        $recorders = array_keys(config('pulse.recorders'));

        foreach ([
            CacheInteractions::class,
            Exceptions::class,
            Queues::class,
            Servers::class,
            SlowJobs::class,
            SlowOutgoingRequests::class,
            SlowQueries::class,
            SlowRequests::class,
            UserJobs::class,
            UserRequests::class,
        ] as $recorder) {
            $this->assertContains($recorder, $recorders, "{$recorder} is not registered.");
        }
    }

    #[DataProvider('thresholdRecorders')]
    public function test_slow_thresholds_are_positive_and_bounded(string $recorder): void
    {
        $threshold = config("pulse.recorders.{$recorder}.threshold");

        $this->assertIsInt($threshold);
        $this->assertGreaterThan(0, $threshold);
        $this->assertLessThanOrEqual(60_000, $threshold, 'Threshold should stay well under a full minute.');
    }

    public static function thresholdRecorders(): array
    {
        return [
            [SlowJobs::class],
            [SlowOutgoingRequests::class],
            [SlowQueries::class],
            [SlowRequests::class],
        ];
    }

    public function test_sample_rates_are_valid_probabilities(): void
    {
        foreach (config('pulse.recorders') as $recorder => $settings) {
            if (! array_key_exists('sample_rate', $settings)) {
                continue;
            }

            $rate = $settings['sample_rate'];

            $this->assertGreaterThan(0, $rate, "{$recorder} sample_rate must be > 0.");
            $this->assertLessThanOrEqual(1, $rate, "{$recorder} sample_rate must be <= 1.");
        }
    }

    public function test_storage_and_ingest_retention_are_bounded(): void
    {
        $storageKeep = config('pulse.storage.trim.keep');
        $ingestKeep = config('pulse.ingest.trim.keep');

        $this->assertNotEmpty($storageKeep);
        $this->assertNotEmpty($ingestKeep);
        $this->assertNotSame('forever', $storageKeep);
        $this->assertNotSame('forever', $ingestKeep);
    }

    #[DataProvider('sensitiveRoutePatterns')]
    public function test_sensitive_routes_are_filtered_from_request_recorders(string $path): void
    {
        foreach ([SlowRequests::class, UserRequests::class] as $recorder) {
            $ignored = collect(config("pulse.recorders.{$recorder}.ignore"))
                ->contains(fn (string $pattern): bool => (bool) preg_match($pattern, $path));

            $this->assertTrue($ignored, "{$path} is not filtered from {$recorder}.");
        }
    }

    public static function sensitiveRoutePatterns(): array
    {
        return [
            'login' => ['/login'],
            'admin login' => ['/admin/login'],
            'forgot password' => ['/forgot-password'],
            'reset password with token' => ['/reset-password/abc123'],
            'verify email signed link' => ['/verify-email/1/hash'],
            'booking payment webhook' => ['/api/webhooks/bookings/payments/stripe'],
            'payout webhook' => ['/api/webhooks/payouts/razorpayx'],
            'resend webhook' => ['/resend/webhook'],
            'instructor document download' => ['/admin/instructor-documents/5/download'],
            'signed export download' => ['/filament/exports/1/download'],
            'health check' => ['/up'],
        ];
    }

    public function test_pulses_own_dashboard_route_is_excluded_from_self_recording(): void
    {
        $path = '/'.config('pulse.path', 'pulse');

        foreach ([SlowRequests::class, UserRequests::class] as $recorder) {
            $ignored = collect(config("pulse.recorders.{$recorder}.ignore"))
                ->contains(fn (string $pattern): bool => (bool) preg_match($pattern, $path));

            $this->assertTrue($ignored, "Pulse's own dashboard route is not excluded from {$recorder}.");
        }
    }

    public function test_slow_queries_recorder_never_captures_raw_bindings(): void
    {
        $source = file_get_contents((new \ReflectionClass(SlowQueries::class))->getFileName());

        $this->assertStringContainsString('$event->sql', $source);
        $this->assertStringNotContainsString('$event->bindings', $source);
    }

    public function test_pulse_check_and_restart_commands_are_available(): void
    {
        $commands = array_keys(Artisan::all());

        $this->assertContains('pulse:check', $commands);
        $this->assertContains('pulse:restart', $commands);
    }

    public function test_supervisor_example_has_placeholders_and_required_controls(): void
    {
        $contents = file_get_contents(base_path('docs/deployment/pulse-check.conf.example'));

        $this->assertStringContainsString('pulse:check', $contents);
        $this->assertStringContainsString('<APP_PATH>', $contents);
        $this->assertStringContainsString('<APP_USER>', $contents);
        $this->assertStringContainsString('<LOG_PATH>', $contents);
        $this->assertStringContainsString('autorestart=true', $contents);
        $this->assertStringContainsString('autostart=true', $contents);

        // Never a real, resolvable working directory — this file must stay a template.
        $this->assertStringNotContainsString(base_path(), $contents);
    }

    public function test_cache_store_supports_cross_process_restart_signalling(): void
    {
        // The testing environment intentionally uses CACHE_STORE=array (no
        // persistence needed since pulse:check never runs in tests) — this
        // checks the documented deployment default in .env.example instead,
        // which is what a real pulse:check process would actually run against.
        preg_match('/^CACHE_STORE=(\w+)/m', file_get_contents(base_path('.env.example')), $matches);

        $this->assertNotEmpty($matches, 'CACHE_STORE not found in .env.example.');
        $this->assertContains(
            $matches[1],
            ['database', 'redis', 'memcached', 'file'],
            "CACHE_STORE [{$matches[1]}] does not persist across processes, so pulse:restart's signal would never reach a running pulse:check."
        );
    }

    public function test_no_third_party_provider_configuration_was_introduced(): void
    {
        $envExample = file_get_contents(base_path('.env.example'));

        $this->assertStringNotContainsString('PULSE_VAPOR', $envExample);
        $this->assertMatchesRegularExpression(
            '/PULSE_ENABLED=false/',
            $envExample,
            'Pulse must ship disabled-by-default, requiring an explicit opt-in per environment.'
        );
    }
}
