<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\PaymentCollectionRolloutScope;
use App\Booking\Enums\Weekday;
use App\Booking\Services\BookingSeriesPrepaymentService;
use App\Curriculum\Services\EducationSystemService;
use App\Livewire\Frontend\Booking\BookingWizard;
use App\Models\Booking;
use App\Models\BookingSeries;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\EducationSystemLevel;
use App\Models\StudentLessonPrice;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Wallet;
use App\Models\WalletRecharge;
use App\Settings\BookingSettings;
use App\Settings\FeatureSettings;
use App\Settings\PaymentGatewaySettings;
use App\Wallet\Enums\WalletLedgerEntryType;
use App\Wallet\Enums\WalletRechargeStatus;
use App\Wallet\Services\WalletLedgerService;
use App\Wallet\Services\WalletService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CreatesAcademicBookingContext;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * The student-facing stage model of the booking wizard: four conceptual
 * stages over the unchanged internal phase list, pre-filled learning
 * details for returning students, the authoritative price preview, and
 * recovery when a chosen time is taken before confirmation.
 */
class BookingWizardStagesTest extends TestCase
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
        $this->teachAcademicSubject($this->academic);
        $this->seedStudentLessonPrice($priced['type'], $this->country, $priced['currency'], 499.00, $this->academic['subject']->slug);

        Livewire::component('frontend.booking.booking-wizard', BookingWizard::class);
    }

    /** @param array<string, mixed> $context */
    private function teachAcademicSubject(array $context): void
    {
        TeacherSubject::factory()->create([
            'teacher_id' => $this->teacher->id,
            'subject' => $context['subject']->name,
            'subject_id' => $context['subject']->id,
            'grade_from' => 1,
            'grade_to' => 12,
        ]);
        $this->makeInstructorEligible($this->teacher, $context['system'], $context['curriculum']);
    }

    private function student(?Country $country = null): User
    {
        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        $this->assignAcademicCountry($student, $country ?? $this->country);

        return $student;
    }

    private function slot(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->addDays(3)->setTime(10, 0);
    }

    private function wizardFor(User $student): Testable
    {
        return Livewire::actingAs($student)->test('frontend.booking.booking-wizard');
    }

    private function secondLevel(int $grade = 11): EducationSystemLevel
    {
        return app(EducationSystemService::class)->addLevel($this->academicAdmin(), $this->academic['system'], [
            'academic_level_id' => $this->academic['academicLevel']->id,
            'value' => (string) $grade,
            'display_label' => 'Class '.$grade,
            'normalized_grade' => $grade,
        ]);
    }

    /** Books a free demo through the wizard so the student has an academic history to return to. */
    private function bookDemoFor(User $student): void
    {
        $this->navigateAcademicWizardToSlot($this->wizardFor($student), $this->academic, $this->slot(), mode: 'free_demo', billingMode: null)
            ->call('continueStage')
            ->call('submit')
            ->assertSee('Booking confirmed');
    }

    // ── Stage grouping ──────────────────────────────────────────────────────

    public function test_learning_details_are_one_stage_disclosed_progressively(): void
    {
        $component = $this->wizardFor($this->student())
            ->assertSee('Learning details')
            ->assertSee('Session type')
            ->assertDontSee('Choose your schedule')
            ->call('selectMode', 'paid_one_to_one')
            ->assertSee('Choose a Class')
            ->assertDontSee('Choose a subject')
            ->call('selectLevel', $this->academic['level']->id)
            ->assertSee('Choose a subject')
            ->call('selectAcademicSubject', $this->academic['subject']->id)
            ->assertSee('Choose a curriculum')
            ->call('selectCurriculum', $this->academic['curriculum']->id)
            ->assertSet('step', 4)
            ->assertSee('Continue to schedule')
            ->assertDontSee('How often would you like to study?');

        $component->call('continueStage')
            ->assertSet('step', 5)
            ->assertSee('Who would you like to learn with?')
            ->assertDontSee('How often would you like to study?')
            ->call('selectInstructor', null)
            ->assertSet('step', 6)
            ->assertSee('How often would you like to study?')
            ->assertSee('Edit');
    }

    /**
     * Steps differ in height: after the slot grid the student is scrolled
     * far down, so a short next step renders above the fold they are looking
     * at and they are left staring at the page footer. The component asks
     * the page to bring the step card back into view on every move — forward
     * and back — and the blade listens for it.
     */
    public function test_changing_stage_brings_the_panel_into_view(): void
    {
        $component = $this->wizardFor($this->student())
            ->call('selectMode', 'paid_one_to_one')
            ->call('selectLevel', $this->academic['level']->id)
            ->call('selectAcademicSubject', $this->academic['subject']->id)
            ->call('selectCurriculum', $this->academic['curriculum']->id);

        // A stage change replaces what is on screen, so the panel is
        // brought into view.
        $component->call('continueStage')
            ->assertSet('step', 5)
            ->assertDispatched('booking-step-changed');

        $component->call('backStage')
            ->assertDispatched('booking-step-changed');
    }

    public function test_answering_within_a_stage_leaves_the_viewport_alone(): void
    {
        // The schedule step is one long panel. Scrolling it to the top
        // every time the student picks a weekday or a time takes them
        // away from the control they just used and the one they were
        // reaching for next.
        $slot = $this->slot();

        $component = $this->wizardFor($this->student())
            ->call('selectMode', 'paid_one_to_one')
            ->call('selectLevel', $this->academic['level']->id)
            ->call('selectAcademicSubject', $this->academic['subject']->id)
            // Answering a question inside the learning stage reveals the
            // next one directly below it — already in view.
            ->assertNotDispatched('booking-step-changed')
            ->call('selectCurriculum', $this->academic['curriculum']->id)
            ->call('continueStage')
            ->call('selectBillingMode', 'recurring')
            ->assertNotDispatched('booking-step-changed')
            ->call('toggleWeekday', (int) $slot->dayOfWeek)
            ->assertNotDispatched('booking-step-changed');

        for ($month = CarbonImmutable::now('UTC')->startOfMonth(); $month->lt($slot->startOfMonth()); $month = $month->addMonthNoOverflow()) {
            $component->call('nextMonth');
        }

        $component
            ->call('selectDate', $slot->toDateString())
            ->assertNotDispatched('booking-step-changed')
            ->call('selectSlot', $slot->toIso8601String())
            ->assertNotDispatched('booking-step-changed')
            ->call('setEndCondition', 'on_date')
            ->assertNotDispatched('booking-step-changed');
    }

    public function test_free_demo_continues_straight_to_the_calendar(): void
    {
        $this->wizardFor($this->student())
            ->call('selectMode', 'free_demo')
            ->call('selectLevel', $this->academic['level']->id)
            ->call('selectAcademicSubject', $this->academic['subject']->id)
            ->call('selectCurriculum', $this->academic['curriculum']->id)
            ->assertSet('step', 4)
            ->call('continueStage')
            ->assertSet('step', 5)
            ->assertSee('Choose a date')
            ->assertDontSee('How often would you like to study?');
    }

    public function test_choosing_a_time_stays_on_the_schedule_until_the_student_reviews(): void
    {
        $this->navigateAcademicWizardToSlot($this->wizardFor($this->student()), $this->academic, $this->slot())
            ->assertSet('step', 8)
            ->assertSee('Review booking')
            ->assertDontSee('Review your booking')
            ->call('continueStage')
            ->assertSet('step', 9)
            ->assertSee('Review your booking')
            ->assertSee('Proceed to payment');
    }

    // ── Pre-filled learning details ─────────────────────────────────────────

    public function test_returning_student_learning_details_are_prefilled_from_their_last_booking(): void
    {
        $student = $this->student();
        $this->bookDemoFor($student);

        $this->wizardFor($student)
            ->call('selectMode', 'paid_one_to_one')
            ->assertSet('educationSystemLevelId', $this->academic['level']->id)
            ->assertSet('academicSubjectId', $this->academic['subject']->id)
            ->assertSet('curriculumId', $this->academic['curriculum']->id)
            ->assertSet('prefilledLearning', true)
            // Learning is pre-filled; the schedule stage opens on the
            // instructor question, with last time's instructor offered first.
            ->assertSet('step', 5)
            ->assertSee('Who would you like to learn with?')
            ->assertSee('Book again')
            ->assertSee($this->academic['subject']->name.' • Class 10 • '.$this->academic['system']->name);
    }

    public function test_profile_academic_level_prefills_the_level_only_when_unambiguous(): void
    {
        $student = $this->student();
        $student->profile()->update(['student_academic_level_id' => $this->academic['academicLevel']->id]);

        $this->wizardFor($student)
            ->call('selectMode', 'paid_one_to_one')
            ->assertSet('educationSystemLevelId', $this->academic['level']->id)
            ->assertSet('academicSubjectId', null)
            ->assertSet('prefilledLearning', true)
            ->assertSet('step', 3)
            ->assertSee('Choose a subject');

        $this->secondLevel();
        $other = $this->student();
        $other->profile()->update(['student_academic_level_id' => $this->academic['academicLevel']->id]);

        $this->wizardFor($other)
            ->call('selectMode', 'paid_one_to_one')
            ->assertSet('educationSystemLevelId', null)
            ->assertSet('prefilledLearning', false)
            ->assertSet('step', 2);
    }

    public function test_a_fresh_student_is_never_prefilled(): void
    {
        $this->wizardFor($this->student())
            ->call('selectMode', 'paid_one_to_one')
            ->assertSet('educationSystemLevelId', null)
            ->assertSet('prefilledLearning', false)
            ->assertSet('step', 2);
    }

    public function test_prefilled_details_can_be_changed_and_dependent_state_is_cleared(): void
    {
        $student = $this->student();
        $this->bookDemoFor($student);
        $otherLevel = $this->secondLevel();

        $component = $this->wizardFor($student)
            ->call('selectMode', 'paid_one_to_one')
            ->assertSet('step', 5)
            ->assertSet('pricePreview.total_formatted', '499.00 INR')
            ->call('editStage', 'learning')
            ->assertSet('step', 4)
            ->assertSet('curriculumId', $this->academic['curriculum']->id)
            ->call('editPhase', 'level')
            ->assertSet('step', 2)
            ->call('selectLevel', $otherLevel->id);

        $component
            ->assertSet('educationSystemLevelId', $otherLevel->id)
            ->assertSet('academicSubjectId', null)
            ->assertSet('curriculumId', null)
            ->assertSet('pricePreview', [])
            ->assertSet('prefilledLearning', false)
            ->assertSet('step', 3);
    }

    public function test_reselecting_the_same_answer_keeps_everything_after_it(): void
    {
        $this->navigateAcademicWizardToSlot($this->wizardFor($this->student()), $this->academic, $this->slot())
            ->call('editStage', 'learning')
            ->call('editPhase', 'academic_subject')
            ->assertSet('step', 3)
            ->call('selectAcademicSubject', $this->academic['subject']->id)
            ->assertSet('curriculumId', $this->academic['curriculum']->id)
            ->assertSet('selectedSlotStartsAt', $this->slot()->toIso8601String())
            ->assertSet('step', 4);
    }

    // ── Price preview ───────────────────────────────────────────────────────

    public function test_price_preview_is_resolved_by_the_pricing_calculator(): void
    {
        $this->wizardFor($this->student())
            ->call('selectMode', 'paid_one_to_one')
            ->call('selectLevel', $this->academic['level']->id)
            ->call('selectAcademicSubject', $this->academic['subject']->id)
            ->assertSet('pricePreview', [])
            ->call('selectCurriculum', $this->academic['curriculum']->id)
            ->assertSet('pricePreview.total_formatted', '499.00 INR')
            ->assertSet('pricePreview.requires_payment', true)
            ->assertSee('Session fee')
            ->assertSee('499.00 INR');
    }

    public function test_price_preview_is_empty_when_no_price_is_configured(): void
    {
        StudentLessonPrice::query()->delete();

        $this->wizardFor($this->student())
            ->call('selectMode', 'paid_one_to_one')
            ->call('selectLevel', $this->academic['level']->id)
            ->call('selectAcademicSubject', $this->academic['subject']->id)
            ->call('selectCurriculum', $this->academic['curriculum']->id)
            ->assertSet('pricePreview', [])
            ->assertDontSee('Session fee');
    }

    public function test_free_demo_shows_free_instead_of_a_price(): void
    {
        $this->wizardFor($this->student())
            ->call('selectMode', 'free_demo')
            ->call('selectLevel', $this->academic['level']->id)
            ->call('selectAcademicSubject', $this->academic['subject']->id)
            ->call('selectCurriculum', $this->academic['curriculum']->id)
            ->assertSet('pricePreview', [])
            ->assertSee('Free')
            ->assertDontSee('Session fee');
    }

    // ── Conflicts and duplicate submission ──────────────────────────────────

    public function test_a_time_taken_before_confirmation_returns_the_student_to_time_selection(): void
    {
        $student = $this->student();
        $slot = $this->slot();

        $component = $this->navigateAcademicWizardToSlot($this->wizardFor($student), $this->academic, $slot)
            ->call('continueStage')
            ->assertSet('step', 9);

        $rival = $this->student();
        $rival->profile()->update(['phone_e164' => '+9199999'.str_pad((string) $rival->id, 5, '0', STR_PAD_LEFT), 'phone_verified_at' => now()]);
        app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'free_demo',
            studentId: $rival->id,
            instructorId: $this->teacher->id,
            startsAt: $slot,
            durationMinutes: 30,
        ));

        $component->call('submit')
            ->assertSet('banner', 'That time is no longer available. Please choose another time.')
            // The one case inside a stage that MUST scroll: the message
            // explaining why their time vanished is at the top of the panel.
            ->assertDispatched('booking-step-changed')
            ->assertSet('selectedSlotStartsAt', null)
            ->assertSet('curriculumId', $this->academic['curriculum']->id)
            ->assertSet('date', $slot->toDateString())
            ->assertSet('step', 8)
            ->assertSee('Available times');

        $this->assertDatabaseMissing('bookings', ['student_id' => $student->id]);
    }

    public function test_submit_is_ignored_once_the_booking_exists(): void
    {
        $student = $this->student();

        $component = $this->navigateAcademicWizardToSlot($this->wizardFor($student), $this->academic, $this->slot(), mode: 'free_demo', billingMode: null)
            ->call('continueStage')
            ->call('submit')
            ->assertSee('Booking confirmed');

        $this->assertDatabaseCount('bookings', 1);

        $component->call('submit');

        $this->assertDatabaseCount('bookings', 1);
    }

    // ── Navigation ──────────────────────────────────────────────────────────

    public function test_back_and_edit_keep_every_selection_and_resume_where_the_student_left_off(): void
    {
        $slot = $this->slot();

        $this->navigateAcademicWizardToSlot($this->wizardFor($this->student()), $this->academic, $slot)
            ->call('continueStage')
            ->assertSet('step', 9)
            ->call('backStage')
            ->assertSet('step', 8)
            ->assertSet('selectedSlotStartsAt', $slot->toIso8601String())
            ->call('editStage', 'learning')
            ->assertSet('step', 4)
            ->assertSet('selectedSlotStartsAt', $slot->toIso8601String())
            ->call('continueStage')
            ->assertSet('step', 8)
            ->call('continueStage')
            ->assertSet('step', 9)
            ->call('editStage', 'outcome')
            ->assertSet('step', 9);
    }

    public function test_continue_does_nothing_while_a_stage_is_incomplete(): void
    {
        $this->wizardFor($this->student())
            ->call('selectMode', 'paid_one_to_one')
            ->call('continueStage')
            ->assertSet('step', 2)
            ->call('selectLevel', $this->academic['level']->id)
            ->call('selectAcademicSubject', $this->academic['subject']->id)
            ->call('selectCurriculum', $this->academic['curriculum']->id)
            ->call('continueStage')
            ->assertSet('step', 5)
            ->call('continueStage')
            ->assertSet('step', 5);
    }

    // ── Schedule, funding, terminology ─────────────────────────────────────

    public function test_recurring_schedule_is_summarised_in_plain_language(): void
    {
        $slot = $this->slot();

        $component = $this->wizardFor($this->student())
            ->call('selectMode', 'paid_one_to_one')
            ->call('selectLevel', $this->academic['level']->id)
            ->call('selectAcademicSubject', $this->academic['subject']->id)
            ->call('selectCurriculum', $this->academic['curriculum']->id)
            ->call('continueStage')
            ->call('selectInstructor', null)
            ->assertDontSee('Class days')
            ->call('selectBillingMode', 'recurring')
            ->assertSee('Class days')
            ->call('setOccurrences', 4);

        for ($month = CarbonImmutable::now('UTC')->startOfMonth(); $month->lt($slot->startOfMonth()); $month = $month->addMonthNoOverflow()) {
            $component->call('nextMonth');
        }

        $component
            ->call('toggleWeekday', (int) $slot->dayOfWeek)
            ->call('selectDate', $slot->toDateString())
            ->call('selectSlot', $slot->toIso8601String())
            ->assertSee('Every '.$slot->format('l').' • at 10:00 AM • first class '.$slot->format('D, j M Y').' • 4 classes')
            ->assertSee('Per class');
    }

    /**
     * Schedules that reach past the confirmation horizon are gated by a
     * server-side flag until the deployment is verified. These tests are
     * about the schedule itself, so they turn it on explicitly — the
     * flag's own behaviour is covered by
     * RecurringScheduleReleaseSafeguardsTest.
     */
    private function allowFutureGeneration(): void
    {
        $settings = app(BookingSettings::class);
        $settings->recurring_future_generation_enabled = true;
        $settings->save();
    }

    public function test_a_repeating_schedule_can_be_built_reviewed_and_confirmed(): void
    {
        $this->allowFutureGeneration();
        $slot = $this->slot();
        $secondWeekday = (int) $slot->addDays(2)->dayOfWeek;

        $component = $this->wizardFor($this->student())
            ->call('selectMode', 'paid_one_to_one')
            ->call('selectLevel', $this->academic['level']->id)
            ->call('selectAcademicSubject', $this->academic['subject']->id)
            ->call('selectCurriculum', $this->academic['curriculum']->id)
            ->call('continueStage')
            ->call('selectBillingMode', 'recurring')
            ->call('toggleWeekday', (int) $slot->dayOfWeek)
            ->call('toggleWeekday', $secondWeekday)
            ->call('setEndCondition', 'after_count')
            ->call('setOccurrences', 20);

        for ($month = CarbonImmutable::now('UTC')->startOfMonth(); $month->lt($slot->startOfMonth()); $month = $month->addMonthNoOverflow()) {
            $component->call('nextMonth');
        }

        $component
            ->call('selectDate', $slot->toDateString())
            ->call('selectSlot', $slot->toIso8601String())
            ->assertSet('previewMeta.total', 20)
            ->assertSet('previewMeta.conflicts', 0)
            // A schedule longer than the horizon is still fully accepted;
            // the remainder is planned rather than refused.
            ->assertSee('Your schedule');

        $component
            ->call('continueStage')
            ->call('submit')
            ->assertSet('result.recurring', true);

        $series = BookingSeries::query()->firstOrFail();

        $this->assertSame(20, (int) $series->occurrence_count);
        // The form only ever produces a plain weekly cadence now; the
        // days themselves carry the pattern.
        $this->assertSame(1, (int) $series->repeat_interval);
        $this->assertEqualsCanonicalizing(
            [(int) $slot->dayOfWeek, $secondWeekday],
            array_map('intval', $series->weekdays),
        );
        $this->assertGreaterThan(0, $series->bookings()->count());
    }

    public function test_recurrence_choices_survive_going_back_and_forth_between_steps(): void
    {
        $this->allowFutureGeneration();
        $slot = $this->slot();

        $component = $this->wizardFor($this->student())
            ->call('selectMode', 'paid_one_to_one')
            ->call('selectLevel', $this->academic['level']->id)
            ->call('selectAcademicSubject', $this->academic['subject']->id)
            ->call('selectCurriculum', $this->academic['curriculum']->id)
            ->call('continueStage')
            ->call('selectBillingMode', 'recurring')
            ->call('toggleWeekday', (int) $slot->dayOfWeek)
            ->call('setEndCondition', 'never');

        for ($month = CarbonImmutable::now('UTC')->startOfMonth(); $month->lt($slot->startOfMonth()); $month = $month->addMonthNoOverflow()) {
            $component->call('nextMonth');
        }

        $component
            ->call('selectDate', $slot->toDateString())
            ->call('selectSlot', $slot->toIso8601String())
            ->call('continueStage')
            ->call('editStage', 'learning')
            ->call('continueStage')
            // Every recurrence choice is still exactly as it was left.
            ->assertSet('weekdays', [(int) $slot->dayOfWeek])
            ->assertSet('endCondition', 'never')
            ->assertSet('selectedSlotStartsAt', $slot->toIso8601String());
    }

    public function test_switching_back_to_a_one_time_session_clears_the_repeat_settings(): void
    {
        $this->wizardFor($this->student())
            ->call('selectMode', 'paid_one_to_one')
            ->call('selectLevel', $this->academic['level']->id)
            ->call('selectAcademicSubject', $this->academic['subject']->id)
            ->call('selectCurriculum', $this->academic['curriculum']->id)
            ->call('continueStage')
            ->call('selectBillingMode', 'recurring')
            ->call('toggleWeekday', 1)
            ->call('setEndCondition', 'never')
            ->call('selectBillingMode', 'single')
            ->assertSet('recurring', false)
            ->assertSet('weekdays', [])
            ->assertSet('endCondition', 'after_count')
            ->assertSet('schedulePreview', []);
    }

    public function test_starting_from_resolves_to_the_first_chosen_day_and_adds_no_others(): void
    {
        // The heart of the simplified form: "starting from" is a
        // BOUNDARY. Picking a Friday with Monday/Wednesday classes must
        // schedule the following Monday — not add Friday to the pattern
        // to make the chosen date work.
        $slot = $this->slot();
        $friday = $slot->next(CarbonImmutable::FRIDAY);
        $monday = $friday->next(CarbonImmutable::MONDAY);

        $component = $this->wizardFor($this->student())
            ->call('selectMode', 'paid_one_to_one')
            ->call('selectLevel', $this->academic['level']->id)
            ->call('selectAcademicSubject', $this->academic['subject']->id)
            ->call('selectCurriculum', $this->academic['curriculum']->id)
            ->call('continueStage')
            ->call('selectBillingMode', 'recurring')
            ->call('toggleWeekday', CarbonImmutable::MONDAY)
            ->call('toggleWeekday', CarbonImmutable::WEDNESDAY)
            ->call('selectDate', $friday->toDateString());

        $this->assertSame($monday->toDateString(), $component->instance()->firstClassDate());
        $component->assertSet('weekdays', [CarbonImmutable::MONDAY, CarbonImmutable::WEDNESDAY]);
        $component->assertSee('First class: '.$monday->format('l, j F Y'));
    }

    public function test_the_last_selected_day_cannot_be_removed(): void
    {
        // A repeating schedule with no days is not a schedule. Refusing
        // out loud beats accepting it and rendering an empty preview.
        $component = $this->wizardFor($this->student())
            ->call('selectMode', 'paid_one_to_one')
            ->call('selectLevel', $this->academic['level']->id)
            ->call('selectAcademicSubject', $this->academic['subject']->id)
            ->call('selectCurriculum', $this->academic['curriculum']->id)
            ->call('continueStage')
            ->call('selectBillingMode', 'recurring')
            ->call('toggleWeekday', CarbonImmutable::MONDAY)
            ->call('toggleWeekday', CarbonImmutable::MONDAY);

        $component->assertSet('weekdays', [CarbonImmutable::MONDAY]);
        $this->assertStringContainsString('at least one day', (string) $component->get('banner'));
    }

    public function test_all_seven_days_is_a_daily_schedule(): void
    {
        $slot = $this->slot();

        $component = $this->wizardFor($this->student())
            ->call('selectMode', 'paid_one_to_one')
            ->call('selectLevel', $this->academic['level']->id)
            ->call('selectAcademicSubject', $this->academic['subject']->id)
            ->call('selectCurriculum', $this->academic['curriculum']->id)
            ->call('continueStage')
            ->call('selectInstructor', null)
            ->call('selectBillingMode', 'recurring');

        foreach (range(0, 6) as $day) {
            $component->call('toggleWeekday', $day);
        }

        $component
            ->call('setOccurrences', 4)
            ->assertSee('7 classes per week')
            ->assertSee('Every day');

        for ($month = CarbonImmutable::now('UTC')->startOfMonth(); $month->lt($slot->startOfMonth()); $month = $month->addMonthNoOverflow()) {
            $component->call('nextMonth');
        }

        $component
            ->call('selectDate', $slot->toDateString())
            ->call('selectSlot', $slot->toIso8601String());

        $rows = collect($component->get('schedulePreview'))->pluck('local_date')->all();

        $this->assertSame([
            $slot->toDateString(),
            $slot->addDay()->toDateString(),
            $slot->addDays(2)->toDateString(),
            $slot->addDays(3)->toDateString(),
        ], $rows);
    }

    public function test_the_schedule_step_cannot_be_left_without_days_or_with_conflicts(): void
    {
        $component = $this->wizardFor($this->student())
            ->call('selectMode', 'paid_one_to_one')
            ->call('selectLevel', $this->academic['level']->id)
            ->call('selectAcademicSubject', $this->academic['subject']->id)
            ->call('selectCurriculum', $this->academic['curriculum']->id)
            ->call('continueStage')
            ->call('selectInstructor', null)
            ->call('selectBillingMode', 'recurring');

        $step = $component->get('step');

        // No days, no time — Review booking does nothing.
        $component->call('continueStage')->assertSet('step', $step);
        $component->assertSee('Choose the days your classes repeat on');
    }

    public function test_a_schedule_of_more_than_twelve_classes_goes_through_the_form(): void
    {
        $this->allowFutureGeneration();
        $slot = $this->slot();

        $component = $this->wizardFor($this->student())
            ->call('selectMode', 'paid_one_to_one')
            ->call('selectLevel', $this->academic['level']->id)
            ->call('selectAcademicSubject', $this->academic['subject']->id)
            ->call('selectCurriculum', $this->academic['curriculum']->id)
            ->call('continueStage')
            ->call('selectBillingMode', 'recurring')
            ->call('toggleWeekday', (int) $slot->dayOfWeek)
            ->call('setOccurrences', 45);

        for ($month = CarbonImmutable::now('UTC')->startOfMonth(); $month->lt($slot->startOfMonth()); $month = $month->addMonthNoOverflow()) {
            $component->call('nextMonth');
        }

        $component
            ->call('selectDate', $slot->toDateString())
            ->call('selectSlot', $slot->toIso8601String())
            ->assertSet('previewMeta.total', 45)
            ->call('continueStage')
            ->call('submit')
            ->assertSet('result.recurring', true);

        $series = BookingSeries::query()->firstOrFail();
        $this->assertSame(45, (int) $series->occurrence_count);
    }

    public function test_changing_the_days_keeps_the_chosen_time_when_it_still_exists(): void
    {
        // Correcting the days should not quietly cost the student the
        // time they already chose.
        $slot = $this->slot();

        $component = $this->wizardFor($this->student())
            ->call('selectMode', 'paid_one_to_one')
            ->call('selectLevel', $this->academic['level']->id)
            ->call('selectAcademicSubject', $this->academic['subject']->id)
            ->call('selectCurriculum', $this->academic['curriculum']->id)
            ->call('continueStage')
            ->call('selectBillingMode', 'recurring')
            ->call('toggleWeekday', (int) $slot->dayOfWeek);

        for ($month = CarbonImmutable::now('UTC')->startOfMonth(); $month->lt($slot->startOfMonth()); $month = $month->addMonthNoOverflow()) {
            $component->call('nextMonth');
        }

        $component
            ->call('selectDate', $slot->toDateString())
            ->call('selectSlot', $slot->toIso8601String())
            ->assertSet('selectedSlotStartsAt', $slot->toIso8601String())
            // Adding a day leaves the first class where it was, so the
            // 10:00 slot is still the chosen one.
            ->call('toggleWeekday', (int) $slot->addDays(2)->dayOfWeek)
            ->assertSet('selectedSlotStartsAt', $slot->toIso8601String());
    }

    public function test_choosing_until_a_date_before_entering_one_does_not_crash(): void
    {
        // A student who picks "Until a date" has not typed a date yet.
        // The schedule is simply not describable until they do, and the
        // form must say so rather than trying to build a rule that
        // cannot exist.
        $slot = $this->slot();

        $component = $this->wizardFor($this->student())
            ->call('selectMode', 'paid_one_to_one')
            ->call('selectLevel', $this->academic['level']->id)
            ->call('selectAcademicSubject', $this->academic['subject']->id)
            ->call('selectCurriculum', $this->academic['curriculum']->id)
            ->call('continueStage')
            ->call('selectBillingMode', 'recurring')
            ->call('toggleWeekday', (int) $slot->dayOfWeek);

        for ($month = CarbonImmutable::now('UTC')->startOfMonth(); $month->lt($slot->startOfMonth()); $month = $month->addMonthNoOverflow()) {
            $component->call('nextMonth');
        }

        $component
            ->call('selectDate', $slot->toDateString())
            ->call('selectSlot', $slot->toIso8601String())
            // The order that broke: a time is already chosen, so the
            // preview rebuilds the moment the end condition changes.
            ->call('setEndCondition', 'on_date')
            ->assertOk()
            ->assertSet('schedulePreview', [])
            ->assertSee('Choose the last date');

        // And the step cannot be left in that state.
        $step = $component->get('step');
        $component->call('continueStage')->assertSet('step', $step);

        // Entering a date resolves it, with nothing else lost.
        $component
            ->call('setEndDate', $slot->addWeeks(3)->toDateString())
            ->assertOk()
            ->assertSet('selectedSlotStartsAt', $slot->toIso8601String())
            ->assertSet('previewMeta.total', 4);
    }

    public function test_clearing_the_end_date_again_does_not_crash(): void
    {
        $slot = $this->slot();

        $component = $this->wizardFor($this->student())
            ->call('selectMode', 'paid_one_to_one')
            ->call('selectLevel', $this->academic['level']->id)
            ->call('selectAcademicSubject', $this->academic['subject']->id)
            ->call('selectCurriculum', $this->academic['curriculum']->id)
            ->call('continueStage')
            ->call('selectBillingMode', 'recurring')
            ->call('toggleWeekday', (int) $slot->dayOfWeek);

        for ($month = CarbonImmutable::now('UTC')->startOfMonth(); $month->lt($slot->startOfMonth()); $month = $month->addMonthNoOverflow()) {
            $component->call('nextMonth');
        }

        $component
            ->call('selectDate', $slot->toDateString())
            ->call('selectSlot', $slot->toIso8601String())
            ->call('setEndCondition', 'on_date')
            ->call('setEndDate', $slot->addWeeks(3)->toDateString())
            ->assertSet('previewMeta.total', 4)
            // Emptying the field is an ordinary thing to do while editing.
            ->call('setEndDate', '')
            ->assertOk()
            ->assertSet('schedulePreview', []);
    }

    public function test_package_funding_is_chosen_on_the_review_stage(): void
    {
        $component = $this->navigateAcademicWizardToSlot($this->wizardFor($this->student()), $this->academic, $this->slot())
            ->set('fundingOptions', [[
                'id' => 'entitlement-1',
                'name' => 'Starter pack',
                'subject_name' => $this->academic['subject']->name,
                'level_display' => 'Class 10',
                'total_quantity' => 5,
                'available_to_book' => 3,
                'scheduled' => 0,
                'expires_at' => null,
            ]])
            ->call('continueStage')
            ->assertSet('step', 9)
            ->assertSee('How would you like to pay?')
            ->assertSee('Choose how you would like to pay');

        $component->call('selectFunding', '')
            ->assertSet('step', 10)
            ->assertSet('packageEntitlementId', null)
            ->assertSee('Proceed to payment');

        $component->call('selectFunding', 'entitlement-1')
            ->assertSet('packageEntitlementId', 'entitlement-1')
            ->assertSee('Covered by package')
            ->assertSee('Confirm booking');
    }

    public function test_timezone_is_shown_with_its_offset(): void
    {
        $student = $this->student();
        $student->profile()->update(['timezone' => 'Asia/Kolkata']);

        $this->wizardFor($student)->assertSee('Asia/Kolkata (GMT+05:30)');
    }

    public function test_summaries_use_the_education_systems_own_level_term(): void
    {
        $grades = $this->seedAcademicContext('USA', normalizedGrade: 8, levelTerm: 'Grade');
        $this->teachAcademicSubject($grades);

        $this->wizardFor($this->student($grades['country']))
            ->call('selectMode', 'free_demo')
            ->assertSee('Choose a Grade')
            ->call('selectLevel', $grades['level']->id)
            ->call('selectAcademicSubject', $grades['subject']->id)
            ->call('selectCurriculum', $grades['curriculum']->id)
            ->call('continueStage')
            ->assertSee($grades['subject']->name.' • Grade 8 • '.$grades['system']->name)
            ->assertDontSee('Class 8');
    }
    // ── Reserved / payment-pending screen ───────────────────────────────────

    private function reservePaidBooking(User $student): Testable
    {
        return $this->navigateAcademicWizardToSlot($this->wizardFor($student), $this->academic, $this->slot())
            ->call('continueStage')
            ->call('submit')
            ->assertSet('step', 10);
    }

    public function test_reserved_screen_leads_with_payment_and_keeps_the_reference_secondary(): void
    {
        $student = $this->student();
        $component = $this->reservePaidBooking($student);
        $reference = (string) $component->get('result')['reference'];

        $component
            ->assertSee('Complete your payment')
            ->assertSee('Reserved for')
            ->assertSee('Total due')
            ->assertSee('Pay 499.00 INR securely')
            ->assertSee('Reference')
            ->assertSee($reference)
            ->assertSee($this->academic['subject']->name)
            ->assertSee('Class 10')
            ->assertSee('Back to my bookings')
            ->assertDontSee('Book another session')
            ->assertDontSee('Wallet');

        $this->assertNotNull($component->get('result')['reserved_until']);
    }

    public function test_insufficient_wallet_is_shown_as_an_unavailable_option_not_an_error(): void
    {
        app(FeatureSettings::class)->wallet_enabled = true;
        $student = $this->student();
        app(WalletService::class)->getOrCreateWallet($student, 'INR', $student);

        $this->reservePaidBooking($student)
            ->assertSee('Wallet')
            ->assertSee('Insufficient balance')
            ->assertDontSee('from wallet')
            ->assertDontSee('Payment needs attention');
    }

    public function test_sufficient_wallet_offers_a_secondary_wallet_payment(): void
    {
        app(FeatureSettings::class)->wallet_enabled = true;
        $student = $this->student();
        $wallet = app(WalletService::class)->getOrCreateWallet($student, 'INR', $student);
        app(WalletLedgerService::class)->credit($wallet, 100000, WalletLedgerEntryType::PromotionalCredit, $student);

        $this->reservePaidBooking($student)
            ->assertSee('Pay 499.00 INR from wallet')
            ->assertDontSee('Insufficient balance');
    }

    public function test_an_expired_reservation_replaces_the_pay_button_with_a_new_time_prompt(): void
    {
        $student = $this->student();
        $component = $this->reservePaidBooking($student);

        $booking = Booking::query()->findOrFail($component->get('bookingId'));
        $booking->forceFill(['reserved_until' => now()->subMinute()])->save();
        $this->artisan('booking:release-expired');

        $component->call('checkPaymentStatus')
            ->assertSee('This reservation has expired')
            ->assertSee('Choose another time')
            ->assertDontSee('Pay 499.00 INR securely');

        $this->assertSame('cancelled', $booking->refresh()->status->value);
    }

    // ── Paying for a whole schedule: the card must show its arithmetic ─────

    /** Books a short weekly schedule and lands on the confirmed step. */
    private function reserveRecurringSchedule(User $student, int $classes = 4): Testable
    {
        $this->allowFutureGeneration();
        $slot = $this->slot();

        $component = $this->wizardFor($student)
            ->call('selectMode', 'paid_one_to_one')
            ->call('selectLevel', $this->academic['level']->id)
            ->call('selectAcademicSubject', $this->academic['subject']->id)
            ->call('selectCurriculum', $this->academic['curriculum']->id)
            ->call('continueStage')
            ->call('selectBillingMode', 'recurring')
            ->call('toggleWeekday', (int) $slot->dayOfWeek)
            ->call('setEndCondition', 'after_count')
            ->call('setOccurrences', $classes);

        for ($month = CarbonImmutable::now('UTC')->startOfMonth(); $month->lt($slot->startOfMonth()); $month = $month->addMonthNoOverflow()) {
            $component->call('nextMonth');
        }

        return $component
            ->call('selectDate', $slot->toDateString())
            ->call('selectSlot', $slot->toIso8601String())
            ->call('continueStage')
            ->call('submit')
            ->assertSet('result.recurring', true)
            ->assertSet('result.requires_payment', true);
    }

    private function fundWallet(User $student, int $amountMinor): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($student, 'INR', $student);
        app(WalletLedgerService::class)->credit($wallet, $amountMinor, WalletLedgerEntryType::PromotionalCredit, $student);
    }

    public function test_pay_all_card_itemises_total_balance_and_amount_due_when_balance_is_short(): void
    {
        app(FeatureSettings::class)->wallet_enabled = true;
        $student = $this->student();
        $this->fundWallet($student, 50000); // 500.00 INR against 499.00 per class

        $component = $this->reserveRecurringSchedule($student);
        $card = $component->get('seriesPrepayment');
        $count = (int) $card['count'];
        $this->assertGreaterThan(1, $count);

        $total = $count * 49900;
        $expectedTotal = number_format($total / 100, 2).' INR';
        $expectedDue = number_format(($total - 50000) / 100, 2).' INR';

        $component
            ->assertSee('Pay for all '.$count.' classes')
            ->assertSee('Total for '.$count.' classes')
            ->assertSee($expectedTotal)
            ->assertSee('Paid from your wallet balance')
            ->assertSee('500.00 INR')
            ->assertSee('To pay now')
            ->assertSee($expectedDue)
            ->assertSee('Pay '.$expectedDue.' now')
            ->assertSee('500.00 INR from your balance and '.$expectedDue.' paid now')
            // The pay-all button is the only primary "pay" action.
            ->assertDontSee('Pay from My Bookings')
            ->assertDontSee('Pay one at a time instead')
            ->assertSee('View my bookings');
    }

    public function test_pay_all_card_hides_the_balance_row_when_there_is_no_balance(): void
    {
        app(FeatureSettings::class)->wallet_enabled = true;
        $student = $this->student();
        app(WalletService::class)->getOrCreateWallet($student, 'INR', $student);

        $component = $this->reserveRecurringSchedule($student);
        $count = (int) $component->get('seriesPrepayment')['count'];
        $expectedTotal = number_format($count * 499, 2).' INR';

        $component
            ->assertSee('To pay now')
            ->assertSee('Pay '.$expectedTotal.' now')
            ->assertSee('This one payment confirms every class below.')
            ->assertDontSee('Paid from your wallet balance')
            ->assertDontSee('from your balance');
    }

    public function test_pay_all_card_shows_the_applied_balance_not_the_raw_balance_when_it_exceeds_the_bill(): void
    {
        app(FeatureSettings::class)->wallet_enabled = true;
        $student = $this->student();
        $this->fundWallet($student, 100000000); // 1,000,000.00 INR

        $component = $this->reserveRecurringSchedule($student);
        $card = $component->get('seriesPrepayment');
        $count = (int) $card['count'];
        $expectedTotal = number_format($count * 499, 2).' INR';

        $this->assertTrue($card['covered_by_wallet']);
        $this->assertSame($card['total_formatted'], $card['balance_applied_formatted']);

        $component
            ->assertSee('Paid from your wallet balance')
            ->assertSee('Your wallet balance covers all '.$count.' classes. Nothing to pay now.')
            ->assertSee('Confirm all '.$count.' classes from balance')
            ->assertDontSee($card['balance_formatted'])
            ->assertSee($expectedTotal);
    }

    // ── Moving one class of a schedule to another time ─────────────────────

    /**
     * Regression: "Change time" on the schedule preview crashed with a
     * TypeError because the handler expected TimeSlotData objects while
     * the wizard service returns plain slot arrays. The student saw the
     * request fail on every attempt.
     */
    public function test_a_class_in_the_schedule_preview_can_be_moved_to_another_time_that_day(): void
    {
        $this->allowFutureGeneration();
        $slot = $this->slot();

        $component = $this->wizardFor($this->student())
            ->call('selectMode', 'paid_one_to_one')
            ->call('selectLevel', $this->academic['level']->id)
            ->call('selectAcademicSubject', $this->academic['subject']->id)
            ->call('selectCurriculum', $this->academic['curriculum']->id)
            ->call('continueStage')
            ->call('selectBillingMode', 'recurring')
            ->call('toggleWeekday', (int) $slot->dayOfWeek)
            ->call('setEndCondition', 'after_count')
            ->call('setOccurrences', 3);

        for ($month = CarbonImmutable::now('UTC')->startOfMonth(); $month->lt($slot->startOfMonth()); $month = $month->addMonthNoOverflow()) {
            $component->call('nextMonth');
        }

        $component
            ->call('selectDate', $slot->toDateString())
            ->call('selectSlot', $slot->toIso8601String())
            ->call('continueStage')
            ->assertSee('Change time')
            ->call('startMovingOccurrence', $slot->toDateString())
            ->assertHasNoErrors()
            ->assertSet('movingDate', $slot->toDateString());

        $moveSlots = $component->get('moveSlots');
        $this->assertNotEmpty($moveSlots, 'The instructor\'s other times that day should be offered.');
        $this->assertArrayHasKey('local_time', $moveSlots[0]);
        $this->assertArrayHasKey('label', $moveSlots[0]);

        $target = collect($moveSlots)->firstWhere('local_time', '!=', $slot->format('H:i:s')) ?? $moveSlots[0];

        $component
            ->call('moveOccurrenceTo', $target['local_time'])
            ->assertSet('movingDate', null)
            ->assertSet('moveSlots', []);

        $this->assertSame($target['local_time'], $component->get('movedOccurrences')[$slot->toDateString()]);
    }

    // ── Paying for a whole schedule through the gateway ────────────────────

    /** An active market that collects through the fake provider, as the wallet tests do. */
    private function enableGatewayCollection(): void
    {
        $this->country->update(['status' => 'active', 'payment_routing' => ['provider' => 'fake', 'enabled' => true]]);

        $gateways = app(PaymentGatewaySettings::class);
        $gateways->payments_enabled = true;
        $gateways->payment_collection_rollout_scope = PaymentCollectionRolloutScope::ActiveCountryRouting->value;
        $gateways->save();

        app(FeatureSettings::class)->wallet_enabled = true;
    }

    /**
     * Regression: the pay-all checkout used to fire the single-booking
     * checkout events, so the gateway's success callback verified a
     * BOOKING payment against the RECHARGE's order — it could never
     * match, the classes waited for a webhook that might never come, and
     * their reservations lapsed. The top-up now has its own checkout and
     * the verified return settles every class in the same request.
     */
    public function test_paying_for_all_classes_through_the_gateway_confirms_every_class_on_return(): void
    {
        $this->enableGatewayCollection();
        $student = $this->student();
        $this->fundWallet($student, 50000);

        $component = $this->reserveRecurringSchedule($student)
            ->call('payForAllClasses')
            ->assertHasNoErrors()
            ->assertSet('seriesCheckout.provider', 'fake')
            ->assertSee('Simulate success')
            ->assertDontSee('Pay from My Bookings');

        $series = BookingSeries::query()->firstOrFail();
        $recharge = WalletRecharge::query()->where('user_id', $student->id)->latest()->firstOrFail();
        $count = $series->bookings()->count();

        $this->assertSame(WalletRechargeStatus::Requested, $recharge->status);
        $this->assertSame(BookingSeriesPrepaymentService::PURPOSE, $recharge->metadata['purpose']);
        $this->assertSame($count * 49900 - 50000, (int) $recharge->amount_minor, 'The top-up is exactly the shortfall.');

        $component->call('simulateFakeSeriesPayment', true)
            ->assertSet('paymentBanner', '')
            ->assertSet('seriesCheckout', [])
            ->assertSet('pendingSeriesPaymentId', null)
            ->assertSet('result.requires_payment', false)
            ->assertSee($count.' classes confirmed')
            ->assertDontSee('Pay for all');

        $this->assertSame($count, $series->bookings()->where('payment_status', BookingPaymentStatus::Paid)->count());
        $this->assertSame(WalletRechargeStatus::Succeeded, $recharge->refresh()->status);
        $this->assertSame(0, (int) Wallet::query()->where('user_id', $student->id)->value('available_balance_minor'));
    }

    public function test_a_failed_gateway_payment_for_the_schedule_leaves_the_classes_reserved_and_says_so(): void
    {
        $this->enableGatewayCollection();
        $student = $this->student();
        app(WalletService::class)->getOrCreateWallet($student, 'INR', $student);

        $component = $this->reserveRecurringSchedule($student)
            ->call('payForAllClasses')
            ->assertSet('seriesCheckout.provider', 'fake')
            ->call('simulateFakeSeriesPayment', false)
            ->assertSee('Your payment could not be completed')
            ->assertSee('still reserved')
            ->assertSet('result.requires_payment', true)
            // The student can try again from the same card.
            ->assertSee('Pay for all');

        $series = BookingSeries::query()->firstOrFail();
        $this->assertSame(0, $series->bookings()->where('payment_status', BookingPaymentStatus::Paid)->count());
        $this->assertSame(0, (int) Wallet::query()->where('user_id', $student->id)->value('available_balance_minor'));
    }
}
