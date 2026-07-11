<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings;

use App\Booking\Enums\BookingPaymentStatus;
use App\Earnings\Contracts\InstructorCompensationAgreementServiceInterface;
use App\Earnings\Contracts\InstructorEarningServiceInterface;
use App\Earnings\Enums\CompensationAgreementStatus;
use App\Earnings\Enums\CompensationExceptionCategory;
use App\Earnings\Enums\CompensationPayBasis;
use App\Earnings\Exceptions\CompensationException;
use App\Earnings\Services\CompensationActivationPreflight;
use App\Earnings\Services\CompensationExceptionService;
use App\Enums\InstructorStatus;
use App\Filament\Pages\Settings\InstructorEarningSettingsPage;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\InstructorCompensationAgreement;
use App\Models\InstructorCompensationException;
use App\Models\InstructorEarning;
use App\Models\Lesson;
use App\Models\User;
use App\Settings\InstructorEarningSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\ManagesFinancialSettings;
use Tests\TestCase;

/**
 * Phase 14.3 — pre-enable operational hardening: agreement resolution
 * pinned to the lesson's SCHEDULED start, the blocked-lesson exception
 * queue and idempotent retry sweep, the earnings activation preflight,
 * and the periodic-compensation rollout gate.
 */
class InstructorCompensationHardeningTest extends TestCase
{
    use ManagesFinancialSettings;
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

