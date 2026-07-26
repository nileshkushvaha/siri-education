<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings;

use App\Booking\Enums\BookingPaymentStatus;
use App\Earnings\Contracts\FinancialFeatureConfigurationServiceInterface;
use App\Earnings\Contracts\InstructorEarningServiceInterface;
use App\Earnings\Enums\CompensationExceptionCategory;
use App\Earnings\Exceptions\CompensationException;
use App\Earnings\Services\CompensationExceptionService;
use App\Enums\InstructorStatus;
use App\Filament\Pages\Settings\InstructorEarningSettingsPage;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\InstructorCompensationAgreement;
use App\Models\InstructorCompensationException;
use App\Models\InstructorEarning;
use App\Models\InstructorPayoutAttempt;
use App\Models\InstructorPayoutReconciliationIssue;
use App\Models\Lesson;
use App\Models\User;
use App\Settings\InstructorEarningSettings;
use Database\Seeders\InstructorEarningPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\ManagesFinancialSettings;
use Tests\TestCase;

/**
 * The financial feature switches flow only through
 * FinancialFeatureConfigurationService: direct saves throw, Filament
 * relays through the service, preflights gate every enable path, retry
 * backoff bounds the sweep, and the consolidated schema carries no
 * commission residue.
 */
class FinancialConfigurationTest extends TestCase
{
    use ManagesFinancialSettings;
    use RefreshDatabase;

