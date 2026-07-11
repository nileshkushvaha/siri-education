<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings;

use App\Booking\Enums\BookingPaymentStatus;
use App\Earnings\Contracts\InstructorCompensationAgreementServiceInterface;
use App\Earnings\Contracts\InstructorEarningServiceInterface;
use App\Earnings\Contracts\InstructorWithdrawalBalanceServiceInterface;
use App\Earnings\Contracts\InstructorWithdrawalServiceInterface;
use App\Earnings\Enums\CompensationPayBasis;
use App\Earnings\Enums\InstructorEarningStatus;
use App\Earnings\Enums\InstructorWithdrawalStatus;
use App\Earnings\Enums\WithdrawalAllocationStatus;
use App\Earnings\Exceptions\WithdrawalException;
use App\Earnings\Services\CompensationActivationPreflight;
use App\Enums\InstructorStatus;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\InstructorCompensationAgreement;
use App\Models\InstructorEarning;
use App\Models\InstructorPayoutMethod;
use App\Models\User;
use App\Settings\InstructorEarningSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\ManagesFinancialSettings;
use Tests\TestCase;

/**
 * Phase 14.4 — the complete lesson-to-withdrawal integration in ONE
 * test, exercising every domain boundary in sequence through the real
 * services: agreement (admin decision) → preflight → enable → paid
 * lesson completion → agreement resolution → earning → hold → release →
 * available balance → withdrawal reservation. No external money moves
 * at any point; the final state is a reserved, approved-ready request.
 */
class FinancialGoldenPathTest extends TestCase
{
    use ManagesFinancialSettings;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_hourly_agreement_golden_path_from_admin_decision_to_reserved_withdrawal(): void
    {
        $settings = app(InstructorEarningSettings::class);

        // 0. Everything ships disabled.
        $this->assertFalse($settings->earnings_enabled);
        $this->assertFalse($settings->withdrawals_enabled);
        $this->assertFalse($settings->periodic_compensation_enabled);

        // 1. Admin decides and activates the instructor's hourly agreement.
        $admin = $this->admin();
        $instructor = $this->instructor();

        $agreements = app(InstructorCompensationAgreementServiceInterface::class);
        $agreement = $agreements->createDraft(
            $admin, $instructor, CompensationPayBasis::Hourly, 80000, 'INR', 'Asia/Kolkata',
            now()->subDay(), 'Senior physics instructor — onboarding review.',
        );
        $agreements->activate($agreement, $admin, 'Rate confirmed.');

        // 2. The activation preflight passes, then (and only then)
        //    earnings are enabled — the exact operational sequence.
        $this->assertSame([], app(CompensationActivationPreflight::class)->failures());
        $this->setFinancialSettings(['earnings_enabled' => true]);

        // 3. A paid 90-minute lesson completes; the earning derives from
        //    the agreement at the scheduled start — never from the price.
        $booking = Booking::factory()->confirmed()->create([
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => '2499.00',
            'currency' => 'INR',
        ]);

        $lessons = app(LessonLifecycleServiceInterface::class);
        $lesson = $lessons->createFromBooking($booking);
        $lesson->forceFill([
            'instructor_id' => $instructor->id,
            'starts_at' => now()->subHours(3),
            'ends_at' => now()->subHours(3)->addMinutes(90),
        ])->save();
        $lessons->complete($lesson->fresh(), override: true);

        $earning = InstructorEarning::query()->where('lesson_id', $lesson->id)->sole();
        $this->assertSame(120000, $earning->earning_amount_minor); // 80000 × 90/60
        $this->assertSame(InstructorEarningStatus::PendingHold, $earning->status);
        $this->assertSame($agreement->id, $earning->getAttribute('metadata')['agreement_id']);

        // 4. The hold window lapses; the release sweep promotes it.
        Carbon::setTestNow(now()->addDays(8));
        $this->artisan('instructor-earnings:release')->assertSuccessful();
        $this->assertSame(InstructorEarningStatus::Releasable, $earning->fresh()->status);

        // 5. The released amount is the instructor's available balance…
        $balance = app(InstructorWithdrawalBalanceServiceInterface::class)->calculate($instructor, 'INR');
        $this->assertSame(120000, $balance->availableMinor);

        // 6. …and a withdrawal request reserves it end to end.
        $this->setFinancialSettings(['withdrawals_enabled' => true, 'minimum_withdrawal_minor' => 10000]);

        $method = InstructorPayoutMethod::factory()->verified()->create([
            'instructor_id' => $instructor->id,
            'currency_code' => 'INR',
            'currency_id' => Currency::query()->where('code', 'INR')->value('id'),
        ]);

        $request = app(InstructorWithdrawalServiceInterface::class)
            ->requestWithdrawal($instructor, $method, 120000);

        $this->assertSame(InstructorWithdrawalStatus::Submitted, $request->status);
        $this->assertSame(120000, (int) $request->allocations()->where('status', WithdrawalAllocationStatus::Reserved)->sum('amount_minor'));
        $this->assertSame(0, app(InstructorWithdrawalBalanceServiceInterface::class)->calculate($instructor, 'INR')->availableMinor);

        // 7. The reserved earning left the settlement pool — one path only.
        $this->assertFalse(InstructorEarning::query()->settleable()->whereKey($earning->id)->exists());

        // Restore the safe development state.
        $this->setFinancialSettings(['earnings_enabled' => false, 'withdrawals_enabled' => false]);
    }

    public function test_all_switches_disabled_means_no_financial_activity_anywhere(): void
    {
        $instructor = $this->instructor();

        InstructorCompensationAgreement::factory()->active()->create([
            'instructor_id' => $instructor->id,
            'effective_from' => now()->subMonth(),
        ]);

        // A completed paid lesson creates nothing…
        $booking = Booking::factory()->confirmed()->create([
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => '499.00',
            'currency' => 'INR',
        ]);
        $lessons = app(LessonLifecycleServiceInterface::class);
        $lesson = $lessons->createFromBooking($booking);
        $lessons->complete($lesson, override: true);

        $this->assertSame(0, InstructorEarning::query()->count());
        $this->assertNull(app(InstructorEarningServiceInterface::class)->createFromLesson($lesson->fresh()));

        // …periodic accrual creates nothing…
        $this->artisan('instructor-earnings:accrue-periodic-compensation')
            ->expectsOutputToContain('Accrued 0 period(s).')
            ->assertSuccessful();

        // …and no withdrawal can be requested.
        $method = InstructorPayoutMethod::factory()->verified()->create([
            'instructor_id' => $instructor->id,
            'currency_code' => 'INR',
        ]);

        $this->expectException(WithdrawalException::class);
        app(InstructorWithdrawalServiceInterface::class)->requestWithdrawal($instructor, $method, 20000);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function instructor(): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('instructor');
        $user->profile->update(['instructor_status' => InstructorStatus::Active]);

        return $user;
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        foreach (['Create', 'Activate', 'Configure'] as $verb) {
            Permission::firstOrCreate(['name' => $verb.':InstructorCompensationAgreement', 'guard_name' => 'web']);
        }
        $admin->givePermissionTo(['Create:InstructorCompensationAgreement', 'Activate:InstructorCompensationAgreement', 'Configure:InstructorCompensationAgreement']);

        return $admin;
    }
}
