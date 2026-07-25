<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Booking\DTOs\CancelBookingData;
use App\Booking\Enums\BookingActor;
use App\Booking\Events\BookingCancelled;
use App\Events\Auth\LoginFailed;
use App\Models\Booking;
use App\Models\LoginHistory;
use App\Models\ReferralReward;
use App\Models\SuspiciousActivityFlag;
use App\Models\User;
use App\Models\Wallet;
use App\Referral\Enums\ReferralRewardStatus;
use App\Referral\Events\ReferralRewardHeld;
use App\Settings\ComplianceMonitoringSettings;
use App\Wallet\Enums\WalletLedgerDirection;
use App\Wallet\Services\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Proves the four rules are actually wired to their real integration
 * points (EventServiceProvider listener registrations, and the
 * direct WalletLedgerService::adjustment() call) — not just callable
 * in isolation. Also proves monitoring failure isolation: an
 * exception inside compliance evaluation must never roll back or
 * otherwise alter the wallet action that triggered it.
 */
class ComplianceEventWiringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => 'database/settings']);
    }

    public function test_login_failed_event_produces_a_flag_via_the_registered_listener(): void
    {
        $settings = app(ComplianceMonitoringSettings::class);
        $settings->repeated_failed_logins_threshold = 3;
        $settings->save();

        $user = User::factory()->create();
        for ($i = 0; $i < 3; $i++) {
            LoginHistory::create(['user_id' => $user->id, 'status' => 'failed', 'logged_in_at' => now()]);
        }

        LoginFailed::dispatch($user, $user->email, '127.0.0.1', 'PHPUnit', 'invalid_credentials');

        $this->assertDatabaseHas('suspicious_activity_flags', [
            'rule_code' => 'repeated_failed_logins',
            'subject_id' => $user->id,
        ]);
    }

    public function test_login_failed_event_with_unknown_user_never_creates_a_flag(): void
    {
        $settings = app(ComplianceMonitoringSettings::class);
        $settings->repeated_failed_logins_threshold = 1;
        $settings->save();

        LoginFailed::dispatch(null, 'nobody@example.test', '127.0.0.1', 'PHPUnit', 'invalid_credentials');

        $this->assertSame(0, SuspiciousActivityFlag::query()->count());
    }

    public function test_booking_cancelled_event_produces_a_flag_via_the_registered_listener(): void
    {
        $settings = app(ComplianceMonitoringSettings::class);
        $settings->excessive_booking_cancellations_threshold = 1;
        $settings->save();

        $student = User::factory()->create();
        $booking = Booking::factory()->cancelled(BookingActor::Student)->create(['student_id' => $student->id]);

        BookingCancelled::dispatch($booking, new CancelBookingData(BookingActor::Student, 'Changed my mind'));

        $this->assertDatabaseHas('suspicious_activity_flags', [
            'rule_code' => 'excessive_booking_cancellations',
            'subject_id' => $student->id,
        ]);
    }

    public function test_referral_reward_held_event_produces_a_flag_via_the_registered_listener(): void
    {
        $settings = app(ComplianceMonitoringSettings::class);
        $settings->repeated_referral_fraud_holds_threshold = 1;
        $settings->save();

        $referrer = User::factory()->create();
        $reward = ReferralReward::factory()->create(['referrer_id' => $referrer->id, 'status' => ReferralRewardStatus::Held]);

        ReferralRewardHeld::dispatch($reward->id, $referrer->id, User::factory()->create()->id);

        $this->assertDatabaseHas('suspicious_activity_flags', [
            'rule_code' => 'repeated_referral_fraud_holds',
            'subject_id' => $referrer->id,
        ]);
    }

    public function test_wallet_admin_adjustment_produces_a_flag_via_the_direct_call_hook(): void
    {
        $settings = app(ComplianceMonitoringSettings::class);
        $settings->unusual_manual_wallet_adjustments_threshold = 1;
        $settings->save();

        $admin = $this->permittedWalletAdmin();
        $wallet = Wallet::factory()->create();

        app(WalletLedgerService::class)->adjustment($wallet, 1000, WalletLedgerDirection::Credit, $admin, 'Goodwill credit');

        $this->assertDatabaseHas('suspicious_activity_flags', [
            'rule_code' => 'unusual_manual_wallet_adjustments',
            'subject_id' => $admin->id,
        ]);
    }

    public function test_a_monitoring_failure_never_rolls_back_or_alters_the_wallet_adjustment(): void
    {
        // An invalid severity setting makes the rule throw (ValueError
        // from SuspiciousActivityFlagSeverity::from()) partway through
        // evaluation — a realistic misconfiguration, not a contrived
        // double. The already-posted, already-audited wallet adjustment
        // must be completely unaffected by it.
        $settings = app(ComplianceMonitoringSettings::class);
        $settings->unusual_manual_wallet_adjustments_threshold = 1;
        $settings->unusual_manual_wallet_adjustments_severity = 'not_a_real_severity';
        $settings->save();

        $admin = $this->permittedWalletAdmin();
        $wallet = Wallet::factory()->create(['balance_minor' => 0, 'available_balance_minor' => 0]);

        $entry = app(WalletLedgerService::class)->adjustment($wallet, 1500, WalletLedgerDirection::Credit, $admin, 'Goodwill credit');

        $this->assertNotNull($entry);
        $this->assertSame(1500, $wallet->fresh()->balance_minor);
        $this->assertSame(0, SuspiciousActivityFlag::query()->count());
    }

    private function permittedWalletAdmin(): User
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $admin->assignRole('manager');
        Permission::firstOrCreate(['name' => 'Manage:Wallet', 'guard_name' => 'web']);
        $admin->givePermissionTo('Manage:Wallet');

        return $admin;
    }
}
