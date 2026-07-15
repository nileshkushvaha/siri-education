<?php

declare(strict_types=1);

namespace Tests\Feature\Audit17T;

use App\Booking\Enums\BookingPaymentStatus;
use App\Exceptions\HistoricalRecordCannotBeDeletedException;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\LessonFinancialDisposition;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ManagesFinancialSettings;
use Tests\TestCase;

/**
 * Phase 17T Finding S-2, remediated in Phase 17U.1.
 *
 * This test originally proved the opposite of what it proves now: that
 * `BookingsTable::deleteTerminalOnly(force: true)` could silently
 * force-delete a terminal booking and cascade-destroy its Lesson and
 * LessonFinancialDisposition, with no exception and no audit entry.
 *
 * Phase 17U.1 removed every force-delete path for Booking, made
 * `Booking::forceDelete()` throw unconditionally
 * (App\Support\Concerns\PreventsHardDeletion), and replaced every
 * historical `ON DELETE CASCADE` foreign key reachable from `bookings`
 * with `RESTRICT` at the database level. This test now proves BOTH
 * layers of that fix hold: the application-level guard throws before
 * any row is touched, and — as a second, independent proof — the
 * database itself would refuse the cascade even if the guard were
 * somehow bypassed.
 */
class BookingForceDeleteCascadeWipesFinancialHistoryTest extends TestCase
{
    use ManagesFinancialSettings;
    use RefreshDatabase;

    public function test_force_deleting_a_completed_demo_booking_is_rejected_and_the_lesson_and_disposition_survive(): void
    {
        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);

        $this->setFinancialSettings(['earnings_enabled' => false, 'financial_disposition_enabled' => true]);

        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'starts_at' => $endsAt->copy()->subMinutes(60),
            'ends_at' => $endsAt,
            'payment_status' => BookingPaymentStatus::NotRequired,
            'price' => null,
            'currency' => null,
        ]);

        $lesson = app(LessonLifecycleServiceInterface::class)->createFromBooking($booking);
        app(LessonOutcomeServiceInterface::class)->finalize($lesson, LessonOutcome::Completed);

        $disposition = LessonFinancialDisposition::query()->where('lesson_id', $lesson->id)->firstOrFail();
        $lessonId = $lesson->id;
        $dispositionId = $disposition->id;

        $booking->refresh();
        $this->assertTrue($booking->status->isTerminal());

        $this->expectException(HistoricalRecordCannotBeDeletedException::class);

        try {
            $booking->forceDelete();
        } finally {
            // Whether or not the exception fired as expected, nothing
            // must be gone — this assertion runs even if the test
            // itself is about to fail, so a regression is loud either way.
            $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
            $this->assertDatabaseHas('lessons', ['id' => $lessonId]);
            $this->assertDatabaseHas('lesson_financial_dispositions', ['id' => $dispositionId]);
        }
    }

    /**
     * Independent, lower-level proof: even bypassing Eloquent entirely
     * (raw DB delete, as if the application-level guard did not exist),
     * the database's own FK constraint now refuses the cascade.
     */
    public function test_raw_sql_deletion_of_a_booking_with_dependents_is_rejected_at_the_database_level(): void
    {
        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);

        $this->setFinancialSettings(['earnings_enabled' => false, 'financial_disposition_enabled' => true]);

        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'starts_at' => $endsAt->copy()->subMinutes(60),
            'ends_at' => $endsAt,
            'payment_status' => BookingPaymentStatus::NotRequired,
            'price' => null,
            'currency' => null,
        ]);

        $lesson = app(LessonLifecycleServiceInterface::class)->createFromBooking($booking);
        app(LessonOutcomeServiceInterface::class)->finalize($lesson, LessonOutcome::Completed);

        $this->expectException(QueryException::class);

        DB::statement('DELETE FROM bookings WHERE id = ?', [$booking->id]);
    }
}
