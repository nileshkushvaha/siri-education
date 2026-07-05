<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Enums\AcademicStatus;
use App\Models\AcademicCategory;
use App\Models\Country;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubjectTest extends TestCase
{
    use RefreshDatabase;

    private AcademicCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = AcademicCategory::create(['name' => 'Mathematics', 'slug' => 'mathematics']);
    }

    public function test_subject_can_be_created_with_default_status(): void
    {
        $subject = Subject::create([
            'academic_category_id' => $this->category->id,
            'name' => 'Algebra',
            'slug' => 'algebra',
        ]);

        $this->assertSame(AcademicStatus::Active, $subject->status);
        $this->assertSame(0, $subject->display_order);
    }

    public function test_subject_belongs_to_academic_category(): void
    {
        $subject = Subject::create([
            'academic_category_id' => $this->category->id,
            'name' => 'Geometry',
            'slug' => 'geometry',
        ]);

        $this->assertTrue($subject->category->is($this->category));
    }

    public function test_scope_active_excludes_inactive_and_archived_subjects(): void
    {
        Subject::create([
            'academic_category_id' => $this->category->id,
            'name' => 'Active Subject',
            'slug' => 'active-subject',
            'status' => AcademicStatus::Active,
        ]);
        Subject::create([
            'academic_category_id' => $this->category->id,
            'name' => 'Inactive Subject',
            'slug' => 'inactive-subject',
            'status' => AcademicStatus::Inactive,
        ]);
        Subject::create([
            'academic_category_id' => $this->category->id,
            'name' => 'Archived Subject',
            'slug' => 'archived-subject',
            'status' => AcademicStatus::Archived,
        ]);

        $active = Subject::active()->get();

        $this->assertTrue($active->contains('name', 'Active Subject'));
        $this->assertFalse($active->contains('name', 'Inactive Subject'));
        $this->assertFalse($active->contains('name', 'Archived Subject'));

        // Archived/inactive subjects remain directly queryable for historical records.
        $this->assertNotNull(Subject::where('name', 'Archived Subject')->first());
    }

    public function test_scope_available_for_assignment_matches_active_scope(): void
    {
        Subject::create([
            'academic_category_id' => $this->category->id,
            'name' => 'Assignable Subject',
            'slug' => 'assignable-subject',
        ]);

        $this->assertCount(1, Subject::availableForAssignment()->get());
    }

    public function test_subject_with_no_country_rows_is_available_everywhere(): void
    {
        $subject = Subject::create([
            'academic_category_id' => $this->category->id,
            'name' => 'Global Subject',
            'slug' => 'global-subject',
        ]);

        $country = Country::query()->first() ?? Country::factory()->create();

        $this->assertTrue($subject->isAvailableInCountry($country));
    }

    public function test_subject_restricted_to_specific_countries(): void
    {
        $subject = Subject::create([
            'academic_category_id' => $this->category->id,
            'name' => 'Restricted Subject',
            'slug' => 'restricted-subject',
        ]);

        $allowedCountry = Country::factory()->create();
        $otherCountry = Country::factory()->create();

        $subject->countries()->attach($allowedCountry->id);

        $this->assertTrue($subject->isAvailableInCountry($allowedCountry));
        $this->assertFalse($subject->isAvailableInCountry($otherCountry));
    }
}
