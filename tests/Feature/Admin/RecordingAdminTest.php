<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Booking\Enums\RecordingFailureCode;
use App\Booking\Enums\RecordingStatus;
use App\Booking\Jobs\CaptureLessonRecordingJob;
use App\Filament\Resources\Recordings\Pages\ListRecordings;
use App\Filament\Resources\Recordings\Pages\ViewRecording;
use App\Filament\Resources\Recordings\RecordingResource;
use App\Models\Activity;
use App\Models\Recording;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Admin operations for recordings: read-only visibility plus one
 * audited recovery action.
 *
 * The security assertions matter as much as the functional ones — an
 * admin screen is a place where a storage locator, a credential, or a
 * provider URL could easily leak into a rendered page.
 */
final class RecordingAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string ...$permissions): User
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']));

        foreach ($permissions as $permission) {
            $admin->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        return $admin;
    }

    public function test_an_admin_with_the_permission_can_list_recordings(): void
    {
        $recording = Recording::factory()->available()->create();

        Livewire::actingAs($this->admin('ViewAny:Recording'))
            ->test(ListRecordings::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$recording]);
    }

    /**
     * The locator is how a recording is fetched from Drive or S3 —
     * rendering it on an admin page would put an out-of-band pointer to
     * private student video into a browser and a proxy cache.
     */
    public function test_the_admin_screens_never_render_the_storage_locator_or_credentials(): void
    {
        $recording = Recording::factory()->available()->create([
            'storage_path' => 'recordings/2026/08/lesson-SECRETLOCATOR.mp4',
        ]);

        Livewire::actingAs($this->admin('ViewAny:Recording', 'View:Recording'))
            ->test(ViewRecording::class, ['record' => $recording->getKey()])
            ->assertOk()
            ->assertDontSee('SECRETLOCATOR')
            ->assertDontSee('drive.google.com')
            ->assertDontSee('googleapis.com')
            // The backend name IS shown — it is operationally useful and
            // reveals nothing about the object itself.
            ->assertSee('Storage backend');
    }

    public function test_the_retry_action_returns_a_failed_recording_to_the_pipeline_and_queues_it(): void
    {
        Queue::fake();

        $recording = Recording::factory()->failed()->create();
        $admin = $this->admin('ViewAny:Recording', 'View:Recording', 'Retry:Recording');

        Livewire::actingAs($admin)
            ->test(ListRecordings::class)
            ->callTableAction('retryIngestion', $recording);

        $recording->refresh();

        $this->assertSame(RecordingStatus::Pending, $recording->status);
        $this->assertNull($recording->failure_code);
        $this->assertSame(0, $recording->capture_attempts);

        // Queued, never inline — an admin click must not hold an HTTP
        // request open for the length of a video transfer.
        Queue::assertPushed(CaptureLessonRecordingJob::class);
    }

    public function test_the_retry_action_is_audited_with_the_acting_admin(): void
    {
        Queue::fake();

        $recording = Recording::factory()->failed()->create([
            'failure_code' => RecordingFailureCode::StorageQuotaExceeded,
        ]);
        $admin = $this->admin('ViewAny:Recording', 'View:Recording', 'Retry:Recording');

        Livewire::actingAs($admin)
            ->test(ListRecordings::class)
            ->callTableAction('retryIngestion', $recording);

        $activity = Activity::query()->where('event', 'recording_retry_requested')->first();

        $this->assertNotNull($activity);
        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame($recording->getKey(), $activity->subject_id);
        $this->assertSame('storage_quota_exceeded', $activity->properties['previous_failure_code'] ?? null);
    }

    public function test_the_retry_action_is_hidden_from_an_admin_without_the_permission(): void
    {
        $recording = Recording::factory()->failed()->create();

        Livewire::actingAs($this->admin('ViewAny:Recording'))
            ->test(ListRecordings::class)
            ->assertTableActionHidden('retryIngestion', $recording);
    }

    /** Only failed recordings are retryable — see RecordingIdempotencyTest for why. */
    public function test_the_retry_action_is_hidden_for_a_recording_that_did_not_fail(): void
    {
        $recording = Recording::factory()->available()->create();

        Livewire::actingAs($this->admin('ViewAny:Recording', 'Retry:Recording'))
            ->test(ListRecordings::class)
            ->assertTableActionHidden('retryIngestion', $recording);
    }

    /**
     * RecordingService is the only writer, and a recording is never
     * administratively deleted — only expired, keeping its metadata.
     */
    public function test_recordings_can_never_be_created_edited_or_deleted_from_the_admin(): void
    {
        $recording = Recording::factory()->available()->create();

        $this->assertFalse(RecordingResource::canCreate());
        $this->assertFalse(RecordingResource::canEdit($recording));
        $this->assertFalse(RecordingResource::canDelete($recording));
        $this->assertFalse(RecordingResource::canDeleteAny());
    }
}
