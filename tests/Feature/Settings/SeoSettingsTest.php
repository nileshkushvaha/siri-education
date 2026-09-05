<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Filament\Pages\Settings\SeoSettingsPage;
use App\Models\Activity;
use App\Models\User;
use App\Settings\SeoSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SeoSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--path' => 'database/settings']);
    }

    public function test_seo_settings_load(): void
    {
        $settings = app(SeoSettings::class);

        $this->assertSame('index,follow', $settings->robots);
        $this->assertSame('summary_large_image', $settings->twitter_card);
    }

    public function test_admin_can_update_seo_settings(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

        $this->actingAs($admin);

        Livewire::test(SeoSettingsPage::class)
            ->set('data.selected_page', 'defaults')
            ->set('data.meta_title', 'Learn Anything, Anywhere')
            ->set('data.meta_description', 'Book tutoring sessions with vetted teachers.')
            ->set('data.robots', 'noindex,nofollow')
            ->set('data.twitter_card', 'summary')
            ->call('save')
            ->assertNotified('SEO settings saved');

        $settings = app()->make(SeoSettings::class)->refresh();

        $this->assertSame('Learn Anything, Anywhere', $settings->meta_title);
        $this->assertSame('noindex,nofollow', $settings->robots);
        $this->assertSame('summary', $settings->twitter_card);
    }

    public function test_saving_seo_settings_creates_an_audit_event_with_the_diff(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
        $this->actingAs($admin);

        Livewire::test(SeoSettingsPage::class)
            ->set('data.selected_page', 'defaults')
            ->set('data.meta_title', 'Learn Anything, Anywhere')
            ->set('data.robots', 'noindex,nofollow')
            ->set('data.twitter_card', 'summary')
            ->call('save');

        $activity = Activity::where('log_name', 'settings')
            ->where('event', 'settings_updated')
            ->where('properties->settings_class', SeoSettings::class)
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame('noindex,nofollow', $activity->properties['changed']['robots']['to']);
    }

    public function test_saving_unchanged_seo_settings_creates_no_audit_event(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
        $this->actingAs($admin);

        $settings = app(SeoSettings::class);

        Livewire::test(SeoSettingsPage::class)
            ->set('data.robots', $settings->robots)
            ->set('data.twitter_card', $settings->twitter_card)
            ->call('save');

        $this->assertDatabaseMissing('activity_log', [
            'log_name' => 'settings',
            'event' => 'settings_updated',
        ]);
    }
}
