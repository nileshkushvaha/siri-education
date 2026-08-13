<?php

declare(strict_types=1);

namespace Tests\Feature\Package;

use App\Exceptions\HistoricalRecordCannotBeDeletedException;
use App\Models\PackageBenefitRule;
use App\Models\User;
use App\Package\Exceptions\PackageException;
use App\Package\Services\PackageBenefitRuleService;
use Database\Seeders\PackagePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PackageBenefitRuleTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PackagePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);

        $this->manager = User::factory()->create(['status' => 'active']);
        $this->manager->assignRole('manager');
    }

    private function service(): PackageBenefitRuleService
    {
        return app(PackageBenefitRuleService::class);
    }

    // ── Quantity validation ──────────────────────────────────────────────

    public function test_manager_can_create_a_rule_with_consistent_quantities(): void
    {
        $rule = $this->service()->create($this->manager, [
            'name' => '14 paid + 1 bonus',
            'paid_quantity' => 14,
            'bonus_quantity' => 1,
            'total_quantity' => 15,
        ]);

        $this->assertDatabaseHas('package_benefit_rules', [
            'id' => $rule->id,
            'paid_quantity' => 14,
            'bonus_quantity' => 1,
            'total_quantity' => 15,
        ]);
    }

    public function test_mismatched_total_quantity_is_rejected(): void
    {
        $this->expectException(PackageException::class);

        $this->service()->create($this->manager, [
            'name' => 'Bad rule',
            'paid_quantity' => 14,
            'bonus_quantity' => 1,
            'total_quantity' => 20,
        ]);
    }

    public function test_mismatched_total_quantity_is_rejected_at_database_level(): void
    {
        $this->expectException(QueryException::class);

        PackageBenefitRule::query()->create([
            'name' => 'Direct bad rule',
            'paid_quantity' => 14,
            'bonus_quantity' => 1,
            'total_quantity' => 99,
        ]);
    }

    public function test_updating_to_inconsistent_quantities_is_rejected(): void
    {
        $rule = $this->service()->create($this->manager, [
            'name' => 'Rule', 'paid_quantity' => 10, 'bonus_quantity' => 0, 'total_quantity' => 10,
        ]);

        $this->expectException(PackageException::class);
        $this->service()->update($this->manager, $rule, ['paid_quantity' => 12]);
    }

    // ── Activation ────────────────────────────────────────────────────────

    public function test_manager_can_deactivate_and_reactivate_a_rule(): void
    {
        $rule = $this->service()->create($this->manager, [
            'name' => 'Rule', 'paid_quantity' => 5, 'bonus_quantity' => 0, 'total_quantity' => 5,
        ]);

        $this->service()->deactivate($this->manager, $rule);
        $this->assertFalse($rule->fresh()->is_active);

        $this->service()->activate($this->manager, $rule);
        $this->assertTrue($rule->fresh()->is_active);
    }

    public function test_inactive_rules_are_excluded_from_the_active_scope(): void
    {
        $rule = $this->service()->create($this->manager, [
            'name' => 'Rule', 'paid_quantity' => 5, 'bonus_quantity' => 0, 'total_quantity' => 5, 'is_active' => false,
        ]);

        $this->assertFalse(PackageBenefitRule::query()->active()->whereKey($rule->id)->exists());
    }

    // ── Authorization ─────────────────────────────────────────────────────

    public function test_unauthorized_user_cannot_create_a_rule(): void
    {
        $student = User::factory()->create(['status' => 'active']);

        $this->expectException(AuthorizationException::class);
        $this->service()->create($student, ['name' => 'Rule', 'paid_quantity' => 5, 'bonus_quantity' => 0, 'total_quantity' => 5]);
    }

    public function test_rule_cannot_be_force_deleted(): void
    {
        $rule = $this->service()->create($this->manager, [
            'name' => 'Rule', 'paid_quantity' => 5, 'bonus_quantity' => 0, 'total_quantity' => 5,
        ]);

        $this->expectException(HistoricalRecordCannotBeDeletedException::class);
        $rule->forceDelete();
    }
}
