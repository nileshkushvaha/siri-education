<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Enums\Weekday;
use App\Livewire\Frontend\Booking\BookingWizard;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Country;
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
 * Paid lessons ask who to learn with. The instructors offered are the
 * eligible set, the student's previous instructor for the subject comes
 * first, a choice binds dates, price and the booking, "Any available"
 * keeps auto-assignment (which now prefers the previous instructor),
 * and demos and deep links never see the step.
 */
final class BookingWizardInstructorChoiceTest extends TestCase
{
    use CreatesAcademicBookingContext;
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private Country $country;

    /** @var array<string, mixed> */
    private array $academic;

    private User $teacherA;

    private User $teacherB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootAcademicBookingContext();
        $this->enableDemoLessons();

        $priced = $this->createPaidBookingTypeWithPrice('paid_one_to_one', 499.00, 'INR', durationMinutes: 60);
        BookingType::query()->where('key', 'paid_one_to_one')->update(['sort_order' => 2]);
        BookingType::factory()->create(['key' => 'free_demo', 'name' => 'Free Demo', 'duration_minutes' => 30, 'sort_order' => 1]);
        $this->country = $priced['country'];

        $this->academic = $this->seedAcademicContext('STG', $this->country, normalizedGrade: 10);
        $this->seedStudentLessonPrice($priced['type'], $this->country, $priced['currency'], 499.00, $this->academic['subject']->slug);

        $this->teacherA = $this->teacher('Ada Instructor', '09:00:00', '12:00:00');
        $this->teacherB = $this->teacher('Ben Instructor', '13:00:00', '17:00:00');

