<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Filament\Pages\Settings\MailSettingsPage;
use App\Models\Activity;
use App\Models\User;
use App\Notifications\Auth\EmailVerificationCodeNotification;
use App\Services\Mail\TransactionalMailSender;
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

        // Blank, not 'smtp': the driver is now actually consumed by
        // TransactionalMailSender, so seeding a concrete transport would
        // override MAIL_MAILER on every fresh install before an administrator
        // has chosen one.
        $this->assertSame('', $settings->driver);
        $this->assertSame('smtp.mailtrap.io', $settings->host);
        $this->assertSame(30, $settings->connection_timeout);
    }

    public function test_settings_nothing_reads_are_not_offered_to_administrators(): void
    {
        // transactional_domain, queue_emails and retry_attempts were editable
        // but inert — changing them persisted and did nothing, which is worse
        // than not offering them. They must stay gone unless something wires
        // them up.
        $properties = array_keys(get_class_vars(MailSettings::class));

        $this->assertNotContains('transactional_domain', $properties);
        $this->assertNotContains('queue_emails', $properties);
        $this->assertNotContains('retry_attempts', $properties);
    }

    public function test_a_blank_driver_inherits_the_environment_mailer(): void
    {
        $settings = app(MailSettings::class);
        $settings->driver = '';
        app()->instance(MailSettings::class, $settings);

        $this->assertSame(
            config('mail.default'),
            app(TransactionalMailSender::class)->mailer(),
        );
    }

    public function test_a_configured_driver_overrides_the_environment_mailer(): void
    {
        $settings = app(MailSettings::class);
        $settings->driver = 'log';
        app()->instance(MailSettings::class, $settings);

        // The settings table is the authority — an admin changing Mail Driver
        // must take effect without a deploy.
        $this->assertNotSame('log', config('mail.default'));
        $this->assertSame('log', app(TransactionalMailSender::class)->mailer());
    }

    public function test_a_driver_naming_no_configured_mailer_falls_back_instead_of_failing(): void
    {
        $settings = app(MailSettings::class);
        $settings->driver = 'a-mailer-that-was-removed';
        app()->instance(MailSettings::class, $settings);

        // A stale value must never hard-fail every send with no way to recover
        // from the UI.
        $this->assertSame(
            config('mail.default'),
            app(TransactionalMailSender::class)->mailer(),
        );
    }

    public function test_queued_transactional_mail_uses_the_configured_driver(): void
    {
        $settings = app(MailSettings::class);
        $settings->driver = 'log';
        $settings->auth_from_email = 'no-reply@sirieducation.com';
        $settings->auth_from_name = 'SIRI Education';
        app()->instance(MailSettings::class, $settings);

        $message = (new EmailVerificationCodeNotification('123456', 15))->toMail(
            User::factory()->create(['email' => 'student@example.test']),
        );

        $this->assertSame('log', $message->mailer);
        $this->assertSame('no-reply@sirieducation.com', $message->from[0]);
    }

    public function test_mail_settings_can_be_updated(): void
    {
        $settings = app(MailSettings::class);
        $settings->host = 'smtp.sendgrid.net';
        $settings->port = 2525;
        $settings->connection_timeout = 45;
        $settings->save();

        $fresh = app()->make(MailSettings::class)->refresh();

        $this->assertSame('smtp.sendgrid.net', $fresh->host);
        $this->assertSame(2525, $fresh->port);
        $this->assertSame(45, $fresh->connection_timeout);
    }

    public function test_saving_on_a_non_smtp_driver_preserves_the_stored_smtp_configuration(): void
    {
        $this->actingAs($this->admin());

        // The SMTP fields are hidden for non-SMTP drivers and therefore absent
        // from the submitted state. Saving must fall back to the stored values:
        // reading them straight off the form state threw a TypeError, and
        // defaulting them to nulls would wipe a config an admin can switch back
        // to.
        Livewire::test(MailSettingsPage::class)
            ->set('data.driver', 'resend')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Mail settings saved');

        $fresh = app()->make(MailSettings::class)->refresh();

        $this->assertSame('resend', $fresh->driver);
        $this->assertSame('smtp.mailtrap.io', $fresh->host);
        $this->assertSame(587, $fresh->port);
        $this->assertSame(30, $fresh->connection_timeout);
    }

    public function test_saving_the_mail_settings_page_creates_an_audit_event(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(MailSettingsPage::class)
            // The SMTP section only renders for the SMTP driver, and Filament
            // does not dehydrate hidden fields.
            ->set('data.driver', 'smtp')
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
            ->set('data.driver', 'smtp')
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
