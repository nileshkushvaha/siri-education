<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Concurrency;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Booking\Concurrency\ConcurrencyTestCase;
use Tests\Support\ConcurrencyRetryTestJob;

/**
 * SRS-26-2: a real cross-process race retrying
 * the SAME failed job. FailedJobRetryService's row lock on
 * `failed_jobs` must let exactly one worker requeue+forget it; the
 * other observes it already gone and returns NotFound. Reuses
 * Tests\Feature\Booking\Concurrency\ConcurrencyTestCase's domain-
 * agnostic race()/tearDownAfterClass() harness, same as
 * SuperAdminGuardConcurrencyTest.
 */
class FailedJobRetryConcurrencyTest extends ConcurrencyTestCase
{
    public function test_concurrent_retries_of_the_same_failed_job_produce_exactly_one_queue_row_and_one_success_audit(): void
    {
        Permission::firstOrCreate(['name' => 'queue_monitor.retry_failed_jobs', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web'])->givePermissionTo('queue_monitor.retry_failed_jobs');

        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('manager');

        dispatch(new ConcurrencyRetryTestJob)->onConnection('database')->onQueue('default');
        $row = DB::table('jobs')->latest('id')->first();
        $payload = json_decode($row->payload, true);
        $uuid = $payload['uuid'];

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => $row->payload,
            'exception' => "RuntimeException: boom\n#0 test",
            'failed_at' => now(),
        ]);
        DB::table('jobs')->where('id', $row->id)->delete();

        $results = $this->race([
            ['retry-failed-job', ['actor_id' => $admin->id, 'uuid' => $uuid]],
            ['retry-failed-job', ['actor_id' => $admin->id, 'uuid' => $uuid]],
        ]);

        foreach ($results as $result) {
            $this->assertTrue($result['ok'], json_encode($results));
        }

        $outcomes = array_map(fn (array $r): string => $r['result']['outcome'], $results);
        sort($outcomes);

        $this->assertSame(['not_found', 'retried'], $outcomes);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->where('uuid', $uuid)->count());
        $this->assertSame(1, Activity::query()->where('log_name', 'queue')->where('event', 'failed_job_retried')->count());
    }
}
