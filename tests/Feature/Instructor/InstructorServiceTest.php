<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Enums\InstructorStatus;
use App\Models\User;
use App\Models\UserExperience;
use App\Services\Instructor\InstructorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InstructorServiceTest extends TestCase
{
    use RefreshDatabase;

    private InstructorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        $this->service = app(InstructorService::class);
    }

    private function makeInstructor(array $overrides = [], array $profileOverrides = []): User
    {
        $user = User::factory()->create(array_merge(['status' => 'active'], $overrides));
        $user->profile->update(array_merge([
            'profile_visibility' => 'public',
            'instructor_status' => InstructorStatus::Approved,
        ], $profileOverrides));
        $user->assignRole('instructor');

        return $user;
    }

    public function test_listing_excludes_non_instructor_users(): void
    {
        $student = User::factory()->create(['status' => 'active']);
        $student->profile->update(['profile_visibility' => 'public']);

        $request = Request::create('/instructors');
        $result = $this->service->listing($request);

        $this->assertSame(0, $result->total());
    }

    public function test_listing_excludes_inactive_users(): void
    {
        $this->makeInstructor(['status' => 'inactive']);

        $request = Request::create('/instructors');
        $result = $this->service->listing($request);

        $this->assertSame(0, $result->total());
    }

    public function test_listing_excludes_private_profiles(): void
    {
        $this->makeInstructor([], ['profile_visibility' => 'private']);

        $request = Request::create('/instructors');
        $result = $this->service->listing($request);

        $this->assertSame(0, $result->total());
    }

    public function test_listing_excludes_pending_instructors(): void
    {
        $this->makeInstructor([], ['instructor_status' => InstructorStatus::Submitted]);

        $request = Request::create('/instructors');
        $result = $this->service->listing($request);

        $this->assertSame(0, $result->total());
    }

    public function test_listing_includes_active_public_instructor(): void
    {
        $this->makeInstructor();

        $request = Request::create('/instructors');
        $result = $this->service->listing($request);

        $this->assertSame(1, $result->total());
    }

    public function test_listing_keyword_search_by_name(): void
    {
        $this->makeInstructor(['name' => 'Alice Walker']);
        $this->makeInstructor(['name' => 'Bob Builder']);

        $request = Request::create('/instructors', 'GET', ['q' => 'Alice']);
        $result = $this->service->listing($request);

        $this->assertSame(1, $result->total());
        $this->assertSame('Alice Walker', $result->first()->name);
    }

    public function test_featured_returns_only_featured_instructors(): void
    {
        $this->makeInstructor();
        $featured = $this->makeInstructor([], ['is_featured' => true, 'featured_order' => 1]);

        $result = $this->service->featured(4);

        $this->assertCount(1, $result);
        $this->assertSame($featured->id, $result->first()->id);
    }

    public function test_related_excludes_the_subject_instructor(): void
    {
        $instructor = $this->makeInstructor();
        $this->makeInstructor();
        $this->makeInstructor();

        $result = $this->service->related($instructor, 10);

        $this->assertFalse($result->contains('id', $instructor->id));
    }

    public function test_stats_returns_zero_stub_for_students(): void
    {
        $instructor = $this->makeInstructor();

        $stats = $this->service->stats($instructor);

        $this->assertSame(0, $stats['students_count']);
        $this->assertNull($stats['avg_rating']);
    }

    public function test_stats_returns_years_of_experience(): void
    {
        $instructor = $this->makeInstructor();
        UserExperience::factory()->for($instructor)->create([
            'start_date' => now()->subYears(3),
            'end_date' => null,
            'is_current' => true,
        ]);

        $stats = $this->service->stats($instructor);

        $this->assertGreaterThanOrEqual(2.9, $stats['years_experience']);
    }
}
