<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings;

use App\Earnings\Contracts\InstructorWithdrawalServiceInterface;
use App\Earnings\Exceptions\WithdrawalException;
use App\Earnings\Support\FinancialFeatureToggle;
use App\Enums\InstructorStatus;
use App\Models\Activity;
use App\Models\Currency;
use App\Models\InstructorEarning;
use App\Models\InstructorPayoutMethod;
use App\Models\InstructorWithdrawalRequest;
use App\Models\User;
use App\Settings\InstructorEarningSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The idempotency-key lifecycle: instructor-bound, payload-bound (a
 * replay with altered input is a conflict, never a
 * silent success), invisible in serialization and audit metadata, and
 * reusable after a rolled-back attempt.
 */
class WithdrawalIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private InstructorWithdrawalServiceInterface $withdrawals;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withdrawals = app(InstructorWithdrawalServiceInterface::class);

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);

        $settings = app(InstructorEarningSettings::class);
        $settings->withdrawals_enabled = true;
        $settings->minimum_withdrawal_minor = 10000;
        $settings->maximum_active_requests_per_instructor = 5;
        FinancialFeatureToggle::unguarded(fn () => $settings->save());
    }

    public function test_identical_replay_returns_the_original_request(): void
    {
        [$instructor, $method] = $this->instructorWithFunds(100000);
        $key = (string) Str::uuid();

        $first = $this->withdrawals->requestWithdrawal($instructor, $method, 20000, null, $key);
        $replay = $this->withdrawals->requestWithdrawal($instructor, $method, 20000, null, $key);

        $this->assertSame($first->id, $replay->id);
        $this->assertSame(1, InstructorWithdrawalRequest::query()->count());
    }

    public function test_replay_with_a_different_amount_is_a_conflict(): void
    {
        [$instructor, $method] = $this->instructorWithFunds(100000);
        $key = (string) Str::uuid();

        $this->withdrawals->requestWithdrawal($instructor, $method, 20000, null, $key);

        $this->expectException(WithdrawalException::class);
        $this->expectExceptionMessage('different details');

        $this->withdrawals->requestWithdrawal($instructor, $method, 30000, null, $key);
    }

    public function test_replay_with_a_different_payout_method_is_a_conflict(): void
    {
        [$instructor, $method] = $this->instructorWithFunds(100000);
        $otherMethod = $this->verifiedMethod($instructor);
        $key = (string) Str::uuid();

        $this->withdrawals->requestWithdrawal($instructor, $method, 20000, null, $key);

        $this->expectException(WithdrawalException::class);
        $this->expectExceptionMessage('different details');

        $this->withdrawals->requestWithdrawal($instructor, $otherMethod, 20000, null, $key);
    }

    public function test_the_key_is_scoped_to_the_instructor(): void
    {
        [$instructorA, $methodA] = $this->instructorWithFunds(100000);
        [$instructorB, $methodB] = $this->instructorWithFunds(100000);
        $sharedKey = (string) Str::uuid();

        $a = $this->withdrawals->requestWithdrawal($instructorA, $methodA, 20000, null, $sharedKey);
        $b = $this->withdrawals->requestWithdrawal($instructorB, $methodB, 20000, null, $sharedKey);

        // Same key, different instructors — two independent requests.
        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(2, InstructorWithdrawalRequest::query()->count());
    }

    public function test_a_rolled_back_attempt_leaves_the_key_reusable(): void
    {
        [$instructor, $method] = $this->instructorWithFunds(15000);
        $key = (string) Str::uuid();

        try {
            $this->withdrawals->requestWithdrawal($instructor, $method, 99000, null, $key);
            $this->fail('Over-balance request should have failed.');
        } catch (WithdrawalException) {
        }

        // The failed attempt persisted nothing, so the same key backs the
        // corrected retry.
        $this->releasableEarning($instructor, 100000);
        $request = $this->withdrawals->requestWithdrawal($instructor, $method, 20000, null, $key);

        $this->assertSame(20000, $request->amount_minor);
        $this->assertSame(1, InstructorWithdrawalRequest::query()->count());
    }

    public function test_the_key_never_appears_in_serialization_or_audit_metadata(): void
    {
        [$instructor, $method] = $this->instructorWithFunds(100000);
        $key = (string) Str::uuid();

        $request = $this->withdrawals->requestWithdrawal($instructor, $method, 20000, null, $key);

        $this->assertStringNotContainsString($key, json_encode($request->fresh()->toArray()));

        Activity::query()
            ->where('log_name', 'instructor_payouts')
            ->get()
            ->each(function (Activity $activity) use ($key): void {
                $this->assertStringNotContainsString($key, json_encode($activity->properties));
                $this->assertStringNotContainsString($key, (string) $activity->description);
            });
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /** @return array{0: User, 1: InstructorPayoutMethod} */
    private function instructorWithFunds(int $amountMinor): array
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('instructor');
        $user->profile->update(['instructor_status' => InstructorStatus::Active]);

        $this->releasableEarning($user, $amountMinor);

        return [$user, $this->verifiedMethod($user)];
    }

    private function verifiedMethod(User $instructor): InstructorPayoutMethod
    {
        return InstructorPayoutMethod::factory()->verified()->create([
            'instructor_id' => $instructor->id,
            'currency_code' => 'INR',
            'currency_id' => Currency::query()->where('code', 'INR')->value('id'),
        ]);
    }

    private function releasableEarning(User $instructor, int $amountMinor): InstructorEarning
    {
        return InstructorEarning::factory()->releasable()->create([
            'instructor_id' => $instructor->id,
            'earning_amount_minor' => $amountMinor,
            'currency_code' => 'INR',
        ]);
    }
}
