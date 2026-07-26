<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings\Concurrency;

use App\Booking\Enums\BookingPaymentStatus;
use App\Earnings\Enums\CompensationAgreementStatus;
use App\Earnings\Enums\CompensationExceptionCategory;
use App\Earnings\Services\CompensationExceptionService;
use App\Earnings\Support\FinancialFeatureToggle;
use App\Models\Booking;
use App\Models\InstructorCompensationAgreement;
use App\Models\InstructorCompensationException;
use App\Models\InstructorCompensationPeriod;
use App\Models\InstructorEarning;
use App\Models\Lesson;
use App\Settings\InstructorEarningSettings;
use Carbon\CarbonImmutable;

/**
 * Real multi-process races on the compensation domain (same harness as
 * the withdrawal/settlement races): the instructor owner-row lock plus
 * the DB uniqueness backstops
 * (ica_active_owner_unique, icp_agreement_period_unique,
 * ie_source_unique) must hold under genuine parallel MySQL connections.
 */
class CompensationConcurrencyTest extends ConcurrencyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $settings = app(InstructorEarningSettings::class);
        $settings->earnings_enabled = true;
        $settings->periodic_compensation_enabled = true;
        FinancialFeatureToggle::unguarded(fn () => $settings->save());
    }

    public function test_concurrent_activation_of_two_agreements_leaves_exactly_one_active(): void
    {
        $admin = $this->makeInstructor(); // any user works for the service call
        $instructor = $this->makeInstructor();

        // Two non-overlapping drafts — each individually activatable, but
        // only one may hold the single-active slot.
        $first = InstructorCompensationAgreement::factory()->create([
            'instructor_id' => $instructor->id,
            'effective_from' => now()->subMonth(),
            'effective_until' => now()->addMonth(),
        ]);
        $second = InstructorCompensationAgreement::factory()->create([
            'instructor_id' => $instructor->id,
            'effective_from' => now()->addMonths(2),
        ]);

        $results = $this->race([
            ['activate-agreement', ['admin_id' => $admin->id, 'agreement_id' => $first->id]],
            ['activate-agreement', ['admin_id' => $admin->id, 'agreement_id' => $second->id]],
        ]);

        // Serialized on the owner lock: one wins, the other gets the safe
        // "another agreement is already active" domain refusal.
        $succeeded = array_filter($results, fn (array $r): bool => $r['ok']);
        $this->assertCount(1, $succeeded, json_encode($results));

        $this->assertSame(1, InstructorCompensationAgreement::query()
            ->forInstructor($instructor->id)
            ->where('status', CompensationAgreementStatus::Active)
            ->count());
    }

    public function test_concurrent_periodic_accrual_runs_never_duplicate_periods(): void
    {
        $instructor = $this->makeInstructor();

        $agreement = InstructorCompensationAgreement::factory()->daily(200000)->active()->create([
            'instructor_id' => $instructor->id,
            'timezone' => 'Asia/Kolkata',
            'effective_from' => CarbonImmutable::now('Asia/Kolkata')->startOfDay()->subDays(3),
        ]);

        $results = $this->race([
            ['accrue-periodic', []],
            ['accrue-periodic', []],
        ]);

        foreach ($results as $result) {
            $this->assertTrue($result['ok'], json_encode($results));
        }

        // Three closed days exist; between both racing runs exactly three
        // periods and three earnings may come into existence.
        $this->assertSame(3, (int) array_sum(array_column(array_column($results, 'result'), 'accrued')));
        $this->assertSame(3, InstructorCompensationPeriod::query()->where('agreement_id', $agreement->id)->count());
        $this->assertSame(3, InstructorEarning::query()->where('instructor_id', $instructor->id)->count());
        $this->assertSame(600000, (int) InstructorEarning::query()->where('instructor_id', $instructor->id)->sum('earning_amount_minor'));
    }

    public function test_concurrent_blocked_lesson_retries_create_exactly_one_earning(): void
    {
        $instructor = $this->makeInstructor();

        // A completed paid lesson blocked by a missing agreement…
        $lesson = Lesson::factory()->completed()->create([
            'booking_id' => Booking::factory()->confirmed()->create([
                'payment_status' => BookingPaymentStatus::Paid,
                'price' => '499.00',
                'currency' => 'INR',
            ])->id,
            'instructor_id' => $instructor->id,
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDays(2)->addMinutes(60),
            'completed_at' => now()->subDay(),
        ]);

        // Seed the queue row deterministically through its owning service
        // (the block→record path itself is covered in
        // InstructorCompensationHardeningTest) — this test is about the
        // RACE on recovery, so its precondition must never be timing-
        // sensitive.
        app(CompensationExceptionService::class)->record(
            $lesson,
            CompensationExceptionCategory::MissingAgreement,
            'Seeded for concurrency test.',
        );
        $this->assertNotNull(InstructorCompensationException::query()->where('lesson_id', $lesson->id)->first());

        // …then the backdated agreement lands and two retries race.
        InstructorCompensationAgreement::factory()->active()->create([
            'instructor_id' => $instructor->id,
            'amount_minor' => 80000,
            'effective_from' => now()->subMonth(),
        ]);

        $results = $this->race([
            ['retry-blocked', ['lesson_id' => $lesson->id]],
            ['retry-blocked', ['lesson_id' => $lesson->id]],
        ]);

        foreach ($results as $result) {
            $this->assertTrue($result['ok'], json_encode($results));
        }

        // Exactly one earning for the lesson; both workers resolved to it.
        $this->assertSame(1, InstructorEarning::query()->where('lesson_id', $lesson->id)->count());
        $exception = InstructorCompensationException::query()->where('lesson_id', $lesson->id)->sole();
        $this->assertNotNull($exception->resolved_at);
    }
}
