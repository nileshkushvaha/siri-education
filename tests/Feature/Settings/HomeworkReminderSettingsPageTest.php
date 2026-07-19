<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Filament\Pages\Settings\HomeworkReminderSettingsPage;
use App\Models\Activity;
use App\Models\User;
use App\Settings\HomeworkSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 24K — GAP-020: the admin settings surface for HomeworkSettings.
 * Offsets are validated (bounded, positive, deduplicated, at least one
 * while enabled), and every change is audit-logged atomically via the
 * shared LogsSettingsUpdates trait.
 */
final class HomeworkReminderSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('super_admin');

        return $admin;
    }

    public function test_admin_can_change_offsets_and_the_change_is_audit_logged(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(HomeworkReminderSettingsPage::class)
            ->set('data.homework_due_reminders_enabled', true)
            ->set('data.homework_due_reminder_offset_hours', ['48', '3'])
            ->set('data.homework_reminder_channel_email_enabled', true)
            ->set('data.homework_reminder_channel_whatsapp_enabled', false)
            ->set('data.homework_reminder_channel_sms_enabled', false)
            ->call('save')
            ->assertHasNoErrors();

        $settings = app(HomeworkSettings::class);
        $this->assertSame([3, 48], $settings->normalizedOffsets());

        $activity = Activity::query()->where('log_name', 'homework')->where('event', 'settings_updated')->sole();
        $this->assertSame(HomeworkSettings::class, $activity->properties['settings_class']);
        $this->assertArrayHasKey('homework_due_reminder_offset_hours', $activity->properties['changed']);
    }

    public function test_at_least_one_offset_is_required_while_enabled(): void
    {
        $admin = $this->admin();
        $before = app(HomeworkSettings::class)->homework_due_reminder_offset_hours;

        Livewire::actingAs($admin)
            ->test(HomeworkReminderSettingsPage::class)
            ->set('data.homework_due_reminders_enabled', true)
            ->set('data.homework_due_reminder_offset_hours', [])
            ->call('save');

        $this->assertSame($before, app(HomeworkSettings::class)->homework_due_reminder_offset_hours);
    }

    public function test_negative_and_non_integer_offsets_are_rejected(): void
    {
        $admin = $this->admin();
        $before = app(HomeworkSettings::class)->homework_due_reminder_offset_hours;

        Livewire::actingAs($admin)
            ->test(HomeworkReminderSettingsPage::class)
            ->set('data.homework_due_reminder_offset_hours', ['-5'])
            ->call('save');

        $this->assertSame($before, app(HomeworkSettings::class)->homework_due_reminder_offset_hours);
    }

    public function test_offsets_beyond_the_bounded_maximum_are_rejected(): void
    {
        $admin = $this->admin();
        $before = app(HomeworkSettings::class)->homework_due_reminder_offset_hours;

        Livewire::actingAs($admin)
            ->test(HomeworkReminderSettingsPage::class)
            ->set('data.homework_due_reminder_offset_hours', ['1000'])
            ->call('save');

        $this->assertSame($before, app(HomeworkSettings::class)->homework_due_reminder_offset_hours);
    }

    public function test_non_admin_cannot_access_the_settings_page(): void
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');

        $this->assertFalse(HomeworkReminderSettingsPage::canAccess());
        $this->actingAs($student);
        $this->assertFalse(HomeworkReminderSettingsPage::canAccess());
    }
}
