<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Enums\AcademicStatus;
use App\Models\AcademicLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicLevelTest extends TestCase
{
    use RefreshDatabase;

    public function test_academic_level_can_be_created_with_default_status(): void
    {
        $level = AcademicLevel::create([
            'name' => 'Primary',
            'slug' => 'primary',
            'min_grade' => 1,
            'max_grade' => 5,
        ]);

        $this->assertSame(AcademicStatus::Active, $level->status);
    }

    public function test_scope_active_excludes_inactive_and_archived_levels(): void
    {
        AcademicLevel::create(['name' => 'Active Level', 'slug' => 'active-level', 'status' => AcademicStatus::Active]);
        AcademicLevel::create(['name' => 'Archived Level', 'slug' => 'archived-level', 'status' => AcademicStatus::Archived]);

        $active = AcademicLevel::active()->get();

        $this->assertTrue($active->contains('name', 'Active Level'));
        $this->assertFalse($active->contains('name', 'Archived Level'));

        // Archived levels remain directly queryable for historical booking records.
        $this->assertNotNull(AcademicLevel::where('name', 'Archived Level')->first());
    }

    public function test_scope_available_for_assignment_matches_active_scope(): void
    {
        AcademicLevel::create(['name' => 'Assignable Level', 'slug' => 'assignable-level']);

        $this->assertCount(1, AcademicLevel::availableForAssignment()->get());
    }

    public function test_covers_grade_within_range(): void
    {
        $level = AcademicLevel::create([
            'name' => 'Middle School',
            'slug' => 'middle-school',
            'min_grade' => 6,
            'max_grade' => 8,
        ]);

        $this->assertTrue($level->coversGrade(7));
        $this->assertFalse($level->coversGrade(9));
    }

    public function test_covers_grade_returns_false_when_not_grade_bound(): void
    {
        $level = AcademicLevel::create(['name' => 'Undergraduate', 'slug' => 'undergraduate']);

        $this->assertFalse($level->coversGrade(1));
    }
}
