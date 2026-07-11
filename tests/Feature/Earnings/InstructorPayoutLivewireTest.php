<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings;

use App\Earnings\Enums\InstructorEarningStatus;
use App\Earnings\Enums\InstructorWithdrawalStatus;
use App\Earnings\Enums\PayoutMethodStatus;
use App\Enums\InstructorStatus;
use App\Livewire\Frontend\Instructor\PayoutMethodsManager;
use App\Livewire\Frontend\Instructor\WithdrawalsManager;
use App\Models\Currency;
use App\Models\InstructorEarning;
use App\Models\InstructorPayoutMethod;
use App\Models\InstructorWithdrawalRequest;
use App\Models\User;
use App\Settings\InstructorEarningSettings;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InstructorPayoutLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);

        $settings = app(InstructorEarningSettings::class);
        $settings->withdrawals_enabled = true;
        $settings->minimum_withdrawal_minor = 10000;
        $settings->save();
    }

    // ── Route protection ─────────────────────────────────────────────

    public function test_pages_require_authentication(): void
    {
        $this->get(route('dashboard.instructor.payout-methods'))->assertRedirect();
        $this->get(route('dashboard.instructor.withdrawals'))->assertRedirect();
    }

    public function test_pages_require_the_instructor_role(): void
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');

        $this->actingAs($student)->get(route('dashboard.instructor.payout-methods'))->assertForbidden();
        $this->actingAs($student)->get(route('dashboard.instructor.withdrawals'))->assertForbidden();
    }

    public function test_pages_render_for_instructors(): void
    {
        $instructor = $this->makeInstructor();

        $this->actingAs($instructor)->get(route('dashboard.instructor.payout-methods'))->assertOk();
        $this->actingAs($instructor)->get(route('dashboard.instructor.withdrawals'))->assertOk();
    }

    // ── Payout methods component ─────────────────────────────────────

    public function test_instructor_creates_a_draft_through_the_component(): void
    {
        $instructor = $this->makeInstructor();

        Livewire::actingAs($instructor)
            ->test(PayoutMethodsManager::class)
            ->call('startCreate')
            ->set('account_holder_name', 'Asha Instructor')
            ->set('bank_name', 'Test Bank')
            ->set('account_number', '9998887774821')
            ->set('routing_number', 'TEST0001234')
            ->set('currency_code', 'INR')
            ->call('save')
            ->assertHasNoErrors()
            // Sensitive input must not survive in component state.
            ->assertSet('account_holder_name', '')
            ->assertSet('account_number', '')
            ->assertSet('routing_number', '');

        $method = InstructorPayoutMethod::query()->where('instructor_id', $instructor->id)->firstOrFail();

        $this->assertSame(PayoutMethodStatus::Draft, $method->status);
        $this->assertSame('Account ending in 4821', $method->masked_identifier);
    }

    public function test_editing_never_repopulates_stored_sensitive_values(): void
    {
        $instructor = $this->makeInstructor();
        $method = InstructorPayoutMethod::factory()->rejected()->create(['instructor_id' => $instructor->id]);

        Livewire::actingAs($instructor)
            ->test(PayoutMethodsManager::class)
            ->call('startEdit', $method->id)
            ->assertSet('editingMethodId', $method->id)
            ->assertSet('account_number', '')
            ->assertSet('account_holder_name', '')
            ->assertSet('iban', '');
    }

    public function test_component_blocks_foreign_methods(): void
    {
        $foreign = InstructorPayoutMethod::factory()->create();
        $instructor = $this->makeInstructor();

        // Ownership-scoped lookup: a foreign ID is indistinguishable from
        // a missing record.
        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($instructor)
            ->test(PayoutMethodsManager::class)
            ->call('startEdit', $foreign->id);
    }

    public function test_component_requires_valid_input(): void
    {
        $instructor = $this->makeInstructor();

        Livewire::actingAs($instructor)
            ->test(PayoutMethodsManager::class)
            ->call('startCreate')
            ->set('currency_code', 'INR')
            ->call('save')
            ->assertHasErrors(['account_holder_name']);
    }

    public function test_submit_and_disable_flow_through_the_component(): void
    {
        $instructor = $this->makeInstructor();
        $method = InstructorPayoutMethod::factory()->create(['instructor_id' => $instructor->id]);

        Livewire::actingAs($instructor)
            ->test(PayoutMethodsManager::class)
            ->call('submitForVerification', $method->id)
            ->assertHasNoErrors();

        $this->assertSame(PayoutMethodStatus::PendingVerification, $method->fresh()->status);

        Livewire::actingAs($instructor)
            ->test(PayoutMethodsManager::class)
            ->call('disableMethod', $method->id)
            ->assertHasNoErrors();

        $this->assertSame(PayoutMethodStatus::Disabled, $method->fresh()->status);
    }

    // ── Withdrawals component ────────────────────────────────────────

    public function test_withdrawal_flow_creates_a_request_via_the_service(): void
    {
        $instructor = $this->makeInstructor();
        $this->releasableEarning($instructor, 50000);
        $method = $this->verifiedMethod($instructor);

        Livewire::actingAs($instructor)
            ->test(WithdrawalsManager::class)
            ->call('openForm')
            ->assertSet('payoutMethodId', $method->id)
            ->set('amount', '300.00')
            ->call('confirm')
            ->assertSet('confirming', true)
            ->call('submit')
            ->assertHasNoErrors();

        $request = InstructorWithdrawalRequest::query()->where('instructor_id', $instructor->id)->firstOrFail();

        $this->assertSame(30000, $request->amount_minor);
        $this->assertSame(InstructorWithdrawalStatus::Submitted, $request->status);
    }

    public function test_double_submission_with_one_form_creates_one_request(): void
    {
        $instructor = $this->makeInstructor();
        $this->releasableEarning($instructor, 100000);
        $this->verifiedMethod($instructor);

        $settings = app(InstructorEarningSettings::class);
        $settings->maximum_active_requests_per_instructor = 5;
        $settings->save();

        $component = Livewire::actingAs($instructor)
            ->test(WithdrawalsManager::class)
            ->call('openForm')
            ->set('amount', '200.00')
            ->call('confirm')
            ->call('submit');

        // Replay the same (already-used) form state — the idempotency key
        // was consumed, so no second request may appear.
        $component->set('amount', '200.00')->call('submit');

        $this->assertSame(1, InstructorWithdrawalRequest::query()->count());
    }

    public function test_browser_supplied_balance_is_ignored(): void
    {
        $instructor = $this->makeInstructor();
        $this->releasableEarning($instructor, 20000);
        $this->verifiedMethod($instructor);

        Livewire::actingAs($instructor)
            ->test(WithdrawalsManager::class)
            ->call('openForm')
            ->set('amount', '999.00')
            ->call('confirm')
            ->call('submit')
            ->assertHasErrors(['form']);

        $this->assertSame(0, InstructorWithdrawalRequest::query()->count());
    }

    public function test_instructor_cancels_own_request_but_not_others(): void
    {
        $instructor = $this->makeInstructor();
        $own = InstructorWithdrawalRequest::factory()->create(['instructor_id' => $instructor->id]);
        $foreign = InstructorWithdrawalRequest::factory()->create();

        Livewire::actingAs($instructor)
            ->test(WithdrawalsManager::class)
            ->call('cancelRequest', $own->id)
            ->assertHasNoErrors();

        $this->assertSame(InstructorWithdrawalStatus::Cancelled, $own->fresh()->status);

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($instructor)
            ->test(WithdrawalsManager::class)
            ->call('cancelRequest', $foreign->id);
    }

    public function test_component_state_never_contains_decrypted_bank_details(): void
    {
        $instructor = $this->makeInstructor();
        $this->releasableEarning($instructor, 50000);
        $method = $this->verifiedMethod($instructor);
        $accountNumber = $method->encrypted_details['account_number'];

        Livewire::actingAs($instructor)
            ->test(WithdrawalsManager::class)
            ->call('openForm')
            ->assertDontSee($accountNumber);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function makeInstructor(): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('instructor');
        $user->profile->update(['instructor_status' => InstructorStatus::Active]);

        return $user;
    }

    private function verifiedMethod(User $instructor): InstructorPayoutMethod
    {
        return InstructorPayoutMethod::factory()->verified()->default()->create([
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
            'status' => InstructorEarningStatus::Releasable,
        ]);
    }
}
