<?php

declare(strict_types=1);

namespace Tests\Feature\Faq;

use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\User;
use App\Services\Faq\FaqService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FaqFrontendTest extends TestCase
{
    use RefreshDatabase;

    private FaqCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $this->category = FaqCategory::create([
            'name' => 'General',
            'is_active' => true,
        ]);
    }

    private function publishedFaq(array $audience, string $question = 'Test question?'): Faq
    {
        return Faq::create([
            'faq_category_id' => $this->category->id,
            'question' => $question,
            'answer' => '<p>Test answer.</p>',
            'audience' => $audience,
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    // ── Public /faqs ──────────────────────────────────────────────────

    public function test_public_faq_page_returns_200(): void
    {
        $this->get(route('faqs.index'))->assertOk();
    }

    public function test_public_faq_page_shows_public_faqs(): void
    {
        $this->publishedFaq(['public'], 'How do I register?');

        $this->get(route('faqs.index'))
            ->assertOk()
            ->assertSee('How do I register?');
    }

    public function test_public_faq_page_does_not_show_student_only_faqs(): void
    {
        $this->publishedFaq(['student'], 'Student only FAQ');

        $this->get(route('faqs.index'))
            ->assertOk()
            ->assertDontSee('Student only FAQ');
    }

    public function test_public_faq_page_does_not_show_draft_faqs(): void
    {
        Faq::create([
            'faq_category_id' => $this->category->id,
            'question' => 'Draft FAQ',
            'answer' => '<p>Draft.</p>',
            'audience' => ['public'],
            'status' => 'draft',
        ]);

        $this->get(route('faqs.index'))
            ->assertOk()
            ->assertDontSee('Draft FAQ');
    }

    public function test_public_faq_page_search_filters_results(): void
    {
        $this->publishedFaq(['public'], 'How to reset password?');
        $this->publishedFaq(['public'], 'How to update profile?');

        $this->get(route('faqs.index', ['q' => 'reset']))
            ->assertOk()
            ->assertSeeText('How to reset password?')
            ->assertDontSeeText('How to update profile?');
    }

    public function test_public_faq_page_filters_by_category(): void
    {
        $other = FaqCategory::create(['name' => 'Billing', 'is_active' => true]);

        $this->publishedFaq(['public'], 'General FAQ');
        Faq::create([
            'faq_category_id' => $other->id,
            'question' => 'Billing FAQ',
            'answer' => '<p>A.</p>',
            'audience' => ['public'],
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->get(route('faqs.index', ['category' => $other->id]))
            ->assertOk()
            ->assertSee('Billing FAQ')
            ->assertDontSee('General FAQ');
    }

    public function test_public_faq_page_shows_categories(): void
    {
        $this->get(route('faqs.index'))
            ->assertOk()
            ->assertSee('General');
    }

    public function test_invalid_category_cache_is_rebuilt_instead_of_breaking_the_public_page(): void
    {
        Cache::put('faq.categories', new \stdClass, 300);

        $categories = app(FaqService::class)->categories();

        $this->assertCount(1, $categories);
        $this->assertTrue($categories->first()->is($this->category));

        $this->get(route('faqs.index'))
            ->assertOk()
            ->assertSee('General');
    }

    // ── Dashboard /dashboard/faqs ─────────────────────────────────────

    public function test_guest_cannot_access_dashboard_faqs(): void
    {
        $this->get(route('dashboard.faqs'))
            ->assertRedirect(route('auth.login'));
    }

    public function test_authenticated_student_can_access_dashboard_faqs(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('student');

        $this->actingAs($user)
            ->get(route('dashboard.faqs'))
            ->assertOk();
    }

    public function test_dashboard_faqs_shows_public_and_student_faqs_to_student(): void
    {
        $student = User::factory()->create(['status' => 'active']);
        $student->assignRole('student');

        $this->publishedFaq(['public'], 'Public FAQ');
        $this->publishedFaq(['student'], 'Student FAQ');
        $this->publishedFaq(['instructor'], 'Instructor FAQ');

        $this->actingAs($student)
            ->get(route('dashboard.faqs'))
            ->assertOk()
            ->assertSee('Public FAQ')
            ->assertSee('Student FAQ')
            ->assertDontSee('Instructor FAQ');
    }

    public function test_dashboard_faqs_shows_public_and_instructor_faqs_to_instructor(): void
    {
        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->assignRole('instructor');

        $this->publishedFaq(['public'], 'Public FAQ');
        $this->publishedFaq(['student'], 'Student FAQ');
        $this->publishedFaq(['instructor'], 'Instructor FAQ');

        $this->actingAs($instructor)
            ->get(route('dashboard.faqs'))
            ->assertOk()
            ->assertSee('Public FAQ')
            ->assertDontSee('Student FAQ')
            ->assertSee('Instructor FAQ');
    }

    public function test_dashboard_faq_search_works(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('student');

        $this->publishedFaq(['public'], 'How to reset password?');
        $this->publishedFaq(['public'], 'Unrelated question?');

        $this->actingAs($user)
            ->get(route('dashboard.faqs', ['q' => 'reset']))
            ->assertOk()
            ->assertSeeText('How to reset password?')
            ->assertDontSeeText('Unrelated question?');
    }

    public function test_empty_state_shown_when_no_faqs(): void
    {
        $this->get(route('faqs.index'))
            ->assertOk()
            ->assertSee('No FAQs found');
    }
}
