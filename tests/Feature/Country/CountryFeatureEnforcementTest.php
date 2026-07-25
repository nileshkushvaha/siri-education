<?php

declare(strict_types=1);

namespace Tests\Feature\Country;

use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\FreeDemoUnavailableException;
use App\Booking\Services\RecordingAvailabilityResolver;
use App\Enums\InstructorStatus;
use App\Enums\LearningGoalStatus;
use App\Enums\LearningPlanStatus;
use App\Enums\StudentStatus;
use App\Homework\Contracts\HomeworkServiceInterface;
use App\Homework\Exceptions\HomeworkException;
use App\Models\AcademicCategory;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\StudentLearningGoal;
use App\Models\StudentLearningPlan;
use App\Models\Subject;
use App\Models\TeacherAvailability;
use App\Models\User;
use App\Models\UserProfile;
use App\PromotionalCredits\Exceptions\PromotionalCreditException;
use App\PromotionalCredits\Services\PromotionalCreditService;
use App\Referral\Contracts\ReferralAttributionServiceInterface;
use App\Referral\Contracts\ReferralCodeServiceInterface;
use App\Settings\FeatureSettings;
use App\Waitlist\Exceptions\WaitlistException;
use App\Waitlist\Services\WaitlistService;
use App\Wallet\Contracts\WalletRechargeServiceInterface;
use App\Wallet\Exceptions\WalletException;
use App\Wallet\Services\WalletService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 34 (GAP-029) — cross-domain enforcement of the country-feature
 * gate at each real service boundary. Every test here calls the
 * domain service directly (never through Livewire/Filament), which is
 * exactly what a crafted/direct request bypassing a disabled UI
 * control would do — proving requirement #4 ("direct requests must
 * also be rejected").
 */
class CountryFeatureEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);

        // All global switches on by default so every rejection observed
        // below is attributable to the country override, not a global one.
        $features = app(FeatureSettings::class);
        $features->demo_lessons_enabled = true;
        $features->wallet_enabled = true;
        $features->referral_enabled = true;
        $features->waitlist_enabled = true;
        $features->homework_enabled = true;
        $features->recording_enabled = true;
        $features->promotional_credit_enabled = true;
        $features->save();
    }

    // ── Fixtures ──────────────────────────────────────────────────────

    private function countryDisabling(array $flags): Country
    {
        return Country::factory()->create(['feature_flags' => $flags]);
    }

    private function studentIn(?Country $country): User
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        UserProfile::updateOrCreate(['user_id' => $student->id], [
            'student_status' => StudentStatus::Active,
            'country_id' => $country?->id,
        ]);

        return $student->fresh();
    }

    private function instructorIn(?Country $country): User
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        UserProfile::updateOrCreate(['user_id' => $instructor->id], [
            'instructor_status' => InstructorStatus::Active,
            'country_id' => $country?->id,
        ]);

        return $instructor->fresh();
    }

    private function givesAvailability(User $instructor): void
    {
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()
                ->state(['teacher_id' => $instructor->id])
                ->forDay($day)
                ->between('09:00:00', '17:00:00')
                ->create();
        }
    }

    // ── 1. Demo lessons ───────────────────────────────────────────────

    public function test_country_disabling_demo_lessons_rejects_new_free_demo_booking(): void
    {
        BookingType::factory()->create(['key' => 'free_demo', 'duration_minutes' => 30]);
        $country = $this->countryDisabling(['demo_lessons' => false]);
        $student = $this->studentIn($country);
        $instructor = $this->instructorIn(null);
        $this->givesAvailability($instructor);

        $this->expectException(FreeDemoUnavailableException::class);

        app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'free_demo',
            studentId: $student->id,
            instructorId: $instructor->id,
            startsAt: CarbonImmutable::now('UTC')->addDays(3)->setTime(10, 0),
            durationMinutes: 30,
        ));
    }

    public function test_country_enabling_demo_lessons_cannot_bypass_a_global_disable(): void
    {
        BookingType::factory()->create(['key' => 'free_demo', 'duration_minutes' => 30]);
        app(FeatureSettings::class)->demo_lessons_enabled = false;
        app(FeatureSettings::class)->save();

        $country = $this->countryDisabling(['demo_lessons' => true]);
        $student = $this->studentIn($country);
        $instructor = $this->instructorIn(null);
        $this->givesAvailability($instructor);

        $this->expectException(FreeDemoUnavailableException::class);

        app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'free_demo',
            studentId: $student->id,
            instructorId: $instructor->id,
            startsAt: CarbonImmutable::now('UTC')->addDays(3)->setTime(10, 0),
            durationMinutes: 30,
        ));
    }

    // ── 2. Paid bookings ──────────────────────────────────────────────

    public function test_country_disabling_paid_bookings_rejects_new_paid_booking(): void
    {
        BookingType::factory()->paid()->create(['key' => 'paid_one_to_one', 'duration_minutes' => 60]);
        $country = $this->countryDisabling(['paid_bookings' => false]);
        $student = $this->studentIn($country);
        $instructor = $this->instructorIn(null);
        $this->givesAvailability($instructor);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('not currently available for your country');

        app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $student->id,
            instructorId: $instructor->id,
            startsAt: CarbonImmutable::now('UTC')->addDays(3)->setTime(10, 0),
            durationMinutes: 60,
        ));
    }

    public function test_free_demo_is_unaffected_while_paid_bookings_are_disabled_for_the_country(): void
    {
        BookingType::factory()->create(['key' => 'free_demo', 'duration_minutes' => 30]);
        $country = $this->countryDisabling(['paid_bookings' => false]);
        $student = $this->studentIn($country);
        $instructor = $this->instructorIn(null);
        $this->givesAvailability($instructor);

        // No exception expected: reaching a later, unrelated failure
        // (e.g. availability) would still prove the demo type's own
        // rule never rejected — but the simplest proof is that a
        // FreeDemoUnavailableException specifically is never thrown.
        try {
            app(BookingServiceInterface::class)->request(new CreateBookingData(
                typeKey: 'free_demo',
                studentId: $student->id,
                instructorId: $instructor->id,
                startsAt: CarbonImmutable::now('UTC')->addDays(3)->setTime(10, 0),
                durationMinutes: 30,
            ));
            $this->addToAssertionCount(1);
        } catch (FreeDemoUnavailableException $e) {
            $this->fail('Paid-bookings country disable must never affect free demo bookings: '.$e->getMessage());
        } catch (BookingException) {
            $this->addToAssertionCount(1);
        }
    }

    // ── 3. Wallet: enforced through its dependents, wallet creation itself untouched ──

    /**
     * Wallet has no bespoke creation-time gate of its own:
     * `WalletService::getOrCreateWallet()` is called from many existing
     * obligation-settlement paths (refunds, reward retries, admin
     * corrections) that have nothing to do with a country policy
     * decision, and gating it there produced false-positive rejections
     * on those paths during regression testing. Wallet's country
     * enforcement instead happens at its dependents' boundaries
     * (WalletRecharge, PromotionalCredits) and at the composed wallet
     * lesson-payment boundary — all covered by their own tests below.
     */
    public function test_country_disabling_wallet_does_not_block_plain_wallet_creation(): void
    {
        $country = $this->countryDisabling(['wallet' => false]);
        $student = $this->studentIn($country);

        $wallet = app(WalletService::class)->getOrCreateWallet($student, 'INR', $student);

        $this->assertNotNull($wallet->id);
    }

    public function test_country_disabling_wallet_does_not_affect_an_already_existing_wallet(): void
    {
        $country = $this->countryDisabling([]);
        $student = $this->studentIn($country);

        $wallet = app(WalletService::class)->getOrCreateWallet($student, 'INR', $student);

        $country->update(['feature_flags' => ['wallet' => false]]);

        $again = app(WalletService::class)->getOrCreateWallet($student, 'INR', $student);
        $this->assertSame($wallet->id, $again->id, 'Historical wallet access/statement retrieval must survive a country disable.');
    }

    public function test_a_protective_credit_still_opens_a_wallet_even_when_the_country_disables_wallet(): void
    {
        $country = $this->countryDisabling(['wallet' => false]);
        $student = $this->studentIn($country);

        $wallet = app(WalletService::class)->getOrCreateWalletForExistingObligation($student, 'INR', $student);

        $this->assertNotNull($wallet->id, 'A refund/held-reward must still be able to open a wallet even where new wallet activity is disabled.');
    }

    // ── 4. Wallet recharge ────────────────────────────────────────────

    public function test_country_disabling_wallet_recharge_rejects_initiation(): void
    {
        $country = $this->countryDisabling(['wallet_recharge' => false]);
        $student = $this->studentIn($country);

        $this->expectException(WalletException::class);
        $this->expectExceptionMessage('not currently enabled');

        app(WalletRechargeServiceInterface::class)->initiate($student, 50000);
    }

    public function test_country_disabling_wallet_also_blocks_recharge_via_dependency(): void
    {
        $country = $this->countryDisabling(['wallet' => false]);
        $student = $this->studentIn($country);

        $this->expectException(WalletException::class);

        app(WalletRechargeServiceInterface::class)->initiate($student, 50000);
    }

    // ── 5. Wallet lesson payment (composed Wallet + Paid Bookings) ──────

    public function test_country_disabling_wallet_blocks_wallet_lesson_payment(): void
    {
        $paidType = BookingType::factory()->create(['key' => 'paid_one_to_one', 'is_paid' => true, 'duration_minutes' => 60]);
        $country = $this->countryDisabling(['wallet' => false]);
        $student = $this->studentIn($country);

        $startsAt = CarbonImmutable::now('UTC')->addDays(3);
        $booking = Booking::factory()->create([
            'student_id' => $student->id,
            'booking_type_id' => $paidType->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(60),
            'price' => 499,
            'currency' => 'INR',
            'reserved_until' => CarbonImmutable::now('UTC')->addMinutes(15),
        ]);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('not currently enabled');

        app(BookingPaymentServiceInterface::class)->payWithWallet($booking, $student);
    }

    public function test_country_disabling_paid_bookings_also_blocks_wallet_lesson_payment(): void
    {
        $paidType = BookingType::factory()->create(['key' => 'paid_one_to_one', 'is_paid' => true, 'duration_minutes' => 60]);
        $country = $this->countryDisabling(['paid_bookings' => false]);
        $student = $this->studentIn($country);

        $startsAt = CarbonImmutable::now('UTC')->addDays(3);
        $booking = Booking::factory()->create([
            'student_id' => $student->id,
            'booking_type_id' => $paidType->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(60),
            'price' => 499,
            'currency' => 'INR',
            'reserved_until' => CarbonImmutable::now('UTC')->addMinutes(15),
        ]);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('not currently enabled');

        app(BookingPaymentServiceInterface::class)->payWithWallet($booking, $student);
    }

    // ── 6. Referrals ──────────────────────────────────────────────────

    public function test_country_disabling_referrals_blocks_attribution(): void
    {
        $referrer = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $referrer->assignRole('student');
        $referrer->profile()->update(['student_status' => StudentStatus::Active]);
        $code = app(ReferralCodeServiceInterface::class)->getOrCreateForStudent($referrer);

        $country = $this->countryDisabling(['referrals' => false]);
        $referred = $this->studentIn($country);

        $attribution = app(ReferralAttributionServiceInterface::class)->attributeFromRegistration($referred, $code->code);

        $this->assertNull($attribution, 'A country that disables referrals must silently skip attribution, mirroring the existing global-disable behavior.');
    }

    // ── 7. Promotional credits ────────────────────────────────────────

    public function test_country_disabling_promotional_credits_blocks_issuance(): void
    {
        $country = $this->countryDisabling(['promotional_credits' => false]);
        $student = $this->studentIn($country);
        $student->markEmailAsVerified();
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->givePermissionTo(Permission::firstOrCreate(['name' => 'IssuePromotionalCredit', 'guard_name' => 'web']));

        $this->expectException(PromotionalCreditException::class);
        $this->expectExceptionMessage('not currently available');

        app(PromotionalCreditService::class)->issueManualCredit($student, $admin, 10000, 'INR', 'Goodwill credit', 'test-idem-key-1');
    }

    public function test_country_disabling_wallet_also_blocks_promotional_credits_via_dependency(): void
    {
        $country = $this->countryDisabling(['wallet' => false]);
        $student = $this->studentIn($country);
        $student->markEmailAsVerified();
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->givePermissionTo(Permission::firstOrCreate(['name' => 'IssuePromotionalCredit', 'guard_name' => 'web']));

        $this->expectException(PromotionalCreditException::class);

        app(PromotionalCreditService::class)->issueManualCredit($student, $admin, 10000, 'INR', 'Goodwill credit', 'test-idem-key-2');
    }

    // ── 8. Waitlist ───────────────────────────────────────────────────

    public function test_country_disabling_waitlist_rejects_join(): void
    {
        $country = $this->countryDisabling(['waitlist' => false]);
        $student = $this->studentIn($country);
        $instructor = $this->instructorIn(null);

        $this->expectException(WaitlistException::class);
        $this->expectExceptionMessage('not currently enabled');

        app(WaitlistService::class)->join($student, $instructor);
    }

    // ── 9. Homework (new enforcement — previously ungated) ──────────────

    public function test_country_disabling_homework_rejects_assignment(): void
    {
        $country = $this->countryDisabling(['homework' => false]);
        $instructor = $this->instructorIn($country);
        $student = $this->studentIn(null);

        $category = AcademicCategory::query()->firstOrCreate(['slug' => 'general'], ['name' => 'General']);
        $subject = Subject::query()->firstOrCreate(
            ['slug' => 'maths'],
            ['academic_category_id' => $category->id, 'name' => 'Maths', 'status' => 'active'],
        );
        $goal = StudentLearningGoal::query()->create([
            'user_id' => $student->id,
            'subject_id' => $subject->id,
            'title' => 'Master algebra',
            'type' => 'academic',
            'status' => LearningGoalStatus::Active,
        ]);
        $plan = StudentLearningPlan::query()->create([
            'student_user_id' => $student->id,
            'learning_goal_id' => $goal->id,
            'primary_instructor_user_id' => $instructor->id,
            'subject_id' => $subject->id,
            'title' => 'Algebra plan',
            'status' => LearningPlanStatus::Active,
            'progress_percent' => 0,
        ]);

        $this->expectException(HomeworkException::class);
        $this->expectExceptionMessage('not currently available for your country');

        app(HomeworkServiceInterface::class)->assign(
            $instructor,
            $student,
            ['title' => 'Fractions worksheet', 'subject' => 'maths', 'due_at' => now()->addWeek()],
            learningPlanId: $plan->id,
        );
    }

    // ── 10. Recording availability ────────────────────────────────────

    public function test_country_disabling_recording_availability_is_reflected_by_the_resolver(): void
    {
        $enabledCountry = $this->countryDisabling([]);
        $disabledCountry = $this->countryDisabling(['recording_availability' => false]);

        $resolver = app(RecordingAvailabilityResolver::class);

        $this->assertFalse($resolver->isAvailable($disabledCountry));
        // Global-only call (no country in scope) is unaffected by any country's override.
        $this->assertIsBool($resolver->isAvailable());
    }

    // ── 11. Country isolation across a real domain boundary ────────────

    public function test_disabling_waitlist_for_one_country_does_not_affect_another(): void
    {
        $disabledCountry = $this->countryDisabling(['waitlist' => false]);
        $enabledCountry = $this->countryDisabling([]);

        $blockedStudent = $this->studentIn($disabledCountry);
        $allowedStudent = $this->studentIn($enabledCountry);
        $instructor = $this->instructorIn(null);

        $threw = false;
        try {
            app(WaitlistService::class)->join($blockedStudent, $instructor);
        } catch (WaitlistException) {
            $threw = true;
        }
        $this->assertTrue($threw);

        $entry = app(WaitlistService::class)->join($allowedStudent, $instructor);
        $this->assertNotNull($entry->id);
    }
}
