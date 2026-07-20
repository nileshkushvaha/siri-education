<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Pulse\Contracts\Storage;
use Laravel\Pulse\Entry;
use Laravel\Pulse\Value;
use Tests\TestCase;

/**
 * Phase 24O.1 — GAP-033 corrective: exercises the actual installed Pulse
 * storage implementation (not just raw SQL) against the now-active,
 * SHA2-based migration, using only benign synthetic entries.
 */
class PulseStorageCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    /** Required test 9: Pulse storage records benign values/entries via the real storage API. */
    public function test_storage_records_a_benign_value_and_entry(): void
    {
        $storage = app(Storage::class);

        $storage->store(collect([
            new Value(now()->timestamp, 'phase_24o1_compat_value', 'benign-value-key', 'benign-value'),
            new Entry(now()->timestamp, 'phase_24o1_compat_entry', 'benign-entry-key', 1),
        ]));

        $this->assertSame(1, DB::table('pulse_values')->where('type', 'phase_24o1_compat_value')->count());
        $this->assertSame(1, DB::table('pulse_entries')->where('type', 'phase_24o1_compat_entry')->count());
        $this->assertSame(
            'benign-value',
            DB::table('pulse_values')->where('type', 'phase_24o1_compat_value')->value('value')
        );
    }

    /** Required test 10: Pulse aggregation/read succeeds through the real storage API. */
    public function test_storage_aggregates_and_reads_benign_entries(): void
    {
        $storage = app(Storage::class);

        $storage->store(collect([
            (new Entry(now()->timestamp, 'phase_24o1_compat_aggregate', 'benign-agg-key', 5))->count()->sum(),
            (new Entry(now()->timestamp, 'phase_24o1_compat_aggregate', 'benign-agg-key', 7))->count()->sum(),
        ]));

        $results = $storage->aggregate(
            'phase_24o1_compat_aggregate',
            ['count', 'sum'],
            CarbonInterval::hour(),
        );

        $row = $results->firstWhere('key', 'benign-agg-key');

        $this->assertNotNull($row, 'Expected an aggregated row for the benign test key.');
        $this->assertSame(2, (int) $row->count);
        $this->assertSame(12, (int) $row->sum);
    }

    /** Required test 11: trimming remains compatible with the replacement hash expression. */
    public function test_trimming_removes_old_benign_entries(): void
    {
        DB::table('pulse_values')->insert([
            'timestamp' => Carbon::now()->subDays(30)->timestamp,
            'type' => 'phase_24o1_compat_trim',
            'key' => 'old-key',
            'value' => 'stale',
        ]);
        DB::table('pulse_entries')->insert([
            'timestamp' => Carbon::now()->subDays(30)->timestamp,
            'type' => 'phase_24o1_compat_trim',
            'key' => 'old-key',
            'value' => 1,
        ]);

        config(['pulse.storage.trim.keep' => '7 days']);

        app(Storage::class)->trim();

        $this->assertSame(0, DB::table('pulse_values')->where('type', 'phase_24o1_compat_trim')->count());
        $this->assertSame(0, DB::table('pulse_entries')->where('type', 'phase_24o1_compat_trim')->count());
    }

    /** Required test 20: no application-domain data is recorded or mutated by a benign storage write. */
    public function test_storage_write_does_not_touch_application_domain_tables(): void
    {
        $usersBefore = DB::table('users')->count();
        $activityBefore = DB::table('activity_log')->count();

        app(Storage::class)->store(collect([
            new Entry(now()->timestamp, 'phase_24o1_compat_isolation', 'domain-isolation-key', 1),
        ]));

        $this->assertSame($usersBefore, DB::table('users')->count());
        $this->assertSame($activityBefore, DB::table('activity_log')->count());
    }

    /** Required test 21: no external provider call occurs while recording/reading benign metrics. */
    public function test_no_external_http_call_occurs_during_storage_operations(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        app(Storage::class)->store(collect([
            new Value(now()->timestamp, 'phase_24o1_compat_no_http', 'no-http-key', 'x'),
        ]));

        Http::assertNothingSent();
    }

    /**
     * Step 8 / required test 17 (meta-check): no Pulse test may silently
     * pass because a table happens to be absent — a genuinely missing
     * table must fail loudly, not be swallowed by markTestSkipped.
     */
    public function test_no_pulse_test_silently_skips_on_missing_tables(): void
    {
        $offenders = [];

        foreach ($this->pulseTestFiles() as $file) {
            if (realpath($file) === realpath(__FILE__)) {
                continue; // This meta-check's own pattern text would otherwise flag itself.
            }

            $source = file_get_contents($file);

            if (preg_match('/markTestSkipped.{0,120}(hasTable|pulse_entries|pulse_values|pulse_aggregates)/s', $source)) {
                $offenders[] = $file;
            }
        }

        $this->assertEmpty(
            $offenders,
            'A Pulse test skips based on table presence instead of failing — this can silently mask a broken migration: '.implode(', ', $offenders)
        );
    }

    /** @return list<string> */
    private function pulseTestFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('tests')));

        foreach ($iterator as $file) {
            if ($file->isFile() && str_starts_with($file->getFilename(), 'Pulse') && str_ends_with($file->getFilename(), 'Test.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
