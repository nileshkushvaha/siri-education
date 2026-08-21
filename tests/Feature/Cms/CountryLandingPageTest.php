<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use App\Content\Models\ContentBlock;
use App\Models\CountryEducationSystem;
use App\Models\Page;
use Database\Seeders\AboutUsPageSeeder;
use Database\Seeders\ContactUsPageSeeder;
use Database\Seeders\CountryLandingPageSeeder;
use Database\Seeders\CountrySeeder;
use Database\Seeders\InternationalAcademicCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CountryLandingPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The nine markets and the level term each one must use. Written out
     * literally here on purpose: the application resolves terminology
     * from EducationSystem, and this test is the independent check that
     * the resolution actually lands on the right word per country.
     *
     * @var array<string, array{slug: string, term: string}>
     */
    private const EXPECTED = [
        'IN' => ['slug' => 'online-tutoring-in-india', 'term' => 'Class'],
        'US' => ['slug' => 'online-tutoring-in-united-states', 'term' => 'Grade'],
        'GB' => ['slug' => 'online-tutoring-in-united-kingdom', 'term' => 'Year'],
        'AU' => ['slug' => 'online-tutoring-in-australia', 'term' => 'Year'],
        'CA' => ['slug' => 'online-tutoring-in-canada', 'term' => 'Grade'],
        'AE' => ['slug' => 'online-tutoring-in-united-arab-emirates', 'term' => 'Grade'],
        'SG' => ['slug' => 'online-tutoring-in-singapore', 'term' => 'Grade'],
        'NZ' => ['slug' => 'online-tutoring-in-new-zealand', 'term' => 'Year'],
        'SA' => ['slug' => 'online-tutoring-in-saudi-arabia', 'term' => 'Grade'],
    ];

    private function seedCountryPages(): void
    {
        $this->seed(CountrySeeder::class);
        $this->seed(InternationalAcademicCatalogueSeeder::class);
        $this->seed(CountryLandingPageSeeder::class);
    }

    // ── Resolution ───────────────────────────────────────────────────────

    public function test_every_active_country_gets_one_published_landing_page(): void
    {
        $this->seedCountryPages();

        foreach (self::EXPECTED as $expected) {
            $this->get('/'.$expected['slug'])->assertOk();
        }

        $this->assertSame(
            count(self::EXPECTED),
            Page::query()->where('slug', 'like', 'online-tutoring-in-%')->count(),
        );
    }

    public function test_country_page_slugs_are_unique(): void
    {
        $this->seedCountryPages();

        $slugs = Page::query()->pluck('slug');

        $this->assertSame($slugs->count(), $slugs->unique()->count(), 'Duplicate page slugs exist.');
    }

    public function test_page_keeps_a_single_h1_because_the_hero_renders_as_h2(): void
    {
        $this->seedCountryPages();

        $html = $this->get('/online-tutoring-in-india')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<h1'), 'A country page must have exactly one H1.');
    }

    // ── Terminology ──────────────────────────────────────────────────────

    public function test_each_country_uses_its_own_configured_level_term(): void
    {
        $this->seedCountryPages();

        foreach (self::EXPECTED as $iso2 => $expected) {
            $html = $this->get('/'.$expected['slug'])->assertOk()->getContent();

            $this->assertMatchesRegularExpression(
                '/\b'.$expected['term'].' \d+/',
                $html,
                "{$iso2} should describe levels as \"{$expected['term']}\"."
            );

            foreach (['Class', 'Grade', 'Year'] as $otherTerm) {
                if ($otherTerm === $expected['term']) {
                    continue;
                }

                // Numbered levels ("Grade 9") are the terminology under
                // test. The bare plural is allowed because every page
                // links to the others and describes their terminology.
                $this->assertDoesNotMatchRegularExpression(
                    '/\b'.$otherTerm.' \d+/',
                    $this->mainContent($html),
                    "{$iso2} must not use \"{$otherTerm}\" for its own levels."
                );
            }
        }
    }

    public function test_terminology_follows_the_education_system_record_rather_than_the_country(): void
    {
        $this->seed(CountrySeeder::class);
        $this->seed(InternationalAcademicCatalogueSeeder::class);

        // Rename India's level term the way an admin would. No string in
        // CountryLandingPageContent mentions "Class", so the prose has to
        // follow the record rather than the country.
        $mapping = CountryEducationSystem::query()
            ->whereRelation('country', 'iso2', 'IN')
            ->with('educationSystem')
            ->firstOrFail();

        $mapping->educationSystem->update([
            'level_term_singular' => 'Standard',
            'level_term_plural' => 'Standards',
        ]);

        $this->seed(CountryLandingPageSeeder::class);

        $page = Page::query()->where('slug', 'online-tutoring-in-india')->firstOrFail();
        $html = $this->get('/online-tutoring-in-india')->assertOk()->getContent();

        $this->assertStringContainsString('Standards', $page->meta_title);
        $this->assertStringContainsString('Standard', $this->mainContent($html));
    }

    public function test_individual_level_names_come_from_their_own_display_labels(): void
    {
        $this->seed(CountrySeeder::class);
        $this->seed(InternationalAcademicCatalogueSeeder::class);

        // Two different admin-managed fields feed these pages, and the
        // distinction is deliberate: level_term_* supplies the generic
        // word used in prose, while EducationSystemLevel::display_label
        // names each selectable level. Renaming one does not silently
        // rewrite the other, so the page shows whatever an admin has
        // actually configured for each.
        $system = CountryEducationSystem::query()
            ->whereRelation('country', 'iso2', 'IN')
            ->firstOrFail()
            ->educationSystem;

        $system->levels()->where('value', '9')->update(['display_label' => 'Standard 9']);

        $this->seed(CountryLandingPageSeeder::class);

        $html = $this->mainContent($this->get('/online-tutoring-in-india')->assertOk()->getContent());

        $this->assertStringContainsString('Standard 9', $html);
        $this->assertStringContainsString('Class 10', $html);
    }

    // ── SEO ──────────────────────────────────────────────────────────────

    public function test_seo_metadata_is_localised_and_canonical_points_at_the_page_itself(): void
    {
        $this->seedCountryPages();

        foreach (self::EXPECTED as $expected) {
            $page = Page::query()->where('slug', $expected['slug'])->firstOrFail();

            $this->assertNotNull($page->meta_title);
            $this->assertNotNull($page->meta_description);
            $this->assertLessThanOrEqual(70, mb_strlen($page->meta_title));
            $this->assertLessThanOrEqual(160, mb_strlen($page->meta_description));

            // Never a shared canonical: SeoManager would otherwise prefer
            // a global SeoSettings::canonical_url for all nine pages.
            $this->assertSame(url('/'.$expected['slug']), $page->canonical_url);

            $html = $this->get('/'.$expected['slug'])->assertOk()->getContent();

            $this->assertStringContainsString('<link rel="canonical" href="'.url('/'.$expected['slug']).'"', $html);
            $this->assertStringContainsString('<meta property="og:title" content="'.e($page->meta_title).'"', $html);
            $this->assertStringContainsString('<meta name="twitter:card"', $html);
        }
    }

    public function test_meta_titles_and_descriptions_are_not_duplicated_across_countries(): void
    {
        $this->seedCountryPages();

        $pages = Page::query()->where('slug', 'like', 'online-tutoring-in-%')->get();

        $this->assertSame($pages->count(), $pages->pluck('meta_title')->unique()->count());
        $this->assertSame($pages->count(), $pages->pluck('meta_description')->unique()->count());
        $this->assertSame($pages->count(), $pages->pluck('excerpt')->unique()->count());
    }

    public function test_pages_inherit_the_global_robots_directive_instead_of_forcing_index(): void
    {
        $this->seedCountryPages();

        // A hardcoded "index, follow" would defeat a site-wide noindex,
        // which is what a staging environment relies on.
        $this->assertNull(Page::query()->where('slug', 'online-tutoring-in-india')->firstOrFail()->robots);
    }

    public function test_country_pages_appear_in_the_existing_sitemap(): void
    {
        $this->seedCountryPages();

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        foreach (self::EXPECTED as $expected) {
            $this->assertStringContainsString($expected['slug'], $xml);
        }
    }

    // ── Links and CTAs ───────────────────────────────────────────────────

    public function test_each_page_links_to_the_demo_booking_about_and_contact_pages(): void
    {
        $this->seedCountryPages();

        foreach (self::EXPECTED as $expected) {
            $main = $this->mainContent($this->get('/'.$expected['slug'])->assertOk()->getContent());

            $this->assertStringContainsString('href="/book"', $main);
            $this->assertStringContainsString('href="/about-us"', $main);
            $this->assertStringContainsString('href="/contact-us"', $main);
        }
    }

    public function test_each_page_cross_links_to_the_other_country_pages_but_not_itself(): void
    {
        $this->seedCountryPages();

        foreach (self::EXPECTED as $expected) {
            $main = $this->mainContent($this->get('/'.$expected['slug'])->assertOk()->getContent());

            foreach (self::EXPECTED as $other) {
                if ($other['slug'] === $expected['slug']) {
                    continue;
                }

                $this->assertStringContainsString('href="/'.$other['slug'].'"', $main);
            }

            $this->assertStringNotContainsString('href="/'.$expected['slug'].'"', $main);
        }
    }

    public function test_hero_image_has_descriptive_alt_text(): void
    {
        $this->seedCountryPages();

        $main = $this->mainContent($this->get('/online-tutoring-in-india')->assertOk()->getContent());

        $this->assertStringContainsString('/images/country-pages/india-student.webp', $main);
        $this->assertMatchesRegularExpression(
            '/alt="[^"]{30,}"/',
            $main,
            'The hero image needs descriptive alt text, not a filename or a stub.'
        );
    }

    // ── Seeder behaviour ─────────────────────────────────────────────────

    public function test_seeder_is_idempotent(): void
    {
        $this->seedCountryPages();

        $pages = Page::query()->count();
        $blocks = ContentBlock::query()->count();

        $this->seed(CountryLandingPageSeeder::class);
        $this->seed(CountryLandingPageSeeder::class);

        $this->assertSame($pages, Page::query()->count());
        $this->assertSame($blocks, ContentBlock::query()->count());
    }

    public function test_reseeding_does_not_overwrite_admin_edited_content(): void
    {
        $this->seedCountryPages();

        $page = Page::query()->where('slug', 'online-tutoring-in-india')->firstOrFail();
        $page->update([
            'title' => 'Editor rewrote this title',
            'meta_description' => 'Editor rewrote this description.',
        ]);

        $block = ContentBlock::query()
            ->where('blockable_id', $page->getKey())
            ->orderBy('sort_order')
            ->firstOrFail();
        $block->update(['content' => ['title' => 'Editor rewrote this hero']]);

        $this->seed(CountryLandingPageSeeder::class);

        $this->assertSame('Editor rewrote this title', $page->fresh()->title);
        $this->assertSame('Editor rewrote this description.', $page->fresh()->meta_description);
        $this->assertSame('Editor rewrote this hero', $block->fresh()->content['title']);
    }

    public function test_existing_cms_pages_are_untouched(): void
    {
        $this->seed(AboutUsPageSeeder::class);
        $this->seed(ContactUsPageSeeder::class);

        $about = Page::query()->where('slug', 'about-us')->firstOrFail();
        $originalContent = $about->content;

        $this->seedCountryPages();

        $this->assertSame($originalContent, $about->fresh()->content);
        $this->get('/about-us')->assertOk();
        $this->get('/contact-us')->assertOk();
    }

    public function test_a_country_without_an_active_education_system_gets_no_page(): void
    {
        $this->seed(CountrySeeder::class);
        $this->seed(InternationalAcademicCatalogueSeeder::class);

        CountryEducationSystem::query()
            ->whereRelation('country', 'iso2', 'SG')
            ->update(['is_active' => false]);

        $this->seed(CountryLandingPageSeeder::class);

        $this->assertDatabaseMissing('pages', ['slug' => 'online-tutoring-in-singapore']);
        $this->get('/online-tutoring-in-singapore')->assertNotFound();
    }

    /** Strip the shared header/footer so assertions only see page content. */
    private function mainContent(string $html): string
    {
        $start = strpos($html, '<main');
        $end = strpos($html, '</main>');

        return ($start === false || $end === false)
            ? $html
            : substr($html, $start, $end - $start);
    }
}
