<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Compliance\DTOs\SuspiciousActivitySignal;
use App\Compliance\Enums\SuspiciousActivityFlagSeverity;
use App\Compliance\Enums\SuspiciousActivityRuleCode;
use App\Compliance\Events\SuspiciousActivityFlagRecorded;
use App\Compliance\Services\ComplianceMonitoringService;
use App\Models\NotificationDispatchLog;
use App\Models\User;
use App\Notifications\Compliance\SuspiciousActivityFlagRecordedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Item 9 of the phase brief: alert authorized administrators only for
 * configured warning/critical flags, via the existing queued/
 * idempotent notification infrastructure, never exposing sensitive
 * evidence in the preview.
 */
class SuspiciousActivityFlagNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function signal(int $subjectId, SuspiciousActivityFlagSeverity $severity): SuspiciousActivitySignal
    {
        return new SuspiciousActivitySignal(
            ruleCode: SuspiciousActivityRuleCode::RepeatedFailedLogins,
            ruleVersion: 1,
            subjectId: $subjectId,
            actorId: null,
            occurredAt: Date::now(),
            severity: $severity,
            evidence: ['failed_login_count' => 5, 'window_minutes' => 30, 'threshold' => 5],
            thresholdSnapshot: ['enabled' => true, 'threshold' => 5],
            cooldownMinutes: 60,
        );
    }

    private function adminWithViewPermission(): User
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $admin->assignRole('manager');
        Permission::firstOrCreate(['name' => 'ViewAny:SuspiciousActivityFlag', 'guard_name' => 'web']);
        $admin->givePermissionTo('ViewAny:SuspiciousActivityFlag');

        return $admin;
    }

    public function test_a_critical_flag_notifies_permitted_administrators(): void
    {
        Notification::fake();

        $admin = $this->adminWithViewPermission();
        $subject = User::factory()->create();

        app(ComplianceMonitoringService::class)->record($this->signal($subject->id, SuspiciousActivityFlagSeverity::Critical));

        Notification::assertSentTo($admin, SuspiciousActivityFlagRecordedNotification::class);
    }

    public function test_a_low_severity_flag_never_notifies_anyone(): void
    {
        Notification::fake();

        $this->adminWithViewPermission();
        $subject = User::factory()->create();

        app(ComplianceMonitoringService::class)->record($this->signal($subject->id, SuspiciousActivityFlagSeverity::Low));

        Notification::assertNothingSent();
    }

    public function test_a_medium_severity_flag_never_notifies_anyone(): void
    {
        Notification::fake();

        $this->adminWithViewPermission();
        $subject = User::factory()->create();

        app(ComplianceMonitoringService::class)->record($this->signal($subject->id, SuspiciousActivityFlagSeverity::Medium));

        Notification::assertNothingSent();
    }

    public function test_notification_preview_never_exposes_the_raw_evidence_snapshot(): void
    {
        $admin = $this->adminWithViewPermission();
        $subject = User::factory()->create();

        $flag = app(ComplianceMonitoringService::class)->record($this->signal($subject->id, SuspiciousActivityFlagSeverity::Critical));

        $notification = new SuspiciousActivityFlagRecordedNotification($flag);
        $mail = $notification->toMail($admin);
        $database = $notification->toDatabase($admin);

        $rendered = collect($mail->introLines)->implode(' ');

        $this->assertStringNotContainsString('failed_login_count', $rendered);
        $this->assertArrayNotHasKey('evidence', $database);
    }

    public function test_duplicate_dispatch_of_the_created_event_sends_the_notification_at_most_once(): void
    {
        $admin = $this->adminWithViewPermission();
        $subject = User::factory()->create();

        $flag = app(ComplianceMonitoringService::class)->record($this->signal($subject->id, SuspiciousActivityFlagSeverity::Critical));

        SuspiciousActivityFlagRecorded::dispatch($flag);
        SuspiciousActivityFlagRecorded::dispatch($flag);

        $this->assertSame(
            1,
            NotificationDispatchLog::query()
                ->where('notification_class', SuspiciousActivityFlagRecordedNotification::class)
                ->count(),
        );
    }
}