        Livewire::component('frontend.booking.booking-wizard', BookingWizard::class);
    }

    private function teacher(string $name, string $from, string $to): User
    {
        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE, 'name' => $name]);
        $teacher->assignRole('instructor');
        UserProfile::updateOrCreate(['user_id' => $teacher->id], ['instructor_status' => 'approved', 'profile_visibility' => 'public']);
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $teacher->id])->forDay($day)->between($from, $to)->create();
        }
        TeacherSubject::factory()->create([
            'teacher_id' => $teacher->id,
            'subject' => $this->academic['subject']->name,
            'subject_id' => $this->academic['subject']->id,
            'grade_from' => 1,
            'grade_to' => 12,
        ]);
        $this->makeInstructorEligible($teacher, $this->academic['system'], $this->academic['curriculum']);

        return $teacher;
    }

    private function student(): User
    {
        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        $this->assignAcademicCountry($student, $this->country);

        return $student;
    }

    private function wizardFor(User $student, array $query = []): Testable
    {
        return Livewire::withQueryParams($query)->actingAs($student)->test('frontend.booking.booking-wizard');
    }

    private function toInstructorStep(Testable $component, string $mode = 'paid_one_to_one'): Testable
    {
        return $component
            ->call('selectMode', $mode)
            ->call('selectEducationSystem', $this->academic['system']->id)
            ->call('selectLevel', $this->academic['level']->id)
            ->call('selectAcademicSubject', $this->academic['subject']->id)
            ->call('selectCurriculum', $this->academic['curriculum']->id)
            ->call('continueStage');
    }

    private function pastLessonWith(User $student, User $teacher, int $daysAgo = 7): Booking
    {
        return Booking::factory()->create([
            'student_id' => $student->id,
            'instructor_id' => $teacher->id,
            'status' => 'completed',
            'starts_at' => CarbonImmutable::now('UTC')->subDays($daysAgo)->setTime(10, 0),
            'ends_at' => CarbonImmutable::now('UTC')->subDays($daysAgo)->setTime(11, 0),
            'meta' => ['subject' => $this->academic['subject']->name, 'grade' => 10],
        ]);
    }

    public function test_a_paid_lesson_asks_who_to_learn_with_after_the_learning_stage(): void
    {
        $this->toInstructorStep($this->wizardFor($this->student()))
            ->assertSee('Who would you like to learn with?')
            ->assertSee('Ada Instructor')
            ->assertSee('Ben Instructor')
            ->assertSee('Any available instructor')
            ->assertSet('instructorChosen', false)
            ->assertDontSee('How often would you like to study?');
    }

    public function test_a_free_demo_never_asks(): void
    {
        $this->toInstructorStep($this->wizardFor($this->student()), 'free_demo')
            ->assertDontSee('Who would you like to learn with?')
            ->assertSee('Choose a date');
    }

    public function test_a_profile_deep_link_locks_the_instructor_and_skips_the_question(): void
    {
        $this->toInstructorStep($this->wizardFor($this->student(), ['instructor' => $this->teacherA->slug]))
            ->assertSet('lockedInstructorId', $this->teacherA->id)
            ->assertDontSee('Who would you like to learn with?')
            ->assertSee('How often would you like to study?')
            ->assertSee('With Ada Instructor');
    }

    public function test_the_previous_instructor_for_the_subject_is_offered_first(): void
    {
        $student = $this->student();
        $this->pastLessonWith($student, $this->teacherB);

        $component = $this->toInstructorStep($this->wizardFor($student));
        $options = $component->get('instructorOptions');

        $this->assertSame([$this->teacherB->id], array_column($options['previous'], 'id'));
        $this->assertSame([$this->teacherA->id], array_column($options['others'], 'id'));
        $component->assertSeeInOrder(['Book again', 'Ben Instructor', 'More instructors', 'Ada Instructor'])->assertSee('Last time');
    }

    public function test_choosing_an_instructor_binds_dates_price_and_the_booking_to_them(): void
    {
        $student = $this->student();

        $component = $this->toInstructorStep($this->wizardFor($student))
            ->call('selectInstructor', $this->teacherB->id)
            ->assertSet('instructorId', $this->teacherB->id)
            ->assertSet('instructorChosen', true)
            ->assertSee('With Ben Instructor')
            ->call('selectBillingMode', 'single');

        $slot = CarbonImmutable::now('UTC')->addDays(3)->setTime(14, 0);
        for ($month = CarbonImmutable::now('UTC')->startOfMonth(); $month->lt($slot->startOfMonth()); $month = $month->addMonthNoOverflow()) {
            $component->call('nextMonth');
        }

        $component->call('selectDate', $slot->toDateString());
        $starts = array_column($component->get('availableSlots'), 'starts_at');
        $this->assertNotEmpty($starts);
        foreach ($starts as $iso) {
            $hour = (int) CarbonImmutable::parse($iso)->utc()->format('G');
            $this->assertGreaterThanOrEqual(13, $hour, 'only Ben\'s afternoon hours are offered');
        }

        $component->call('selectSlot', $slot->toIso8601String())
            ->call('continueStage')
            ->call('continueStage')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame($this->teacherB->id, Booking::query()->where('student_id', $student->id)->sole()->instructor_id);
    }

    public function test_any_available_keeps_auto_assignment_but_prefers_the_previous_instructor(): void
    {
        $student = $this->student();
        $this->pastLessonWith($student, $this->teacherB);
        // Ben teaches afternoons; ask for a morning slot both could serve
        // by widening Ben's day, so continuity — not availability — decides.
        TeacherAvailability::query()->where('teacher_id', $this->teacherB->id)->delete();
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $this->teacherB->id])->forDay($day)->between('09:00:00', '17:00:00')->create();
        }

        $slot = CarbonImmutable::now('UTC')->addDays(3)->setTime(10, 0);
        $this->navigateAcademicWizardToSlot($this->wizardFor($student), $this->academic, $slot)
            ->assertSet('instructorId', null)
            ->assertSet('instructorChosen', true)
            ->assertSee('Any available instructor')
            ->call('continueStage')
            ->call('submit')
            ->assertHasNoErrors();

        $booking = Booking::query()->where('student_id', $student->id)->latest('created_at')->firstOrFail();
        $this->assertSame($this->teacherB->id, $booking->instructor_id, 'Ada has the lower id and would win a tie; continuity picks Ben');
    }

    public function test_a_tampered_instructor_id_is_ignored(): void
    {
        $this->toInstructorStep($this->wizardFor($this->student()))
            ->call('selectInstructor', 999999)
            ->assertSet('instructorId', null)
            ->assertSet('instructorChosen', false);
    }

    public function test_re_answering_the_learning_stage_resets_the_choice_but_the_same_answers_keep_it(): void
    {
        $component = $this->toInstructorStep($this->wizardFor($this->student()))
            ->call('selectInstructor', $this->teacherA->id)
            ->assertSet('instructorChosen', true)
            ->call('editStage', 'learning')
            // The same curriculum again is not a change: the choice survives.
            ->call('selectCurriculum', $this->academic['curriculum']->id)
            ->assertSet('instructorId', $this->teacherA->id)
            ->assertSet('instructorChosen', true)
            // Re-entering the education system is a change: everything after it clears.
            ->call('selectEducationSystem', $this->academic['system']->id)
            ->assertSet('instructorId', null)
            ->assertSet('instructorChosen', false)
            ->assertSet('instructorOptions', []);
    }

    public function test_previous_instructor_ids_are_subject_aware_and_most_recent_first(): void
    {
        $student = $this->student();
        $this->pastLessonWith($student, $this->teacherA, daysAgo: 20);
        $this->pastLessonWith($student, $this->teacherB, daysAgo: 5);
        Booking::factory()->create([
            'student_id' => $student->id,
            'instructor_id' => $this->teacherA->id,
            'status' => 'completed',
            'starts_at' => CarbonImmutable::now('UTC')->subDay()->setTime(10, 0),
            'ends_at' => CarbonImmutable::now('UTC')->subDay()->setTime(11, 0),
            'meta' => ['subject' => 'Something Else', 'grade' => 10],
        ]);

        $repository = app(BookingRepositoryInterface::class);

        $this->assertSame([$this->teacherA->id, $this->teacherB->id], $repository->previousInstructorIdsForStudent($student->id)->all(), 'all subjects: Ada taught yesterday');
        $this->assertSame([$this->teacherB->id, $this->teacherA->id], $repository->previousInstructorIdsForStudent($student->id, $this->academic['subject']->name)->all(), 'this subject: Ben is most recent');
    }
}
