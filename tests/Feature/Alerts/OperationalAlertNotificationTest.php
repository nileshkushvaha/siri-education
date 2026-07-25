<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Alerts\DTOs\OperationalAlertSignal;
use App\Alerts\Enums\OperationalAlertSeverity;
use App\Alerts\Enums\OperationalAlertType;
use App\Alerts\Events\OperationalAlertRecorded;
use App\Alerts\Services\OperationalAlertService;
use App\Models\NotificationDispatchLog;
use App\Models\User;
use App\Notifications\Alerts\OperationalAlertRecordedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Requirement #6 — alert authorized administrators only for High/
 * Critical severity, routed by category via the existing
 * AdminRecipientResolver::forPermission() mechanism, through the
 * existing queued/idempotent notification infrastructure, never
 * exposing anything beyond the source's own safe title/summary.
 */
class OperationalAlertNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function signal(OperationalAlertSeverity $severity, OperationalAlertType $type = OperationalAlertType::MeetingCreationFailed): OperationalAlertSignal
    {
        return new OperationalAlertSignal(
            type: $type,
            category: $type->category(),
            severity: $severity,
            title: 'Meeting creation failed',
            summary: 'Safe summary text only.',
            subjectType: 'App\\Models\\Booking',
            subjectId: 'booking-1',
        );
    }

    private function adminWithPermission(string $permission): User
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $admin->assignRole('manager');
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        $admin->givePermissionTo($permission);

        return $admin;
    }

    public function test_a_critical_alert_notifies_administrators_holding_the_categorys_permission(): void
    {
        Notification::fake();

        $admin = $this->adminWithPermission('ViewAny:Booking');

        app(OperationalAlertService::class)->createOrMerge($this->signal(OperationalAlertSeverity::Critical));

        Notification::assertSentTo($admin, OperationalAlertRecordedNotification::class);
    }

    public function test_an_administrator_without_the_categorys_permission_is_not_notified(): void
    {
        Notification::fake();

        $walletAdmin = $this->adminWithPermission('ViewAny:Wallet');

        app(OperationalAlertService::class)->createOrMerge($this->signal(OperationalAlertSeverity::Critical, OperationalAlertType::MeetingCreationFailed));

        Notification::assertNotSentTo($walletAdmin, OperationalAlertRecordedNotification::class);
    }

    public function test_a_finance_category_alert_notifies_wallet_permission_holders(): void
    {
        Notification::fake();

        $financeAdmin = $this->adminWithPermission('ViewAny:Wallet');

        app(OperationalAlertService::class)->createOrMerge($this->signal(OperationalAlertSeverity::Critical, OperationalAlertType::WalletRechargeCreditFailed));

        Notification::assertSentTo($financeAdmin, OperationalAlertRecordedNotification::class);
    }

    public function test_an_info_severity_alert_never_notifies_anyone(): void
    {
        Notification::fake();

        $this->adminWithPermission('ViewAny:Booking');

        app(OperationalAlertService::class)->createOrMerge($this->signal(OperationalAlertSeverity::Info));

        Notification::assertNothingSent();
    }

    public function test_a_warning_severity_alert_never_notifies_anyone(): void
    {
        Notification::fake();

        $this->adminWithPermission('ViewAny:Booking');

        app(OperationalAlertService::class)->createOrMerge($this->signal(OperationalAlertSeverity::Warning));

        Notification::assertNothingSent();
    }

    public function test_notification_preview_never_exposes_metadata_beyond_the_safe_title_and_summary(): void
    {
        $admin = $this->adminWithPermission('ViewAny:Booking');

        $alert = app(OperationalAlertService::class)->createOrMerge(new OperationalAlertSignal(
            type: OperationalAlertType::MeetingCreationFailed,
            category: OperationalAlertType::MeetingCreationFailed->category(),
            severity: OperationalAlertSeverity::Critical,
            title: 'Meeting creation failed',
            summary: 'Safe summary text only.',
            metadata: ['provider_secret_token' => 'should-never-appear'],
        ));

        $notification = new OperationalAlertRecordedNotification($alert);
        $mail = $notification->toMail($admin);
        $database = $notification->toDatabase($admin);

        $rendered = collect($mail->introLines)->implode(' ');

        $this->assertStringNotContainsString('should-never-appear', $rendered);
        $this->assertArrayNotHasKey('metadata', $database);
    }

    public function test_duplicate_dispatch_of_the_recorded_event_sends_the_notification_at_most_once(): void
    {
        $admin = $this->adminWithPermission('ViewAny:Booking');

        $alert = app(OperationalAlertService::class)->createOrMerge($this->signal(OperationalAlertSeverity::Critical));

        OperationalAlertRecorded::dispatch($alert);
        OperationalAlertRecorded::dispatch($alert);

        $this->assertSame(
            1,
            NotificationDispatchLog::query()
                ->where('notification_class', OperationalAlertRecordedNotification::class)
                ->count(),
        );
    }
}
