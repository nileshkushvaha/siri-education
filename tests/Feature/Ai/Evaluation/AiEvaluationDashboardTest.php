<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Evaluation;

use App\Ai\Enums\AiFeature;
use App\Ai\Enums\AiRunStatus;
use App\Alerts\Enums\OperationalAlertType;
use App\Console\Commands\Ai\CheckAiBudgetThreshold;
use App\Filament\Pages\AiEvaluationDashboard;
use App\Models\AiRun;
use App\Models\OperationalAlert;
use App\Models\User;
use App\Settings\AiSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The admin dashboard's access control, and the budget alert that reuses
 * the existing operational-alert pipeline.
 */
class AiEvaluationDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole(Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']));
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'Configure:AiPlatform', 'guard_name' => 'web']));

        return $user->fresh();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

        return $user->fresh();
    }

    private function spend(float $cost): void
    {
        AiRun::query()->create([
            'feature_key' => AiFeature::QualityInsights->value,
            'provider' => 'openai',
            'model' => 'gpt-4.1',
            'status' => AiRunStatus::Succeeded->value,
            'estimated_cost' => $cost,
        ]);
    }

    // ── Access ────────────────────────────────────────────────────────

    public function test_an_ai_platform_operator_can_open_the_dashboard(): void
    {
        $this->actingAs($this->operator());

        $this->assertTrue(AiEvaluationDashboard::canAccess());
        Livewire::test(AiEvaluationDashboard::class)->assertOk();
    }

    public function test_a_super_admin_can_open_the_dashboard(): void
    {
        $this->actingAs($this->superAdmin());

        $this->assertTrue(AiEvaluationDashboard::canAccess());
    }

    public function test_a_user_without_the_permission_cannot(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole(Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']));

        $this->actingAs($user->fresh());

        $this->assertFalse(AiEvaluationDashboard::canAccess());
    }

    public function test_an_instructor_cannot_reach_it_over_http(): void
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole(Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']));

        $this->actingAs($instructor->fresh())
            ->get(AiEvaluationDashboard::getUrl())
            ->assertForbidden();
    }

    public function test_the_dashboard_renders_with_no_ai_activity_at_all(): void
    {
        $this->actingAs($this->operator());

        Livewire::test(AiEvaluationDashboard::class)
            ->assertOk()
            ->assertSee('No AI activity in this period');
    }

    // ── Budget alerting ───────────────────────────────────────────────

    public function test_no_alert_is_raised_below_the_threshold(): void
    {
        $settings = app(AiSettings::class);
        $settings->daily_cost_limit = 10.0;
        $settings->monthly_cost_limit = null;
        $settings->budget_alert_threshold = 0.8;
        $settings->save();

        $this->spend(1.0);

        $this->artisan(CheckAiBudgetThreshold::class)->assertSuccessful();

        $this->assertSame(0, OperationalAlert::query()->count());
    }

    public function test_an_alert_is_raised_once_spend_crosses_the_threshold(): void
    {
        $settings = app(AiSettings::class);
        $settings->daily_cost_limit = 10.0;
        $settings->monthly_cost_limit = null;
        $settings->budget_alert_threshold = 0.8;
        $settings->save();

        $this->spend(8.5);

        $this->artisan(CheckAiBudgetThreshold::class)->assertSuccessful();

        $alert = OperationalAlert::query()->sole();

        $this->assertSame(OperationalAlertType::AiBudgetThresholdReached, $alert->type);
        $this->assertStringContainsString('85%', $alert->title);
        // The alert carries spend figures only — never a feature, prompt
        // or anything about what was analysed.
        $this->assertSame('daily', $alert->metadata['window']);
        $this->assertArrayNotHasKey('feature', $alert->metadata);
    }

    /** Repeat checks merge into one alert rather than one per hour. */
    public function test_repeat_checks_do_not_stack_alerts(): void
    {
        $settings = app(AiSettings::class);
        $settings->daily_cost_limit = 10.0;
        $settings->monthly_cost_limit = null;
        $settings->budget_alert_threshold = 0.8;
        $settings->save();

        $this->spend(9.0);

        $this->artisan(CheckAiBudgetThreshold::class);
        $this->artisan(CheckAiBudgetThreshold::class);

        $this->assertSame(1, OperationalAlert::query()->count());
        $this->assertSame(2, OperationalAlert::query()->sole()->occurrence_count);
    }

    public function test_alerting_can_be_disabled(): void
    {
        $settings = app(AiSettings::class);
        $settings->daily_cost_limit = 10.0;
        $settings->budget_alert_threshold = null;
        $settings->save();

        $this->spend(9.9);

        $this->artisan(CheckAiBudgetThreshold::class)->assertSuccessful();

        $this->assertSame(0, OperationalAlert::query()->count());
    }

    /** A zero limit is a deliberate stop, not a threshold to warn about. */
    public function test_a_zero_limit_raises_no_alert(): void
    {
        $settings = app(AiSettings::class);
        $settings->daily_cost_limit = 0.0;
        $settings->monthly_cost_limit = null;
        $settings->budget_alert_threshold = 0.8;
        $settings->save();

        $this->spend(0.0);

        $this->artisan(CheckAiBudgetThreshold::class)->assertSuccessful();

        $this->assertSame(0, OperationalAlert::query()->count());
    }
}
