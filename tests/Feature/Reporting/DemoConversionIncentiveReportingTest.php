<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Types\FreeDemoType;
use App\Booking\Types\PaidOneToOneType;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Currency;
use App\Models\InstructorCompensationAgreement;
use App\Models\User;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\Repositories\InstructorFinancialReportRepository;
use App\Reporting\ValueObjects\ReportingPeriod;
use App\Settings\DemoConversionIncentiveSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ManagesFinancialSettings;
use Tests\TestCase;

/**
 * The instructor financial report is the natural extension point for
 * incentive award numbers (unlike the
 * pre-existing marketplace demo-conversion-RATE metric, which is
 * deliberately student-only/unattributed by design — see
 * DemoConversionData's own docblock — and is left untouched).
 */
final class DemoConversionIncentiveReportingTest extends TestCase
{
    use ManagesFinancialSettings;
    use RefreshDatabase;

    private LessonLifecycleServiceInterface $lessons;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lessons = app(LessonLifecycleServiceInterface::class);

        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);

        $this->setFinancialSettings(['earnings_enabled' => true]);

        $settings = app(DemoConversionIncentiveSettings::class);
        $settings->enabled = true;
        $settings->conversion_window_days = 7;
        $settings->min_completed_paid_lessons = 1;
        $settings->bonus_amount_minor = 20000;
        $settings->bonus_currency_code = 'INR';
        $settings->max_awards_per_pair = 1;
        $settings->applicable_country_ids = [];
        $settings->applicable_subject_ids = [];
        $settings->save();
    }

    private function bookingType(string $key, bool $isPaid): BookingType
    {
        return BookingType::query()->firstOrCreate(
            ['key' => $key],
            ['name' => $key, 'duration_minutes' => $isPaid ? 60 : 30, 'is_paid' => $isPaid, 'is_active' => true],
        );
    }

    private function convertStudent(User $student, User $instructor): void
    {
        $demoBooking = Booking::factory()->confirmed()->create([
            'booking_type_id' => $this->bookingType(FreeDemoType::KEY, false)->id,
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'payment_status' => BookingPaymentStatus::NotRequired,
        ]);
        $demoLesson = $this->lessons->createFromBooking($demoBooking);
        $this->lessons->complete($demoLesson, override: true);

        $paidBooking = Booking::factory()->confirmed()->create([
            'booking_type_id' => $this->bookingType(PaidOneToOneType::KEY, true)->id,
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => '499.00',
            'currency' => 'INR',
        ]);
        $paidLesson = $this->lessons->createFromBooking($paidBooking);

        InstructorCompensationAgreement::factory()->active()->create([
            'instructor_id' => $instructor->id,
            'amount_minor' => 80000,
            'currency_code' => 'INR',
            'effective_from' => now()->subMonth(),
        ]);

        $this->lessons->complete($paidLesson->fresh(), override: true);
    }

    public function test_the_financial_summary_reports_the_incentive_award_created_in_the_period(): void
    {
        $instructor = User::factory()->create();
        $student = User::factory()->create();

        $this->convertStudent($student, $instructor);

        $period = ReportingPeriod::custom(now()->subDay()->toDateString(), now()->addDay()->toDateString());
        $summary = app(InstructorFinancialReportRepository::class)->summary($period, new ReportFilters($period));

        $this->assertSame(1, $summary->demoConversionIncentiveAwardsCount);
        $this->assertSame(20000, $summary->demoConversionIncentiveAmountByCurrency['INR']);
    }

    public function test_the_financial_summary_reports_zero_when_no_awards_exist(): void
    {
        $period = ReportingPeriod::custom(now()->subDay()->toDateString(), now()->addDay()->toDateString());
        $summary = app(InstructorFinancialReportRepository::class)->summary($period, new ReportFilters($period));

        $this->assertSame(0, $summary->demoConversionIncentiveAwardsCount);
        $this->assertSame([], $summary->demoConversionIncentiveAmountByCurrency);
    }
}
