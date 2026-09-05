<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Content\SEO\SeoRoute;
use App\Filament\Pages\Settings\SeoSettingsPage;
use App\Models\User;
use App\Settings\RegistrationSettings;
use App\Settings\SeoSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** The per-page overrides on the SEO Settings page and how the public layout renders them. */
class SeoPageOverridesTest extends TestCase
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

    private function seoPage(): Testable
    {
        return Livewire::test(SeoSettingsPage::class)
            ->set('data.robots', 'index,follow')
            ->set('data.twitter_card', 'summary_large_image');
    }

    public function test_page_overrides_start_empty(): void
    {
        $this->assertSame([], app(SeoSettings::class)->pages);
    }

    public function test_an_override_is_rendered_by_the_public_page(): void
    {
        $this->actingAs($this->admin());

        $this->seoPage()
            ->set('data.selected_page', 'instructors')
            ->set('data.pages.instructors.meta_title', 'Online Tutors for CBSE, US and UK')
            ->set('data.pages.instructors.meta_description', 'Compare vetted instructors and book a lesson.')
            ->set('data.pages.instructors.meta_keywords', 'online tutor, cbse tutor')
            ->set('data.pages.instructors.canonical_url', 'https://example.test/tutors')
            ->call('save')
            ->assertNotified('SEO settings saved');

        $settings = app()->make(SeoSettings::class)->refresh();
        $this->assertSame('Online Tutors for CBSE, US and UK', $settings->pages['instructors']['meta_title']);
        $this->assertArrayNotHasKey('faqs', $settings->pages);

        $html = $this->get('/instructors')->assertOk()->getContent();
        $this->assertStringContainsString('<title>Online Tutors for CBSE, US and UK</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="Compare vetted instructors and book a lesson.">', $html);
        $this->assertStringContainsString('<meta name="keywords" content="online tutor, cbse tutor">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://example.test/tutors">', $html);
        $this->assertSame(1, substr_count($html, 'name="description"'));
        $this->assertSame(1, substr_count($html, 'rel="canonical"'));
    }

    public function test_pages_without_an_override_keep_their_template_meta(): void
    {
        $html = $this->get('/faqs')->assertOk()->getContent();

        $this->assertStringContainsString('<title>Help Center', $html);
        $this->assertStringContainsString('Find clear answers about accounts', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.url('/faqs').'">', $html);
        $this->assertSame(1, substr_count($html, 'name="description"'));
        $this->assertSame(1, substr_count($html, 'rel="canonical"'));
    }

    public function test_site_defaults_only_fill_what_a_page_lacks(): void
    {
        $this->actingAs($this->admin());

        $this->seoPage()
            ->set('data.selected_page', 'defaults')
            ->set('data.meta_title', 'Learn Anything, Anywhere')
            ->set('data.meta_description', 'Book tutoring sessions with vetted teachers.')
            ->set('data.meta_keywords', 'tutoring, online lessons')
            ->call('save');

        // The login template has a title but no description of its own.
        auth()->logout();
        $html = $this->get('/login')->assertOk()->getContent();
        $this->assertStringContainsString('<meta name="robots" content="index, follow">', $html);
        $this->assertStringContainsString('<title>Sign In', $html);
        $this->assertStringContainsString('content="Book tutoring sessions with vetted teachers."', $html);
        $this->assertStringContainsString('<meta name="keywords" content="tutoring, online lessons">', $html);

        $this->actingAs($this->admin());
        $this->seoPage()
            ->set('data.selected_page', 'home')
            ->set('data.pages.home.meta_title', 'SIRI Education — 1-on-1 Online Tutoring')
            ->set('data.pages.home.robots', 'noindex,nofollow')
            ->call('save');

        $home = $this->get('/')->getContent();
        $this->assertStringContainsString('<title>SIRI Education — 1-on-1 Online Tutoring</title>', $home);
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $home);
        $this->assertSame(1, substr_count($home, 'name="robots"'));
        $this->assertSame('Learn Anything, Anywhere', app()->make(SeoSettings::class)->refresh()->meta_title);
    }

    public function test_saving_one_page_keeps_the_others_and_the_defaults(): void
    {
        $this->actingAs($this->admin());

        $this->seoPage()
            ->set('data.selected_page', 'defaults')
            ->set('data.meta_title', 'Default title')
            ->call('save');

        $this->seoPage()
            ->set('data.selected_page', 'blog')
            ->set('data.pages.blog.meta_title', 'SIRI Blog')
            ->call('save');

        $this->seoPage()
            ->set('data.selected_page', 'login')
            ->set('data.pages.login.meta_title', 'Sign in to SIRI')
            ->call('save');

        $settings = app()->make(SeoSettings::class)->refresh();
        $this->assertSame('Default title', $settings->meta_title);
        $this->assertSame('SIRI Blog', $settings->pages['blog']['meta_title']);
        $this->assertSame('Sign in to SIRI', $settings->pages['login']['meta_title']);

        auth()->logout();
        $this->assertStringContainsString('<title>Sign in to SIRI</title>', $this->get('/login')->getContent());
    }

    public function test_every_managed_page_renders_its_own_override(): void
    {
        $registration = app(RegistrationSettings::class);
        $registration->self_registration_enabled = true;
        $registration->save();

        $this->actingAs($this->admin());

        foreach (SeoRoute::cases() as $route) {
            $this->seoPage()
                ->set('data.selected_page', $route->value)
                ->set('data.pages.'.$route->value.'.meta_title', 'Override for '.$route->label())
                ->set('data.pages.'.$route->value.'.meta_description', 'Description for '.$route->label())
                ->set('data.pages.'.$route->value.'.robots', 'noindex,follow')
                ->call('save')
                ->assertNotified('SEO settings saved');
        }

        auth()->logout();

        foreach (SeoRoute::cases() as $route) {
            $response = $this->get($route->path());
            $this->assertSame(200, $response->status(), $route->path().' redirected to '.$response->headers->get('Location'));
            $html = $response->getContent();

            $this->assertStringContainsString('<title>Override for '.$route->label().'</title>', $html, $route->path());
            $this->assertStringContainsString('<meta name="description" content="Description for '.$route->label().'">', $html, $route->path());
            $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', $html, $route->path());
            $this->assertStringContainsString('<link rel="canonical" href="'.url($route->path()).'">', $html, $route->path());
            $this->assertSame(1, substr_count($html, 'name="description"'), $route->path());
            $this->assertSame(1, substr_count($html, 'rel="canonical"'), $route->path());
            $this->assertSame(1, substr_count($html, 'name="robots"'), $route->path());
            $this->assertSame(1, substr_count($html, 'property="og:title"'), $route->path());
        }
    }
}
