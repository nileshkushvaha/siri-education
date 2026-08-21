<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Content\Models\ContentBlock;
use App\Enums\BlockType;
use App\Enums\PageStatus;
use App\Enums\PageVisibility;
use App\Models\Country;
use App\Models\CountryEducationSystem;
use App\Models\Curriculum;
use App\Models\CurriculumEducationSystem;
use App\Models\EducationSystem;
use App\Models\Page;
use App\Models\Subject;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Public SEO landing pages, one per active country education system.
 *
 * REUSES THE CMS RATHER THAN ADDING A SECOND ONE. Each country page is
 * an ordinary App\Models\Page assembled from existing ContentBlock types
 * (Hero, RichText, Timeline, Features, FAQ, CTA), so it resolves through
 * the normal /{slug} route, renders through ContentRenderer, gets its
 * metadata from SeoManager, appears in the existing sitemap, and is
 * editable in the existing Filament block editor. No new route, no new
 * controller, no new template, no page-specific CSS.
 *
 * THE COUNTRY LIST IS NOT HARDCODED. It is whatever CountryEducationSystem
 * currently marks active — the same table the booking and academic
 * flows resolve against — intersected with the countries that have copy
 * in CountryLandingPageContent. Deactivating a market in the admin and
 * re-running this seeder simply stops producing that page; it does not
 * delete one already published, because unpublishing a live URL is an
 * editorial decision, not a seeding one.
 *
 * TERMINOLOGY COMES FROM THE DATABASE. Every "Class" / "Grade" / "Year"
 * in the rendered output is EducationSystem::levelTermSingular() or
 * levelTermPlural() substituted into a {term} / {terms} placeholder. No
 * country conditional exists anywhere in the application because of
 * these pages.
 *
 * RE-RUN BEHAVIOUR: CREATE-ONLY, following BookingTypeSeeder's stated
 * strategy ("existing rows keep their admin-tuned values; only missing
 * types are created"). A page that already exists is left completely
 * alone, and its blocks are only seeded when it has none — so re-running
 * on production can add a newly activated country without overwriting a
 * single word an editor has changed. AboutUsPageSeeder's updateOrCreate
 * is deliberately NOT copied here: it owns one page whose content is a
 * single HTML blob, whereas these nine pages carry ~12 admin-editable
 * blocks each, and silently replacing those on a deploy would destroy
 * real editorial work.
 */
class CountryLandingPageSeeder extends Seeder
{
    /** Slug prefix for every country page: /online-tutoring-in-india, etc. */
    private const SLUG_PREFIX = 'online-tutoring-in-';

    /** Public path the country hero images are expected at. */
    private const IMAGE_DIRECTORY = 'images/country-pages';

    /** Subjects listed on a page — enough to be useful, not a data dump. */
    private const SUBJECT_LIMIT = 12;

    public function run(): void
    {
        $content = CountryLandingPageContent::all();
        $systems = $this->activeCountrySystems();

        if ($systems->isEmpty()) {
            $this->command?->warn('No active country education systems found — run InternationalAcademicCatalogueSeeder first.');

            return;
        }

        $created = 0;
        $skipped = 0;

        foreach ($systems as $mapping) {
            $country = $mapping->country;
            $system = $mapping->educationSystem;

            if ($country === null || $system === null || ! isset($content[$country->iso2])) {
                continue;
            }

            $page = $this->page($country, $system, $content[$country->iso2]);

            if ($page->wasRecentlyCreated) {
                $created++;
            } else {
                $skipped++;
            }

            $this->blocks($page, $country, $system, $content[$country->iso2], $systems);
            $this->attachFeaturedImage($page, $country, $content[$country->iso2]);

            cache()->forget('page-render:'.$page->getKey());
        }

        $this->command?->info("✓ Country landing pages: {$created} created, {$skipped} already present and left untouched.");
    }

    /**
     * Active country/system mappings, ordered so the cross-links between
     * pages come out in a stable order on every page.
     *
     * @return Collection<int, CountryEducationSystem>
     */
    private function activeCountrySystems(): Collection
    {
        return CountryEducationSystem::query()
            ->where('is_active', true)
            ->with(['country', 'educationSystem'])
            ->get()
            ->filter(fn (CountryEducationSystem $mapping): bool => $mapping->country !== null
                && $mapping->educationSystem !== null
                && $mapping->educationSystem->status->value === 'active')
            ->sortBy(fn (CountryEducationSystem $mapping): string => (string) $mapping->country->name)
            ->values();
    }

    /**
     * The Page row. Canonical URL is written explicitly rather than left
     * to SeoManager's fallback chain: that chain prefers a global
     * SeoSettings::canonical_url over the page's own URL, which would
     * point all nine pages at one address.
     */
    private function page(Country $country, EducationSystem $system, array $copy): Page
    {
        $slug = $this->slugFor($country);

        return Page::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'title' => "Online Tutoring in {$country->name}",
                'excerpt' => $this->fill($copy['excerpt'], $country, $system),
                'content' => null,
                'template' => 'default',
                'layout' => 'full-width',
                'status' => PageStatus::Published,
                'visibility' => PageVisibility::Public,
                'published_at' => now(),
                'meta_title' => $this->fill($copy['meta_title'], $country, $system),
                'meta_description' => $this->fill($copy['meta_description'], $country, $system),
                'canonical_url' => url('/'.$slug),
                // robots is deliberately left null so these pages inherit
                // the global SeoSettings directive. Writing "index, follow"
                // here would override a site-wide noindex, which is exactly
                // the switch a staging environment relies on.
                'robots' => null,
            ],
        );
    }

    /**
     * Seed the block stack — only for a page that has none. A page with
     * blocks has either been seeded already or edited by an admin, and
     * both cases must be left alone.
     *
     * @param  Collection<int, CountryEducationSystem>  $allSystems
     */
    private function blocks(Page $page, Country $country, EducationSystem $system, array $copy, Collection $allSystems): void
    {
        $existing = ContentBlock::query()
            ->where('blockable_type', $page->getMorphClass())
            ->where('blockable_id', $page->getKey())
            ->exists();

        if ($existing) {
            return;
        }

        foreach ($this->blockDefinitions($country, $system, $copy, $allSystems) as $order => $definition) {
            ContentBlock::create([
                'blockable_type' => $page->getMorphClass(),
                'blockable_id' => $page->getKey(),
                'block_type' => $definition['type'],
                'name' => $definition['name'],
                'content' => $definition['content'],
                'settings' => [],
                'sort_order' => $order + 1,
                'position' => 'after_content',
                'is_active' => true,
            ]);
        }
    }

    /**
     * @param  Collection<int, CountryEducationSystem>  $allSystems
     * @return list<array{type: BlockType, name: string, content: array}>
     */
    private function blockDefinitions(Country $country, EducationSystem $system, array $copy, Collection $allSystems): array
    {
        $term = $system->levelTermSingular();
        $terms = $system->levelTermPlural();

        return [
            [
                'type' => BlockType::Hero,
                'name' => "{$country->name} — Hero",
                'content' => [
                    'title' => "Online tutoring for students in {$country->name}",
                    'subtitle' => $this->fill($copy['hero_subtitle'], $country, $system),
                    'image' => '/'.self::IMAGE_DIRECTORY.'/'.$this->imageFilename($country),
                    'image_alt' => $this->fill($copy['image_alt'], $country, $system),
                    // layouts.page already prints the page title as the
                    // document H1, so this hero must not add a second one.
                    'heading_level' => 'h2',
                    'button_text' => 'Book a free demo lesson',
                    'button_link' => '/book',
                    'button_style' => 'primary',
                ],
            ],
            [
                'type' => BlockType::RichText,
                'name' => "{$country->name} — Availability",
                'content' => [
                    'text' => '<h2>'.e($this->fill($copy['availability_heading'], $country, $system)).'</h2>'
                        .$this->fill($copy['availability_body'], $country, $system),
                ],
            ],
            [
                'type' => BlockType::RichText,
                'name' => "{$country->name} — Education system",
                'content' => [
                    'text' => '<h2>'.e($this->fill($copy['system_heading'], $country, $system)).'</h2>'
                        .$this->fill($copy['system_body'], $country, $system)
                        .$this->levelsList($system),
                ],
            ],
            [
                'type' => BlockType::RichText,
                'name' => "{$country->name} — How it works heading",
                'content' => [
                    'text' => '<h2>How one-to-one tutoring works</h2>'
                        .'<p>The same five steps for every student, from the first free demo lesson to reviewing progress later on.</p>',
                ],
            ],
            [
                'type' => BlockType::Timeline,
                'name' => "{$country->name} — How it works",
                'content' => ['items' => $this->howItWorks($term)],
            ],
            [
                'type' => BlockType::Features,
                'name' => "{$country->name} — Subjects",
                'content' => [
                    'eyebrow' => 'Subjects',
                    'title' => 'Subjects available for '.$terms.' 6 to 12',
                    'description' => 'These subjects are configured for '.$system->name.' on the platform. Which instructors teach a given subject at a given '.$term.' varies, so open a subject to see who is currently available.',
                    'features' => $this->subjectFeatures($system),
                    'columns' => 4,
                ],
            ],
            [
                'type' => BlockType::Features,
                'name' => "{$country->name} — Benefits",
                'content' => [
                    'eyebrow' => 'Why one-to-one',
                    'title' => $this->fill($copy['benefits_title'], $country, $system),
                    'description' => '',
                    'features' => $this->fillFeatures($copy['benefits'], $country, $system),
                    'columns' => 3,
                ],
            ],
            [
                'type' => BlockType::RichText,
                'name' => "{$country->name} — FAQ heading",
                'content' => [
                    'text' => '<h2>Frequently asked questions</h2>'
                        .'<p>Common questions from families in '.e($country->name).' about how tutoring on this platform works.</p>',
                ],
            ],
            [
                'type' => BlockType::FAQ,
                'name' => "{$country->name} — FAQ",
                'content' => ['items' => $this->fillFaqs($copy['faqs'], $country, $system)],
            ],
            [
                'type' => BlockType::Features,
                'name' => "{$country->name} — Explore more",
                'content' => [
                    'eyebrow' => 'Explore',
                    'title' => 'More about how the platform works',
                    'description' => '',
                    'features' => [
                        ['icon' => 'ℹ️', 'title' => 'About us', 'description' => 'Why the platform exists and how lessons, homework, progress and payments are designed to work as one experience.', 'link_label' => 'Read about us', 'link' => '/about-us'],
                        ['icon' => '👩‍🏫', 'title' => 'Browse instructors', 'description' => 'Public instructor profiles, with the subjects, languages and availability each instructor has published.', 'link_label' => 'Browse instructors', 'link' => '/instructors'],
                        ['icon' => '❓', 'title' => 'Help centre', 'description' => 'Answers about accounts, bookings, lessons, homework and payments across the whole platform.', 'link_label' => 'Read the FAQs', 'link' => '/faqs'],
                        ['icon' => '✉️', 'title' => 'Contact us', 'description' => 'Questions about student accounts, instructor applications, bookings or anything else — send them to the team.', 'link_label' => 'Contact us', 'link' => '/contact-us'],
                    ],
                    'columns' => 4,
                ],
            ],
            [
                'type' => BlockType::Features,
                'name' => "{$country->name} — Other countries",
                'content' => [
                    'eyebrow' => 'Other countries',
                    'title' => 'Online tutoring in other countries',
                    'description' => 'The platform supports students in several education systems. Each country page uses that system\'s own level terminology.',
                    'features' => $this->otherCountryFeatures($country, $allSystems),
                    'columns' => 4,
                ],
            ],
            [
                'type' => BlockType::CTA,
                'name' => "{$country->name} — Demo CTA",
                'content' => [
                    'title' => 'Start with a free demo lesson',
                    'description' => 'A free demo is a full one-to-one lesson, and you can take one with each instructor. Sign in or create a student account to choose an instructor and a time that suits you.',
                    'button_text' => 'Book a free demo lesson',
                    'button_link' => '/book',
                    'button_style' => 'primary',
                    'background_color' => '#ffffff',
                    'text_color' => '#000000',
                ],
            ],
        ];
    }

    /** @return list<array{title: string, description: string}> */
    private function howItWorks(string $term): array
    {
        return [
            ['title' => 'Create a student account', 'description' => "Set the student's ".strtolower($term).', subjects and timezone once. Every booking, learning plan and homework task uses that context afterwards.', 'date' => 'Step 1'],
            ['title' => 'Find an instructor', 'description' => 'Compare public instructor profiles by subject, language, timezone and published availability.', 'date' => 'Step 2'],
            ['title' => 'Book a free demo lesson', 'description' => 'Take one free demo with an instructor before committing to anything paid. It runs as a normal one-to-one lesson.', 'date' => 'Step 3'],
            ['title' => 'Learn one to one', 'description' => 'Live online lessons at times you chose, with homework, instructor feedback and lesson records kept on the platform.', 'date' => 'Step 4'],
            ['title' => 'Review progress', 'description' => 'Learning goals, plans and progress milestones stay in the student dashboard so improvement is something you can look at.', 'date' => 'Step 5'],
        ];
    }

    /**
     * Subjects genuinely mapped to this education system through the
     * curriculum tables — never an invented marketing list.
     *
     * @return list<array{icon: string, title: string, description: string, link_label: string, link: string}>
     */
    private function subjectFeatures(EducationSystem $system): array
    {
        $curriculumIds = CurriculumEducationSystem::query()
            ->where('education_system_id', $system->getKey())
            ->pluck('curriculum_id');

        $subjectIds = Curriculum::query()
            ->whereIn('id', $curriculumIds)
            ->pluck('subject_id')
            ->unique();

        $subjects = Subject::query()
            ->whereIn('id', $subjectIds)
            ->where('status', 'active')
            ->orderBy('display_order')
            ->orderBy('name')
            ->limit(self::SUBJECT_LIMIT)
            ->get(['name', 'slug']);

        return $subjects->map(fn (Subject $subject): array => [
            'icon' => '',
            'title' => $subject->name,
            'description' => '',
            'link_label' => 'Find '.$subject->name.' instructors',
            'link' => '/instructors?subject='.$subject->slug,
        ])->values()->all();
    }

    /**
     * Cross-links to the other country pages. Internal linking between
     * the nine pages is the point; the current page is excluded so no
     * page links to itself.
     *
     * @param  Collection<int, CountryEducationSystem>  $allSystems
     * @return list<array{icon: string, title: string, description: string, link_label: string, link: string}>
     */
    private function otherCountryFeatures(Country $current, Collection $allSystems): array
    {
        $available = CountryLandingPageContent::all();

        return $allSystems
            ->filter(fn (CountryEducationSystem $mapping): bool => $mapping->country->iso2 !== $current->iso2
                && isset($available[$mapping->country->iso2]))
            ->map(fn (CountryEducationSystem $mapping): array => [
                'icon' => '',
                'title' => $mapping->country->name,
                'description' => 'Levels are described as '.$mapping->educationSystem->levelTermPlural().' under '.$mapping->educationSystem->name.'.',
                'link_label' => 'Tutoring in '.$mapping->country->name,
                'link' => '/'.$this->slugFor($mapping->country),
            ])
            ->values()
            ->all();
    }

    /** The exact, student-selectable levels this system offers, in the system's own words. */
    private function levelsList(EducationSystem $system): string
    {
        $labels = $system->levels()
            ->where('is_active', true)
            ->orderBy('display_order')
            ->pluck('display_label');

        if ($labels->isEmpty()) {
            return '';
        }

        return '<p><strong>Levels supported:</strong> '.e($labels->implode(', ')).'.</p>';
    }

    /**
     * @param  list<array<string, string>>  $features
     * @return list<array<string, string>>
     */
    private function fillFeatures(array $features, Country $country, EducationSystem $system): array
    {
        return array_map(fn (array $feature): array => [
            'icon' => $feature['icon'] ?? '',
            'title' => $this->fill($feature['title'], $country, $system),
            'description' => $this->fill($feature['description'], $country, $system),
            'link_label' => '',
            'link' => '',
        ], $features);
    }

    /**
     * @param  list<array{question: string, answer: string}>  $faqs
     * @return list<array{question: string, answer: string}>
     */
    private function fillFaqs(array $faqs, Country $country, EducationSystem $system): array
    {
        return array_map(fn (array $faq): array => [
            'question' => $this->fill($faq['question'], $country, $system),
            'answer' => $this->fill($faq['answer'], $country, $system),
        ], $faqs);
    }

    /**
     * The single place country/terminology substitution happens. Copy
     * never names a level term directly, so an admin renaming "Class" to
     * "Standard" only has to re-run this seeder for a fresh page.
     */
    private function fill(string $text, Country $country, EducationSystem $system): string
    {
        return strtr($text, [
            '{country}' => $country->name,
            '{system}' => $system->name,
            '{term}' => $system->levelTermSingular(),
            '{terms}' => $system->levelTermPlural(),
            '{levelRange}' => $this->levelRange($system),
        ]);
    }

    /** e.g. "Class 6 to Class 12" — read from the configured levels, never assumed. */
    private function levelRange(EducationSystem $system): string
    {
        $labels = $system->levels()
            ->where('is_active', true)
            ->orderBy('display_order')
            ->pluck('display_label');

        if ($labels->isEmpty()) {
            return $system->levelTermPlural();
        }

        if ($labels->count() === 1) {
            return (string) $labels->first();
        }

        return $labels->first().' to '.$labels->last();
    }

    private function slugFor(Country $country): string
    {
        return self::SLUG_PREFIX.Str::slug($country->name);
    }

    private function imageFilename(Country $country): string
    {
        return Str::slug($country->name).'-student.webp';
    }

    /**
     * Attach the country image to the page's existing featured-image
     * media collection so SeoManager can use it for og:image — the same
     * mechanism every other CMS page uses, not a second image pipeline.
     *
     * Skipped silently when the file has not been generated yet, and
     * never replaces an image an admin has already set.
     */
    private function attachFeaturedImage(Page $page, Country $country, array $copy): void
    {
        if ($page->getFirstMedia('featured-image') !== null) {
            return;
        }

        $path = public_path(self::IMAGE_DIRECTORY.'/'.$this->imageFilename($country));

        if (! is_file($path)) {
            $this->command?->warn('  · No hero image at public/'.self::IMAGE_DIRECTORY.'/'.$this->imageFilename($country).' — og:image will fall back until it is added.');

            return;
        }

        $page->addMedia($path)
            ->preservingOriginal()
            ->usingName("Online tutoring in {$country->name}")
            ->toMediaCollection('featured-image');
    }
}
