<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Models\AcademicLevel;
use App\Models\InstructorSubjectTopic;
use App\Models\SubjectTopic;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Instructor\InstructorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MarketplaceTopicFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $covering;

    private User $other;

    private SubjectTopic $topic;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $this->covering = $this->publicInstructor('Covering Instructor');
        $this->other = $this->publicInstructor('Other Instructor');

        $this->topic = SubjectTopic::factory()->create(['name' => 'Algebra', 'slug' => 'algebra']);

        InstructorSubjectTopic::factory()->create([
            'teacher_id' => $this->covering->id,
            'subject_topic_id' => $this->topic->id,
        ]);
    }

    private function publicInstructor(string $name): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE, 'name' => $name]);
        $user->assignRole('instructor');
        UserProfile::updateOrCreate(['user_id' => $user->id], [
            'instructor_status' => 'approved',
            'profile_visibility' => 'public',
        ]);

        return $user;
    }

    private function service(): InstructorService
    {
        return app(InstructorService::class);
    }

    public function test_topic_filter_shows_only_instructors_with_bookable_coverage(): void
    {
        $names = $this->service()
            ->listing(Request::create('/instructors', 'GET', ['topic' => $this->topic->id]))
            ->pluck('name');

        $this->assertTrue($names->contains('Covering Instructor'));
        $this->assertFalse($names->contains('Other Instructor'));
    }

    public function test_topic_filter_by_slug_also_works(): void
    {
        $names = $this->service()
            ->listing(Request::create('/instructors', 'GET', ['topic' => 'algebra']))
            ->pluck('name');

        $this->assertTrue($names->contains('Covering Instructor'));
    }

    public function test_unapproved_coverage_is_excluded_from_the_topic_filter(): void
    {
        InstructorSubjectTopic::query()->update(['approved_at' => null]);

        $names = $this->service()
            ->listing(Request::create('/instructors', 'GET', ['topic' => $this->topic->id]))
            ->pluck('name');

        $this->assertFalse($names->contains('Covering Instructor'));
    }

    public function test_inactive_topics_do_not_appear_in_filter_options(): void
    {
        $inactive = SubjectTopic::factory()->inactive()->create(['slug' => 'hidden-topic']);
        InstructorSubjectTopic::factory()->create([
            'teacher_id' => $this->covering->id,
            'subject_topic_id' => $inactive->id,
        ]);

        $values = collect($this->service()->filters()['topics'])->pluck('value');

        $this->assertTrue($values->contains($this->topic->id));
        $this->assertFalse($values->contains($inactive->id));
    }

    public function test_topic_and_academic_level_filters_combine(): void
    {
        // The covering instructor also declares the matching level on
        // their profile — both filters must agree for a row to survive.
        $level = AcademicLevel::create(['name' => 'High School', 'slug' => 'high-school', 'min_grade' => 9, 'max_grade' => 12]);
        $this->covering->profile->update(['instructor_academic_level_ids' => [$level->id]]);

        $matching = $this->service()->listing(Request::create('/instructors', 'GET', [
            'topic' => $this->topic->id,
            'academic_level' => $level->id,
        ]))->pluck('name');
        $this->assertTrue($matching->contains('Covering Instructor'));

        $this->other->profile->update(['instructor_academic_level_ids' => [$level->id]]);
        $stillOnlyCovering = $this->service()->listing(Request::create('/instructors', 'GET', [
            'topic' => $this->topic->id,
            'academic_level' => $level->id,
        ]))->pluck('name');
        $this->assertFalse($stillOnlyCovering->contains('Other Instructor'));
    }

    public function test_instructor_profile_exposes_approved_topics_without_pricing_data(): void
    {
        $topics = $this->service()->topicsFor($this->covering);

        $this->assertCount(1, $topics);
        $this->assertSame('Algebra', $topics->first()['name']);
        // Safe keys only — never price/coverage internals.
        $this->assertSame(['name', 'slug', 'subject'], array_keys($topics->first()));
    }

    public function test_directory_page_renders_with_topic_filter(): void
    {
        $this->get('/instructors?topic='.$this->topic->id)
            ->assertOk()
            ->assertSee('Covering Instructor')
            ->assertDontSee('Other Instructor');
    }
}
