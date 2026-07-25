<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Booking\Enums\BookingActor;
use App\Compliance\Rules\ExcessiveBookingCancellationsRule;
use App\Compliance\Rules\RepeatedFailedLoginsRule;
use App\Compliance\Rules\RepeatedReferralFraudHoldsRule;
use App\Compliance\Rules\UnusualManualWalletAdjustmentsRule;
use App\Models\Booking;
use App\Models\LoginHistory;
use App\Models\ReferralReward;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Referral\Enums\ReferralRewardStatus;
use App\Settings\ComplianceMonitoringSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SRS §9.13/§9.14/§24.25/§24.26 (GAP-014/GAP-015): every rule fires
 * only at/above its threshold, never below, and never at all while
 * disabled. Each rule is exercised directly against the exact
 * pre-existing table it counts against — no new tracking table is
 * introduced by these tests.
 */
class ComplianceRuleEvaluationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => 'database/settings']);
    }

    // ── Repeated failed logins (Auth) ───────────────────────────────────

    public function test_repeated_failed_logins_does_not_fire_below_threshold(): void
    {
        $settings = app(ComplianceMonitoringSettings::class);
        $settings->repeated_failed_logins_threshold = 5;
        $settings->save();

        $user = User::factory()->create();
        $this->seedFailedLogins($user, 4);

        $signal = app(RepeatedFailedLoginsRule::class)->evaluate($user);

        $this->assertNull($signal);
    }

    public function test_repeated_failed_logins_fires_at_threshold(): void
    {
        $settings = app(ComplianceMonitoringSettings::class);
        $settings->repeated_failed_logins_threshold = 5;
        $settings->save();

        $user = User::factory()->create();
        $this->seedFailedLogins($user, 5);

        $signal = app(RepeatedFailedLoginsRule::class)->evaluate($user);

        $this->assertNotNull($signal);
        $this->assertSame($user->id, $signal->subjectId);
        $this->assertSame(5, $signal->evidence['failed_login_count']);
    }

    public function test_repeated_failed_logins_rule_is_a_no_op_when_disabled(): void
    {
        $settings = app(ComplianceMonitoringSettings::class);
        $settings->repeated_failed_logins_enabled = false;
        $settings->repeated_failed_logins_threshold = 1;
        $settings->save();

        $user = User::factory()->create();
        $this->seedFailedLogins($user, 10);

        $this->assertNull(app(RepeatedFailedLoginsRule::class)->evaluate($user));
    }

    public function test_repeated_failed_logins_ignores_attempts_outside_the_window(): void
    {
        $settings = app(ComplianceMonitoringSettings::class);
        $settings->repeated_failed_logins_threshold = 3;
        $settings->repeated_failed_logins_window_minutes = 30;
        $settings->save();

        $user = User::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            LoginHistory::create([
                'user_id' => $user->id,
                'status' => 'failed',
                'logged_in_at' => now()->subHours(2),
            ]);
        }

        $this->assertNull(app(RepeatedFailedLoginsRule::class)->evaluate($user));
    }

    private function seedFailedLogins(User $user, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            LoginHistory::create([
                'user_id' => $user->id,
                'status' => 'failed',
                'logged_in_at' => now(),
            ]);
        }
    }

    // ── Excessive booking cancellations (Booking) ───────────────────────

    public function test_excessive_booking_cancellations_does_not_fire_below_threshold(): void
    {
        $settings = app(ComplianceMonitoringSettings::class);
        $settings->excessive_booking_cancellations_threshold = 3;
        $settings->save();

        $student = User::factory()->create();
        $latest = Booking::factory()->cancelled(BookingActor::Student)->create(['student_id' => $student->id]);

        $signal = app(ExcessiveBookingCancellationsRule::class)->evaluate($latest, BookingActor::Student);

        $this->assertNull($signal);
    }

    public function test_excessive_booking_cancellations_fires_at_threshold(): void
    {
        $settings = app(ComplianceMonitoringSettings::class);
        $settings->excessive_booking_cancellations_threshold = 3;
        $settings->save();

        $student = User::factory()->create();
        Booking::factory()->count(2)->cancelled(BookingActor::Student)->create(['student_id' => $student->id]);
        $latest = Booking::factory()->cancelled(BookingActor::Student)->create(['student_id' => $student->id]);

        $signal = app(ExcessiveBookingCancellationsRule::class)->evaluate($latest, BookingActor::Student);

        $this->assertNotNull($signal);
        $this->assertSame($student->id, $signal->subjectId);
        $this->assertSame(3, $signal->evidence['cancellation_count']);
    }

    public function test_excessive_booking_cancellations_skips_admin_and_system_actors(): void
    {
        $settings = app(ComplianceMonitoringSettings::class);
        $settings->excessive_booking_cancellations_threshold = 1;
        $settings->save();

        $booking = Booking::factory()->cancelled(BookingActor::Admin)->create();

        $this->assertNull(app(ExcessiveBookingCancellationsRule::class)->evaluate($booking, BookingActor::Admin));
        $this->assertNull(app(ExcessiveBookingCancellationsRule::class)->evaluate($booking, BookingActor::System));
    }

    // ── Repeated referral fraud holds (Referral) ────────────────────────

    public function test_repeated_referral_fraud_holds_does_not_fire_below_threshold(): void
    {
        $settings = app(ComplianceMonitoringSettings::class);
        $settings->repeated_referral_fraud_holds_threshold = 3;
        $settings->save();

        $referrer = User::factory()->create();
        ReferralReward::factory()->count(2)->create(['referrer_id' => $referrer->id, 'status' => ReferralRewardStatus::Held]);

        $this->assertNull(app(RepeatedReferralFraudHoldsRule::class)->evaluate($referrer->id));
    }

    public function test_repeated_referral_fraud_holds_fires_at_threshold(): void
    {
        $settings = app(ComplianceMonitoringSettings::class);
        $settings->repeated_referral_fraud_holds_threshold = 3;
        $settings->save();

        $referrer = User::factory()->create();
        ReferralReward::factory()->count(3)->create(['referrer_id' => $referrer->id, 'status' => ReferralRewardStatus::Held]);

        $signal = app(RepeatedReferralFraudHoldsRule::class)->evaluate($referrer->id);

        $this->assertNotNull($signal);
        $this->assertSame($referrer->id, $signal->subjectId);
        $this->assertSame(3, $signal->evidence['held_reward_count']);
    }

    // ── Unusual manual wallet adjustments (Wallet) ──────────────────────

    public function test_unusual_manual_wallet_adjustments_does_not_fire_below_threshold(): void
    {
        $settings = app(ComplianceMonitoringSettings::class);
        $settings->unusual_manual_wallet_adjustments_threshold = 3;
        $settings->save();

        $admin = User::factory()->create();
        $wallet = Wallet::factory()->create();
        WalletLedgerEntry::factory()->count(2)->create(['wallet_id' => $wallet->id, 'created_by' => $admin->id]);

        $this->assertNull(app(UnusualManualWalletAdjustmentsRule::class)->evaluate($admin));
    }

    public function test_unusual_manual_wallet_adjustments_fires_at_threshold(): void
    {
        $settings = app(ComplianceMonitoringSettings::class);
        $settings->unusual_manual_wallet_adjustments_threshold = 3;
        $settings->save();

        $admin = User::factory()->create();
        $wallet = Wallet::factory()->create();
        WalletLedgerEntry::factory()->count(3)->create(['wallet_id' => $wallet->id, 'created_by' => $admin->id]);

        $signal = app(UnusualManualWalletAdjustmentsRule::class)->evaluate($admin);

        $this->assertNotNull($signal);
        $this->assertSame($admin->id, $signal->subjectId);
        $this->assertSame($admin->id, $signal->actorId);
        $this->assertSame(3, $signal->evidence['admin_adjustment_count']);
    }

    public function test_evidence_never_contains_raw_sensitive_fields(): void
    {
        $settings = app(ComplianceMonitoringSettings::class);
        $settings->repeated_failed_logins_threshold = 1;
        $settings->save();

        $user = User::factory()->create();
        $this->seedFailedLogins($user, 1);

        $signal = app(RepeatedFailedLoginsRule::class)->evaluate($user);

        $this->assertNotNull($signal);
        $forbidden = ['password', 'secret', 'bank', 'kyc', 'raw_request', 'ip_address', 'user_agent'];
        $keys = array_map('strtolower', array_keys($signal->evidence));

        foreach ($forbidden as $term) {
            foreach ($keys as $key) {
                $this->assertStringNotContainsString($term, $key);
            }
        }
    }
}
