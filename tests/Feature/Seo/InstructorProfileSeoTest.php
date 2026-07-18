<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Enums\InstructorStatus;
use App\Models\InstructorRatingAggregate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class InstructorProfileSeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
    }

    public function test_canonical_link_exists(): void
    {
        $instructor = $this->makeInstructor();

        $this->get(route('instructors.show', $instructor))
            ->assertOk()
            ->assertSee('rel="canonical" href="'.route('instructors.show', $instructor).'"', false);
    }

    public function test_person_schema_exists(): void
    {
        $instructor = $this->makeInstructor();

        $response = $this->get(route('instructors.show', $instructor));

        $response->assertOk();
        $jsonLd = $this->extractJsonLd($response->getContent());

        $this->assertSame('Person', $jsonLd['@type']);
        $this->assertSame($instructor->name, $jsonLd['name']);
    }

    public function test_aggregate_rating_is_present_when_reviews_exist(): void
    {
        $instructor = $this->makeInstructor();
        InstructorRatingAggregate::factory()->create([
            'instructor_id' => $instructor->id,
            'eligible_review_count' => 12,
            'overall_rating_sum' => 55,
            'rating_distribution' => ['5' => 8, '4' => 4],
        ]);

        $response = $this->get(route('instructors.show', $instructor));

        $response->assertOk();
        $jsonLd = $this->extractJsonLd($response->getContent());

        $this->assertArrayHasKey('aggregateRating', $jsonLd);
        $this->assertSame(12, $jsonLd['aggregateRating']['reviewCount']);
    }

    public function test_aggregate_rating_is_absent_when_no_reviews_exist(): void
    {
        $instructor = $this->makeInstructor();

        $response = $this->get(route('instructors.show', $instructor));

        $response->assertOk();
        $jsonLd = $this->extractJsonLd($response->getContent());

        $this->assertArrayNotHasKey('aggregateRating', $jsonLd);
        $response->assertDontSee('"0 reviews"', false);
    }

    public function test_sitemap_contains_approved_instructor(): void
    {
        $instructor = $this->makeInstructor(['instructor_status' => InstructorStatus::Approved]);

        $this->get(route('seo.sitemap'))
            ->assertOk()
            ->assertSee(route('instructors.show', $instructor), false);
    }

    public function test_sitemap_contains_active_instructor(): void
    {
        $instructor = $this->makeInstructor(['instructor_status' => InstructorStatus::Active]);

        $this->get(route('seo.sitemap'))
            ->assertOk()
            ->assertSee(route('instructors.show', $instructor), false);
    }

    public function test_sitemap_excludes_rejected_and_suspended_instructors(): void
    {
        $rejected = $this->makeInstructor(['instructor_status' => InstructorStatus::Rejected]);
        $suspended = $this->makeInstructor(['instructor_status' => InstructorStatus::Suspended]);
        $draft = $this->makeInstructor(['instructor_status' => InstructorStatus::Draft]);
        $vacation = $this->makeInstructor(['instructor_status' => InstructorStatus::Vacation]);

        $response = $this->get(route('seo.sitemap'))->assertOk();

        foreach ([$rejected, $suspended, $draft, $vacation] as $excluded) {
            $response->assertDontSee($excluded->slug, false);
        }
    }

    /** @return array<string, mixed> */
    private function extractJsonLd(string $html): array
    {
        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);
        $this->assertNotEmpty($matches, 'No JSON-LD script tag found.');

        $decoded = json_decode(html_entity_decode($matches[1]), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function makeInstructor(array $profileOverrides = []): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->profile->update(array_merge([
            'profile_visibility' => 'public',
            'instructor_status' => InstructorStatus::Approved,
            'offers_demo' => true,
        ], $profileOverrides));
        $user->assignRole('instructor');

        return $user->fresh();
    }
}