        $this->settings(['earnings_enabled' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── Canonical resolution timestamp: scheduled lesson start ───────

    public function test_a_july_lesson_completed_in_august_uses_the_july_agreement(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-31 10:00:00', 'UTC'));

        $instructor = $this->instructor();
        $admin = $this->configuringAdmin();

        $julyAgreement = InstructorCompensationAgreement::factory()->active()->create([
            'instructor_id' => $instructor->id,
            'amount_minor' => 80000,
            'effective_from' => Carbon::parse('2026-06-01 00:00:00', 'UTC'),
        ]);

        // Lesson scheduled (and taught) on 31 July.
        $lesson = $this->scheduledLesson($instructor, startsAt: Carbon::parse('2026-07-31 14:00:00', 'UTC'));

        // New rate begins 1 August via replacement.
        app(InstructorCompensationAgreementServiceInterface::class)->replace(
            $julyAgreement,
            $admin,
            CompensationPayBasis::Hourly,
            95000,
            'INR',
            Carbon::parse('2026-08-01 00:00:00', 'Asia/Kolkata'),
            'August raise.',
        );

        // The instructor marks it completed on 1 August; the queue
        // processes even later.
        Carbon::setTestNow(Carbon::parse('2026-08-01 09:00:00', 'UTC'));
        $lesson->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        Carbon::setTestNow(Carbon::parse('2026-08-02 03:00:00', 'UTC'));
        $earning = $this->earnings->createFromLesson($lesson->fresh());

        // Old (July) rate, because the service was delivered under it.
        $this->assertNotNull($earning);
        $this->assertSame(80000, $earning->earning_amount_minor);

        $metadata = $earning->getAttribute('metadata');
        $this->assertSame($julyAgreement->id, $metadata['agreement_id']);
        $this->assertSame('2026-07-31T14:00:00+00:00', $metadata['lesson_scheduled_start_at']);
        $this->assertSame('2026-07-31T14:00:00+00:00', $metadata['agreement_effective_timestamp']);
        $this->assertSame(80000, $metadata['calculated_amount_minor']);
        $this->assertArrayHasKey('currency_id', $metadata);
    }

    public function test_delayed_processing_and_completion_time_never_change_compensation(): void
    {
        $instructor = $this->instructor();
        InstructorCompensationAgreement::factory()->active()->create([
            'instructor_id' => $instructor->id,
            'amount_minor' => 80000,
            'effective_from' => now()->subMonth(),
        ]);

        $startsAt = now()->subDays(2);

        // Same scheduled slot, three different completion/processing times.
        $prompt = $this->completedLesson($instructor, $startsAt, completedAt: $startsAt->copy()->addHour());
        $auto = $this->completedLesson($instructor, $startsAt->copy()->addHours(3), completedAt: $startsAt->copy()->addHours(30));

        $first = $this->earnings->createFromLesson($prompt);

        Carbon::setTestNow(now()->addDays(5)); // queue backlog
        $second = $this->earnings->createFromLesson($auto);

        $this->assertSame(80000, $first->earning_amount_minor);
        $this->assertSame(80000, $second->earning_amount_minor);
    }

    // ── Exception queue & recovery ───────────────────────────────────

    public function test_blocked_lesson_recovers_via_retry_after_backdated_agreement(): void
    {
        $instructor = $this->instructor();
        $lesson = $this->completedLesson($instructor, now()->subDays(3), completedAt: now()->subDays(3)->addHour());

        // Blocked: no agreement anywhere near the scheduled time.
        $this->assertNull($this->earnings->createFromLesson($lesson));

        $exception = InstructorCompensationException::query()->where('lesson_id', $lesson->id)->sole();
        $this->assertSame(CompensationExceptionCategory::MissingAgreement, $exception->category);
        $this->assertTrue($exception->retry_eligible);
        $this->assertSame(1, $exception->attempt_count);
        $this->assertNull($exception->resolved_at);

        // A CURRENT agreement is not enough — the retry resolves at the
        // scheduled time, never "whatever is active when the retry runs".
        $futureOnly = InstructorCompensationAgreement::factory()->active()->create([
            'instructor_id' => $instructor->id,
            'amount_minor' => 95000,
            'effective_from' => now()->subDay(), // after the lesson start
        ]);

        $this->artisan('instructor-earnings:retry-blocked-lessons')->assertSuccessful();
        $this->assertNull($exception->fresh()->resolved_at);
        $this->assertSame(0, InstructorEarning::query()->count());

        // End that agreement and add the correctly BACKDATED one.
        $futureOnly->forceFill(['status' => CompensationAgreementStatus::Ended, 'effective_until' => now(), 'ended_at' => now()])->save();
        InstructorCompensationAgreement::factory()->active()->create([
            'instructor_id' => $instructor->id,
            'amount_minor' => 80000,
            'effective_from' => now()->subWeek(),
            'effective_until' => now()->subDay(),
        ]);

        // Attempt 2 sits behind the +2h backoff window (Phase 14.5) —
        // travel past it so the sweep picks the exception up again.
        Carbon::setTestNow(now()->addHours(3));

        $this->artisan('instructor-earnings:retry-blocked-lessons')
            ->expectsOutputToContain('recovered 1')
            ->assertSuccessful();

        $exception = $exception->fresh();
        $this->assertNotNull($exception->resolved_at);
        $earning = InstructorEarning::query()->where('lesson_id', $lesson->id)->sole();
        $this->assertSame($earning->id, $exception->resolved_earning_id);
        // Backdated agreement's rate — not the 95000 of the "current" one.
        $this->assertSame(80000, $earning->earning_amount_minor);
    }

    public function test_retry_execution_cannot_duplicate_earnings(): void
    {
        $instructor = $this->instructor();
        $lesson = $this->completedLesson($instructor, now()->subDays(2), completedAt: now()->subDay());

        $this->assertNull($this->earnings->createFromLesson($lesson)); // blocked

        InstructorCompensationAgreement::factory()->active()->create([
            'instructor_id' => $instructor->id,
            'effective_from' => now()->subMonth(),
        ]);

        $this->artisan('instructor-earnings:retry-blocked-lessons')->assertSuccessful();
        $this->artisan('instructor-earnings:retry-blocked-lessons')->assertSuccessful();
        $this->earnings->createFromLesson($lesson->fresh());

        $this->assertSame(1, InstructorEarning::query()->where('lesson_id', $lesson->id)->count());
    }

    public function test_permanent_failures_are_not_retried(): void
    {
        $instructor = $this->instructor();
        $lesson = $this->completedLesson($instructor, now()->subDays(2), completedAt: now()->subDay());

        $this->assertNull($this->earnings->createFromLesson($lesson)); // blocked: missing agreement

        // The booking then leaves the eligible state — the next attempt
        // flips the exception to permanently ineligible.
        $lesson->booking->forceFill(['status' => 'cancelled'])->save();
        $this->assertNull($this->earnings->createFromLesson($lesson->fresh()));

        $exception = InstructorCompensationException::query()->where('lesson_id', $lesson->id)->sole();
        $this->assertSame(CompensationExceptionCategory::PermanentlyIneligible, $exception->category);
        $this->assertFalse($exception->retry_eligible);

        $attempts = $exception->attempt_count;
        $this->artisan('instructor-earnings:retry-blocked-lessons')
            ->expectsOutputToContain('Retried 0 blocked lesson(s)')
            ->assertSuccessful();

        $this->assertSame($attempts, $exception->fresh()->attempt_count);
    }

    public function test_invalid_currency_and_unsupported_duration_are_categorized(): void
    {
        $instructor = $this->instructor();
        $agreement = InstructorCompensationAgreement::factory()->active()->create([
            'instructor_id' => $instructor->id,
            'currency_code' => 'XXX', // not an active currency
            'effective_from' => now()->subMonth(),
        ]);

        $lesson = $this->completedLesson($instructor, now()->subDays(2), completedAt: now()->subDay());
        $this->assertNull($this->earnings->createFromLesson($lesson));
        $this->assertSame(
            CompensationExceptionCategory::InvalidCurrency,
            InstructorCompensationException::query()->where('lesson_id', $lesson->id)->sole()->category,
        );

        $agreement->forceFill(['currency_code' => 'INR'])->save();

        // 10-hour lesson → unsupported duration.
        $marathon = $this->completedLesson($instructor, now()->subDays(2), completedAt: now()->subDay(), minutes: 600);
        $this->assertNull($this->earnings->createFromLesson($marathon));
        $this->assertSame(
            CompensationExceptionCategory::UnsupportedDuration,
            InstructorCompensationException::query()->where('lesson_id', $marathon->id)->sole()->category,
        );
    }

    // ── Activation preflight ─────────────────────────────────────────

    public function test_activation_is_blocked_while_payable_instructors_lack_agreements(): void
    {
        $this->settings(['earnings_enabled' => false]);
        $this->instructor(); // payable, no agreement

        $failures = app(CompensationActivationPreflight::class)->failures();
        $this->assertContains('missing_agreements', array_column($failures, 'check'));

        Livewire::actingAs($this->superAdmin())
            ->test(InstructorEarningSettingsPage::class)
            ->set('data.earnings_enabled', true)
            ->call('save');

        $this->assertFalse(app(InstructorEarningSettings::class)->refresh()->earnings_enabled);
    }

    public function test_activation_succeeds_once_every_preflight_condition_passes(): void
    {
        $this->settings(['earnings_enabled' => false]);

        $instructor = $this->instructor();
        InstructorCompensationAgreement::factory()->active()->create([
            'instructor_id' => $instructor->id,
            'effective_from' => now()->subMonth(),
        ]);

        $this->assertSame([], app(CompensationActivationPreflight::class)->failures());

        Livewire::actingAs($this->superAdmin())
            ->test(InstructorEarningSettingsPage::class)
            ->set('data.earnings_enabled', true)
            ->call('save');

        $this->assertTrue(app(InstructorEarningSettings::class)->refresh()->earnings_enabled);

        // Restore the safe state.
        $this->settings(['earnings_enabled' => false]);
    }

    public function test_open_exceptions_and_ungated_periodic_agreements_block_activation(): void
    {
        $this->settings(['earnings_enabled' => false]);

        $instructor = $this->instructor();
        InstructorCompensationAgreement::factory()->active()->create([
            'instructor_id' => $instructor->id,
            'effective_from' => now()->subMonth(),
        ]);

        // Open exception blocks…
        $other = $this->instructor();
        InstructorCompensationAgreement::factory()->active()->create(['instructor_id' => $other->id, 'effective_from' => now()->subMonth()]);
        $lesson = $this->completedLesson($other, now()->subDays(400), completedAt: now()->subDays(400)->addHour());
        app(CompensationExceptionService::class)
            ->record($lesson, CompensationExceptionCategory::MissingAgreement, 'Backfill needed.');

        $checks = array_column(app(CompensationActivationPreflight::class)->failures(), 'check');
        $this->assertContains('open_exceptions', $checks);

        // …and so does a periodic agreement while the gate is off.
        InstructorCompensationException::query()->update(['resolved_at' => now()]);
        InstructorCompensationAgreement::query()->where('instructor_id', $other->id)->update([
            'pay_basis' => CompensationPayBasis::Monthly->value,
        ]);

        $checks = array_column(app(CompensationActivationPreflight::class)->failures(), 'check');
        $this->assertContains('periodic_not_enabled', $checks);
    }

    // ── Periodic rollout gate ────────────────────────────────────────

    public function test_periodic_agreements_cannot_activate_while_the_gate_is_off(): void
    {
        $service = app(InstructorCompensationAgreementServiceInterface::class);
        $admin = $this->configuringAdmin();
        $instructor = $this->instructor();

        // Drafting stays possible (preparation)…
        $draft = $service->createDraft(
            $admin, $instructor, CompensationPayBasis::Monthly, 4000000, 'INR', 'Asia/Kolkata',
            new \DateTimeImmutable('2027-01-01 00:00:00', new \DateTimeZone('Asia/Kolkata')), 'Prepared for later.',
        );

        // …activation and scheduling do not.
        try {
            $service->activate($draft, $admin, 'Go live.');
            $this->fail('Periodic activation should be gated.');
        } catch (CompensationException $e) {
            $this->assertStringContainsString('not enabled', $e->getMessage());
        }

        $this->expectException(CompensationException::class);
        $service->schedule($draft, $admin);
    }

    public function test_scheduled_periodic_agreements_are_not_promoted_while_gated(): void
    {
        $instructor = $this->instructor();
        $scheduled = InstructorCompensationAgreement::factory()->daily()->create([
            'instructor_id' => $instructor->id,
            'status' => CompensationAgreementStatus::Scheduled,
            'effective_from' => now()->subWeek()->startOfDay(),
        ]);

        app(InstructorCompensationAgreementServiceInterface::class)->syncLifecycle($instructor->id, now());

        $this->assertSame(CompensationAgreementStatus::Scheduled, $scheduled->fresh()->status);
    }

    public function test_periodic_accrual_creates_nothing_while_gated(): void
    {
        InstructorCompensationAgreement::factory()->daily()->active()->create([
            'instructor_id' => $this->instructor()->id,
            'effective_from' => now()->subWeek()->startOfDay(),
        ]);

        $this->artisan('instructor-earnings:accrue-periodic-compensation')
            ->expectsOutputToContain('Accrued 0 period(s).')
            ->assertSuccessful();

        $this->assertSame(0, InstructorEarning::query()->count());
        $this->assertDatabaseHas('activity_log', ['event' => 'accrual_skipped_disabled']);
    }

    // ── Commission-absence regression (snapshot fields) ──────────────

    public function test_snapshot_carries_the_required_fields_and_no_student_values(): void
    {
        $instructor = $this->instructor();
        InstructorCompensationAgreement::factory()->active()->create([
            'instructor_id' => $instructor->id,
            'amount_minor' => 80000,
            'effective_from' => now()->subMonth(),
        ]);

        $lesson = $this->completedLesson($instructor, now()->subDays(2), completedAt: now()->subDay());
        $earning = $this->earnings->createFromLesson($lesson);

        $metadata = $earning->getAttribute('metadata');

        foreach ([
            'lesson_scheduled_start_at', 'agreement_effective_timestamp', 'agreement_id',
            'agreement_version', 'pay_basis', 'rate_minor', 'eligible_minutes',
            'rounding_policy', 'calculated_amount_minor', 'currency_id', 'currency_code',
        ] as $key) {
            $this->assertArrayHasKey($key, $metadata, $key);
        }

        $json = json_encode($metadata);
        $this->assertStringNotContainsString('student', $json);
        $this->assertStringNotContainsString('percentage', $json);
        $this->assertStringNotContainsString('margin', $json);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /** @param array<string, mixed> $overrides */
    private function settings(array $overrides): void
    {
        $this->setFinancialSettings($overrides);
    }

    private function instructor(): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('instructor');
        $user->profile->update(['instructor_status' => InstructorStatus::Active]);

        return $user;
    }

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('super_admin');

        return $admin;
    }

    private function configuringAdmin(): User
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        foreach (['Create', 'Schedule', 'Activate', 'End', 'Cancel', 'Configure'] as $verb) {
            Permission::firstOrCreate(['name' => $verb.':InstructorCompensationAgreement', 'guard_name' => 'web']);
        }

        $admin->givePermissionTo(Permission::where('name', 'like', '%InstructorCompensationAgreement')->pluck('name')->all());

        return $admin;
    }

    private function scheduledLesson(User $instructor, \DateTimeInterface $startsAt, int $minutes = 60): Lesson
    {
        $booking = Booking::factory()->confirmed()->create([
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => '499.00',
            'currency' => 'INR',
        ]);

        return Lesson::factory()->create([
            'booking_id' => $booking->id,
            'instructor_id' => $instructor->id,
            'starts_at' => $startsAt,
            'ends_at' => Carbon::instance($startsAt)->addMinutes($minutes),
        ]);
    }

    private function completedLesson(User $instructor, \DateTimeInterface $startsAt, \DateTimeInterface $completedAt, int $minutes = 60): Lesson
    {
        $booking = Booking::factory()->confirmed()->create([
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => '499.00',
            'currency' => 'INR',
        ]);

        return Lesson::factory()->completed()->create([
            'booking_id' => $booking->id,
            'instructor_id' => $instructor->id,
            'starts_at' => $startsAt,
            'ends_at' => Carbon::instance($startsAt)->addMinutes($minutes),
            'completed_at' => $completedAt,
        ]);
    }
}
