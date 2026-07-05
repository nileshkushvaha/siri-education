<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AcademicCategory;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_academic_category_can_be_created(): void
    {
        $category = AcademicCategory::create([
            'name' => 'Mathematics',
            'slug' => 'mathematics',
        ]);

        $this->assertDatabaseHas('academic_categories', [
            'name' => 'Mathematics',
            'is_active' => 1,
        ]);
        $this->assertTrue($category->is_active);
        $this->assertSame(0, $category->display_order);
    }

    public function test_academic_category_soft_deletes_and_restores(): void
    {
        $category = AcademicCategory::create(['name' => 'Sciences', 'slug' => 'sciences']);
        $id = $category->id;

        $category->delete();

        $this->assertSoftDeleted('academic_categories', ['id' => $id]);
        $this->assertNull(AcademicCategory::find($id));

        AcademicCategory::withTrashed()->find($id)->restore();

        $this->assertNotNull(AcademicCategory::find($id));
    }

    public function test_academic_category_has_many_subjects(): void
    {
        $category = AcademicCategory::create(['name' => 'Languages', 'slug' => 'languages']);

        Subject::create([
            'academic_category_id' => $category->id,
            'name' => 'English',
            'slug' => 'english',
        ]);

        $this->assertCount(1, $category->subjects);
    }

    public function test_scope_active_excludes_inactive_categories(): void
    {
        AcademicCategory::create(['name' => 'Active Cat', 'slug' => 'active-cat', 'is_active' => true]);
        AcademicCategory::create(['name' => 'Inactive Cat', 'slug' => 'inactive-cat', 'is_active' => false]);

        $active = AcademicCategory::active()->get();

        $this->assertTrue($active->contains('name', 'Active Cat'));
        $this->assertFalse($active->contains('name', 'Inactive Cat'));

        // Inactive categories remain directly queryable (historical records).
        $this->assertNotNull(AcademicCategory::where('name', 'Inactive Cat')->first());
    }
}
