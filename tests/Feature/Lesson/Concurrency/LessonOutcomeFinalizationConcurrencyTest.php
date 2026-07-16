<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson\Concurrency;

use App\Booking\Enums\BookingPaymentStatus;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\InstructorCompensationAgreement;
use App\Models\InstructorEarning;
use App\Models\Lesson;
use Tests\Support\ManagesFinancialSettings;

/**
 * Phase 17U.4 (Section 16) — a real cross-process race on
 * FinalizeLessonOutcomeAction, the single writer of a lesson's
 * finalized outcome. Two independent worker processes call
 * LessonOutcomeService::finalize() for the very same lesson at the
 * same instant, exercising the row lock genuinely under MySQL rather
 * than through a sequential in-process simulation (see
 * StaleJobSafetyTest for the sequential-simulation counterpart on the
 * two batch commands — this test complements it by proving the
 * FinalizeLessonOutcomeAction lock itself is race-safe, not merely
 * correct in isolation).
 *
 * Completed also fans out into CreateEarningOnLessonCompleted, whose
 * downstream InstructorEarningService::createFromLesson() is the
 * codebase's reference unique-constraint-violation recovery pattern —
 * so this race additionally proves that pattern converges to exactly
 * one earning row under genuine concurrent delivery, not just the
 * sequential-replay coverage already in LessonFinancialDispositionTest.
 */
class LessonOutcomeFinalizationConcurrencyTest extends ConcurrencyTestCase
{
    use ManagesFinancialSettings;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);

        // Child worker processes each boot their own application
        // container, so this must be a committed setting (this class's
        // $connectionsToTransact = [] already ensures that), not merely
        // an in-process override — both racing finalizers need
        // earnings_enabled to observe the same, persisted value.
        $this->setFinancialSettings(['earnings_enabled' => true]);
    }

    public function test_two_concurrent_finalizers_converge_to_exactly_one_applied_outcome_and_one_earning(): void
    {
        $lesson = $this->makeCompletableLesson();

        $results = $this->race([
            ['finalize-lesson-outcome', ['lesson_id' => $lesson->id, 'outcome' => 'completed']],
            ['finalize-lesson-outcome', ['lesson_id' => $lesson->id, 'outcome' => 'completed']],
        ]);

        foreach ($results as $result) {
            $this->assertTrue($result['ok'] ?? false, 'Concurrent finalizer failed: '.json_encode($result));
        }

        $appliedCount = count(array_filter($results, static fn (array $r): bool => $r['result']['applied'] === true));
        $this->assertSame(
            1,
            $appliedCount,
            'Exactly one of the two concurrent finalizers must have applied the outcome — the other must observe the already-finalized, idempotent no-op path.',
        );

        $lesson->refresh();
        $this->assertNotNull($lesson->outcome_finalized_at);
        $this->assertSame(1, $lesson->outcome_version, 'A raced duplicate finalization must never bump outcome_version a second time.');

        $this->assertSame(
            1,
            InstructorEarning::query()->where('lesson_id', $lesson->id)->count(),
            'The Completed outcome fan-out must converge to exactly one earning row even when delivered by two racing finalizers.',
        );
    }

    private function makeCompletableLesson(): Lesson
    {
        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'starts_at' => $endsAt->copy()->subMinutes(60),
            'ends_at' => $endsAt,
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => '499.00',
            'currency' => 'INR',
            'payment_reference' => 'PAY-17U4-CONCURRENCY',
        ]);

        $lesson = app(LessonLifecycleServiceInterface::class)->createFromBooking($booking);

        InstructorCompensationAgreement::factory()->active()->create([
            'instructor_id' => $lesson->instructor_id,
            'amount_minor' => 80000,
            'currency_code' => 'INR',
            'currency_id' => Currency::query()->where('code', 'INR')->value('id'),
            'effective_from' => now()->subMonth(),
        ]);

        return $lesson;
    }
}
