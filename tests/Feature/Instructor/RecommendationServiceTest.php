<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Enums\AcademicStatus;
use App\Enums\InstructorStatus;
use App\Enums\LearningGoalStatus;
use App\Enums\LearningPlanStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicCategory;
use App\Models\InstructorRatingAggregate;
use App\Models\StudentFavoriteInstructor;
use App\Models\StudentLearningGoal;
use App\Models\StudentLearningPlan;
use App\Models\Subject;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Services\Instructor\RecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS Chapter 8 §8.10/§8.19-FR#4/FR#10: RecommendationService
 * is the single entry point for every Version 1 recommendation section,
 * built entirely on InstructorService::baseQuery()/card() — no parallel
 * discovery engine, no independent pricing, no random ordering.
 */
final class RecommendationServiceTest extends TestCase
{
    use RefreshDatabase;

    private RecommendationService $recommendations;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->recommendations = app(RecommendationService::class);
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

    private function activeStudent(): User
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $student->profile()->update(['student_status' => StudentStatus::Active]);

        return $student;
    }

    private function activeSubject(string $slug = 'maths', string $name = 'Maths'): Subject
    {
        $category = AcademicCategory::query()->firstOrCreate(['slug' => 'general'], ['name' => 'General']);

        return Subject::query()->firstOrCreate(
            ['slug' => $slug],
            ['academic_category_id' => $category->id, 'name' => $name, 'status' => AcademicStatus::Active],
        );
    }

    private function linkSubject(User $instructor, Subject $subject): TeacherSubject
    {
        return TeacherSubject::factory()->create([
            'teacher_id' => $instructor->id,
            'subject' => $subject->name,
            'subject_id' => $subject->id,
        ]);
    }

    private function setRating(User $instructor, int $reviewCount, float $average): void
    {
        InstructorRatingAggregate::factory()->create([
            'instructor_id' => $instructor->id,
            'eligible_review_count' => $reviewCount,
            'overall_rating_sum' => (int) round($average * $reviewCount),
        ]);
    }

    private function request(?User $viewer = null): Request
    {
        $request = Request::create('/');

        if ($viewer !== null) {
            $request->setUserResolver(fn () => $viewer);
        }

        return $request;
    }

    // ── Featured ───────────────────────────────────────────────────────

    public function test_featured_section_reuses_the_existing_admin_curated_featured_flag(): void
    {
        $this->makeInstructor();
        $featured = $this->makeInstructor([], ['is_featured' => true, 'featured_order' => 1]);

        $cards = $this->recommendations->featured($this->request());

        $this->assertCount(1, $cards);
        $this->assertSame($featured->id, $cards->first()['model']->id);
    }

    // ── Popular: deterministic ranking / tie-breaking ────────────────

    public function test_popular_section_ranks_by_review_count_then_average_rating_then_name(): void
    {
        $low = $this->makeInstructor(['name' => 'Zed Low']);
        $this->setRating($low, 2, 5.0);

        $high = $this->makeInstructor(['name' => 'Amy High']);
        $this->setRating($high, 50, 4.0);

        $tieA = $this->makeInstructor(['name' => 'Bea Tie']);
        $this->setRating($tieA, 10, 4.5);

        $tieB = $this->makeInstructor(['name' => 'Cid Tie']);
        $this->setRating($tieB, 10, 4.5);

        $cards = $this->recommendations->popular($this->request(), 10);
        $ids = $cards->map(fn (array $card): int => $card['model']->id)->values()->all();

        $this->assertSame([$high->id, $tieA->id, $tieB->id, $low->id], $ids);
    }

    public function test_popular_ranking_is_stable_across_repeated_calls(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $instructor = $this->makeInstructor();
            $this->setRating($instructor, 5, 4.0);
        }

        $first = $this->recommendations->popular($this->request(), 10)->map(fn (array $c) => $c['model']->id)->all();
        $second = $this->recommendations->popular($this->request(), 10)->map(fn (array $c) => $c['model']->id)->all();

        $this->assertSame($first, $second);
    }

    public function test_popular_excludes_given_instructor_ids(): void
    {
        $keep = $this->makeInstructor();
        $exclude = $this->makeInstructor();
        $this->setRating($exclude, 100, 5.0);

        $cards = $this->recommendations->popular($this->request(), 10, [$exclude->id]);

        $this->assertFalse($cards->contains(fn (array $c) => $c['model']->id === $exclude->id));
        $this->assertTrue($cards->contains(fn (array $c) => $c['model']->id === $keep->id));
    }

    // ── New instructors ───────────────────────────────────────────────

    public function test_new_instructors_section_orders_by_most_recently_created(): void
    {
        $old = $this->makeInstructor(['created_at' => now()->subDays(10)]);
        $newest = $this->makeInstructor(['created_at' => now()->subDay()]);

        $cards = $this->recommendations->newInstructors($this->request(), 10);
        $ids = $cards->map(fn (array $c) => $c['model']->id)->all();

        $this->assertSame([$newest->id, $old->id], $ids);
    }

    // ── Related: subject-matched, deterministic, excludes current instructor ──

    public function test_related_excludes_the_current_instructor(): void
    {
        $instructor = $this->makeInstructor();
        $this->makeInstructor();

        $cards = $this->recommendations->related($instructor, $this->request(), 10);

        $this->assertFalse($cards->contains(fn (array $c) => $c['model']->id === $instructor->id));
    }

    public function test_related_prefers_subject_matched_instructors_over_others(): void
    {
        $maths = $this->activeSubject('maths', 'Maths');
        $english = $this->activeSubject('english', 'English');

        $instructor = $this->makeInstructor();
        $this->linkSubject($instructor, $maths);

        $mathMatch = $this->makeInstructor(['name' => 'Math Match']);
        $this->linkSubject($mathMatch, $maths);
        $this->setRating($mathMatch, 1, 3.0);

        $englishOnly = $this->makeInstructor(['name' => 'English Only']);
        $this->linkSubject($englishOnly, $english);
        $this->setRating($englishOnly, 100, 5.0);

        $cards = $this->recommendations->related($instructor, $this->request(), 1);

        $this->assertSame($mathMatch->id, $cards->first()['model']->id);
    }

    public function test_related_never_duplicates_an_instructor_within_one_section(): void
    {
        $maths = $this->activeSubject();
        $instructor = $this->makeInstructor();
        $this->linkSubject($instructor, $maths);

        $other = $this->makeInstructor();
        $this->linkSubject($other, $maths);

        $cards = $this->recommendations->related($instructor, $this->request(), 10);
        $ids = $cards->map(fn (array $c) => $c['model']->id)->all();

        $this->assertSame(array_unique($ids), $ids);
    }

    public function test_related_tops_up_with_popular_instructors_when_subject_matches_are_sparse(): void
    {
        $maths = $this->activeSubject();
        $instructor = $this->makeInstructor();
        $this->linkSubject($instructor, $maths);
        // No other instructor teaches maths — the subject-matched query
        // alone would return zero results.
        $other = $this->makeInstructor();

        $cards = $this->recommendations->related($instructor, $this->request(), 1);

        $this->assertCount(1, $cards);
        $this->assertSame($other->id, $cards->first()['model']->id);
    }

    // ── Active academic entities ──────────────────────────────────────

    public function test_related_ignores_subject_overlap_through_an_inactive_subject(): void
    {
        $category = AcademicCategory::query()->firstOrCreate(['slug' => 'general'], ['name' => 'General']);
        $retired = Subject::query()->create([
            'academic_category_id' => $category->id,
            'name' => 'Retired Subject',
            'slug' => 'retired-subject',
            'status' => AcademicStatus::Inactive,
        ]);

        $instructor = $this->makeInstructor();
        $this->linkSubject($instructor, $retired);

        $other = $this->makeInstructor();
        $this->linkSubject($other, $retired);

        // Both instructors share only an INACTIVE subject — this must
        // not count as a subject match (SRS §8.21), so related() falls
        // back to its popularity top-up rather than treating them as
        // "related" via the retired subject.
        $cards = $this->recommendations->related($instructor, $this->request(), 1);

        $this->assertSame($other->id, $cards->first()['model']->id);
    }

    // ── Visibility / lifecycle exclusions ──────────────────────────────

    public function test_all_sections_exclude_non_public_or_non_bookable_instructors(): void
    {
        $this->makeInstructor([], ['profile_visibility' => 'private']);
        $this->makeInstructor([], ['instructor_status' => InstructorStatus::Suspended]);
        $this->makeInstructor(['status' => User::STATUS_INACTIVE]);
        $visible = $this->makeInstructor();

        $popular = $this->recommendations->popular($this->request(), 10);
        $new = $this->recommendations->newInstructors($this->request(), 10);

        $this->assertCount(1, $popular);
        $this->assertSame($visible->id, $popular->first()['model']->id);
        $this->assertCount(1, $new);
        $this->assertSame($visible->id, $new->first()['model']->id);
    }

    // ── Guest fallback / authenticated personalization / privacy ──────

    public function test_recommended_for_you_falls_back_to_popular_for_a_guest(): void
    {
        $popularInstructor = $this->makeInstructor();
        $this->setRating($popularInstructor, 20, 4.8);

        $cards = $this->recommendations->recommendedForYou(null, $this->request(), 5);

        $this->assertTrue($cards->contains(fn (array $c) => $c['model']->id === $popularInstructor->id));
    }

    public function test_recommended_for_you_falls_back_to_popular_for_a_student_with_no_signal(): void
    {
        $student = $this->activeStudent();
        $popularInstructor = $this->makeInstructor();
        $this->setRating($popularInstructor, 20, 4.8);

        $cards = $this->recommendations->recommendedForYou($student, $this->request($student), 5);

        $this->assertTrue($cards->contains(fn (array $c) => $c['model']->id === $popularInstructor->id));
    }

    public function test_recommended_for_you_matches_the_students_active_learning_plan_subject(): void
    {
        $student = $this->activeStudent();
        $maths = $this->activeSubject();

        $goal = StudentLearningGoal::query()->create([
            'user_id' => $student->id,
            'subject_id' => $maths->id,
            'title' => 'Master algebra',
            'type' => 'academic',
            'status' => LearningGoalStatus::Active,
        ]);

        $planInstructor = $this->makeInstructor();
        StudentLearningPlan::query()->create([
            'student_user_id' => $student->id,
            'learning_goal_id' => $goal->id,
            'primary_instructor_user_id' => $planInstructor->id,
            'subject_id' => $maths->id,
            'title' => 'Algebra plan',
            'status' => LearningPlanStatus::Active,
            'progress_percent' => 0,
        ]);

        $mathsMatch = $this->makeInstructor(['name' => 'Maths Match']);
        $this->linkSubject($mathsMatch, $maths);

        $unrelated = $this->makeInstructor(['name' => 'Unrelated']);
        $this->setRating($unrelated, 100, 5.0);

        $cards = $this->recommendations->recommendedForYou($student, $this->request($student), 1);

        $this->assertSame($mathsMatch->id, $cards->first()['model']->id);
    }

    public function test_recommended_for_you_excludes_already_favorited_instructors(): void
    {
        $student = $this->activeStudent();
        $maths = $this->activeSubject();

        $favorited = $this->makeInstructor(['name' => 'Already Favorited']);
        $this->linkSubject($favorited, $maths);
        StudentFavoriteInstructor::query()->create([
            'student_user_id' => $student->id,
            'instructor_user_id' => $favorited->id,
        ]);

        $newMatch = $this->makeInstructor(['name' => 'New Match']);
        $this->linkSubject($newMatch, $maths);

        $cards = $this->recommendations->recommendedForYou($student, $this->request($student), 10);
        $ids = $cards->map(fn (array $c) => $c['model']->id)->all();

        $this->assertNotContains($favorited->id, $ids);
        $this->assertContains($newMatch->id, $ids);
    }

    public function test_recommended_for_you_never_reads_another_students_favorites_or_plans(): void
    {
        $student = $this->activeStudent();
        $otherStudent = $this->activeStudent();
        $maths = $this->activeSubject();

        // Only the OTHER student has a signal — this student has none.
        $otherFavorite = $this->makeInstructor();
        $this->linkSubject($otherFavorite, $maths);
        StudentFavoriteInstructor::query()->create([
            'student_user_id' => $otherStudent->id,
            'instructor_user_id' => $otherFavorite->id,
        ]);

        $popularInstructor = $this->makeInstructor();
        $this->setRating($popularInstructor, 20, 4.8);

        // No exception, no cross-student signal leakage — this student
        // gets the safe popular() fallback exactly as if they had no
        // relationship data at all.
        $cards = $this->recommendations->recommendedForYou($student, $this->request($student), 5);

        $this->assertTrue($cards->contains(fn (array $c) => $c['model']->id === $popularInstructor->id));
    }

    // ── Empty states ───────────────────────────────────────────────────

    public function test_sections_return_empty_collections_when_no_instructors_exist(): void
    {
        $this->assertCount(0, $this->recommendations->popular($this->request()));
        $this->assertCount(0, $this->recommendations->newInstructors($this->request()));
        $this->assertCount(0, $this->recommendations->featured($this->request()));
        $this->assertCount(0, $this->recommendations->recommendedForYou(null, $this->request()));
    }

    // ── Localized pricing reuse ────────────────────────────────────────

    public function test_every_section_returns_card_data_with_a_price_key_from_the_shared_pricing_service(): void
    {
        $instructor = $this->makeInstructor();
        $this->linkSubject($instructor, $this->activeSubject());

        foreach ([
            $this->recommendations->popular($this->request(), 1),
            $this->recommendations->newInstructors($this->request(), 1),
            $this->recommendations->recommendedForYou(null, $this->request(), 1),
        ] as $cards) {
            $this->assertArrayHasKey('price', $cards->first());
        }
    }

    // ── Bounded queries ──────────────────────────────────────────────

    public function test_popular_query_count_stays_flat_as_instructor_count_grows(): void
    {
        // The absolute count includes card()'s existing (pre-Phase-39,
        // unchanged) per-card pricing/rating lookups — bounded by
        // $limit, not by table size. The property that actually matters
        // is that MORE instructors in the table never means MORE
        // queries for the same $limit: no per-row scan of the full set.
        for ($i = 0; $i < 10; $i++) {
            $instructor = $this->makeInstructor();
            $this->setRating($instructor, $i, 4.0);
        }

        // Warm up settings/config caches once so neither measurement
        // below is skewed by a one-time "first read" query unrelated to
        // instructor table size.
        $this->recommendations->popular($this->request(), 4);

        DB::enableQueryLog();
        $this->recommendations->popular($this->request(), 4);
        $smallCount = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        for ($i = 0; $i < 40; $i++) {
            $instructor = $this->makeInstructor();
            $this->setRating($instructor, $i, 4.0);
        }

        DB::enableQueryLog();
        $this->recommendations->popular($this->request(), 4);
        $largeCount = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        $this->assertSame($smallCount, $largeCount);
    }
}
