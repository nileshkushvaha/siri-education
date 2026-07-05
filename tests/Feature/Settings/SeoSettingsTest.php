<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Filament\Pages\Settings\SeoSettingsPage;
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
}
