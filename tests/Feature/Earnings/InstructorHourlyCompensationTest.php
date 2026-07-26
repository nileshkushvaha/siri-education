<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings;

use App\Booking\Enums\BookingPaymentStatus;
use App\Earnings\Contracts\InstructorCompensationAgreementServiceInterface;
use App\Earnings\Contracts\InstructorEarningServiceInterface;
use App\Earnings\Enums\CompensationPayBasis;
use App\Earnings\Exceptions\CompensationException;
use App\Earnings\Support\CompensationMath;
use App\Earnings\Support\FinancialFeatureToggle;
use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\InstructorCompensationAgreement;
use App\Models\InstructorEarning;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\User;
use App\Settings\InstructorEarningSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Hourly lesson compensation: rate × eligible minutes / 60, integer
 * half-up rounding, override resolution by specificity,
 * and total independence from student pricing.
 */
class InstructorHourlyCompensationTest extends TestCase
{
    use RefreshDatabase;

    private InstructorEarningServiceInterface $earnings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->earnings = app(InstructorEarningServiceInterface::class);

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);

        $settings = app(InstructorEarningSettings::class);
        $settings->earnings_enabled = true;
        FinancialFeatureToggle::unguarded(fn () => $settings->save());
    }

    // ── Duration proportions ─────────────────────────────────────────

    public function test_duration_proportional_amounts(): void
    {
        // ₹800/hour: 30 → 400, 45 → 600, 60 → 800, 90 → 1200.
        foreach ([30 => 40000, 45 => 60000, 60 => 80000, 90 => 120000] as $minutes => $expected) {
            $earning = $this->earnForLesson(rateMinor: 80000, minutes: $minutes);

            $this->assertSame($expected, $earning->earning_amount_minor, "{$minutes} minutes");
            $this->assertSame($minutes, $earning->getAttribute('metadata')['eligible_minutes']);
        }
    }

    public function test_rounding_policy_is_half_up_and_integer_only(): void
    {
        // The documented example: 1001 × 45 / 60 = 750.75 → 751.
        $this->assertSame(751, CompensationMath::hourlyAmount(1001, 45));
        // Exact halves round up: 1001 × 30 / 60 = 500.5 → 501.
        $this->assertSame(501, CompensationMath::hourlyAmount(1001, 30));
        // Below half rounds down: 1000 × 20 / 60 = 333.33 → 333.
        $this->assertSame(333, CompensationMath::hourlyAmount(1000, 20));
        // Large magnitudes stay exact (integer path, no float).
        $this->assertSame(intdiv(9007199254740 * 45 + 30, 60), CompensationMath::hourlyAmount(9007199254740, 45));

        $earning = $this->earnForLesson(rateMinor: 1001, minutes: 45);
        $this->assertSame(751, $earning->earning_amount_minor);
        $this->assertSame('half_up_minor', $earning->getAttribute('metadata')['rounding_policy']);
    }

    // ── Overrides ────────────────────────────────────────────────────

    public function test_override_resolution_prefers_the_most_specific_match(): void
    {
        $service = app(InstructorCompensationAgreementServiceInterface::class);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $instructor = $this->instructor();
        $category = AcademicCategory::create(['name' => 'Science', 'slug' => 'science']);
        $subject = Subject::create(['academic_category_id' => $category->id, 'name' => 'Physics', 'slug' => 'physics', 'status' => 'active']);
        $level = AcademicLevel::create(['name' => 'Grade 10', 'slug' => 'grade-10']);

        $agreement = InstructorCompensationAgreement::factory()->create([
            'instructor_id' => $instructor->id,
            'amount_minor' => 80000,
            'effective_from' => now()->subMonth(),
        ]);

        $service->addOverride($agreement, $admin, $subject->id, null, null, 90000);              // subject
        $service->addOverride($agreement, $admin, $subject->id, null, 60, 100000);               // subject + duration
        $service->addOverride($agreement, $admin, $subject->id, $level->id, 60, 110000);         // subject + level + duration
        $service->addOverride($agreement, $admin, null, null, 60, 85000);                        // duration only

        $agreement->forceFill(['status' => 'active', 'approved_at' => now()])->save();

        // Full match wins.
        $earning = $this->earnForLesson(60, agreement: $agreement, subjectId: $subject->id, levelId: $level->id);
        $this->assertSame(110000, $earning->earning_amount_minor);
        $this->assertNotNull($earning->getAttribute('metadata')['override_id'] ?? null);

        // Subject + duration when the level differs.
        $otherLevel = AcademicLevel::create(['name' => 'Grade 11', 'slug' => 'grade-11']);
        $earning = $this->earnForLesson(60, agreement: $agreement, subjectId: $subject->id, levelId: $otherLevel->id);
        $this->assertSame(100000, $earning->earning_amount_minor);

        // Subject-only for a different duration.
        $earning = $this->earnForLesson(45, agreement: $agreement, subjectId: $subject->id, levelId: $otherLevel->id);
        // subject override (90000/hour) × 45/60 = 67500.
        $this->assertSame(67500, $earning->earning_amount_minor);

        // Duration-only when the subject has no override.
        $otherSubject = Subject::create(['academic_category_id' => $category->id, 'name' => 'Chemistry', 'slug' => 'chemistry', 'status' => 'active']);
        $earning = $this->earnForLesson(60, agreement: $agreement, subjectId: $otherSubject->id, levelId: $otherLevel->id);
        $this->assertSame(85000, $earning->earning_amount_minor);

        // Base rate when nothing matches.
        $earning = $this->earnForLesson(30, agreement: $agreement, subjectId: $otherSubject->id, levelId: $otherLevel->id);
        $this->assertSame(40000, $earning->earning_amount_minor);
    }

    public function test_overrides_are_locked_once_the_agreement_is_active(): void
    {
        $service = app(InstructorCompensationAgreementServiceInterface::class);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $agreement = InstructorCompensationAgreement::factory()->active()->create([
            'instructor_id' => $this->instructor()->id,
        ]);

        $this->expectException(CompensationException::class);

        $service->addOverride($agreement, $admin, null, null, 60, 90000);
    }

    // ── Commission removal guarantees ────────────────────────────────

    public function test_student_price_and_discounts_have_no_effect_on_compensation(): void
    {
        $instructor = $this->instructor();
        $agreement = InstructorCompensationAgreement::factory()->active()->create([
            'instructor_id' => $instructor->id,
            'amount_minor' => 80000,
            'effective_from' => now()->subMonth(),
        ]);

        $lowPriced = $this->earnForLesson(60, agreement: $agreement, price: '10.00');
        $highPriced = $this->earnForLesson(60, agreement: $agreement, price: '99999.99');

        $this->assertSame(80000, $lowPriced->earning_amount_minor);
        $this->assertSame(80000, $highPriced->earning_amount_minor);
    }

    public function test_no_student_payment_value_reaches_the_earning_snapshot(): void
    {
        $earning = $this->earnForLesson(rateMinor: 80000, minutes: 60, price: '499.00');

        $metadata = json_encode($earning->getAttribute('metadata'));

        $this->assertStringNotContainsString('student', $metadata);
        $this->assertStringNotContainsString('49900', $metadata);
        $this->assertStringNotContainsString('percentage', $metadata);
        $this->assertNull($earning->getAttribute('student_amount_minor'));
        $this->assertNull($earning->getAttribute('platform_margin_minor'));
    }

    public function test_historical_snapshot_survives_rate_replacement(): void
    {
        $service = app(InstructorCompensationAgreementServiceInterface::class);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        foreach (['Configure', 'End'] as $verb) {
            Permission::firstOrCreate(['name' => $verb.':InstructorCompensationAgreement', 'guard_name' => 'web']);
        }
        $admin->givePermissionTo(['Configure:InstructorCompensationAgreement', 'End:InstructorCompensationAgreement']);

        $instructor = $this->instructor();
        $agreement = InstructorCompensationAgreement::factory()->active()->create([
            'instructor_id' => $instructor->id,
            'amount_minor' => 80000,
            'effective_from' => now()->subMonth(),
        ]);

        $earning = $this->earnForLesson(60, agreement: $agreement);
        $originalMetadata = $earning->fresh()->getAttribute('metadata');
        $this->assertSame(80000, $originalMetadata['rate_minor']);

        $service->replace($agreement, $admin, CompensationPayBasis::Hourly, 95000, 'INR', now(), 'Raise.');

        // The historical earning and its snapshot are untouched.
        $fresh = $earning->fresh();
        $this->assertSame(80000, $fresh->earning_amount_minor);
        $this->assertEquals($originalMetadata, $fresh->getAttribute('metadata'));
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function instructor(): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('instructor');

        return $user;
    }

    private function earnForLesson(
        int $minutes,
        int $rateMinor = 80000,
        ?InstructorCompensationAgreement $agreement = null,
        ?string $subjectId = null,
        ?string $levelId = null,
        string $price = '499.00',
    ): InstructorEarning {
        $agreement ??= InstructorCompensationAgreement::factory()->active()->create([
            'instructor_id' => $this->instructor()->id,
            'amount_minor' => $rateMinor,
            'effective_from' => now()->subMonth(),
        ]);

        $booking = Booking::factory()->confirmed()->create([
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => $price,
            'currency' => 'INR',
        ]);

        $start = now()->subHours(3);

        $lesson = Lesson::factory()->completed()->create([
            'booking_id' => $booking->id,
            'instructor_id' => $agreement->instructor_id,
            'subject_id' => $subjectId,
            'academic_level_id' => $levelId,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addMinutes($minutes),
            'completed_at' => now(),
        ]);

        $earning = $this->earnings->createFromLesson($lesson);

        $this->assertNotNull($earning, 'Expected an earning to be created.');

        return $earning;
    }
}
