<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Filament\Pages\Settings\PageSeoSettingsPage;
use App\Models\Activity;
use App\Models\User;
use App\Settings\PageSeoSettings;
use App\Settings\SeoSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PageSeoSettingsTest extends TestCase
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

    public function test_page_seo_settings_load_empty(): void
    {
        $this->assertSame([], app(PageSeoSettings::class)->pages);
    }

    public function test_admin_can_save_a_page_override_that_the_public_page_renders(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(PageSeoSettingsPage::class)
            ->set('data.pages.instructors.meta_title', 'Online Tutors for CBSE, US and UK')
            ->set('data.pages.instructors.meta_description', 'Compare vetted instructors and book a lesson.')
            ->call('save')
            ->assertNotified('Page SEO saved');

        $settings = app()->make(PageSeoSettings::class)->refresh();
        $this->assertSame('Online Tutors for CBSE, US and UK', $settings->pages['instructors']['meta_title']);
        $this->assertNull($settings->pages['faqs']['meta_title']);

        $html = $this->get('/instructors')->assertOk()->getContent();
        $this->assertStringContainsString('<title>Online Tutors for CBSE, US and UK</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="Compare vetted instructors and book a lesson.">', $html);
        $this->assertSame(1, substr_count($html, 'name="description"'));

        $activity = Activity::where('log_name', 'settings')
            ->where('event', 'settings_updated')
            ->where('properties->settings_class', PageSeoSettings::class)
            ->first();
        $this->assertNotNull($activity);
    }

    public function test_pages_without_an_override_keep_their_template_meta(): void
    {
        $html = $this->get('/faqs')->assertOk()->getContent();

        $this->assertStringContainsString('<title>Help Center', $html);
        $this->assertStringContainsString('Find clear answers about accounts', $html);
        $this->assertSame(1, substr_count($html, 'name="description"'));
    }

    public function test_home_page_falls_back_to_the_global_seo_defaults(): void
    {
        $seo = app(SeoSettings::class);
        $seo->meta_title = 'Learn Anything, Anywhere';
        $seo->meta_description = 'Book tutoring sessions with vetted teachers.';
        $seo->save();

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('<title>Learn Anything, Anywhere</title>', $html);
        $this->assertStringContainsString('content="Book tutoring sessions with vetted teachers."', $html);

        $this->actingAs($this->admin());
        Livewire::test(PageSeoSettingsPage::class)
            ->set('data.pages.home.meta_title', 'SIRI Education — 1-on-1 Online Tutoring')
            ->call('save');

        $this->assertStringContainsString('<title>SIRI Education — 1-on-1 Online Tutoring</title>', $this->get('/')->getContent());
        $this->assertStringContainsString('<title>Help Center', $this->get('/faqs')->getContent());
    }

    public function test_auth_pages_can_be_overridden(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(PageSeoSettingsPage::class)
            ->set('data.pages.login.meta_title', 'Sign in to SIRI')
            ->set('data.pages.forgot_password.meta_description', 'Reset your SIRI password.')
            ->call('save');
        auth()->logout();

        $this->assertStringContainsString('<title>Sign in to SIRI</title>', $this->get('/login')->getContent());
        $this->assertStringContainsString('content="Reset your SIRI password."', $this->get('/forgot-password')->getContent());
    }
}
