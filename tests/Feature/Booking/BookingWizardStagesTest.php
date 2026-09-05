<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\Enums\Weekday;
use App\Curriculum\Services\EducationSystemService;
use App\Livewire\Frontend\Booking\BookingWizard;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\EducationSystemLevel;
use App\Models\StudentLessonPrice;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Settings\FeatureSettings;
use App\Wallet\Enums\WalletLedgerEntryType;
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
            ->assertSee('How often would you like to study?')
            ->assertSee('Edit');
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
            ->assertSet('step', 7)
            ->assertSee('Review booking')
            ->assertDontSee('Review your booking')
            ->call('continueStage')
            ->assertSet('step', 8)
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
            ->assertSet('step', 5)
            ->assertSee('How often would you like to study?')
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
            ->assertSet('step', 8);

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
            ->assertSet('selectedSlotStartsAt', null)
            ->assertSet('curriculumId', $this->academic['curriculum']->id)
            ->assertSet('date', $slot->toDateString())
            ->assertSet('step', 7)
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
            ->assertSet('step', 8)
            ->call('backStage')
            ->assertSet('step', 7)
            ->assertSet('selectedSlotStartsAt', $slot->toIso8601String())
            ->call('editStage', 'learning')
            ->assertSet('step', 4)
            ->assertSet('selectedSlotStartsAt', $slot->toIso8601String())
            ->call('continueStage')
            ->assertSet('step', 7)
            ->call('continueStage')
            ->assertSet('step', 8)
            ->call('editStage', 'outcome')
            ->assertSet('step', 8);
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
            ->assertDontSee('Set the repeat pattern')
            ->call('selectBillingMode', 'recurring')
            ->assertSee('Set the repeat pattern')
            ->call('selectFrequency', 'weekly', 4)
            ->assertSee('Weekly · 4 sessions');

        for ($month = CarbonImmutable::now('UTC')->startOfMonth(); $month->lt($slot->startOfMonth()); $month = $month->addMonthNoOverflow()) {
            $component->call('nextMonth');
        }

        $component
            ->call('selectDate', $slot->toDateString())
            ->call('selectSlot', $slot->toIso8601String())
            ->assertSee('Every '.$slot->format('l').' at 10:00 AM • Starting '.$slot->format('j F').' • 4 sessions')
            ->assertSee('Per session');
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
            ->assertSet('step', 8)
            ->assertSee('How would you like to pay?')
            ->assertSee('Choose how you would like to pay');

        $component->call('selectFunding', '')
            ->assertSet('step', 9)
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
            ->assertSet('step', 9);
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
}
