<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PageStatus;
use App\Enums\PageVisibility;
use App\Livewire\Frontend\Layout\SearchOverlay;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SearchOverlayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
    }

    private function makeInstructor(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge(['status' => 'active'], $overrides));
        $user->profile->update(['profile_visibility' => 'public']);
        $user->assignRole('instructor');

        return $user;
    }

    public function test_empty_query_returns_no_results_in_any_category(): void
    {
        Livewire::test(SearchOverlay::class)
            ->set('open', true)
            ->set('query', '')
            ->assertSet('hasResults', false);
    }

    public function test_finds_matching_teacher(): void
    {
        $this->makeInstructor(['name' => 'Jordan Physics Expert']);

        Livewire::test(SearchOverlay::class)
            ->set('open', true)
            ->set('query', 'Jordan')
            ->assertSee('Physics Expert')
            ->assertSee('Teachers');
    }

    public function test_finds_matching_published_page(): void
    {
        Page::factory()->create([
            'title' => 'About Our Tutoring',
            'slug' => 'about-tutoring',
            'status' => PageStatus::Published,
            'visibility' => PageVisibility::Public,
        ]);

        Livewire::test(SearchOverlay::class)
            ->set('open', true)
            ->set('query', 'Tutoring')
            ->assertSee('About Our')
            ->assertSee('Pages');
    }

    public function test_finds_matching_published_post(): void
    {
        Post::factory()->published()->create(['title' => 'How to Study Better']);

        Livewire::test(SearchOverlay::class)
            ->set('open', true)
            ->set('query', 'Study Better')
            ->assertSee('How to')
            ->assertSee('Posts');
    }

    public function test_finds_matching_public_faq(): void
    {
        $category = FaqCategory::create(['name' => 'General', 'is_active' => true]);
        Faq::create([
            'faq_category_id' => $category->id,
            'question' => 'How do I cancel a booking?',
            'answer' => '<p>Contact your teacher.</p>',
            'audience' => ['public'],
            'status' => 'published',
            'published_at' => now(),
        ]);

        Livewire::test(SearchOverlay::class)
            ->set('open', true)
            ->set('query', 'cancel a booking')
            ->assertSee('How do I')
            ->assertSee('FAQs');
    }

    public function test_courses_category_is_always_empty(): void
    {
        Livewire::test(SearchOverlay::class)
            ->set('open', true)
            ->set('query', 'anything')
            ->assertSee('Course search is coming soon');
    }

    public function test_no_results_shows_empty_state(): void
    {
        Livewire::test(SearchOverlay::class)
            ->set('open', true)
            ->set('query', 'zzz-nothing-matches-zzz')
            ->assertSee('No results')
            ->assertSet('hasResults', false);
    }

    public function test_highlighted_text_escapes_html_in_source_data(): void
    {
        Page::factory()->create([
            'title' => '<script>alert(1)</script> Pricing',
            'slug' => 'xss-pricing',
            'status' => PageStatus::Published,
            'visibility' => PageVisibility::Public,
        ]);

        Livewire::test(SearchOverlay::class)
            ->set('open', true)
            ->set('query', 'Pricing')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;', false);
    }

    public function test_close_resets_the_query(): void
    {
        Livewire::test(SearchOverlay::class)
            ->set('open', true)
            ->set('query', 'something')
            ->call('close')
            ->assertSet('query', '')
            ->assertSet('open', false);
    }
}
