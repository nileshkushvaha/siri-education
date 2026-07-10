<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Enums\AcademicStatus;
use App\Models\AcademicCategory;
use App\Models\Subject;
use App\Models\SubjectTopic;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubjectTopicTest extends TestCase
{
    use RefreshDatabase;

    private function subject(string $slug = 'mathematics'): Subject
    {
        $category = AcademicCategory::query()->firstOrCreate(['slug' => 'school'], ['name' => 'School']);

        return Subject::create([
            'academic_category_id' => $category->id,
            'name' => ucfirst($slug),
            'slug' => $slug,
        ]);
    }

    public function test_subject_can_have_topics(): void
    {
        $subject = $this->subject();
        SubjectTopic::factory()->count(3)->create(['subject_id' => $subject->id]);

        $this->assertCount(3, $subject->topics);
    }

    public function test_topic_belongs_to_one_subject(): void
    {
        $subject = $this->subject();
        $topic = SubjectTopic::factory()->create(['subject_id' => $subject->id, 'name' => 'Algebra', 'slug' => 'algebra']);

        $this->assertTrue($topic->subject->is($subject));
    }

    public function test_topic_can_have_parent_and_children(): void
    {
        $subject = $this->subject();
        $algebra = SubjectTopic::factory()->create(['subject_id' => $subject->id, 'name' => 'Algebra', 'slug' => 'algebra']);
        $linear = SubjectTopic::factory()->childOf($algebra)->create(['name' => 'Linear Equations', 'slug' => 'linear-equations']);

        $this->assertTrue($linear->parent->is($algebra));
        $this->assertTrue($algebra->children->first()->is($linear));
        $this->assertSame($subject->id, $linear->subject_id);
    }

    public function test_slug_is_unique_per_subject_but_reusable_across_subjects(): void
    {
        $maths = $this->subject('mathematics');
        $satMath = $this->subject('sat-math');

        SubjectTopic::factory()->create(['subject_id' => $maths->id, 'slug' => 'algebra']);
        // Same slug under a different subject is fine.
        SubjectTopic::factory()->create(['subject_id' => $satMath->id, 'slug' => 'algebra']);
        $this->assertSame(2, SubjectTopic::query()->where('slug', 'algebra')->count());

        // Duplicate within the same subject is rejected by the DB.
        $this->expectException(QueryException::class);
        SubjectTopic::factory()->create(['subject_id' => $maths->id, 'slug' => 'algebra']);
    }

    public function test_inactive_topic_is_excluded_from_the_active_pool(): void
    {
        $subject = $this->subject();
        $active = SubjectTopic::factory()->create(['subject_id' => $subject->id]);
        SubjectTopic::factory()->inactive()->create(['subject_id' => $subject->id]);

        $pool = SubjectTopic::query()->active()->pluck('id');

        $this->assertTrue($pool->contains($active->id));
        $this->assertCount(1, $pool);
    }

    public function test_soft_deleting_a_subject_keeps_topic_rows(): void
    {
        // Subjects soft-delete; existing bookings keep their meta snapshot
        // and topic rows stay addressable for history/restore.
        $subject = $this->subject();
        $topic = SubjectTopic::factory()->create(['subject_id' => $subject->id]);

        $subject->delete();

        $this->assertSoftDeleted('subjects', ['id' => $subject->id]);
        $this->assertDatabaseHas('subject_topics', ['id' => $topic->id, 'deleted_at' => null]);
        $this->assertSame(AcademicStatus::Active, $topic->fresh()->status);
    }
}
