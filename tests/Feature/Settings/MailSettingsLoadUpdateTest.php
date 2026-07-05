<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Settings\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailSettingsLoadUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--path' => 'database/settings']);
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
}
