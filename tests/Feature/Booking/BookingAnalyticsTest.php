<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingAnalyticsServiceInterface;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\TeacherAvailability;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private BookingAnalyticsServiceInterface $analytics;

    private CarbonImmutable $from;

    private CarbonImmutable $to;

    private User $teacher;

    private BookingType $demo;

    private BookingType $paid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analytics = app(BookingAnalyticsServiceInterface::class);
        $this->from = now()->subDays(29)->startOfDay()->toImmutable();
        $this->to = now()->toImmutable();

        $this->teacher = User::factory()->create();
        $this->demo = BookingType::factory()->create(['key' => 'free_demo', 'duration_minutes' => 30]);
        $this->paid = BookingType::factory()->paid(50.00, 'USD')->create(['key' => 'paid_one_to_one', 'duration_minutes' => 60]);
    }

    private function booking(BookingType $type, array $attributes = []): Booking
    {
        return Booking::factory()->for($type, 'type')->create([
            'instructor_id' => $this->teacher->id,
            'created_at' => now()->subDays(5),
            'starts_at' => now()->subDays(2)->setTime(10, 0),
            'ends_at' => now()->subDays(2)->setTime(10, 0)->addMinutes($type->duration_minutes),
            ...$attributes,
        ]);
    }

    public function test_kpis_cover_demos_conversion_revenue_and_cancellations(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        // Two demo bookers; Alice later converts to a paid booking.
        $this->booking($this->demo, ['student_id' => $alice->id, 'status' => BookingStatus::Completed, 'completed_at' => now()->subDay()]);
        $this->booking($this->demo, ['student_id' => $bob->id, 'status' => BookingStatus::Cancelled, 'cancelled_at' => now()->subDay()]);
        $this->booking($this->paid, [
            'student_id' => $alice->id,
            'created_at' => now()->subDays(3),
            'starts_at' => now()->addDay()->setTime(11, 0),
            'ends_at' => now()->addDay()->setTime(12, 0),
            'status' => BookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => '50.00',
            'currency' => 'USD',
        ]);

        $kpis = $this->analytics->kpis($this->from, $this->to);

        $this->assertSame(3, $kpis->totalBookings);
        $this->assertSame(2, $kpis->demoRequests);
        $this->assertSame(2, $kpis->demoBookers);
        $this->assertSame(1, $kpis->convertedBookers);
        $this->assertSame(50.0, $kpis->conversionRate);
        $this->assertSame('50.00', $kpis->revenue);
        $this->assertSame(1, $kpis->cancelled);
        $this->assertSame(round(1 / 3 * 100, 1), $kpis->cancellationRate);
        $this->assertSame(1, $kpis->completed);
    }

    public function test_popular_subjects_and_time_slots(): void
    {
        $this->booking($this->demo, ['meta' => ['subject' => 'maths', 'grade' => 5]]);
        $this->booking($this->demo, ['meta' => ['subject' => 'maths', 'grade' => 6], 'starts_at' => now()->subDays(2)->setTime(10, 30), 'ends_at' => now()->subDays(2)->setTime(11, 0)]);
        $this->booking($this->demo, ['meta' => ['subject' => 'english', 'grade' => 4], 'starts_at' => now()->subDays(2)->setTime(15, 0), 'ends_at' => now()->subDays(2)->setTime(15, 30)]);

        $subjects = $this->analytics->popularSubjects($this->from, $this->to);
        $this->assertSame('maths', $subjects[0]['subject']);
        $this->assertSame(2, $subjects[0]['bookings']);

        $slots = $this->analytics->popularTimeSlots($this->from, $this->to);
        $this->assertCount(24, $slots);
        $this->assertSame(2, $slots[10]['bookings']); // 10:00 + 10:30
        $this->assertSame(1, $slots[15]['bookings']);
        $this->assertSame(0, $slots[3]['bookings']);
    }

    public function test_teacher_utilization_ratio(): void
    {
        // 10 weekly hours of availability (2h × 5 days)
        foreach ([Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday] as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $this->teacher->id])
                ->forDay($day)->between('09:00:00', '11:00:00')->create();
        }

        // 6 booked hours in the period
        foreach (range(1, 6) as $i) {
            $this->booking($this->paid, [
                'student_id' => User::factory()->create()->id,
                'status' => BookingStatus::Completed,
                'starts_at' => now()->subDays($i)->setTime(9, 0),
                'ends_at' => now()->subDays($i)->setTime(10, 0),
            ]);
        }

        $rows = $this->analytics->teacherUtilization($this->from, $this->to);

        $this->assertCount(1, $rows);
        $this->assertSame($this->teacher->name, $rows[0]['teacher']);
        $this->assertSame(6.0, $rows[0]['booked_hours']);
        $this->assertGreaterThan(0, $rows[0]['utilization']);
        $this->assertLessThanOrEqual(100, $rows[0]['utilization']);
    }

    public function test_trend_is_gap_filled_and_results_are_cached(): void
    {
        $this->booking($this->demo);

        $trend = $this->analytics->trend($this->from, $this->to);
        $this->assertCount(30, $trend);
        $this->assertSame(1, collect($trend)->sum('bookings'));

        // Cached: adding data within the TTL does not change the result.
        $this->booking($this->demo, ['created_at' => now()->subDays(4)]);
        $this->assertSame(1, collect($this->analytics->trend($this->from, $this->to))->sum('bookings'));
    }
}
