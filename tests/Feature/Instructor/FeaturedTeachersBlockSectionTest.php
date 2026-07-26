<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Enums\InstructorStatus;
use App\Livewire\Frontend\Cms\FeaturedTeachers;
use App\Models\InstructorRatingAggregate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * GAP-025 requirement #9 — admin configuration reuses the EXISTING CMS
 * block save flow (FeaturedTeachersBlockForm's new `section` field)
 * rather than a new settings page. This proves the block genuinely
 * switches recommendation strategy per its configured section, and
 * that omitting `section` (every block already placed on existing
 * pages) keeps the original Featured behavior.
 */
final class FeaturedTeachersBlockSectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
    }

    private function makeInstructor(array $overrides = [], array $profileOverrides = []): User
    {
        $user = User::factory()->create(array_merge(['status' => User::STATUS_ACTIVE], $overrides));
        $user->profile->update(array_merge([
            'profile_visibility' => 'public',
            'instructor_status' => InstructorStatus::Approved,
        ], $profileOverrides));
        $user->assignRole('instructor');

        return $user;
    }

    public function test_omitting_section_keeps_the_original_featured_behavior(): void
    {
        $this->makeInstructor();
        $featured = $this->makeInstructor(['name' => 'Featured One'], ['is_featured' => true, 'featured_order' => 1]);

        Livewire::test(FeaturedTeachers::class)
            ->assertSee($featured->name);
    }

    public function test_section_popular_shows_the_highest_rated_instructor_not_the_featured_one(): void
    {
        $featured = $this->makeInstructor(['name' => 'Featured Only'], ['is_featured' => true, 'featured_order' => 1]);
        $popular = $this->makeInstructor(['name' => 'Popular One']);
        InstructorRatingAggregate::factory()->create([
            'instructor_id' => $popular->id,
            'eligible_review_count' => 50,
            'overall_rating_sum' => 250,
        ]);

        Livewire::test(FeaturedTeachers::class, ['section' => 'popular', 'limit' => 1])
            ->assertSee($popular->name)
            ->assertDontSee($featured->name);
    }

    public function test_section_new_shows_the_most_recently_created_instructor(): void
    {
        $this->makeInstructor(['name' => 'Old Instructor', 'created_at' => now()->subMonth()]);
        $newest = $this->makeInstructor(['name' => 'Newest Instructor', 'created_at' => now()]);

        Livewire::test(FeaturedTeachers::class, ['section' => 'new', 'limit' => 1])
            ->assertSee($newest->name)
            ->assertDontSee('Old Instructor');
    }

    public function test_section_recommended_for_you_falls_back_to_popular_for_a_guest(): void
    {
        $popular = $this->makeInstructor(['name' => 'Guest Popular']);
        InstructorRatingAggregate::factory()->create([
            'instructor_id' => $popular->id,
            'eligible_review_count' => 30,
            'overall_rating_sum' => 140,
        ]);

        Livewire::test(FeaturedTeachers::class, ['section' => 'recommended_for_you'])
            ->assertSee('Guest Popular');
    }
}
