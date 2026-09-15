<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Booking\Repositories\BookingRepository;
use App\Curriculum\Services\EducationSystemService;
use App\Enums\AcademicStatus;
use App\Livewire\Frontend\Booking\BookingWizard;
use App\Models\AcademicCategory;
use App\Models\Booking;
use App\Models\BookingAcademicContext;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\Curriculum;
use App\Models\Subject;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CreatesAcademicBookingContext;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * The subject a returning student sees pre-selected follows the subjects
 * they chose on their profile: exactly one offered → chosen for them;
 * several → they pick on the subject step, preferred ones first; none →
 * the last booking's subject, resolved deterministically.
 */
final class BookingWizardPreferredSubjectTest extends TestCase
{
    use CreatesAcademicBookingContext;
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private User $teacher;

    private Country $country;

    /** @var array<string, mixed> */
    private array $academic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootAcademicBookingContext();
        $this->enableDemoLessons();

        $this->teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->teacher->assignRole('instructor');
        UserProfile::updateOrCreate(['user_id' => $this->teacher->id], [
            'instructor_status' => 'approved',
            'profile_visibility' => 'public',
        ]);
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $this->teacher->id])
                ->forDay($day)->between('09:00:00', '17:00:00')->create();
        }

        $priced = $this->createPaidBookingTypeWithPrice('paid_one_to_one', 499.00, 'INR', durationMinutes: 60);
        BookingType::query()->where('key', 'paid_one_to_one')->update(['sort_order' => 2]);
        BookingType::factory()->create(['key' => 'free_demo', 'name' => 'Free Demo', 'duration_minutes' => 30, 'sort_order' => 1]);
        $this->country = $priced['country'];

        $this->academic = $this->seedAcademicContext('STG', $this->country, normalizedGrade: 10);
        $this->teach($this->academic['subject'], $this->academic['curriculum']);
        $this->seedStudentLessonPrice($priced['type'], $this->country, $priced['currency'], 499.00, $this->academic['subject']->slug);

        Livewire::component('frontend.booking.booking-wizard', BookingWizard::class);
    }

    private function teach(Subject $subject, Curriculum $curriculum): void
    {
        TeacherSubject::factory()->create([
            'teacher_id' => $this->teacher->id,
            'subject' => $subject->name,
            'subject_id' => $subject->id,
            'grade_from' => 1,
            'grade_to' => 12,
        ]);
        $this->makeInstructorEligible($this->teacher, $this->academic['system'], $curriculum);
    }

    /** Another active subject, offered in every country, with a bookable curriculum under the same system. */
    private function anotherSubject(string $name): Subject
    {
        $category = AcademicCategory::query()->firstOrCreate(
            ['slug' => 'academic-context-general'],
            ['name' => 'Academic Context General'],
        );
        $subject = Subject::create([
            'academic_category_id' => $category->id,
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'status' => 'active',
        ]);

        $curriculum = $this->publishCurriculum($subject, $this->academic['academicLevel'], "{$name} Curriculum");
        app(EducationSystemService::class)->mapToCurriculum($this->academicAdmin(), $this->academic['system'], $curriculum);
        $this->teach($subject, $curriculum);

        return $subject;
    }

    private function student(): User
    {
        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        $this->assignAcademicCountry($student, $this->country);

        return $student;
    }

    private function wizardFor(User $student): Testable
    {
        return Livewire::actingAs($student)->test('frontend.booking.booking-wizard');
    }

    private function bookDemoFor(User $student): void
    {
        $slot = CarbonImmutable::now('UTC')->addDays(3)->setTime(10, 0);

        $this->navigateAcademicWizardToSlot($this->wizardFor($student), $this->academic, $slot, mode: 'free_demo', billingMode: null)
            ->call('continueStage')
            ->call('submit')
            ->assertSee('Booking confirmed');
    }

    public function test_a_single_preferred_subject_is_chosen_over_the_last_bookings_subject(): void
    {
        $student = $this->student();
        $this->bookDemoFor($student);
        $science = $this->anotherSubject('Stg Science');
        $student->preferredSubjects()->sync([$science->id]);

        $this->wizardFor($student)
            ->call('selectMode', 'paid_one_to_one')
            ->assertSet('educationSystemLevelId', $this->academic['level']->id)
            ->assertSet('academicSubjectId', $science->id)
            ->assertSet('curriculumId', null)
            ->assertSet('prefilledLearning', true)
            ->assertSet('step', 4)
            ->assertSee('Choose a curriculum')
            ->assertSee('Stg Science Curriculum');
    }

    public function test_a_single_preferred_subject_with_only_a_profile_level_skips_the_subject_step(): void
    {
        $student = $this->student();
        $student->profile()->update(['student_academic_level_id' => $this->academic['academicLevel']->id]);
        $student->preferredSubjects()->sync([$this->academic['subject']->id]);

        $this->wizardFor($student)
            ->call('selectMode', 'paid_one_to_one')
            ->assertSet('academicSubjectId', $this->academic['subject']->id)
            ->assertSet('step', 4)
            ->assertSee('Choose a curriculum');
    }

    public function test_several_preferred_subjects_are_never_chosen_for_the_student_and_are_listed_first(): void
    {
        $student = $this->student();
        $this->bookDemoFor($student);
        $science = $this->anotherSubject('Stg Science');
        $this->anotherSubject('Aaa Art');
        $student->preferredSubjects()->sync([$this->academic['subject']->id, $science->id]);

        $this->wizardFor($student)
            ->call('selectMode', 'paid_one_to_one')
            ->assertSet('educationSystemLevelId', $this->academic['level']->id)
            ->assertSet('academicSubjectId', null)
            ->assertSet('prefilledLearning', true)
            ->assertSet('step', 3)
            ->assertSee('Choose a subject')
            ->assertSee('You chose 2 subjects in your profile. Which one is this lesson for?')
            ->assertSee('Your subject')
            ->assertSeeInOrder([$this->academic['subject']->name, 'Stg Science', 'Aaa Art']);
    }

    public function test_a_preferred_subject_not_offered_in_the_students_country_is_ignored(): void
    {
        $student = $this->student();
        $this->bookDemoFor($student);
        $elsewhere = $this->anotherSubject('Stg Elsewhere');
        $elsewhere->countries()->attach(Country::factory()->create()->id);
        $student->preferredSubjects()->sync([$elsewhere->id]);

        $this->wizardFor($student)
            ->call('selectMode', 'paid_one_to_one')
            ->assertSet('academicSubjectId', $this->academic['subject']->id)
            ->assertSet('curriculumId', $this->academic['curriculum']->id)
            ->assertSet('step', 5);
    }

    public function test_an_archived_preferred_subject_is_ignored(): void
    {
        $student = $this->student();
        $science = $this->anotherSubject('Stg Science');
        $student->preferredSubjects()->sync([$this->academic['subject']->id, $science->id]);
        $science->update(['status' => AcademicStatus::Archived]);
        $student->profile()->update(['student_academic_level_id' => $this->academic['academicLevel']->id]);

        $this->wizardFor($student)
            ->call('selectMode', 'paid_one_to_one')
            ->assertSet('academicSubjectId', $this->academic['subject']->id)
            ->assertSet('step', 4);
    }

    public function test_preferred_ordering_survives_changing_the_level(): void
    {
        $student = $this->student();
        $science = $this->anotherSubject('Stg Science');
        $student->preferredSubjects()->sync([$this->academic['subject']->id, $science->id]);
        $student->profile()->update(['student_academic_level_id' => $this->academic['academicLevel']->id]);

        $secondLevel = app(EducationSystemService::class)->addLevel($this->academicAdmin(), $this->academic['system'], [
            'academic_level_id' => $this->academic['academicLevel']->id,
            'value' => '11',
            'display_label' => 'Class 11',
            'normalized_grade' => 11,
        ]);

        $this->wizardFor($student)
            ->call('selectMode', 'paid_one_to_one')
            ->assertSet('step', 2)
            ->call('selectLevel', $secondLevel->id)
            ->assertSet('academicSubjectId', null)
            ->assertSet('step', 3)
            ->assertSee('Your subject')
            ->assertSee('You chose 2 subjects in your profile.');
    }

    public function test_the_last_booking_resolves_the_same_way_when_two_were_made_in_the_same_second(): void
    {
        $student = $this->student();
        $science = $this->anotherSubject('Stg Science');
        $createdAt = CarbonImmutable::parse('2026-09-10 12:00:00', 'UTC');

        $contexts = collect([$this->academic['subject'], $science])->map(function (Subject $subject) use ($student, $createdAt): BookingAcademicContext {
            $booking = Booking::factory()->create([
                'student_id' => $student->id,
                'instructor_id' => $this->teacher->id,
                'status' => BookingStatus::Confirmed,
                'starts_at' => $createdAt->addDays(2),
                'ends_at' => $createdAt->addDays(2)->addHour(),
            ]);

            return BookingAcademicContext::create([
                'booking_id' => $booking->id,
                'country_id' => $this->country->id,
                'education_system_id' => $this->academic['system']->id,
                'education_system_level_id' => $this->academic['level']->id,
                'academic_level_id' => $this->academic['academicLevel']->id,
                'subject_id' => $subject->id,
                'subject_name' => $subject->name,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        });

        $expected = $contexts->sortByDesc('id')->first();

        foreach (range(1, 3) as $attempt) {
            $this->assertSame($expected->id, app(BookingRepository::class)->latestAcademicContextForStudent($student->id)?->id, "attempt {$attempt}");
        }
    }
}
