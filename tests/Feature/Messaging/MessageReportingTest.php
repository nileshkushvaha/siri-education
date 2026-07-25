<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Compliance\Enums\SuspiciousActivityRuleCode;
use App\Messaging\Enums\MessageReportReason;
use App\Messaging\Enums\MessageReportStatus;
use App\Messaging\Exceptions\MessagingException;
use App\Messaging\Services\MessagingService;
use App\Models\MessageReport;
use App\Models\MessagingRestriction;
use App\Models\SuspiciousActivityFlag;
use App\Models\User;
use App\Settings\ComplianceMonitoringSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * SRS §17.35-§17.36: message reporting, admin review, and the
 * requirement #7 integration with the existing compliance rule
 * engine (never an automatic sanction).
 */
class MessageReportingTest extends TestCase
{
    use CreatesMessagingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMessagingRoles();
        Permission::firstOrCreate(['name' => 'ReviewReport:Messaging', 'guard_name' => 'web']);
    }

    public function test_a_participant_can_report_a_message(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $service = app(MessagingService::class);
        $conversation = $service->openOrFindConversation($student, $instructor, $student);
        $message = $service->send($conversation, $instructor, 'Call me at +1 555 123 4567');

        $report = $service->reportMessage($message, $student, MessageReportReason::ContactSharing, 'Shared a phone number');

        $this->assertSame(MessageReportStatus::Pending, $report->status);
        $this->assertSame($student->id, $report->reporter_id);
    }

    public function test_a_non_participant_cannot_report_a_message(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $service = app(MessagingService::class);
        $conversation = $service->openOrFindConversation($student, $instructor, $student);
        $message = $service->send($conversation, $instructor, 'Hello');
        $outsider = $this->student();

        $this->expectException(MessagingException::class);
        $service->reportMessage($message, $outsider, MessageReportReason::Other);
    }

    public function test_reporting_the_same_message_twice_is_idempotent(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $service = app(MessagingService::class);
        $conversation = $service->openOrFindConversation($student, $instructor, $student);
        $message = $service->send($conversation, $instructor, 'Hello');

        $first = $service->reportMessage($message, $student, MessageReportReason::Spam);
        $second = $service->reportMessage($message, $student, MessageReportReason::Spam);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, MessageReport::query()->count());
    }

    public function test_leakage_flagged_message_is_still_sent_but_marked(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $service = app(MessagingService::class);
        $conversation = $service->openOrFindConversation($student, $instructor, $student);

        $message = $service->send($conversation, $student, 'Email me at test@example.com instead');

        $this->assertTrue($message->flagged_leakage);
        $this->assertContains('email_address', $message->flagged_leakage_reasons);
        $this->assertSame('Email me at test@example.com instead', $message->body, 'Body is never mutated by leakage detection.');
    }

    public function test_admin_can_review_a_report(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $service = app(MessagingService::class);
        $conversation = $service->openOrFindConversation($student, $instructor, $student);
        $message = $service->send($conversation, $instructor, 'Hello');
        $report = $service->reportMessage($message, $student, MessageReportReason::Other);

        $admin = $this->admin();
        $reviewed = $service->reviewReport($report, $admin, MessageReportStatus::Dismissed, 'Not a violation.');

        $this->assertSame(MessageReportStatus::Dismissed, $reviewed->status);
        $this->assertSame($admin->id, $reviewed->reviewed_by);
    }

    public function test_unauthorized_user_cannot_review_a_report(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $service = app(MessagingService::class);
        $conversation = $service->openOrFindConversation($student, $instructor, $student);
        $message = $service->send($conversation, $instructor, 'Hello');
        $report = $service->reportMessage($message, $student, MessageReportReason::Other);

        $this->expectException(MessagingException::class);
        $service->reviewReport($report, $student, MessageReportStatus::Dismissed);
    }

    public function test_repeated_reports_against_the_same_sender_trigger_a_compliance_flag(): void
    {
        $settings = app(ComplianceMonitoringSettings::class);
        $settings->repeated_message_reports_enabled = true;
        $settings->repeated_message_reports_threshold = 2;
        $settings->save();

        $instructor = $this->instructor();
        $service = app(MessagingService::class);

        foreach ([$this->student(), $this->student()] as $reportingStudent) {
            $this->confirmedPaidBooking($reportingStudent, $instructor);
            $conversation = $service->openOrFindConversation($reportingStudent, $instructor, $reportingStudent);
            $message = $service->send($conversation, $instructor, 'Suspicious message');
            $service->reportMessage($message, $reportingStudent, MessageReportReason::AbuseOrHarassment);
        }

        $flag = SuspiciousActivityFlag::query()->where('rule_code', SuspiciousActivityRuleCode::RepeatedMessageReports)->first();

        $this->assertNotNull($flag);
        $this->assertSame($instructor->id, $flag->subject_id);
    }

    public function test_compliance_flag_never_auto_restricts_messaging(): void
    {
        $settings = app(ComplianceMonitoringSettings::class);
        $settings->repeated_message_reports_enabled = true;
        $settings->repeated_message_reports_threshold = 1;
        $settings->save();

        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $service = app(MessagingService::class);
        $conversation = $service->openOrFindConversation($student, $instructor, $student);
        $message = $service->send($conversation, $instructor, 'Hello');
        $service->reportMessage($message, $student, MessageReportReason::Other);

        $this->assertSame(0, MessagingRestriction::query()->count(), 'A compliance flag is evidence for human review — never an automatic restriction.');
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('manager');
        $admin->givePermissionTo('ReviewReport:Messaging');

        return $admin;
    }
}
