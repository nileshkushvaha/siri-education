<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Filament\Pages\Settings\MailSettingsPage;
use App\Models\Activity;
use App\Models\User;
use App\Settings\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * MailSettingsPage previously had zero audit coverage
 * at all (no LogsSettingsUpdates usage, plain ->save()). SMTP password
 * is the one sensitive field here — must always be presence-only
 * redacted, never logged in plaintext or ciphertext.
 */
class MailSettingsLoadUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--path' => 'database/settings']);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

        return $admin;
    }

    public function test_mail_settings_load_defaults(): void
    {
        $settings = app(MailSettings::class);

        $this->assertSame('smtp', $settings->driver);
        $this->assertSame('smtp.mailtrap.io', $settings->host);
        $this->assertFalse($settings->queue_emails);
    }

    public function test_mail_settings_can_be_updated(): void
    {
        $settings = app(MailSettings::class);
        $settings->host = 'smtp.sendgrid.net';
        $settings->port = 2525;
        $settings->queue_emails = true;
        $settings->save();

        $fresh = app()->make(MailSettings::class)->refresh();

        $this->assertSame('smtp.sendgrid.net', $fresh->host);
        $this->assertSame(2525, $fresh->port);
        $this->assertTrue($fresh->queue_emails);
    }

    public function test_saving_the_mail_settings_page_creates_an_audit_event(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(MailSettingsPage::class)
            ->set('data.host', 'smtp.sendgrid.net')
            ->call('save')
            ->assertNotified('Mail settings saved');

        $activity = Activity::where('log_name', 'settings')
            ->where('event', 'settings_updated')
            ->where('properties->settings_class', MailSettings::class)
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame('smtp.mailtrap.io', $activity->properties['changed']['host']['from']);
        $this->assertSame('smtp.sendgrid.net', $activity->properties['changed']['host']['to']);
    }

    public function test_a_newly_set_smtp_password_is_never_stored_in_audit_metadata(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(MailSettingsPage::class)
            ->set('data.password', 'synthetic-smtp-password')
            ->call('save')
            ->assertHasNoFormErrors();

        $activity = Activity::where('log_name', 'settings')
            ->where('event', 'settings_updated')
            ->where('properties->settings_class', MailSettings::class)
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $changed = $activity->properties['changed']['password'];
        $this->assertSame('set', $changed['action']);
        $this->assertFalse($changed['previously_set']);
        $this->assertTrue($changed['now_set']);

        $serialized = (string) json_encode($activity->properties);
        $this->assertStringNotContainsString('synthetic-smtp-password', $serialized);
    }

    public function test_saving_the_mail_settings_page_with_no_changes_creates_no_audit_event(): void
    {
        $this->actingAs($this->admin());

        $settings = app(MailSettings::class);

        Livewire::test(MailSettingsPage::class)
            ->set('data.from_name', $settings->from_name)
            ->call('save');

        $this->assertDatabaseMissing('activity_log', [
            'log_name' => 'settings',
            'event' => 'settings_updated',
        ]);
    }

    public function test_unauthorized_user_cannot_save_mail_settings(): void
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $student = User::factory()->create(['status' => 'active']);
        $student->assignRole('student');

        $this->actingAs($student)
            ->get('/admin/settings/mail')
            ->assertForbidden();

        $this->assertDatabaseMissing('activity_log', [
            'log_name' => 'settings',
            'event' => 'settings_updated',
        ]);
    }
}
