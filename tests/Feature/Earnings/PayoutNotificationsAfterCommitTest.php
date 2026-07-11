<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings;

use App\Earnings\Contracts\InstructorWithdrawalServiceInterface;
use App\Earnings\Exceptions\WithdrawalException;
use App\Enums\InstructorStatus;
use App\Events\ActivityCreated;
use App\Listeners\NotifyInstructorOnPayoutActivity;
use App\Models\Activity;
use App\Models\Currency;
use App\Models\InstructorEarning;
use App\Models\InstructorPayoutMethod;
use App\Models\InstructorWithdrawalRequest;
use App\Models\User;
use App\Notifications\Instructor\InstructorWithdrawalStatusNotification;
use App\Settings\InstructorEarningSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 15.1 §12 — the notification pipeline can never precede, alter,
 * or duplicate financial state: notifications hang off audit entries
 * written only after the financial transaction commits, idempotent
 * replays add no second entry, and the queued listener is read-only
 * against financial tables (a retry re-reads, never re-writes).
 */
class PayoutNotificationsAfterCommitTest extends TestCase
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
        $settings->save();
    }

    public function test_idempotent_replay_sends_exactly_one_notification(): void
    {
        Notification::fake();

        [$instructor, $method] = $this->instructorWithFunds(100000);
        $key = (string) Str::uuid();

        $this->withdrawals->requestWithdrawal($instructor, $method, 20000, null, $key);
        $this->withdrawals->requestWithdrawal($instructor, $method, 20000, null, $key);

        Notification::assertSentToTimes($instructor, InstructorWithdrawalStatusNotification::class, 1);

        // And exactly one audit entry backs it.
        $this->assertSame(1, Activity::query()
            ->where('log_name', 'instructor_payouts')
            ->where('event', 'withdrawal_requested')
            ->count());
    }

    public function test_rolled_back_request_writes_no_audit_entry_and_sends_nothing(): void
    {
        Notification::fake();

        [$instructor, $method] = $this->instructorWithFunds(15000);

        try {
            $this->withdrawals->requestWithdrawal($instructor, $method, 99000);
        } catch (WithdrawalException) {
        }

        Notification::assertNotSentTo($instructor, InstructorWithdrawalStatusNotification::class);
        $this->assertSame(0, Activity::query()->where('event', 'withdrawal_requested')->count());
        $this->assertSame(0, InstructorWithdrawalRequest::query()->count());
    }

    public function test_queued_notification_payload_carries_no_sensitive_or_internal_data(): void
    {
        Notification::fake();

        [$instructor, $method] = $this->instructorWithFunds(100000);
        $request = $this->withdrawals->requestWithdrawal($instructor, $method, 20000);
        $request->forceFill(['internal_review_note' => 'INTERNAL-ONLY-NOTE'])->save();

        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Permission::firstOrCreate(['name' => 'Reject:InstructorWithdrawalRequest', 'guard_name' => 'web']);
        $admin->givePermissionTo('Reject:InstructorWithdrawalRequest');

        $this->withdrawals->reject($request, $admin, 'Bank details mismatch.');

        Notification::assertSentTo($instructor, InstructorWithdrawalStatusNotification::class, function ($notification) use ($instructor, $method): bool {
            $payload = json_encode($notification->toArray($instructor)).json_encode($notification->toMail($instructor));

            return ! str_contains($payload, $method->encrypted_details['account_number'])
                && ! str_contains($payload, 'INTERNAL-ONLY-NOTE')
                && str_contains($payload, 'Bank details mismatch.');
        });
    }

    public function test_listener_retry_re_sends_but_never_mutates_financial_state(): void
    {
        Notification::fake();

        [$instructor, $method] = $this->instructorWithFunds(100000);
        $request = $this->withdrawals->requestWithdrawal($instructor, $method, 20000);

        $activity = Activity::query()
            ->where('log_name', 'instructor_payouts')
            ->where('event', 'withdrawal_requested')
            ->sole();

        $financialSnapshot = [
            $request->fresh()->toArray(),
            $request->allocations()->orderBy('id')->get()->toArray(),
        ];

        // Simulate a queue retry: run the listener again on the same
        // audit entry.
        app(NotifyInstructorOnPayoutActivity::class)->handle(new ActivityCreated($activity));

        $this->assertEquals($financialSnapshot, [
            $request->fresh()->toArray(),
            $request->allocations()->orderBy('id')->get()->toArray(),
        ], 'A notification retry must be read-only against financial records.');
    }

    public function test_notifications_ride_the_notifications_queue(): void
    {
        Notification::fake();

        [$instructor, $method] = $this->instructorWithFunds(100000);
        $this->withdrawals->requestWithdrawal($instructor, $method, 20000);

        Notification::assertSentTo($instructor, InstructorWithdrawalStatusNotification::class, fn ($notification): bool => $notification->queue === 'notifications');
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /** @return array{0: User, 1: InstructorPayoutMethod} */
    private function instructorWithFunds(int $amountMinor): array
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('instructor');
        $user->profile->update(['instructor_status' => InstructorStatus::Active]);

        InstructorEarning::factory()->releasable()->create([
            'instructor_id' => $user->id,
            'earning_amount_minor' => $amountMinor,
            'currency_code' => 'INR',
        ]);

        $method = InstructorPayoutMethod::factory()->verified()->create([
            'instructor_id' => $user->id,
            'currency_code' => 'INR',
            'currency_id' => Currency::query()->where('code', 'INR')->value('id'),
        ]);

        return [$user, $method];
    }
}
