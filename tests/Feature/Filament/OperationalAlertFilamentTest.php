<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Alerts\DTOs\OperationalAlertSignal;
use App\Alerts\Enums\OperationalAlertSeverity;
use App\Alerts\Enums\OperationalAlertStatus;
use App\Alerts\Enums\OperationalAlertType;
use App\Alerts\Services\OperationalAlertService;
use App\Filament\Resources\OperationalAlerts\OperationalAlertResource;
use App\Filament\Resources\OperationalAlerts\Pages\ListOperationalAlerts;
use App\Models\OperationalAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Requirement #7 — a permission-controlled Filament surface: list/
 * view/filter/search only, no create/edit/delete, acknowledge/resolve
 * actions call OperationalAlertService exclusively, and bounded
 * queries regardless of how many alerts exist.
 */
class OperationalAlertFilamentTest extends TestCase
{
    use RefreshDatabase;

    private function permittedAdmin(array $permissions): User
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $admin->assignRole('manager');

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $admin->givePermissionTo($permissions);

        return $admin;
    }

    private function makeAlert(string $subjectId = 'booking-1'): OperationalAlert
    {
        return app(OperationalAlertService::class)->createOrMerge(new OperationalAlertSignal(
            type: OperationalAlertType::MeetingCreationFailed,
            category: OperationalAlertType::MeetingCreationFailed->category(),
            severity: OperationalAlertSeverity::High,
            title: 'Meeting creation failed',
            summary: 'Provider returned a failure.',
            subjectType: 'App\\Models\\Booking',
            subjectId: $subjectId,
        ));
    }

    public function test_resource_has_no_create_edit_or_delete_capability(): void
    {
        $alert = $this->makeAlert();

        $this->assertFalse(OperationalAlertResource::canCreate());
        $this->assertFalse(OperationalAlertResource::canEdit($alert));
        $this->assertFalse(OperationalAlertResource::canDelete($alert));
        $this->assertFalse(OperationalAlertResource::canDeleteAny());
    }

    public function test_permitted_admin_can_view_the_alert_list(): void
    {
        $admin = $this->permittedAdmin(['ViewAny:OperationalAlert', 'View:OperationalAlert']);
        $this->makeAlert();

        $this->actingAs($admin)
            ->get(OperationalAlertResource::getUrl('index'))
            ->assertOk();
    }

    public function test_unauthorized_user_cannot_view_the_alert_list(): void
    {
        $unauthorized = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->actingAs($unauthorized)
            ->get(OperationalAlertResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_create_and_edit_routes_do_not_exist(): void
    {
        $admin = $this->permittedAdmin(['ViewAny:OperationalAlert', 'View:OperationalAlert']);
        $alert = $this->makeAlert();

        $this->actingAs($admin)->get('/admin/operational-alerts/create')->assertNotFound();
        $this->actingAs($admin)->get("/admin/operational-alerts/{$alert->id}/edit")->assertNotFound();
    }

    public function test_list_query_stays_bounded_as_alert_count_grows(): void
    {
        $admin = $this->permittedAdmin(['ViewAny:OperationalAlert', 'View:OperationalAlert']);

        for ($i = 0; $i < 20; $i++) {
            $this->makeAlert("booking-{$i}");
        }

        DB::enableQueryLog();
        $this->actingAs($admin)->get(OperationalAlertResource::getUrl('index'))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(60, $count);
    }

    public function test_permitted_admin_can_acknowledge_an_open_alert(): void
    {
        $admin = $this->permittedAdmin(['ViewAny:OperationalAlert', 'Acknowledge:OperationalAlert']);
        $alert = $this->makeAlert();

        $this->actingAs($admin);

        Livewire::test(ListOperationalAlerts::class)
            ->callTableAction('acknowledge', $alert);

        $this->assertSame(OperationalAlertStatus::Acknowledged, $alert->fresh()->status);
        $this->assertSame($admin->id, $alert->fresh()->acknowledged_by);
    }

    public function test_permitted_admin_can_resolve_an_alert_with_a_reason(): void
    {
        $admin = $this->permittedAdmin(['ViewAny:OperationalAlert', 'Resolve:OperationalAlert']);
        $alert = $this->makeAlert();

        $this->actingAs($admin);

        Livewire::test(ListOperationalAlerts::class)
            ->callTableAction('resolve', $alert, data: ['reason' => 'Meeting was manually created.']);

        $this->assertSame(OperationalAlertStatus::Resolved, $alert->fresh()->status);
        $this->assertSame('Meeting was manually created.', $alert->fresh()->resolution_reason);
    }

    public function test_an_admin_without_resolve_permission_cannot_resolve(): void
    {
        $admin = $this->permittedAdmin(['ViewAny:OperationalAlert']);
        $alert = $this->makeAlert();

        $this->actingAs($admin);

        Livewire::test(ListOperationalAlerts::class)
            ->assertTableActionHidden('resolve', $alert);

        $this->assertSame(OperationalAlertStatus::Open, $alert->fresh()->status);
    }
}
