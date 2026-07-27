<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Two independent guarantees.
 *
 *  1. A recorder that fails to write (e.g. because its storage table is
 *     unavailable) must never break the business request it's attached to —
 *     Pulse's internal rescue() + our AppServiceProvider::configurePulse()
 *     exception routing must swallow it, not surface it as a 500. Storage
 *     is deliberately made unavailable via an isolated, table-less
 *     connection scoped to this one test (pulse.storage.database.connection
 *     override) — never by relying on incidental table absence, and never
 *     by touching the real dev/testing schema (which now has the Pulse
 *     tables — see docs/pulse-monitoring.md).
 *  2. A benign metric can be stored and read back on an isolated in-memory
 *     sqlite connection built from the project's own (now-active) Pulse
 *     migration — proving the schema/config are sound independent of any
 *     one database's quirks.
 */
class PulseFailureIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_recorder_failure_does_not_break_a_business_request_when_storage_is_deliberately_unavailable(): void
    {
        config(['database.connections.pulse_missing_tables_test' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);
        DB::purge('pulse_missing_tables_test');

        // Deliberately do NOT run the Pulse migration on this connection —
        // it stays table-less for the duration of this test only.
        config([
            'pulse.enabled' => true,
            'pulse.storage.database.connection' => 'pulse_missing_tables_test',
        ]);

        Log::spy();

        try {
            // "/" is not in any Pulse ignore list, so recorders genuinely
            // attempt to write — with the target connection table-less,
            // the underlying insert throws, which must be swallowed rather
            // than surfaced as a 500.
            $this->get('/')->assertOk();
        } finally {
            config(['pulse.storage.database.connection' => null]);
            DB::purge('pulse_missing_tables_test');
        }
    }

    public function test_benign_metric_can_be_stored_and_retrieved_in_an_isolated_schema(): void
    {
        config(['database.connections.pulse_isolated_test' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);

        $originalDefault = config('database.default');
        config(['database.default' => 'pulse_isolated_test']);
        DB::purge('pulse_isolated_test');

        try {
            (require base_path('database/migrations/2026_07_20_150026_create_pulse_tables.php'))->up();

            DB::connection('pulse_isolated_test')->table('pulse_entries')->insert([
                'timestamp' => now()->timestamp,
                'type' => 'benign_test_metric',
                'key' => 'isolated-test-key',
                'key_hash' => 'isolated-test-key',
                'value' => 1,
            ]);

            $this->assertSame(
                1,
                DB::connection('pulse_isolated_test')->table('pulse_entries')->count()
            );
            $this->assertSame(
                'benign_test_metric',
                DB::connection('pulse_isolated_test')->table('pulse_entries')->value('type')
            );
        } finally {
            config(['database.default' => $originalDefault]);
            DB::purge('pulse_isolated_test');
        }
    }
}
