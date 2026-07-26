<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Filament\Pages\Settings\InstructorEarningSettingsPage;
use App\Models\Activity;
use App\Models\User;
use App\Settings\InstructorEarningSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * InstructorEarningSettingsPage previously wrote its audit record as a
 * second, separate step after ->save() (not atomic with it). The three
 * financial feature switches (earnings/periodic compensation/withdrawals)
 * are deliberately untouched here — they flow through
 * FinancialFeatureConfigurationService, a distinct, already
 * audited/preflighted path, out of scope for these tests. These tests
 * exercise only the plain fields InstructorEarningSettingsPage itself
 * persists via saveSettingsWithAudit().
 */
class InstructorEarningSettingsAuditTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

        return $admin;
    }

    private function latestUpdate(): ?Activity
    {
        return Activity::query()
            ->where('event', 'settings_updated')
            ->where('properties->settings_class', InstructorEarningSettings::class)
            ->latest('id')
            ->first();
    }

    public function test_saving_a_plain_field_change_creates_an_audit_event(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(InstructorEarningSettingsPage::class)
            ->set('data.hold_days', 10)
            ->call('save')
            ->assertNotified('Instructor earning settings saved');

        $activity = $this->latestUpdate();
        $this->assertNotNull($activity);
        $this->assertArrayHasKey('hold_days', $activity->properties['changed']);
        $this->assertSame(10, $activity->properties['changed']['hold_days']['to']);
    }

    public function test_saving_with_no_changes_creates_no_audit_event(): void
    {
        $this->actingAs($this->admin());

        $settings = app(InstructorEarningSettings::class);

        Livewire::test(InstructorEarningSettingsPage::class)
            ->set('data.hold_days', $settings->hold_days)
            ->set('data.payout_max_attempts', $settings->payout_max_attempts)
            ->call('save');

        $this->assertNull($this->latestUpdate());
    }

    public function test_unauthorized_user_cannot_save_instructor_earning_settings(): void
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');

        $this->actingAs($student)
            ->get('/admin/settings/instructor-earnings')
            ->assertForbidden();

        $this->assertNull($this->latestUpdate());
    }
}
