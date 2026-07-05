<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\SkillLevel;
use App\Models\Subject;
use App\Models\TeacherAvailability;
use App\Models\TeacherUnavailability;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Confirms LogsActivity was actually wired up (not just the trait added)
 * for the models the Filament admin foundation audit found missing it.
 */
class ActivityLoggingCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_academic_category_update_is_logged(): void
    {
        $category = AcademicCategory::create(['name' => 'Mathematics', 'slug' => 'mathematics']);
        $category->update(['name' => 'Applied Mathematics']);

        $this->assertDatabaseHas('activity_log', ['log_name' => 'academic_categories']);
    }

    public function test_subject_update_is_logged(): void
    {
        $category = AcademicCategory::create(['name' => 'Science', 'slug' => 'science']);
        $subject = Subject::create(['academic_category_id' => $category->id, 'name' => 'Physics', 'slug' => 'physics']);
        $subject->update(['name' => 'Applied Physics']);

        $this->assertDatabaseHas('activity_log', ['log_name' => 'subjects']);
    }

    public function test_academic_level_update_is_logged(): void
    {
        $level = AcademicLevel::create(['name' => 'Primary', 'slug' => 'primary']);
        $level->update(['name' => 'Primary School']);

        $this->assertDatabaseHas('activity_log', ['log_name' => 'academic_levels']);
    }

    public function test_skill_level_update_is_logged(): void
    {
        $skill = SkillLevel::create(['name' => 'Beginner', 'slug' => 'beginner']);
        $skill->update(['name' => 'Novice']);

        $this->assertDatabaseHas('activity_log', ['log_name' => 'skill_levels']);
    }

    public function test_faq_category_update_is_logged(): void
    {
        $category = FaqCategory::create(['name' => 'Billing', 'is_active' => true]);
        $category->update(['name' => 'Payments']);

        $this->assertDatabaseHas('activity_log', ['log_name' => 'faq_categories']);
    }

    public function test_faq_update_is_logged(): void
    {
        $category = FaqCategory::create(['name' => 'General', 'is_active' => true]);
        $faq = Faq::create([
            'faq_category_id' => $category->id,
            'question' => 'How do I pay?',
            'answer' => '<p>Use a card.</p>',
            'audience' => ['public'],
            'status' => 'draft',
        ]);
        $faq->update(['question' => 'How do I make a payment?']);

        $this->assertDatabaseHas('activity_log', ['log_name' => 'faqs']);
    }

    public function test_teacher_availability_update_is_logged(): void
    {
        $teacher = User::factory()->create(['status' => 'active']);
        $availability = TeacherAvailability::create([
            'teacher_id' => $teacher->id,
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'is_active' => true,
        ]);
        $availability->update(['is_active' => false]);

        $this->assertDatabaseHas('activity_log', ['log_name' => 'teacher_availability']);
    }

    public function test_teacher_unavailability_update_is_logged(): void
    {
        $teacher = User::factory()->create(['status' => 'active']);
        $blackout = TeacherUnavailability::create([
            'teacher_id' => $teacher->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
            'reason' => 'Holiday',
        ]);
        $blackout->update(['reason' => 'Sick leave']);

        $this->assertDatabaseHas('activity_log', ['log_name' => 'teacher_unavailability']);
    }
}
