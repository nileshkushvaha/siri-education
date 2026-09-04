<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Booking\Services\RecordingService;
use App\Filament\Resources\Recordings\Pages\ListRecordings;
use App\Models\Activity;
use App\Models\Recording;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The per-recording exception to student playback: withholding and
 * restoring. Authorized below the UI, audited as an override with the
 * reason, idempotent, reversible, and never touching the object.
 */
final class RecordingStudentAccessAdminTest extends TestCase
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

    public function test_withholding_requires_the_permission(): void
    {
        $recording = Recording::factory()->available()->create();

        $this->expectException(AuthorizationException::class);

        app(RecordingService::class)->withholdStudentAccess($recording, $this->admin('View:Recording'), 'dispute open');
    }

    public function test_withholding_requires_a_reason(): void
    {
        $recording = Recording::factory()->available()->create();

        $this->expectException(InvalidArgumentException::class);

        app(RecordingService::class)->withholdStudentAccess($recording, $this->admin('Withhold:Recording'), '   ');
    }

    public function test_withholding_is_audited_as_an_override_with_the_reason_and_is_idempotent(): void
    {
        $recording = Recording::factory()->available()->create();
        $admin = $this->admin('Withhold:Recording');
        $service = app(RecordingService::class);

        $this->assertTrue($service->withholdStudentAccess($recording, $admin, 'Dispute SC-1042 under review'));
        $this->assertFalse($service->withholdStudentAccess($recording, $admin, 'again'), 'a second withhold changes nothing');

        $fresh = $recording->fresh();
        $this->assertTrue($fresh->isStudentAccessWithheld());
        $this->assertSame($admin->id, (int) $fresh->student_access_revoked_by);
        $this->assertNotNull($fresh->storage_path, 'the object is untouched');

        $entries = Activity::query()->where('log_name', 'recordings')->where('event', 'recording_student_access_withheld')->get();
        $this->assertCount(1, $entries);
        $this->assertSame($admin->id, (int) $entries->first()->causer_id);
        $this->assertSame('Dispute SC-1042 under review', $entries->first()->properties['override_reason']);
        $this->assertTrue((bool) $entries->first()->properties['is_override']);
    }

    public function test_restoring_reverses_withholding_and_is_audited(): void
    {
        $recording = Recording::factory()->available()->create();
        $admin = $this->admin('Withhold:Recording');
        $service = app(RecordingService::class);

        $this->assertFalse($service->restoreStudentAccess($recording, $admin), 'nothing to restore yet');

        $service->withholdStudentAccess($recording, $admin, 'safety review');
        $this->assertTrue($service->restoreStudentAccess($recording, $admin));

        $fresh = $recording->fresh();
        $this->assertFalse($fresh->isStudentAccessWithheld());
        $this->assertNull($fresh->student_access_revoked_by);

        $this->assertSame(1, Activity::query()->where('event', 'recording_student_access_restored')->count());
    }

    public function test_the_admin_actions_are_shown_only_to_the_permitted_and_only_in_the_applicable_state(): void
    {
        $recording = Recording::factory()->available()->create();

        Livewire::actingAs($this->admin('ViewAny:Recording', 'View:Recording'))
            ->test(ListRecordings::class)
            ->assertTableActionHidden('withholdStudentAccess', $recording)
            ->assertTableActionHidden('restoreStudentAccess', $recording)
            ->assertTableActionVisible('downloadRecording', $recording);

        Livewire::actingAs($this->admin('ViewAny:Recording', 'View:Recording', 'Withhold:Recording'))
            ->test(ListRecordings::class)
            ->assertTableActionVisible('withholdStudentAccess', $recording)
            ->assertTableActionHidden('restoreStudentAccess', $recording);

        $recording->forceFill(['student_access_revoked_at' => now()])->save();

        Livewire::actingAs($this->admin('ViewAny:Recording', 'View:Recording', 'Withhold:Recording'))
            ->test(ListRecordings::class)
            ->assertTableActionHidden('withholdStudentAccess', $recording)
            ->assertTableActionVisible('restoreStudentAccess', $recording);
    }

    public function test_the_withhold_action_runs_through_the_service(): void
    {
        $recording = Recording::factory()->available()->create();
        $admin = $this->admin('ViewAny:Recording', 'View:Recording', 'Withhold:Recording');

        Livewire::actingAs($admin)
            ->test(ListRecordings::class)
            ->callTableAction('withholdStudentAccess', $recording, ['reason' => 'Guardian request received'])
            ->assertHasNoTableActionErrors();

        $this->assertTrue($recording->fresh()->isStudentAccessWithheld());
        $this->assertSame(1, Activity::query()->where('event', 'recording_student_access_withheld')->count());

        Livewire::actingAs($admin)
            ->test(ListRecordings::class)
            ->callTableAction('restoreStudentAccess', $recording);

        $this->assertFalse($recording->fresh()->isStudentAccessWithheld());
    }

    /** A withhold without a reason is refused at the form, before the service is reached. */
    public function test_the_withhold_action_requires_a_reason(): void
    {
        $recording = Recording::factory()->available()->create();

        Livewire::actingAs($this->admin('ViewAny:Recording', 'View:Recording', 'Withhold:Recording'))
            ->test(ListRecordings::class)
            ->callTableAction('withholdStudentAccess', $recording, ['reason' => ''])
            ->assertHasTableActionErrors(['reason']);

        $this->assertFalse($recording->fresh()->isStudentAccessWithheld());
    }

    public function test_the_download_action_links_to_the_admin_download_route_and_never_a_backend_url(): void
    {
        $recording = Recording::factory()->available()->create();

        Livewire::actingAs($this->admin('ViewAny:Recording', 'View:Recording'))
            ->test(ListRecordings::class)
            ->assertTableActionHasUrl('downloadRecording', route('admin.recordings.download', $recording), $recording)
            ->assertTableActionShouldOpenUrlInNewTab('downloadRecording', $recording)
            ->assertDontSee($recording->storage_path)
            ->assertDontSee('drive.google.com');
    }

    public function test_an_unpermitted_admin_cannot_download(): void
    {
        $recording = Recording::factory()->available()->create();

        Livewire::actingAs($this->admin('ViewAny:Recording'))
            ->test(ListRecordings::class)
            ->assertTableActionHidden('downloadRecording', $recording);
    }
}
