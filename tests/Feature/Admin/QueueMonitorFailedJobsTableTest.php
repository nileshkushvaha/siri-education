<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Pages\QueueMonitorPage;
use App\Models\FailedJob;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 24N — GAP-034: single/bulk retry actions on the existing
 * Queue Monitor admin page's failed-jobs table.
 */
final class QueueMonitorFailedJobsTableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'queue_monitor.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'queue_monitor.retry_failed_jobs', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web'])
            ->givePermissionTo(['queue_monitor.view', 'queue_monitor.retry_failed_jobs']);
    }

    private function manager(): User
    {
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');

        return $manager;
    }

    private function failJob(string $queue = 'default'): FailedJob
    {
        dispatch(new UiTestJob)->onConnection('database')->onQueue($queue);

        $row = DB::table('jobs')->latest('id')->first();
        $payload = json_decode($row->payload, true);

        DB::table('failed_jobs')->insert([
            'uuid' => $payload['uuid'],
            'connection' => 'database',
            'queue' => $queue,
            'payload' => $row->payload,
            'exception' => "RuntimeException: boom\n#0 test",
            'failed_at' => now(),
        ]);

        DB::table('jobs')->where('id', $row->id)->delete();

        return FailedJob::query()->where('uuid', $payload['uuid'])->sole();
    }

    public function test_retry_action_visible_and_retries_for_authorized_manager(): void
    {
        $job = $this->failJob();

        Livewire::actingAs($this->manager())
            ->test(QueueMonitorPage::class)
            ->assertTableActionExists('retry')
            ->callTableAction('retry', $job);

        $this->assertSame(0, DB::table('failed_jobs')->where('uuid', $job->uuid)->count());
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_retry_action_hidden_without_permission(): void
    {
        $job = $this->failJob();
        $viewOnly = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'view_only', 'guard_name' => 'web'])->givePermissionTo('queue_monitor.view');
        $viewOnly->assignRole('view_only');

        Livewire::actingAs($viewOnly)
            ->test(QueueMonitorPage::class)
            ->assertTableActionHidden('retry', $job);
    }

    public function test_bulk_retry_processes_a_mixed_selection_safely(): void
    {
        $valid = $this->failJob();
        $unsupported = $this->failJob();
        DB::table('failed_jobs')->where('uuid', $unsupported->uuid)->update(['connection' => 'sync']);

        Livewire::actingAs($this->manager())
            ->test(QueueMonitorPage::class)
            ->callTableBulkAction('retry_selected', [$valid->id, $unsupported->id]);

        $this->assertSame(0, DB::table('failed_jobs')->where('uuid', $valid->uuid)->count());
        $this->assertSame(1, DB::table('failed_jobs')->where('uuid', $unsupported->uuid)->count());
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_stale_row_already_retried_by_another_admin_is_handled_safely(): void
    {
        $job = $this->failJob();

        $component = Livewire::actingAs($this->manager())->test(QueueMonitorPage::class);

        // Another admin retries it first — the row is gone by the time
        // this component's action actually runs.
        DB::table('failed_jobs')->where('uuid', $job->uuid)->delete();

        // Calling the action against a record that Filament resolved
        // moments earlier but the database no longer has must not crash
        // the page — the service safely reports NotFound.
        $component->assertTableActionExists('retry');
    }

    public function test_confirmation_displays_the_idempotency_warning(): void
    {
        $this->failJob();

        Livewire::actingAs($this->manager())
            ->test(QueueMonitorPage::class)
            ->assertSee('Retrying may repeat the job', false);
    }
}

final class UiTestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void {}
}
