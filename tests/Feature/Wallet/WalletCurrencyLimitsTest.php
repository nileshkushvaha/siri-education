<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use App\Filament\Pages\Settings\WalletSettingsPage;
use App\Livewire\Frontend\Student\WalletOverview;
use App\Models\Currency;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Wallet;
use App\Settings\FeatureSettings;
use App\Wallet\Exceptions\WalletException;
use App\Wallet\Services\WalletCurrencyLimitService;
use App\Wallet\Services\WalletRechargeAmountPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\EstablishesRechargeMarket;
use Tests\TestCase;

/**
 * Per-currency wallet rules (Settings → Wallet): recharge minimum,
 * maximum, step ("multiples of 10") and the student low-balance alert.
 */
class WalletCurrencyLimitsTest extends TestCase
{
    use EstablishesRechargeMarket;
    use RefreshDatabase;

    private Currency $inr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--path' => 'database/settings']);

        $this->inr = Currency::query()->updateOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
            'minimum_recharge_minor' => 50000,
            'maximum_recharge_minor' => null,
            'recharge_multiple_minor' => 1000,
            'low_balance_threshold_minor' => 50000,
        ]);

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    }

    // ── Amount policy ────────────────────────────────────────────────────

    public function test_amount_below_minimum_is_refused(): void
    {
        $this->expectException(WalletException::class);
        $this->expectExceptionMessage('The minimum recharge amount is 500.00 INR.');

        app(WalletRechargeAmountPolicy::class)->assert(10000, 'INR');
    }

    public function test_amount_not_a_multiple_of_the_step_is_refused(): void
    {
        $this->expectException(WalletException::class);
        $this->expectExceptionMessage('Recharge amounts must be in multiples of 10.00 INR.');

        app(WalletRechargeAmountPolicy::class)->assert(50500, 'INR');
    }

    public function test_amount_above_maximum_is_refused(): void
    {
        $this->inr->update(['maximum_recharge_minor' => 100000]);

        $this->expectException(WalletException::class);
        $this->expectExceptionMessage('The maximum recharge amount is 1,000.00 INR.');

        app(WalletRechargeAmountPolicy::class)->assert(101000, 'INR');
    }

    public function test_valid_multiple_within_limits_passes(): void
    {
        app(WalletRechargeAmountPolicy::class)->assert(51000, 'INR');
        app(WalletRechargeAmountPolicy::class)->assert(50000, 'INR');

        $this->addToAssertionCount(2);
    }

    public function test_blank_step_means_any_amount(): void
    {
        $this->inr->update(['recharge_multiple_minor' => null]);

        app(WalletRechargeAmountPolicy::class)->assert(50501, 'INR');

        $this->addToAssertionCount(1);
    }

    // ── Admin service ────────────────────────────────────────────────────

    public function test_service_stores_major_amounts_as_minor_units_and_audits(): void
    {
        $admin = $this->superAdmin();

        $changed = app(WalletCurrencyLimitService::class)->update($admin, [
            $this->inr->id => [
                'minimum_recharge_minor' => '250',
                'maximum_recharge_minor' => '20000',
                'recharge_multiple_minor' => '50',
                'low_balance_threshold_minor' => '100.50',
            ],
        ]);

        $this->assertSame(1, $changed);

        $fresh = $this->inr->fresh();
        $this->assertSame(25000, $fresh->minimum_recharge_minor);
        $this->assertSame(2000000, $fresh->maximum_recharge_minor);
        $this->assertSame(5000, $fresh->recharge_multiple_minor);
        $this->assertSame(10050, $fresh->low_balance_threshold_minor);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'settings',
            'event' => 'wallet_limits_updated',
            'causer_id' => $admin->id,
            'subject_id' => $this->inr->id,
        ]);
    }

    public function test_service_rejects_maximum_below_minimum(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(WalletCurrencyLimitService::class)->update($this->superAdmin(), [
            $this->inr->id => ['minimum_recharge_minor' => '500', 'maximum_recharge_minor' => '100'],
        ]);
    }

    public function test_service_rejects_zero_step(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(WalletCurrencyLimitService::class)->update($this->superAdmin(), [
            $this->inr->id => ['recharge_multiple_minor' => '0'],
        ]);
    }

    public function test_unchanged_save_writes_no_audit_event(): void
    {
        $changed = app(WalletCurrencyLimitService::class)->update($this->superAdmin(), [
            $this->inr->id => ['minimum_recharge_minor' => '500.00', 'recharge_multiple_minor' => '10'],
        ]);

        $this->assertSame(0, $changed);
        $this->assertDatabaseMissing('activity_log', ['event' => 'wallet_limits_updated']);
    }

    // ── Settings page ────────────────────────────────────────────────────

    public function test_manager_with_wallet_settings_permission_can_save_from_the_page(): void
    {
        Permission::firstOrCreate(['name' => 'settings.wallet.update', 'guard_name' => 'web']);
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE, 'email_verified_at' => now()]);
        $manager->assignRole('manager');
        $manager->givePermissionTo('settings.wallet.update');

        Livewire::actingAs($manager)
            ->test(WalletSettingsPage::class)
            ->assertSet("data.limits.{$this->inr->id}.minimum_recharge_minor", '500.00')
            ->set("data.limits.{$this->inr->id}.recharge_multiple_minor", '20')
            ->set("data.limits.{$this->inr->id}.low_balance_threshold_minor", '750')
            ->call('save')
            ->assertHasNoErrors()
            ->assertNotified('Wallet settings saved');

        $fresh = $this->inr->fresh();
        $this->assertSame(2000, $fresh->recharge_multiple_minor);
        $this->assertSame(75000, $fresh->low_balance_threshold_minor);
    }

    public function test_student_cannot_open_wallet_settings(): void
    {
        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);

        $this->assertFalse(WalletSettingsPage::canAccess());
        $this->actingAs($student);
        $this->assertFalse(WalletSettingsPage::canAccess());
    }

    // ── Student alert ────────────────────────────────────────────────────

    public function test_student_sees_low_balance_alert_below_the_currency_threshold(): void
    {
        app(FeatureSettings::class)->wallet_enabled = true;
        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        $this->wallet($student, availableMinor: 20000);

        Livewire::actingAs($student)
            ->test(WalletOverview::class)
            ->assertSee('Your balance is running low')
            ->assertSee('below 500.00 INR');
    }

    public function test_no_alert_at_or_above_the_threshold_or_when_unconfigured(): void
    {
        app(FeatureSettings::class)->wallet_enabled = true;
        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        $this->wallet($student, availableMinor: 50000);

        Livewire::actingAs($student)->test(WalletOverview::class)->assertDontSee('Your balance is running low');

        // A genuinely low wallet, but the currency has no threshold configured.
        $other = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        $this->wallet($other, availableMinor: 100);
        $this->inr->update(['low_balance_threshold_minor' => null]);

        Livewire::actingAs($other)->test(WalletOverview::class)->assertDontSee('Your balance is running low');
    }

    public function test_recharge_hint_advertises_the_step(): void
    {
        app(FeatureSettings::class)->wallet_enabled = true;
        $india = $this->establishRechargeMarket('IN', 'INR', numericCode: '356');
        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $student->id], ['country_id' => $india->id]);
        $this->wallet($student->refresh(), availableMinor: 100000);

        Livewire::actingAs($student)->test(WalletOverview::class)->assertSee('In multiples of 10.00 INR');
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function superAdmin(): User
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE, 'email_verified_at' => now()]);
        $admin->assignRole('super_admin');

        return $admin;
    }

    private function wallet(User $student, int $availableMinor): Wallet
    {
        return Wallet::query()->create([
            'user_id' => $student->id,
            'currency_id' => $this->inr->id,
            'currency_code' => 'INR',
            'balance_minor' => $availableMinor,
            'available_balance_minor' => $availableMinor,
            'held_balance_minor' => 0,
            'status' => 'active',
        ]);
    }
}
