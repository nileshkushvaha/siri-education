<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Alerts\Enums\OperationalAlertCategory;
use App\Alerts\Enums\OperationalAlertSeverity;
use App\Alerts\Enums\OperationalAlertType;
use App\Booking\Contracts\BookingMeetingServiceInterface;
use App\Booking\Contracts\BookingPaymentReconciliationServiceInterface;
use App\Booking\DTOs\MeetingUpdateContext;
use App\Booking\Enums\BookingPaymentReconciliationIssueType;
use App\Booking\Enums\BookingPaymentReconciliationSeverity;
use App\Earnings\Enums\PayoutReconciliationIssueType;
use App\Earnings\Enums\PayoutReconciliationSeverity;
use App\Earnings\Services\InstructorPayoutReconciliationService;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\InstructorPayoutAttempt;
use App\Models\OperationalAlert;
use App\Models\User;
use App\Models\WalletRecharge;
use App\Settings\MeetingSettings;
use App\Wallet\Events\WalletRechargeCreditFailed;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Crypt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 35 (GAP-035) — each evidence-backed alert source, verified end
 * to end from its real trigger (not by calling
 * OperationalAlertService directly).
 */
class OperationalAlertSourcesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
    }

    // ── 1. Meeting creation failure ──────────────────────────────────────

    public function test_meeting_creation_failure_creates_a_high_severity_alert(): void
    {
        $settings = app(MeetingSettings::class);
        $settings->zoom_enabled = true;
        $settings->zoom_account_id = 'acct_1';
        $settings->zoom_client_id = 'client_1';
        $settings->zoom_client_secret = Crypt::encryptString('super-secret-zoom-client-value');
        $settings->zoom_host_user_id = null;
        $settings->zoom_host_email = null;
        $settings->save();

        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $booking = Booking::factory()->confirmed()->paid()->create([
            'instructor_id' => $teacher->id,
            'student_id' => $student->id,
            'starts_at' => now()->addMinutes(10),
            'ends_at' => now()->addMinutes(40),
        ]);

        app(BookingMeetingServiceInterface::class)->createMeeting($booking, 'zoom');

        $alert = OperationalAlert::query()->where('type', OperationalAlertType::MeetingCreationFailed->value)->sole();

        $this->assertSame(OperationalAlertSeverity::High, $alert->severity);
        $this->assertSame(OperationalAlertCategory::BookingMeeting, $alert->category);
        $this->assertSame(Booking::class, $alert->subject_type);
        $this->assertSame((string) $booking->id, $alert->subject_id);
        $this->assertStringNotContainsString('super-secret-zoom-client-value', json_encode($alert->toArray()));
    }

    // ── 2. Reconciliation issues (Critical-only) ─────────────────────────

    public function test_a_critical_payment_reconciliation_issue_creates_a_finance_alert(): void
    {
        $payment = BookingPayment::factory()->create();

        app(BookingPaymentReconciliationServiceInterface::class)->raiseIssue(
            $payment,
            BookingPaymentReconciliationIssueType::UnknownPaymentOutcome,
            BookingPaymentReconciliationSeverity::Critical,
            'Reconciliation could not confirm the provider outcome.',
        );

        $alert = OperationalAlert::query()->where('type', OperationalAlertType::PaymentReconciliationIssue->value)->sole();

        $this->assertSame(OperationalAlertSeverity::Critical, $alert->severity);
        $this->assertSame(OperationalAlertCategory::Finance, $alert->category);
    }

    public function test_a_warning_payment_reconciliation_issue_does_not_create_an_alert(): void
    {
        $payment = BookingPayment::factory()->create();

        app(BookingPaymentReconciliationServiceInterface::class)->raiseIssue(
            $payment,
            BookingPaymentReconciliationIssueType::ProviderUnavailable,
            BookingPaymentReconciliationSeverity::Warning,
            'Provider temporarily unavailable.',
        );

        $this->assertSame(0, OperationalAlert::query()->count());
    }

    public function test_a_critical_payout_reconciliation_issue_creates_a_finance_alert(): void
    {
        $attempt = InstructorPayoutAttempt::factory()->create();

        app(InstructorPayoutReconciliationService::class)->raiseIssue(
            $attempt,
            PayoutReconciliationIssueType::UnknownProviderOutcome,
            PayoutReconciliationSeverity::Critical,
            'Reconciliation could not confirm the payout outcome.',
        );

        $alert = OperationalAlert::query()->where('type', OperationalAlertType::PayoutReconciliationIssue->value)->sole();

        $this->assertSame(OperationalAlertSeverity::Critical, $alert->severity);
        $this->assertSame(OperationalAlertCategory::Finance, $alert->category);
    }

    // ── 3. Wallet recharge credit failure ────────────────────────────────

    public function test_wallet_recharge_credit_failure_creates_a_critical_finance_alert(): void
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $recharge = WalletRecharge::factory()->create(['user_id' => $student->id]);

        event(new WalletRechargeCreditFailed($recharge));

        $alert = OperationalAlert::query()->where('type', OperationalAlertType::WalletRechargeCreditFailed->value)->sole();

        $this->assertSame(OperationalAlertSeverity::Critical, $alert->severity);
        $this->assertSame(OperationalAlertCategory::Finance, $alert->category);
        $this->assertSame(WalletRecharge::class, $alert->subject_type);
        $this->assertSame((string) $recharge->id, $alert->subject_id);
    }

    // ── 4. Critical failed jobs ──────────────────────────────────────────

    public function test_a_failed_wallet_job_creates_a_critical_finance_alert(): void
    {
        $job = $this->fakeQueueJob('App\\Listeners\\Wallet\\SendWalletNotifications');

        event(new JobFailed('database', $job, new \RuntimeException('Simulated failure')));

        $alert = OperationalAlert::query()->where('type', OperationalAlertType::CriticalFailedJob->value)->sole();

        $this->assertSame(OperationalAlertSeverity::Critical, $alert->severity);
        $this->assertSame(OperationalAlertCategory::Finance, $alert->category);
    }

    public function test_a_failed_non_critical_job_creates_no_alert(): void
    {
        $job = $this->fakeQueueJob('App\\Jobs\\Reporting\\ExportReport');

        event(new JobFailed('database', $job, new \RuntimeException('Simulated failure')));

        $this->assertSame(0, OperationalAlert::query()->count());
    }

    // ── 5. Missing meeting link sweep ────────────────────────────────────

    public function test_the_sweep_flags_an_upcoming_booking_with_no_meeting_link(): void
    {
        app(MeetingSettings::class)->missing_meeting_link_threshold_minutes = 60;
        app(MeetingSettings::class)->save();

        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        $booking = Booking::factory()->confirmed()->paid()->create([
            'student_id' => $student->id,
            'starts_at' => now()->addMinutes(30),
            'ends_at' => now()->addMinutes(60),
        ]);

        $this->artisan('alerts:check-missing-meeting-links')->assertSuccessful();

        $alert = OperationalAlert::query()->where('type', OperationalAlertType::MissingMeetingLink->value)->sole();
        $this->assertSame((string) $booking->id, $alert->subject_id);
        $this->assertSame(OperationalAlertSeverity::Critical, $alert->severity);
    }

    public function test_the_sweep_does_not_flag_a_booking_outside_the_threshold(): void
    {
        app(MeetingSettings::class)->missing_meeting_link_threshold_minutes = 60;
        app(MeetingSettings::class)->save();

        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        Booking::factory()->confirmed()->paid()->create([
            'student_id' => $student->id,
            'starts_at' => now()->addHours(5),
            'ends_at' => now()->addHours(6),
        ]);

        $this->artisan('alerts:check-missing-meeting-links')->assertSuccessful();

        $this->assertSame(0, OperationalAlert::query()->count());
    }

    public function test_the_sweep_does_not_flag_a_booking_with_a_ready_meeting_link(): void
    {
        app(MeetingSettings::class)->missing_meeting_link_threshold_minutes = 60;
        app(MeetingSettings::class)->save();

        $settings = app(MeetingSettings::class);
        $settings->meetings_enabled = true;
        $settings->manual_provider_enabled = true;
        $settings->default_provider = 'manual';
        $settings->create_after_paid_booking_confirmation = true;
        $settings->save();

        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        $booking = Booking::factory()->confirmed()->paid()->create([
            'student_id' => $student->id,
            'starts_at' => now()->addMinutes(30),
            'ends_at' => now()->addMinutes(60),
        ]);

        app(BookingMeetingServiceInterface::class)->saveManualMeeting(
            $booking,
            new MeetingUpdateContext(joinUrl: 'https://meet.example.test/ready'),
        );

        $this->artisan('alerts:check-missing-meeting-links')->assertSuccessful();

        $this->assertSame(0, OperationalAlert::query()->count());
    }

    private function fakeQueueJob(string $displayName): Job
    {
        $job = \Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->andReturn($displayName);
        $job->shouldReceive('getQueue')->andReturn('notifications');

        return $job;
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