    private FinancialFeatureConfigurationServiceInterface $configuration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configuration = app(FinancialFeatureConfigurationServiceInterface::class);

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);
    }

    // ── Bypass prevention ────────────────────────────────────────────

    public function test_direct_settings_save_cannot_flip_a_financial_switch(): void
    {
        $settings = app(InstructorEarningSettings::class);
        $settings->earnings_enabled = true;

        try {
            $settings->save();
            $this->fail('Direct switch save should be rejected.');
        } catch (CompensationException $e) {
            $this->assertStringContainsString('FinancialFeatureConfigurationService', $e->getMessage());
        }

        // In-memory drift is discarded; the repository still says false.
        $settings->refresh();
        $this->assertFalse($settings->earnings_enabled);
    }

    public function test_non_switch_settings_still_save_normally(): void
    {
        $settings = app(InstructorEarningSettings::class);
        $settings->hold_days = 10;
        $settings->save();

        $this->assertSame(10, $settings->refresh()->hold_days);

        $settings->hold_days = 7;
        $settings->save();
    }

    public function test_all_three_switches_default_to_false(): void
    {
        $settings = app(InstructorEarningSettings::class)->refresh();

        $this->assertFalse($settings->earnings_enabled);
        $this->assertFalse($settings->withdrawals_enabled);
        $this->assertFalse($settings->periodic_compensation_enabled);
    }

    public function test_no_production_code_flips_switches_outside_the_service(): void
    {
        $offenders = [];
        $allowed = [
            app_path('Earnings/Services/FinancialFeatureConfigurationService.php'),
            app_path('Settings/InstructorEarningSettings.php'),
        ];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php' || in_array($file->getPathname(), $allowed, true)) {
                continue;
            }

            $code = file_get_contents($file->getPathname());

            if (preg_match('/(earnings_enabled|withdrawals_enabled|periodic_compensation_enabled|payout_execution_enabled|financial_disposition_enabled|lesson_refund_execution_enabled|earning_reconciliation_execution_enabled)\s*=\s*(?!=)/', $code)
                || str_contains($code, 'FinancialFeatureToggle::unguarded')) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame([], $offenders, 'Production code writes financial switches outside the canonical service.');
    }

    // ── Preflight-gated enablement ───────────────────────────────────

    public function test_earnings_cannot_enable_with_missing_agreements(): void
    {
        $this->instructor(); // payable, no agreement

        $readiness = $this->configuration->evaluateEarningsReadiness();
        $this->assertFalse($readiness->isReady);
        $this->assertContains('missing_agreements', $readiness->blockingCodes);

        $this->expectException(CompensationException::class);

        $this->configuration->enableEarnings($this->superAdmin());
    }

    public function test_earnings_enable_succeeds_only_when_every_condition_passes(): void
    {
        $instructor = $this->instructor();
        InstructorCompensationAgreement::factory()->active()->create([
            'instructor_id' => $instructor->id,
            'effective_from' => now()->subMonth(),
        ]);

        $readiness = $this->configuration->enableEarnings($this->superAdmin());

        $this->assertTrue($readiness->isReady);
        $this->assertTrue(app(InstructorEarningSettings::class)->refresh()->earnings_enabled);

        // Restore the safe state through the service.
        $this->configuration->disableEarnings($this->superAdmin());
        $this->assertFalse(app(InstructorEarningSettings::class)->refresh()->earnings_enabled);
    }

    public function test_earnings_cannot_enable_without_any_payable_instructor(): void
    {
        $readiness = $this->configuration->evaluateEarningsReadiness();

        $this->assertContains('no_payable_instructors', $readiness->blockingCodes);
    }

    public function test_periodic_compensation_cannot_enable_while_earnings_disabled(): void
    {
        $readiness = $this->configuration->evaluatePeriodicCompensationReadiness();
        $this->assertContains('earnings_disabled', $readiness->blockingCodes);

        $this->expectException(CompensationException::class);

        $this->configuration->enablePeriodicCompensation($this->superAdmin());
    }

    public function test_withdrawals_cannot_enable_while_earnings_disabled(): void
    {
        $readiness = $this->configuration->evaluateWithdrawalReadiness();
        $this->assertContains('earnings_disabled', $readiness->blockingCodes);

        $this->expectException(CompensationException::class);

        $this->configuration->enableWithdrawals($this->superAdmin());
    }

    public function test_disabling_earnings_auto_disables_periodic_compensation(): void
    {
        // Force the combined state via the test bypass, then disable
        // through the service and verify the documented rule.
        $this->setFinancialSettings(['earnings_enabled' => true, 'periodic_compensation_enabled' => true]);

        $this->configuration->disableEarnings($this->superAdmin());

        $settings = app(InstructorEarningSettings::class)->refresh();
        $this->assertFalse($settings->earnings_enabled);
        $this->assertFalse($settings->periodic_compensation_enabled);
    }

    // financial_disposition_enabled, lesson_refund_execution_enabled,
    // earning_reconciliation_execution_enabled share the same guarded
    // write path as the original four switches.

    public function test_direct_settings_save_cannot_flip_the_financial_disposition_switch(): void
    {
        $settings = app(InstructorEarningSettings::class);
        $settings->financial_disposition_enabled = true;

        try {
            $settings->save();
            $this->fail('Direct switch save should be rejected.');
        } catch (CompensationException $e) {
            $this->assertStringContainsString('FinancialFeatureConfigurationService', $e->getMessage());
        }

        $settings->refresh();
        $this->assertFalse($settings->financial_disposition_enabled);
    }

    public function test_direct_settings_save_cannot_flip_the_lesson_refund_execution_switch(): void
    {
        $settings = app(InstructorEarningSettings::class);
        $settings->lesson_refund_execution_enabled = true;

        $this->expectException(CompensationException::class);
        $settings->save();
    }

    public function test_direct_settings_save_cannot_flip_the_earning_reconciliation_execution_switch(): void
    {
        $settings = app(InstructorEarningSettings::class);
        $settings->earning_reconciliation_execution_enabled = true;

        $this->expectException(CompensationException::class);
        $settings->save();
    }

    public function test_the_three_execution_switches_default_to_false(): void
    {
        $settings = app(InstructorEarningSettings::class)->refresh();

        $this->assertFalse($settings->financial_disposition_enabled);
        $this->assertFalse($settings->lesson_refund_execution_enabled);
        $this->assertFalse($settings->earning_reconciliation_execution_enabled);
    }

    public function test_financial_disposition_cannot_enable_while_earnings_disabled(): void
    {
        $readiness = $this->configuration->evaluateFinancialDispositionReadiness();
        $this->assertContains('earnings_disabled', $readiness->blockingCodes);

        $this->expectException(CompensationException::class);

        $this->configuration->enableFinancialDisposition($this->superAdmin());
    }

    public function test_financial_disposition_enable_succeeds_when_earnings_enabled(): void
    {
        $this->setFinancialSettings(['earnings_enabled' => true]);

        $readiness = $this->configuration->enableFinancialDisposition($this->superAdmin());

        $this->assertTrue($readiness->isReady);
        $this->assertTrue(app(InstructorEarningSettings::class)->refresh()->financial_disposition_enabled);
    }

    public function test_lesson_refund_execution_cannot_enable_while_financial_disposition_disabled(): void
    {
        $readiness = $this->configuration->evaluateLessonRefundExecutionReadiness();
        $this->assertContains('financial_disposition_disabled', $readiness->blockingCodes);

        $this->expectException(CompensationException::class);

        $this->configuration->enableLessonRefundExecution($this->superAdmin());
    }

    public function test_lesson_refund_execution_enable_succeeds_when_financial_disposition_enabled(): void
    {
        $this->setFinancialSettings(['earnings_enabled' => true, 'financial_disposition_enabled' => true]);

        $readiness = $this->configuration->enableLessonRefundExecution($this->superAdmin());

        $this->assertTrue($readiness->isReady);
        $this->assertTrue(app(InstructorEarningSettings::class)->refresh()->lesson_refund_execution_enabled);
    }

    public function test_earning_reconciliation_execution_cannot_enable_while_financial_disposition_disabled(): void
    {
        $this->setFinancialSettings(['earnings_enabled' => true]);

        $readiness = $this->configuration->evaluateEarningReconciliationExecutionReadiness();
        $this->assertContains('financial_disposition_disabled', $readiness->blockingCodes);

        $this->expectException(CompensationException::class);

        $this->configuration->enableEarningReconciliationExecution($this->superAdmin());
    }

    public function test_earning_reconciliation_execution_enable_succeeds_when_ready(): void
    {
        $this->setFinancialSettings(['earnings_enabled' => true, 'financial_disposition_enabled' => true]);

        $readiness = $this->configuration->enableEarningReconciliationExecution($this->superAdmin());

        $this->assertTrue($readiness->isReady);
        $this->assertTrue(app(InstructorEarningSettings::class)->refresh()->earning_reconciliation_execution_enabled);
    }

    public function test_disabling_financial_disposition_auto_disables_refund_and_reconciliation_execution(): void
    {
        $this->setFinancialSettings([
            'earnings_enabled' => true,
            'financial_disposition_enabled' => true,
            'lesson_refund_execution_enabled' => true,
            'earning_reconciliation_execution_enabled' => true,
        ]);

        $this->configuration->disableFinancialDisposition($this->superAdmin());

        $settings = app(InstructorEarningSettings::class)->refresh();
        $this->assertFalse($settings->financial_disposition_enabled);
        $this->assertFalse($settings->lesson_refund_execution_enabled);
        $this->assertFalse($settings->earning_reconciliation_execution_enabled);
    }

    public function test_filament_page_routes_switches_through_the_service(): void
    {
        $this->instructor(); // payable, no agreement → preflight blocks

        Livewire::actingAs($this->superAdmin())
            ->test(InstructorEarningSettingsPage::class)
            ->set('data.earnings_enabled', true)
            ->call('save');

        $this->assertFalse(app(InstructorEarningSettings::class)->refresh()->earnings_enabled);
    }

    // ── Payout execution readiness ────────────────────────────────────

    public function test_payout_execution_cannot_enable_while_withdrawals_disabled(): void
    {
        $this->setFinancialSettings(['earnings_enabled' => true]);

        $readiness = $this->configuration->evaluatePayoutExecutionReadiness();
        $this->assertContains('withdrawals_disabled', $readiness->blockingCodes);

        $this->expectException(CompensationException::class);
        $this->configuration->enablePayoutExecution($this->superAdmin());
    }

    public function test_payout_execution_enable_succeeds_when_earnings_and_withdrawals_are_on(): void
    {
        $this->setFinancialSettings(['earnings_enabled' => true, 'withdrawals_enabled' => true]);

        $readiness = $this->configuration->enablePayoutExecution($this->superAdmin());

        $this->assertTrue($readiness->isReady, $readiness->summary());
        $this->assertTrue(app(InstructorEarningSettings::class)->refresh()->payout_execution_enabled);

        $this->configuration->disablePayoutExecution($this->superAdmin());
        $this->assertFalse(app(InstructorEarningSettings::class)->refresh()->payout_execution_enabled);
    }

    public function test_payout_execution_readiness_fails_for_an_unregistered_provider(): void
    {
        // 'razorpayx' is a registered adapter — use a key that is
        // genuinely never registered to keep testing this failure mode
        // specifically.
        $this->setFinancialSettings(['earnings_enabled' => true, 'withdrawals_enabled' => true, 'payout_provider' => 'stripe_connect']);

        $readiness = $this->configuration->evaluatePayoutExecutionReadiness();
        $this->assertFalse($readiness->isReady);
        $this->assertContains('provider_not_configured', $readiness->blockingCodes);

        $this->setFinancialSettings(['payout_provider' => 'fake']);
    }

    public function test_disabling_payout_execution_does_not_touch_withdrawals(): void
    {
        $this->setFinancialSettings(['earnings_enabled' => true, 'withdrawals_enabled' => true, 'payout_execution_enabled' => true]);

        $this->configuration->disablePayoutExecution($this->superAdmin());

        $settings = app(InstructorEarningSettings::class)->refresh();
        $this->assertFalse($settings->payout_execution_enabled);
        $this->assertTrue($settings->withdrawals_enabled, 'Withdrawals must stay independent of payout-execution readiness (§17).');
    }

    public function test_filament_page_blocks_payout_execution_switch_without_the_configure_permission(): void
    {
        $this->setFinancialSettings(['earnings_enabled' => true, 'withdrawals_enabled' => true]);

        // A manager without Configure:InstructorPayoutExecution must be
        // refused server-side. Also needs the unrelated settings.* page-
        // access permission (HasSettingsAccess) just to load this page.
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $manager->assignRole('manager');
        Permission::firstOrCreate(['name' => 'settings.instructor-earnings', 'guard_name' => 'web']);
        $manager->givePermissionTo('settings.instructor-earnings');

        Livewire::actingAs($manager)
            ->test(InstructorEarningSettingsPage::class)
            ->set('data.payout_execution_enabled', true)
            ->call('save');

        $this->assertFalse(app(InstructorEarningSettings::class)->refresh()->payout_execution_enabled, 'A manager without Configure:InstructorPayoutExecution must not be able to enable it.');

        $this->setFinancialSettings(['earnings_enabled' => false, 'withdrawals_enabled' => false]);
    }

    public function test_filament_page_routes_payout_execution_switch_through_the_service_with_permission(): void
    {
        $this->setFinancialSettings(['earnings_enabled' => true, 'withdrawals_enabled' => true]);

        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $manager->assignRole('manager');
        Permission::firstOrCreate(['name' => 'Configure:InstructorPayoutExecution', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'settings.instructor-earnings', 'guard_name' => 'web']);
        $manager->givePermissionTo(['Configure:InstructorPayoutExecution', 'settings.instructor-earnings']);

        Livewire::actingAs($manager)
            ->test(InstructorEarningSettingsPage::class)
            ->set('data.payout_execution_enabled', true)
            ->call('save');

        $this->assertTrue(app(InstructorEarningSettings::class)->refresh()->payout_execution_enabled);

        $this->setFinancialSettings(['earnings_enabled' => false, 'withdrawals_enabled' => false, 'payout_execution_enabled' => false]);
    }

    // ── Retry backoff ────────────────────────────────────────────────

    public function test_retry_selection_respects_backoff_and_exhaustion(): void
    {
        $this->setFinancialSettings(['earnings_enabled' => true]);

        $lesson = $this->blockedLesson();
        $exception = InstructorCompensationException::query()->where('lesson_id', $lesson->id)->sole();

        // Attempt 1 → due at the next sweep (no delay).
        $this->assertSame(1, $exception->attempt_count);
        $this->assertTrue($exception->next_retry_at <= now());
        $this->assertSame(1, InstructorCompensationException::query()->dueForRetry()->count());

        // Attempt 2 → +2h backoff: no longer due, the sweep skips it.
        app(InstructorEarningServiceInterface::class)->createFromLesson($lesson->fresh());
        $exception->refresh();
        $this->assertSame(2, $exception->attempt_count);
        $this->assertTrue($exception->next_retry_at > now()->addMinutes(90));
        $this->assertSame(0, InstructorCompensationException::query()->dueForRetry()->count());

        $this->artisan('instructor-earnings:retry-blocked-lessons')
            ->expectsOutputToContain('Retried 0 blocked lesson(s)')
            ->assertSuccessful();
        $this->assertSame(2, $exception->fresh()->attempt_count);

        // Exhaustion: at the configured max the sweep excludes it for good.
        $this->setFinancialSettings(['compensation_retry_max_attempts' => 3]);
        app(InstructorEarningServiceInterface::class)->createFromLesson($lesson->fresh());

        $exception->refresh();
        $this->assertSame(3, $exception->attempt_count);
        $this->assertNotNull($exception->retry_exhausted_at);
        $this->assertNull($exception->next_retry_at);
        $this->assertSame(0, InstructorCompensationException::query()->dueForRetry()->count());

        $this->setFinancialSettings(['earnings_enabled' => false, 'compensation_retry_max_attempts' => 10]);
    }

    public function test_permanent_failures_are_never_due_for_retry(): void
    {
        $lesson = Lesson::factory()->completed()->create();

        app(CompensationExceptionService::class)->record(
            $lesson, CompensationExceptionCategory::PermanentlyIneligible, 'Never retried.',
        );

        $exception = InstructorCompensationException::query()->where('lesson_id', $lesson->id)->sole();
        $this->assertFalse($exception->retry_eligible);
        $this->assertNull($exception->next_retry_at);
        $this->assertSame(0, InstructorCompensationException::query()->dueForRetry()->count());
    }

    public function test_manual_retry_stays_permission_protected(): void
    {
        $lesson = Lesson::factory()->completed()->create();
        $exception = app(CompensationExceptionService::class)->record(
            $lesson, CompensationExceptionCategory::MissingAgreement, 'Blocked.',
        );

        $plain = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->assertFalse($plain->can('retry', $exception));

        Permission::firstOrCreate(['name' => 'Configure:InstructorCompensationAgreement', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->givePermissionTo('Configure:InstructorCompensationAgreement');
        $this->assertTrue($admin->can('retry', $exception));
    }

    // ── Consolidated schema & permission cleanup ─────────────────────

    public function test_fresh_schema_has_no_commission_columns_and_all_financial_constraints(): void
    {
        $earnings = Schema::getColumnListing('instructor_earnings');
        $this->assertNotContains('student_amount_minor', $earnings);
        $this->assertNotContains('platform_margin_minor', $earnings);
        $this->assertNotContains('calculation_value', $earnings);

        $indexNames = fn (string $table): array => array_column(Schema::getIndexes($table), 'name');

        $this->assertContains('ie_source_unique', $indexNames('instructor_earnings'));
        $this->assertContains('icp_agreement_period_unique', $indexNames('instructor_compensation_periods'));
        $this->assertContains('ica_active_owner_unique', $indexNames('instructor_compensation_agreements'));
        $this->assertContains('ipm_active_default_owner_unique', $indexNames('instructor_payout_methods'));
        $this->assertContains('iwr_instructor_idempotency_unique', $indexNames('instructor_withdrawal_requests'));
        $this->assertContains('iwa_request_earning_unique', $indexNames('instructor_withdrawal_allocations'));
        $this->assertContains('next_retry_at', Schema::getColumnListing('instructor_compensation_exceptions'));

        // Payout execution schema
        $this->assertContains('ipa_withdrawal_sequence_unique', $indexNames('instructor_payout_attempts'));
        $this->assertContains('ipa_provider_idempotency_unique', $indexNames('instructor_payout_attempts'));
        $this->assertContains('ippe_provider_event_unique', $indexNames('instructor_payout_provider_events'));
        $this->assertContains('ipri_open_dedupe_unique', $indexNames('instructor_payout_reconciliation_issues'));
        $this->assertContains('reversed_at', Schema::getColumnListing('instructor_withdrawal_allocations'));
        $this->assertContains('reversed_at', Schema::getColumnListing('instructor_withdrawal_requests'));
    }

    public function test_payout_attempts_and_reconciliation_issues_can_never_be_edited_or_deleted(): void
    {
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $manager->assignRole('manager');

        $attempt = InstructorPayoutAttempt::factory()->create();
        $issue = InstructorPayoutReconciliationIssue::factory()->create();

        $this->assertFalse($manager->can('update', $attempt));
        $this->assertFalse($manager->can('delete', $attempt));
        $this->assertFalse($manager->can('update', $issue));
        $this->assertFalse($manager->can('delete', $issue));
    }

    public function test_earnings_can_never_be_edited_or_deleted_by_anyone_below_super_admin(): void
    {
        $this->seed(InstructorEarningPermissionSeeder::class);

        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');
        $earning = InstructorEarning::factory()->create();

        $this->assertFalse($manager->can('update', $earning));
        $this->assertFalse($manager->can('delete', $earning));
        $this->assertFalse(Permission::query()->whereIn('name', [
            'Update:InstructorEarning', 'Delete:InstructorEarning',
            'Update:InstructorSettlementBatch', 'Delete:InstructorSettlementBatch',
        ])->exists());
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function instructor(): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('instructor');
        $user->profile->update(['instructor_status' => InstructorStatus::Active]);

        return $user;
    }

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('super_admin');

        return $admin;
    }

    private function blockedLesson(): Lesson
    {
        $instructor = $this->instructor();

        $lesson = Lesson::factory()->completed()->create([
            'booking_id' => Booking::factory()->confirmed()->create([
                'payment_status' => BookingPaymentStatus::Paid,
                'price' => '499.00',
                'currency' => 'INR',
            ])->id,
            'instructor_id' => $instructor->id,
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDays(2)->addMinutes(60),
            'completed_at' => now()->subDay(),
        ]);

        app(InstructorEarningServiceInterface::class)->createFromLesson($lesson);

        return $lesson;
    }
}
