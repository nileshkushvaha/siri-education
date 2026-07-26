<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Models\Booking;
use App\Models\BookingType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Reporting\Contracts\FinancialReportsServiceInterface;
use App\Reporting\Contracts\MetricRegistryInterface;
use App\Reporting\Contracts\ReferralCommunicationReportServiceInterface;
use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\ValueObjects\ReportingPeriod;
use Database\Seeders\ReportingPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Referral, review-rate and notification reporting: ledger-only
 * referral truth, submission-rate definition, notification
 * activity semantics, structural-absence honesty (no conversion, no
 * delivery rate, no messaging), permission separation, privacy and
 * zero side effects.
 */
class ReferralCommunicationReportsTest extends TestCase
{
    use RefreshDatabase;

    private ReferralCommunicationReportServiceInterface $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReportingPermissionSeeder::class);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->reports = app(ReferralCommunicationReportServiceInterface::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function manager(): User
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('manager');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin;
    }

    private function period(): ReportingPeriod
    {
        return ReportingPeriod::forPreset(ReportingPeriodPreset::Last30Days, 'UTC');
    }

    private function filters(): ReportFilters
    {
        return new ReportFilters(period: $this->period());
    }

    /** Ledger row via the factories — reporting only ever reads the ledger. */
    private function ledgerEntry(User $user, array $overrides = []): void
    {
        $currency = $overrides['currency_code'] ?? 'INR';
        unset($overrides['currency_code']);

        $wallet = Wallet::query()->where('user_id', $user->id)->where('currency_code', $currency)->first()
            ?? Wallet::factory()->create(['user_id' => $user->id, 'currency_code' => $currency]);

        WalletLedgerEntry::factory()->create(array_merge([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'entry_type' => 'referral_credit',
            'direction' => 'credit',
            'status' => 'posted',
            'amount_minor' => 50000,
            'currency_code' => $currency,
            'created_at' => now()->subDay(),
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function eligibility(array $overrides = []): void
    {
        $student = User::factory()->create(['status' => 'active']);
        $instructor = User::factory()->create(['status' => 'active']);

        $bookingId = $overrides['booking_id'] ?? null;
        if ($bookingId === null) {
            $booking = Booking::factory()->confirmed()->create([
                'booking_type_id' => BookingType::factory()->paid()->create(),
                'student_id' => $student->id,
                'instructor_id' => $instructor->id,
            ]);
            $bookingId = $booking->id;
            $lessonId = DB::table('lessons')->where('booking_id', $bookingId)->value('id');
            if ($lessonId === null) {
                $lessonId = (string) Str::uuid();
                DB::table('lessons')->insert([
                    'id' => $lessonId, 'booking_id' => $bookingId,
                    'student_id' => $student->id, 'instructor_id' => $instructor->id,
                    'status' => 'completed', 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(3)->addHour(),
                    'created_at' => now()->subDays(4), 'updated_at' => now()->subDays(3),
                ]);
            }
            $overrides['booking_id'] = $bookingId;
            $overrides['lesson_id'] = $overrides['lesson_id'] ?? $lessonId;
        }

        DB::table('lesson_review_eligibilities')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'lesson_type' => 'paid',
            'eligibility_mode' => 'public_review',
            'status' => 'open',
            'opens_at' => now()->subDays(5),
            'expires_at' => now()->subDay(),
            'outcome_snapshot' => 'completed',
            'source_outcome_version' => 1,
            'version' => 1,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ], $overrides));
    }

    // ── Referral (ledger is the only truth) ───────────────────────────────

    public function test_referral_credits_count_ledger_confirmed_rows_only(): void
    {
        $admin = $this->manager();
        $studentA = User::factory()->create(['status' => 'active']);
        $studentB = User::factory()->create(['status' => 'active']);

        $this->ledgerEntry($studentA);
        $this->ledgerEntry($studentA, ['amount_minor' => 25000, 'idempotency_key' => (string) Str::uuid()]);
        $this->ledgerEntry($studentB, ['status' => 'pending', 'idempotency_key' => (string) Str::uuid()]);
        $this->ledgerEntry($studentB, ['status' => 'failed', 'idempotency_key' => (string) Str::uuid()]);
        // Non-referral credit never counts.
        $this->ledgerEntry($studentB, ['entry_type' => 'refund', 'idempotency_key' => (string) Str::uuid()]);

        $summary = $this->reports->referralActivity($admin, $this->period(), $this->filters());

        $this->assertSame(2, $summary->creditsExecutedInPeriod, 'Pending/failed rows were never credited value.');
        $this->assertSame(['INR' => 75000], $summary->creditedAmountByCurrency);
        $this->assertSame(1, $summary->distinctRecipientsInPeriod);
        $this->assertSame(0, $summary->reversalsInPeriod);
    }

    public function test_referral_amounts_never_sum_across_currencies(): void
    {
        $admin = $this->manager();
        $a = User::factory()->create(['status' => 'active']);
        $b = User::factory()->create(['status' => 'active']);

        $this->ledgerEntry($a, ['currency_code' => 'INR', 'amount_minor' => 10000]);
        $this->ledgerEntry($b, ['currency_code' => 'USD', 'amount_minor' => 900, 'idempotency_key' => (string) Str::uuid()]);

        $summary = $this->reports->referralActivity($admin, $this->period(), $this->filters());

        $this->assertSame(['INR' => 10000, 'USD' => 900], $summary->creditedAmountByCurrency);
        $this->assertFalse(property_exists($summary, 'totalCreditedMinor'), 'No cross-currency total may exist.');
    }

    public function test_reversed_referral_credit_is_a_separate_exception_signal(): void
    {
        $admin = $this->manager();
        $student = User::factory()->create(['status' => 'active']);

        $this->ledgerEntry($student, ['status' => 'reversed']);

        $summary = $this->reports->referralActivity($admin, $this->period(), $this->filters());

        $this->assertSame(1, $summary->creditsExecutedInPeriod, 'Gross executed activity stays visible.');
        $this->assertSame(1, $summary->reversalsInPeriod);
    }

    public function test_no_referral_conversion_revenue_or_roi_metric_exists(): void
    {
        $registry = app(MetricRegistryInterface::class);

        foreach (['referral_conversion_rate', 'referral_generated_revenue', 'referral_roi', 'referral_acquisition_cost', 'referral_registrations'] as $key) {
            $this->assertNull($registry->find($key), "'{$key}' has no authoritative Version 1 source and must not exist.");
        }

        foreach ($registry->all() as $metric) {
            if (str_contains($metric->key, 'referral')) {
                $this->assertStringContainsString('wallet', strtolower($metric->sourceDomain), 'Every referral metric must be ledger-backed.');
            }
        }
    }

    // ── Review submission rates ───────────────────────────────────────────

    public function test_submission_rate_uses_concluded_windows_and_excludes_revoked_and_manual_review(): void
    {
        $admin = $this->manager();

        $this->eligibility(['status' => 'used', 'used_at' => now()->subDays(2)]); // submitted
        $this->eligibility(['status' => 'expired']); // expired (expires_at past)
        $this->eligibility(['status' => 'open', 'expires_at' => now()->addDays(3)]); // still open — not concluded
        $this->eligibility(['status' => 'revoked', 'revoked_at' => now()->subDay()]); // excluded
        $this->eligibility(['status' => 'manual_review']); // excluded

        $rates = $this->reports->reviewQualityRates($admin, $this->period(), $this->filters());

        $this->assertSame(2, $rates->concludedWindowsInPeriod);
        $this->assertSame(1, $rates->usedWindowsInPeriod);
        $this->assertEqualsWithDelta(50.0, $rates->submissionRate, 0.01);
        $this->assertSame(1, $rates->revokedExcludedInPeriod);
        $this->assertSame(1, $rates->manualReviewExcludedInPeriod);
    }

    public function test_demo_and_paid_rates_are_split_by_lesson_type(): void
    {
        $admin = $this->manager();

        $this->eligibility(['lesson_type' => 'demo', 'status' => 'used', 'used_at' => now()->subDay()]);
        $this->eligibility(['lesson_type' => 'paid', 'status' => 'expired']);

        $rates = $this->reports->reviewQualityRates($admin, $this->period(), $this->filters());

        $this->assertEqualsWithDelta(100.0, $rates->demoSubmissionRate, 0.01);
        $this->assertEqualsWithDelta(0.0, $rates->paidSubmissionRate, 0.01);
    }

    public function test_rates_are_null_never_zero_percent_at_zero_denominator(): void
    {
        $admin = $this->manager();

        $rates = $this->reports->reviewQualityRates($admin, $this->period(), $this->filters());

        $this->assertNull($rates->submissionRate);
        $this->assertNull($rates->demoSubmissionRate);
        $this->assertNull($rates->paidSubmissionRate);
        $this->assertNull($rates->platformAverageRating, 'No fabricated platform rating without eligible reviews.');
    }

    public function test_platform_rating_reuses_the_phase_17_aggregate_table(): void
    {
        $admin = $this->manager();
        $instructor = User::factory()->create(['status' => 'active']);

        DB::table('instructor_rating_aggregates')->insert([
            'id' => (string) Str::uuid(),
            'instructor_id' => $instructor->id,
            'eligible_review_count' => 4,
            'overall_rating_sum' => 18,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $rates = $this->reports->reviewQualityRates($admin, $this->period(), $this->filters());

        $this->assertSame(4.5, $rates->platformAverageRating, 'Identical formula to AdminQualityDashboardRepository::platformAverageRating() — never recomputed from raw reviews.');
        $this->assertSame(4, $rates->publishedEligibleReviewCount);
    }

    // ── Notifications ─────────────────────────────────────────────────────

    public function test_notification_activity_separates_cohort_read_state_and_dedup_claims(): void
    {
        $admin = $this->manager();
        $user = User::factory()->create(['status' => 'active']);

        DB::table('notifications')->insert([
            ['id' => (string) Str::uuid(), 'type' => 'App\Notifications\Booking\BookingConfirmedNotification', 'notifiable_type' => 'App\Models\User', 'notifiable_id' => $user->id, 'data' => '{"secret":"PAYLOAD-BODY"}', 'read_at' => now()->subHour(), 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()],
            ['id' => (string) Str::uuid(), 'type' => 'App\Notifications\Booking\BookingConfirmedNotification', 'notifiable_type' => 'App\Models\User', 'notifiable_id' => $user->id, 'data' => '{}', 'read_at' => null, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()],
            // Created outside the period — in the unread current count only.
            ['id' => (string) Str::uuid(), 'type' => 'App\Notifications\Wallet\WalletNotification', 'notifiable_type' => 'App\Models\User', 'notifiable_id' => $user->id, 'data' => '{}', 'read_at' => null, 'created_at' => now()->subDays(60), 'updated_at' => now()->subDays(60)],
        ]);

        DB::table('notification_dispatch_log')->insert([
            'idempotency_key' => 'k1', 'notification_class' => 'App\Notifications\Booking\BookingConfirmedNotification', 'created_at' => now()->subDay(),
        ]);

        $summary = $this->reports->notificationActivity($admin, $this->period(), $this->filters());

        $this->assertSame(2, $summary->inAppCreatedInPeriod);
        $this->assertSame(1, $summary->inAppReadOfPeriodCohort);
        $this->assertEqualsWithDelta(50.0, $summary->readRate, 0.01);
        $this->assertSame(2, $summary->currentUnread);
        $this->assertSame(1, $summary->dedupClaimsInPeriod);
        $this->assertSame('BookingConfirmedNotification', $summary->byType[0]->label, 'Class basename only — never a payload.');
    }

    public function test_read_rate_is_null_when_nothing_was_created(): void
    {
        $summary = $this->reports->notificationActivity($this->manager(), $this->period(), $this->filters());

        $this->assertNull($summary->readRate);
        $this->assertSame(0, $summary->inAppCreatedInPeriod);
    }

    public function test_no_delivery_rate_provider_or_channel_outcome_metric_exists(): void
    {
        $registry = app(MetricRegistryInterface::class);

        foreach (['notification_delivery_rate', 'notifications_delivered', 'notifications_failed', 'provider_performance', 'sms_delivery', 'whatsapp_delivery', 'email_delivery_rate'] as $key) {
            $this->assertNull($registry->find($key), "'{$key}' has no delivery-attempt source in Version 1 and must not exist.");
        }
    }

    public function test_notification_payloads_never_reach_report_output(): void
    {
        $admin = $this->manager();
        $user = User::factory()->create(['status' => 'active']);

        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(), 'type' => 'App\Notifications\Booking\BookingConfirmedNotification',
            'notifiable_type' => 'App\Models\User', 'notifiable_id' => $user->id,
            'data' => '{"phone":"SECRET-PHONE-999","body":"SECRET-PAYLOAD-XYZ"}',
            'read_at' => null, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);

        $summary = $this->reports->notificationActivity($admin, $this->period(), $this->filters());
        $serialized = json_encode($summary);

        $this->assertStringNotContainsString('SECRET-PHONE-999', $serialized);
        $this->assertStringNotContainsString('SECRET-PAYLOAD-XYZ', $serialized);
    }

    // ── Messaging absence ─────────────────────────────────────────────────

    public function test_messaging_metrics_and_reports_remain_unavailable(): void
    {
        $registry = app(MetricRegistryInterface::class);

        foreach ($registry->all() as $metric) {
            foreach (['message', 'conversation', 'messaging'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $metric->key, 'No messaging domain exists in Version 1 — no messaging metric may exist.');
            }
        }

        $this->assertFalse(
            method_exists(ReferralCommunicationReportServiceInterface::class, 'messagingActivity'),
            'The contract must not pretend messaging analytics exist.',
        );
    }

    // ── Permissions ───────────────────────────────────────────────────────

    public function test_each_section_authorizes_independently(): void
    {
        $admin = $this->manager();
        Role::findByName('manager', 'web')->revokePermissionTo('ViewReferralReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Notification section still works…
        $this->reports->notificationActivity($admin, $this->period(), $this->filters());
        $this->assertFalse($this->reports->canViewReferrals($admin));
        $this->assertTrue($this->reports->canViewNotifications($admin));

        // …referral section does not.
        $this->expectException(AuthorizationException::class);
        $this->reports->referralActivity($admin, $this->period(), $this->filters());
    }

    public function test_referral_access_never_grants_wallet_or_finance_reports(): void
    {
        $admin = $this->manager();
        foreach (['ViewFinanceReports', 'ViewWalletReports', 'ViewPaymentReports', 'ViewInstructorCompensationReports'] as $permission) {
            Role::findByName('manager', 'web')->revokePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Referral reporting still works on its own permission…
        $this->reports->referralActivity($admin, $this->period(), $this->filters());

        // …but wallet reporting stays closed.
        $this->expectException(AuthorizationException::class);
        app(FinancialReportsServiceInterface::class)->walletSummary($admin, $this->period(), $this->filters());
    }

    public function test_quality_rates_require_the_review_quality_permission(): void
    {
        $admin = $this->manager();
        Role::findByName('manager', 'web')->revokePermissionTo('ViewReviewQualityReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->expectException(AuthorizationException::class);
        $this->reports->reviewQualityRates($admin, $this->period(), $this->filters());
    }

    // ── Zero side effects ─────────────────────────────────────────────────

    public function test_rendering_every_section_mutates_nothing_and_calls_no_provider(): void
    {
        Http::fake();
        $admin = $this->manager();
        $student = User::factory()->create(['status' => 'active']);

        $this->ledgerEntry($student);
        $this->eligibility(['status' => 'used', 'used_at' => now()->subDay()]);
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(), 'type' => 'App\Notifications\Wallet\WalletNotification',
            'notifiable_type' => 'App\Models\User', 'notifiable_id' => $student->id,
            'data' => '{}', 'read_at' => null, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);

        $before = [
            DB::table('wallet_ledger_entries')->orderBy('id')->get(['id', 'status', 'amount_minor'])->toJson(),
            DB::table('lesson_review_eligibilities')->orderBy('id')->get(['id', 'status', 'used_at'])->toJson(),
            DB::table('notifications')->orderBy('id')->get(['id', 'read_at'])->toJson(),
            DB::table('review_reports')->count(),
            DB::table('quality_alerts')->count(),
        ];
        $auditBefore = DB::table('activity_log')->count();
        $jobsBefore = DB::table('jobs')->count();

        $this->reports->referralActivity($admin, $this->period(), $this->filters());
        $this->reports->reviewQualityRates($admin, $this->period(), $this->filters());
        $this->reports->notificationActivity($admin, $this->period(), $this->filters());

        $after = [
            DB::table('wallet_ledger_entries')->orderBy('id')->get(['id', 'status', 'amount_minor'])->toJson(),
            DB::table('lesson_review_eligibilities')->orderBy('id')->get(['id', 'status', 'used_at'])->toJson(),
            DB::table('notifications')->orderBy('id')->get(['id', 'read_at'])->toJson(),
            DB::table('review_reports')->count(),
            DB::table('quality_alerts')->count(),
        ];

        $this->assertSame($before, $after, 'Reporting must never credit, moderate, resolve or mark-read anything.');
        $this->assertSame($auditBefore, DB::table('activity_log')->count());
        $this->assertSame($jobsBefore, DB::table('jobs')->count());
        Http::assertNothingSent();
    }

    // ── Performance ───────────────────────────────────────────────────────

    public function test_summary_query_count_is_bounded(): void
    {
        $admin = $this->manager();
        $student = User::factory()->create(['status' => 'active']);
        $this->ledgerEntry($student);
        $this->eligibility(['status' => 'used', 'used_at' => now()->subDay()]);

        DB::enableQueryLog();
        $this->reports->referralActivity($admin, $this->period(), $this->filters());
        $this->reports->reviewQualityRates($admin, $this->period(), $this->filters());
        $this->reports->notificationActivity($admin, $this->period(), $this->filters());
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(25, $count, 'Three sections must stay a bounded set of grouped queries.');
    }
}
