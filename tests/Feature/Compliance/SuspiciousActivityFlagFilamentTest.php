<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Compliance\DTOs\SuspiciousActivitySignal;
use App\Compliance\Enums\SuspiciousActivityFlagSeverity;
use App\Compliance\Enums\SuspiciousActivityRuleCode;
use App\Compliance\Services\ComplianceMonitoringService;
use App\Filament\Resources\SuspiciousActivityFlags\SuspiciousActivityFlagResource;
use App\Models\SuspiciousActivityFlag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Item 8 of the phase brief: a permission-controlled, read-only-by-
 * default Filament surface — list/view/filter/search only, no
 * create/edit/delete, and bounded queries regardless of how many
 * flags exist.
 */
class SuspiciousActivityFlagFilamentTest extends TestCase
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

    public function test_resource_has_no_create_edit_or_delete_capability(): void
    {
        $flag = $this->makeFlag();

        $this->assertFalse(SuspiciousActivityFlagResource::canCreate());
        $this->assertFalse(SuspiciousActivityFlagResource::canEdit($flag));
        $this->assertFalse(SuspiciousActivityFlagResource::canDelete($flag));
        $this->assertFalse(SuspiciousActivityFlagResource::canDeleteAny());
    }

    public function test_permitted_admin_can_view_the_compliance_list(): void
    {
        $admin = $this->permittedAdmin(['ViewAny:SuspiciousActivityFlag', 'View:SuspiciousActivityFlag']);
        $this->makeFlag();

        $this->actingAs($admin)
            ->get(SuspiciousActivityFlagResource::getUrl('index'))
            ->assertOk();
    }

    public function test_unauthorized_user_cannot_view_the_compliance_list(): void
    {
        $unauthorized = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->actingAs($unauthorized)
            ->get(SuspiciousActivityFlagResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_create_and_edit_routes_do_not_exist(): void
    {
        $admin = $this->permittedAdmin(['ViewAny:SuspiciousActivityFlag', 'View:SuspiciousActivityFlag']);
        $flag = $this->makeFlag();

        $this->actingAs($admin)->get('/admin/suspicious-activity-flags/create')->assertNotFound();
        $this->actingAs($admin)->get("/admin/suspicious-activity-flags/{$flag->id}/edit")->assertNotFound();
    }

    public function test_list_query_stays_bounded_as_flag_count_grows(): void
    {
        $admin = $this->permittedAdmin(['ViewAny:SuspiciousActivityFlag', 'View:SuspiciousActivityFlag']);

        for ($i = 0; $i < 20; $i++) {
            $this->makeFlag();
        }

        DB::enableQueryLog();
        $this->actingAs($admin)->get(SuspiciousActivityFlagResource::getUrl('index'))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(60, $count);
    }

    private function makeFlag(): SuspiciousActivityFlag
    {
        $subject = User::factory()->create();

        $signal = new SuspiciousActivitySignal(
            ruleCode: SuspiciousActivityRuleCode::RepeatedFailedLogins,
            ruleVersion: 1,
            subjectId: $subject->id,
            actorId: null,
            occurredAt: Date::now(),
            severity: SuspiciousActivityFlagSeverity::High,
            evidence: ['failed_login_count' => 5, 'window_minutes' => 30, 'threshold' => 5],
            thresholdSnapshot: ['enabled' => true, 'threshold' => 5],
            cooldownMinutes: 60,
        );

        return app(ComplianceMonitoringService::class)->record($signal);
    }
}
