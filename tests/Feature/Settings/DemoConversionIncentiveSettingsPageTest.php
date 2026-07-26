<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Filament\Pages\Settings\DemoConversionIncentiveSettingsPage;
use App\Models\Activity;
use App\Models\User;
use App\Settings\DemoConversionIncentiveSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * GAP-008 — the admin settings surface for DemoConversionIncentiveSettings.
 * Every change is audit-logged atomically via the shared
 * LogsSettingsUpdates trait, matching every other settings page.
 */
final class DemoConversionIncentiveSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('super_admin');

        return $admin;
    }

    public function test_admin_can_change_the_rule_and_the_change_is_audit_logged(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(DemoConversionIncentiveSettingsPage::class)
            ->set('data.enabled', true)
            ->set('data.conversion_window_days', 14)
            ->set('data.min_completed_paid_lessons', 2)
            ->set('data.bonus_amount_minor', 50000)
            ->set('data.bonus_currency_code', 'inr')
            ->set('data.max_awards_per_pair', 3)
            ->call('save')
            ->assertHasNoErrors();

        $settings = app(DemoConversionIncentiveSettings::class);
        $this->assertTrue($settings->enabled);
        $this->assertSame(14, $settings->conversion_window_days);
        $this->assertSame(2, $settings->min_completed_paid_lessons);
        $this->assertSame(50000, $settings->bonus_amount_minor);
        $this->assertSame('INR', $settings->bonus_currency_code);
        $this->assertSame(3, $settings->max_awards_per_pair);

        $activity = Activity::query()->where('log_name', 'demo_conversion_incentive')->where('event', 'settings_updated')->sole();
        $this->assertSame(DemoConversionIncentiveSettings::class, $activity->properties['settings_class']);
        $this->assertArrayHasKey('enabled', $activity->properties['changed']);
    }

    public function test_a_non_admin_cannot_access_the_settings_page(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->assertFalse(DemoConversionIncentiveSettingsPage::canAccess());

        $this->actingAs($user)->get(DemoConversionIncentiveSettingsPage::getUrl())->assertForbidden();
    }
}
