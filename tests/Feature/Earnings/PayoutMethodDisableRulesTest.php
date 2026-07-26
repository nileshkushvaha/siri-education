<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings;

use App\Earnings\Contracts\InstructorPayoutMethodServiceInterface;
use App\Earnings\Contracts\InstructorWithdrawalServiceInterface;
use App\Earnings\Enums\InstructorWithdrawalStatus;
use App\Earnings\Enums\PayoutMethodStatus;
use App\Earnings\Exceptions\PayoutMethodException;
use App\Earnings\Support\FinancialFeatureToggle;
use App\Enums\InstructorStatus;
use App\Models\Currency;
use App\Models\InstructorEarning;
use App\Models\InstructorPayoutMethod;
use App\Models\InstructorWithdrawalRequest;
use App\Models\User;
use App\Settings\InstructorEarningSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A payout method must never be silently disabled while an active
 * withdrawal (submitted / under_review / approved /
 * processing) depends on it, immutable snapshot or not: a method may be
 * disabled precisely because the destination is compromised, and the
 * pending payment must be resolved first, explicitly.
 */
class PayoutMethodDisableRulesTest extends TestCase
{
    use RefreshDatabase;

    private InstructorPayoutMethodServiceInterface $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(InstructorPayoutMethodServiceInterface::class);

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);
    }

    public function test_draft_and_rejected_methods_can_be_disabled(): void
    {
        $instructor = $this->makeInstructor();

        $draft = InstructorPayoutMethod::factory()->create(['instructor_id' => $instructor->id]);
        $rejected = InstructorPayoutMethod::factory()->rejected()->create(['instructor_id' => $instructor->id]);

        $this->assertSame(PayoutMethodStatus::Disabled, $this->service->disable($draft, $instructor)->status);
        $this->assertSame(PayoutMethodStatus::Disabled, $this->service->disable($rejected, $instructor)->status);
    }

    public function test_method_with_submitted_withdrawal_cannot_be_disabled(): void
    {
        [$instructor, $method] = $this->methodWithWithdrawal(InstructorWithdrawalStatus::Submitted);

        $this->expectException(PayoutMethodException::class);
        $this->expectExceptionMessage('active withdrawal');

        $this->service->disable($method, $instructor);
    }

    public function test_method_with_under_review_withdrawal_cannot_be_disabled(): void
    {
        [$instructor, $method] = $this->methodWithWithdrawal(InstructorWithdrawalStatus::UnderReview);

        $this->expectException(PayoutMethodException::class);

        $this->service->disable($method, $instructor);
    }

    public function test_method_with_approved_withdrawal_cannot_be_disabled(): void
    {
        [$instructor, $method] = $this->methodWithWithdrawal(InstructorWithdrawalStatus::Approved);

        $this->expectException(PayoutMethodException::class);

        $this->service->disable($method, $instructor);
    }

    public function test_method_with_processing_withdrawal_cannot_be_disabled(): void
    {
        // `processing` is reached via the admin's "Queue for Execution"
        // action; this rule must hold there too.
        [$instructor, $method] = $this->methodWithWithdrawal(InstructorWithdrawalStatus::Processing);

        $this->expectException(PayoutMethodException::class);

        $this->service->disable($method, $instructor);
    }

    public function test_method_with_only_terminal_withdrawal_history_can_be_disabled(): void
    {
        $instructor = $this->makeInstructor();
        $method = InstructorPayoutMethod::factory()->verified()->create(['instructor_id' => $instructor->id]);

        InstructorWithdrawalRequest::factory()->create([
            'instructor_id' => $instructor->id,
            'payout_method_id' => $method->id,
            'status' => InstructorWithdrawalStatus::Rejected,
        ]);
        InstructorWithdrawalRequest::factory()->create([
            'instructor_id' => $instructor->id,
            'payout_method_id' => $method->id,
            'status' => InstructorWithdrawalStatus::Cancelled,
        ]);

        $disabled = $this->service->disable($method, $instructor);

        $this->assertSame(PayoutMethodStatus::Disabled, $disabled->status);
    }

    public function test_disabling_the_default_clears_it_and_keeps_the_default_invariant(): void
    {
        $instructor = $this->makeInstructor();
        $default = InstructorPayoutMethod::factory()->verified()->default()->create(['instructor_id' => $instructor->id]);
        $other = InstructorPayoutMethod::factory()->verified()->create(['instructor_id' => $instructor->id]);

        $this->service->disable($default, $instructor);

        $this->assertFalse($default->fresh()->is_default);
        $this->assertSame(0, InstructorPayoutMethod::query()->forInstructor($instructor->id)->where('is_default', true)->count());

        // A remaining verified method can now take over as default.
        $this->service->setDefault($other, $instructor);
        $this->assertSame(1, InstructorPayoutMethod::query()->forInstructor($instructor->id)->where('is_default', true)->count());
    }

    public function test_blocked_disable_message_carries_no_bank_information(): void
    {
        [$instructor, $method] = $this->methodWithWithdrawal(InstructorWithdrawalStatus::Submitted);
        $accountNumber = $method->encrypted_details['account_number'];

        try {
            $this->service->disable($method, $instructor);
            $this->fail('Disable should have been blocked.');
        } catch (PayoutMethodException $e) {
            $this->assertStringNotContainsString($accountNumber, $e->getMessage());
        }
    }

    /** Route the real creation path where possible; force only the status. */
    private function methodWithWithdrawal(InstructorWithdrawalStatus $status): array
    {
        $instructor = $this->makeInstructor();
        $method = InstructorPayoutMethod::factory()->verified()->create([
            'instructor_id' => $instructor->id,
            'currency_code' => 'INR',
            'currency_id' => Currency::query()->where('code', 'INR')->value('id'),
        ]);

        $settings = app(InstructorEarningSettings::class);
        $settings->withdrawals_enabled = true;
        $settings->minimum_withdrawal_minor = 10000;
        FinancialFeatureToggle::unguarded(fn () => $settings->save());

        InstructorEarning::factory()->releasable()->create([
            'instructor_id' => $instructor->id,
            'earning_amount_minor' => 50000,
            'currency_code' => 'INR',
        ]);

        $request = app(InstructorWithdrawalServiceInterface::class)
            ->requestWithdrawal($instructor, $method, 30000);

        if ($status !== InstructorWithdrawalStatus::Submitted) {
            $request->forceFill(['status' => $status])->save();
        }

        return [$instructor, $method->fresh()];
    }

    private function makeInstructor(): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('instructor');
        $user->profile->update(['instructor_status' => InstructorStatus::Active]);

        return $user;
    }
}
