<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Filament\Pages\RechargeMonitoring;
use App\Models\Currency;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Models\WalletRecharge;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Services\PaymentService;
use App\Reporting\Contracts\FinancialReportsServiceInterface;
use App\Reporting\Contracts\ReportRegistryInterface;
use App\Reporting\Repositories\WalletFinancialReportRepository;
use App\Wallet\Enums\WalletLedgerEntryType;
use App\Wallet\Enums\WalletRechargeOperationalClassification;
use App\Wallet\Enums\WalletRechargeStatus;
use App\Wallet\Enums\WalletStatus;
use App\Wallet\Services\WalletLedgerService;
use App\Wallet\Services\WalletRechargeReconciliationService;
use Carbon\CarbonImmutable;
use Database\Seeders\ReportingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use ReflectionClass;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class WalletRechargeMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReportingPermissionSeeder::class);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);
        Currency::query()->firstOrCreate(['code' => 'USD'], [
            'name' => 'US Dollar', 'symbol' => '$', 'numeric_code' => '840',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 2,
        ]);
    }

    private function manager(): User
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('manager');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin;
    }

    private function studentUser(): User
    {
        $student = User::factory()->create(['status' => 'active']);
        $student->assignRole('student');

        return $student;
    }

    /** @return array{0: User, 1: Wallet} student + their wallet, currency defaults to INR */
    private function studentWithWallet(string $currency = 'INR'): array
    {
        $student = User::factory()->create(['status' => 'active']);
        $wallet = Wallet::query()->create([
            'user_id' => $student->id,
            'currency_id' => Currency::query()->where('code', $currency)->value('id'),
            'currency_code' => $currency,
        ]);

        return [$student, $wallet];
    }

    /**
     * A recharge plus its real Payment attempt.
     *
     * `wallet_recharges` carries no provider columns, so a `provider`
     * override is applied where provider identity actually lives — the
     * attempt. Hand-building rows here would let the fixtures describe
     * a shape the application can no longer produce.
     */
    private function recharge(Wallet $wallet, array $overrides = []): WalletRecharge
    {
        $provider = $overrides['provider'] ?? 'razorpay';
        $paymentStatus = $overrides['payment_status'] ?? PaymentStatus::Pending;
        unset($overrides['provider'], $overrides['payment_status']);

        $recharge = WalletRecharge::query()->create(array_merge([
            'wallet_id' => $wallet->id,
            'user_id' => $wallet->user_id,
            'amount_minor' => 50000,
            'currency_code' => $wallet->currency_code,
            'status' => WalletRechargeStatus::Requested,
            'reference' => 'WRCH-'.strtoupper(uniqid()),
        ], $overrides));

        $payments = app(PaymentService::class);
        $payment = $payments->startAttempt($recharge, $provider, $recharge->reference);
        $payments->recordProviderOrder($payment, 'order_'.uniqid());

        if ($paymentStatus !== PaymentStatus::Pending) {
            $payments->transition($payment->refresh(), $paymentStatus);
        }

        return $recharge->refresh();
    }

    // ── Access ───────────────────────────────────────────────────────────

    public function test_authorized_administrator_can_access_the_page(): void
    {
        $this->actingAs($this->manager())
            ->get(RechargeMonitoring::getUrl())
            ->assertOk()
            ->assertSee('Recharge Monitoring');
    }

    public function test_unauthorized_user_receives_the_standard_denial(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)->get(RechargeMonitoring::getUrl())->assertForbidden();
        $this->assertFalse(RechargeMonitoring::canAccess());
    }

    public function test_navigation_is_hidden_without_permission(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->actingAs($user);

        $this->assertFalse(RechargeMonitoring::canAccess());
    }

    public function test_student_cannot_access_the_page(): void
    {
        $this->actingAs($this->studentUser())
            ->get(RechargeMonitoring::getUrl())
            ->assertForbidden();
    }

    // ── Read-only safety ─────────────────────────────────────────────────

    public function test_the_page_renders_no_mutation_controls(): void
    {
        [, $wallet] = $this->studentWithWallet();
        $this->recharge($wallet);

        $response = $this->actingAs($this->manager())->get(RechargeMonitoring::getUrl())->assertOk();
        $html = $response->getContent();

        // Specific action affordances, never generic words that could
        // legitimately appear elsewhere in the panel's own navigation
        // chrome (e.g. the sibling "Wallet & Refunds" nav label).
        foreach ([
            'wire:click="retry',
            'wire:click="credit',
            'wire:click="markSuccessful',
            'wire:click="markSuccess',
            'wire:click="refund',
            'wire:click="forceSettle',
            'wire:click="delete',
            'wire:click="restore',
            'wire:click="update',
            'Mark successful', 'Mark as successful', 'Credit wallet', 'Force settle',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html, "Found forbidden mutation affordance: {$forbidden}");
        }
    }

    public function test_the_page_class_exposes_no_public_mutation_action(): void
    {
        $publicMethods = array_map(
            fn (ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(RechargeMonitoring::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        foreach (['retry', 'credit', 'markSuccessful', 'markSuccess', 'refund', 'forceSettle', 'delete', 'restore', 'update', 'edit'] as $forbidden) {
            $this->assertNotContains($forbidden, $publicMethods, "RechargeMonitoring must not expose a public '{$forbidden}' action.");
        }
    }

    // ── Visibility ───────────────────────────────────────────────────────

    public function test_razorpay_and_stripe_attempts_both_appear(): void
    {
        [, $wallet] = $this->studentWithWallet();
        $this->recharge($wallet, ['provider' => 'razorpay', 'reference' => 'WRCH-RZP1']);
        $this->recharge($wallet, ['provider' => 'stripe', 'reference' => 'WRCH-STR1']);

        $this->actingAs($this->manager())->get(RechargeMonitoring::getUrl())
            ->assertOk()
            ->assertSee('WRCH-RZP1')
            ->assertSee('WRCH-STR1');
    }

    public function test_status_filter_narrows_results(): void
    {
        [, $wallet] = $this->studentWithWallet();
        $this->recharge($wallet, ['status' => WalletRechargeStatus::Requested, 'reference' => 'WRCH-PC1']);
        $this->recharge($wallet, ['status' => WalletRechargeStatus::Failed, 'failed_at' => now(), 'reference' => 'WRCH-FAIL1']);

        Livewire::actingAs($this->manager())
            ->test(RechargeMonitoring::class)
            ->set('rechargeStatus', WalletRechargeStatus::Failed->value)
            ->assertSee('WRCH-FAIL1')
            ->assertDontSee('WRCH-PC1');
    }

    public function test_provider_filter_narrows_results(): void
    {
        [, $wallet] = $this->studentWithWallet();
        $this->recharge($wallet, ['provider' => 'razorpay', 'reference' => 'WRCH-RZP2']);
        $this->recharge($wallet, ['provider' => 'stripe', 'reference' => 'WRCH-STR2']);

        Livewire::actingAs($this->manager())
            ->test(RechargeMonitoring::class)
            ->set('rechargeProvider', 'stripe')
            ->assertSee('WRCH-STR2')
            ->assertDontSee('WRCH-RZP2');
    }

    public function test_currency_filter_narrows_results(): void
    {
        [, $inrWallet] = $this->studentWithWallet('INR');
        [, $usdWallet] = $this->studentWithWallet('USD');
        $this->recharge($inrWallet, ['reference' => 'WRCH-INR1']);
        $this->recharge($usdWallet, ['currency_code' => 'USD', 'reference' => 'WRCH-USD1']);

        Livewire::actingAs($this->manager())
            ->test(RechargeMonitoring::class)
            ->set('currencyCode', 'USD')
            ->assertSee('WRCH-USD1')
            ->assertDontSee('WRCH-INR1');
    }

    public function test_internal_reference_search_works(): void
    {
        [, $wallet] = $this->studentWithWallet();
        $this->recharge($wallet, ['reference' => 'WRCH-FINDME1']);
        $this->recharge($wallet, ['reference' => 'WRCH-OTHER1']);

        Livewire::actingAs($this->manager())
            ->test(RechargeMonitoring::class)
            ->set('rechargeReference', 'FINDME')
            ->assertSee('WRCH-FINDME1')
            ->assertDontSee('WRCH-OTHER1');
    }

    public function test_captured_credit_pending_appears_in_captured_but_uncredited_filtering(): void
    {
        [, $wallet] = $this->studentWithWallet();
        $this->recharge($wallet, [
            'status' => WalletRechargeStatus::CreditPending,
            'payment_status' => PaymentStatus::Paid,
            'reference' => 'WRCH-CP1',
        ]);
        $this->recharge($wallet, ['status' => WalletRechargeStatus::Requested, 'reference' => 'WRCH-PC2']);

        Livewire::actingAs($this->manager())
            ->test(RechargeMonitoring::class)
            ->set('capturedUncreditedOnly', true)
            ->assertSee('WRCH-CP1')
            ->assertDontSee('WRCH-PC2');
    }

    public function test_credit_failed_appears_in_operational_exceptions(): void
    {
        [, $wallet] = $this->studentWithWallet();
        $recharge = $this->recharge($wallet, [
            'status' => WalletRechargeStatus::CreditFailed,
            'payment_status' => PaymentStatus::Paid,
            'failure_code' => 'wallet_not_usable',
            'reference' => 'WRCH-CF1',
        ]);

        $classification = WalletRechargeOperationalClassification::fromRecharge($recharge, CarbonImmutable::now()->subMinutes(10));
        $this->assertSame(WalletRechargeOperationalClassification::CapturedCreditFailed, $classification);
        $this->assertTrue($classification->isCapturedButUncredited());

        $this->actingAs($this->manager())->get(RechargeMonitoring::getUrl())
            ->assertOk()
            ->assertSee('WRCH-CF1')
            ->assertSee('Captured — credit failed');
    }

    public function test_provider_created_without_confirmation_is_not_classified_as_captured(): void
    {
        [, $wallet] = $this->studentWithWallet();
        $recharge = $this->recharge($wallet, ['status' => WalletRechargeStatus::Requested]);

        $classification = WalletRechargeOperationalClassification::fromRecharge($recharge, CarbonImmutable::now()->subMinutes(10));

        $this->assertFalse($classification->isCapturedButUncredited());
        $this->assertSame(WalletRechargeOperationalClassification::AwaitingPayment, $classification);
    }

    public function test_a_processing_stripe_intent_remains_pending_not_succeeded(): void
    {
        [, $wallet] = $this->studentWithWallet();
        $recharge = $this->recharge($wallet, ['provider' => 'stripe', 'status' => WalletRechargeStatus::Requested]);

        $classification = WalletRechargeOperationalClassification::fromRecharge($recharge, CarbonImmutable::now()->subMinutes(10));

        $this->assertNotSame(WalletRechargeOperationalClassification::Succeeded, $classification);
        $this->assertSame(WalletRechargeOperationalClassification::AwaitingPayment, $classification);
    }

    public function test_terminal_provider_failure_shows_no_ledger_credit(): void
    {
        [, $wallet] = $this->studentWithWallet();
        $this->recharge($wallet, ['status' => WalletRechargeStatus::Failed, 'failed_at' => now(), 'reference' => 'WRCH-TERMFAIL1']);

        $this->assertSame(0, WalletLedgerEntry::query()->where('wallet_id', $wallet->id)->count());

        $this->actingAs($this->manager())->get(RechargeMonitoring::getUrl())
            ->assertOk()
            ->assertSee('WRCH-TERMFAIL1')
            ->assertSee('Provider terminal failure');
    }

    public function test_a_succeeded_recharge_links_to_exactly_one_recharge_confirmed_ledger_entry(): void
    {
        [$student, $wallet] = $this->studentWithWallet();
        $recharge = $this->recharge($wallet, ['status' => WalletRechargeStatus::CreditPending, 'provider_confirmed_at' => now()]);

        app(WalletLedgerService::class)->credit(
            $wallet,
            $recharge->amount_minor,
            WalletLedgerEntryType::RechargeConfirmed,
            $student,
            idempotencyKey: "wallet-recharge:{$recharge->id}",
            sourceType: WalletRecharge::class,
            sourceId: (string) $recharge->id,
        );
        $recharge->forceFill(['status' => WalletRechargeStatus::Succeeded, 'succeeded_at' => now()])->save();

        $entries = WalletLedgerEntry::query()
            ->where('source_type', WalletRecharge::class)
            ->where('source_id', $recharge->id)
            ->where('entry_type', 'recharge_confirmed')
            ->get();

        $this->assertCount(1, $entries);

        $this->actingAs($this->manager())->get(RechargeMonitoring::getUrl())->assertOk();
    }

    public function test_a_reconciliation_recovered_recharge_appears_as_succeeded(): void
    {
        [, $wallet] = $this->studentWithWallet();
        Wallet::query()->whereKey($wallet->id)->update(['status' => WalletStatus::Active]);

        $recharge = $this->recharge($wallet, [
            'status' => WalletRechargeStatus::CreditFailed,
            'payment_status' => PaymentStatus::Paid,
            'failure_code' => 'wallet_not_usable',
            'reference' => 'WRCH-RECOVER1',
        ]);

        // Recovery from CreditFailed is the wallet-domain half of the
        // sweep: the money is already collected, so it needs no provider
        // call at all.
        app(WalletRechargeReconciliationService::class)->reconcileDue();

        $this->assertSame(WalletRechargeStatus::Succeeded, $recharge->fresh()->status);

        $this->actingAs($this->manager())->get(RechargeMonitoring::getUrl())
            ->assertOk()
            ->assertSee('WRCH-RECOVER1');
    }

    public function test_stale_classification_uses_age_correctly(): void
    {
        [, $wallet] = $this->studentWithWallet();

        $fresh = $this->recharge($wallet, ['status' => WalletRechargeStatus::Requested]);
        $stale = $this->recharge($wallet, ['status' => WalletRechargeStatus::Requested]);
        WalletRecharge::query()->whereKey($stale->id)->update(['created_at' => CarbonImmutable::now()->subMinutes(30)]);

        $cutoff = CarbonImmutable::now()->subMinutes(WalletRechargeReconciliationService::DUE_AFTER_MINUTES);

        $this->assertSame(WalletRechargeOperationalClassification::AwaitingPayment, WalletRechargeOperationalClassification::fromRecharge($fresh->fresh(), $cutoff));
        $this->assertSame(WalletRechargeOperationalClassification::StaleAwaitingPayment, WalletRechargeOperationalClassification::fromRecharge($stale->fresh(), $cutoff));
        $this->assertTrue(WalletRechargeOperationalClassification::StaleAwaitingPayment->isStale());
    }

    public function test_fake_provider_test_data_is_handled_safely(): void
    {
        [, $wallet] = $this->studentWithWallet();
        $this->recharge($wallet, ['provider' => 'fake', 'reference' => 'WRCH-FAKE1']);

        $this->actingAs($this->manager())->get(RechargeMonitoring::getUrl())
            ->assertOk()
            ->assertSee('WRCH-FAKE1')
            ->assertSee('Fake');
    }

    // ── Privacy and financial correctness ───────────────────────────────

    public function test_client_secret_and_sensitive_stripe_data_are_never_rendered(): void
    {
        [, $wallet] = $this->studentWithWallet();
        $this->recharge($wallet, [
            'provider' => 'stripe',
            'provider_order_id' => 'pi_SENSITIVE123456',
            'metadata' => ['wallet_recharge_reference' => 'WRCH-META1'],
        ]);

        $response = $this->actingAs($this->manager())->get(RechargeMonitoring::getUrl())->assertOk();

        foreach (['client_secret', 'pi_SENSITIVE123456', 'webhook', 'signature', 'X-Razorpay-Signature', 'Stripe-Signature'] as $forbidden) {
            $response->assertDontSee($forbidden);
        }
    }

    public function test_raw_metadata_is_never_rendered(): void
    {
        [, $wallet] = $this->studentWithWallet();
        $this->recharge($wallet, ['metadata' => ['secret_internal_note' => 'do-not-leak-this-value']]);

        $this->actingAs($this->manager())->get(RechargeMonitoring::getUrl())
            ->assertOk()
            ->assertDontSee('secret_internal_note')
            ->assertDontSee('do-not-leak-this-value');
    }

    public function test_raw_provider_exception_details_are_never_rendered(): void
    {
        [, $wallet] = $this->studentWithWallet();
        $this->recharge($wallet, [
            'status' => WalletRechargeStatus::Failed,
            'failed_at' => now(),
            'failure_code' => 'provider_reported_failure',
            'failure_reason' => 'Stripe\\Exception\\ApiErrorException: raw internal stack trace detail should never be a failure_code display',
        ]);

        // failure_code (a short machine classification) is safe and shown;
        // failure_reason (potentially containing raw exception text) is
        // deliberately not selected/rendered by the repository/view at all.
        $this->actingAs($this->manager())->get(RechargeMonitoring::getUrl())
            ->assertOk()
            ->assertDontSee('ApiErrorException')
            ->assertDontSee('raw internal stack trace');
    }

    public function test_student_identity_is_masked_without_the_student_identity_permission(): void
    {
        $manager = $this->manager();
        Role::findByName('manager', 'web')->revokePermissionTo('ViewStudentReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        [$student, $wallet] = $this->studentWithWallet();
        $student->forceFill(['first_name' => 'Confidential', 'name' => 'Confidential Studentname'])->save();
        $this->recharge($wallet);

        $this->actingAs($manager)->get(RechargeMonitoring::getUrl())
            ->assertOk()
            ->assertDontSee('Confidential Studentname')
            ->assertSee('C***');
    }

    public function test_student_identity_is_unmasked_with_the_student_identity_permission(): void
    {
        $manager = $this->manager();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        [$student, $wallet] = $this->studentWithWallet();
        $student->forceFill(['first_name' => 'Visible', 'name' => 'Visible Studentname'])->save();
        $this->recharge($wallet);

        $this->actingAs($manager)->get(RechargeMonitoring::getUrl())
            ->assertOk()
            ->assertSee('Visible Studentname');
    }

    public function test_provider_identifiers_are_masked(): void
    {
        [, $wallet] = $this->studentWithWallet();
        $recharge = $this->recharge($wallet);
        Payment::query()
            ->where('payable_type', WalletRecharge::PAYABLE_TYPE)
            ->where('payable_id', $recharge->id)
            ->update(['provider_order_id' => 'order_ABCDEFGHIJKLMNOP']);

        $response = $this->actingAs($this->manager())->get(RechargeMonitoring::getUrl())->assertOk();

        $response->assertDontSee('order_ABCDEFGHIJKLMNOP');
        // Masked convention: first 4 + … + last 4.
        $response->assertSee('orde…MNOP', false);
    }

    public function test_amounts_use_minor_unit_formatting(): void
    {
        [, $wallet] = $this->studentWithWallet();
        $this->recharge($wallet, ['amount_minor' => 123456]);

        $this->actingAs($this->manager())->get(RechargeMonitoring::getUrl())
            ->assertOk()
            ->assertSee('1,234.56');
    }

    public function test_multiple_currencies_are_never_summed_together(): void
    {
        [, $inrWallet] = $this->studentWithWallet('INR');
        [, $usdWallet] = $this->studentWithWallet('USD');
        $this->recharge($inrWallet, ['amount_minor' => 100000]);
        $this->recharge($usdWallet, ['currency_code' => 'USD', 'amount_minor' => 200000]);

        $response = $this->actingAs($this->manager())->get(RechargeMonitoring::getUrl())->assertOk();

        // No cross-currency combined total anywhere on the page.
        $response->assertDontSee('3,000.00');
        $response->assertDontSee('300000');
    }

    public function test_pagination_prevents_unbounded_result_loading(): void
    {
        [, $wallet] = $this->studentWithWallet();
        for ($i = 0; $i < 30; $i++) {
            $this->recharge($wallet, ['reference' => 'WRCH-PAGE'.$i]);
        }

        $rows = app(FinancialReportsServiceInterface::class)->paginatedRechargeMonitoring($this->manager(), [], null, 25);

        $this->assertSame(25, $rows->perPage());
        $this->assertLessThanOrEqual(25, $rows->count());
        $this->assertSame(30, $rows->total());
    }

    public function test_query_count_remains_bounded_without_n_plus_one_behaviour(): void
    {
        $manager = $this->manager();

        [, $wallet] = $this->studentWithWallet();
        for ($i = 0; $i < 3; $i++) {
            $this->recharge($wallet, ['reference' => 'WRCH-NPLUS1SMALL'.$i]);
        }

        DB::enableQueryLog();
        app(FinancialReportsServiceInterface::class)->paginatedRechargeMonitoring($manager, [], null, 25);
        $smallCount = count(DB::getQueryLog());
        DB::flushQueryLog();

        for ($i = 0; $i < 20; $i++) {
            $this->recharge($wallet, ['reference' => 'WRCH-NPLUS1BIG'.$i]);
        }
        DB::flushQueryLog();

        app(FinancialReportsServiceInterface::class)->paginatedRechargeMonitoring($manager, [], null, 25);
        $bigCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 17 more rows on the page (still within one page of 25) must not
        // add anywhere near 17 more queries — the eager-loaded `user`
        // relation is what prevents one query per row.
        $this->assertLessThanOrEqual($smallCount + 2, $bigCount, "Query count grew from {$smallCount} to {$bigCount} — looks like N+1.");
    }

    // ── Repository/service ───────────────────────────────────────────────

    public function test_status_breakdown_counts_are_correct(): void
    {
        [, $wallet] = $this->studentWithWallet();
        $this->recharge($wallet, ['status' => WalletRechargeStatus::Requested]);
        $this->recharge($wallet, ['status' => WalletRechargeStatus::Requested]);
        $this->recharge($wallet, ['status' => WalletRechargeStatus::Failed, 'failed_at' => now()]);

        $breakdown = app(WalletFinancialReportRepository::class)->rechargeAttemptStatusBreakdown();

        $this->assertSame(2, $breakdown[WalletRechargeStatus::Requested->value] ?? 0);
        $this->assertSame(1, $breakdown[WalletRechargeStatus::Failed->value] ?? 0);
    }

    public function test_captured_but_uncredited_selection_is_correct(): void
    {
        [, $wallet] = $this->studentWithWallet();
        $this->recharge($wallet, ['status' => WalletRechargeStatus::CreditPending, 'provider_confirmed_at' => now()]);
        $this->recharge($wallet, ['status' => WalletRechargeStatus::CreditFailed, 'provider_confirmed_at' => now()]);
        $this->recharge($wallet, ['status' => WalletRechargeStatus::Requested]);

        $uncredited = app(WalletFinancialReportRepository::class)->uncreditedCapturedRecharges();

        $this->assertCount(2, $uncredited);
    }

    public function test_stale_selection_is_correct(): void
    {
        [, $wallet] = $this->studentWithWallet();
        $recent = $this->recharge($wallet, ['status' => WalletRechargeStatus::Requested]);
        $old = $this->recharge($wallet, ['status' => WalletRechargeStatus::Requested]);
        WalletRecharge::query()->whereKey($old->id)->update(['created_at' => CarbonImmutable::now()->subMinutes(30)]);

        $rows = app(FinancialReportsServiceInterface::class)->paginatedRechargeMonitoring($this->manager(), ['staleOnly' => true], null, 25);

        $ids = collect($rows->items())->pluck('id')->all();
        $this->assertContains($old->id, $ids);
        $this->assertNotContains($recent->id, $ids);
    }

    public function test_succeeded_and_terminal_rows_are_excluded_from_retry_needed_classification(): void
    {
        $cutoff = CarbonImmutable::now()->subMinutes(10);
        [, $wallet] = $this->studentWithWallet();

        $succeeded = $this->recharge($wallet, ['status' => WalletRechargeStatus::Succeeded, 'succeeded_at' => now()]);
        $failed = $this->recharge($wallet, ['status' => WalletRechargeStatus::Failed, 'failed_at' => now()]);
        $cancelled = $this->recharge($wallet, ['status' => WalletRechargeStatus::Cancelled]);

        foreach ([$succeeded, $failed, $cancelled] as $recharge) {
            $classification = WalletRechargeOperationalClassification::fromRecharge($recharge, $cutoff);
            $this->assertFalse($classification->isCapturedButUncredited(), $classification->value);
        }
    }

    public function test_credit_pending_and_credit_failed_remain_retry_visible(): void
    {
        $cutoff = CarbonImmutable::now()->subMinutes(10);
        [, $wallet] = $this->studentWithWallet();

        $pending = $this->recharge($wallet, ['status' => WalletRechargeStatus::CreditPending, 'provider_confirmed_at' => now()]);
        $failed = $this->recharge($wallet, ['status' => WalletRechargeStatus::CreditFailed, 'provider_confirmed_at' => now()]);

        $this->assertTrue(WalletRechargeOperationalClassification::fromRecharge($pending, $cutoff)->isCapturedButUncredited());
        $this->assertTrue(WalletRechargeOperationalClassification::fromRecharge($failed, $cutoff)->isCapturedButUncredited());
        $this->assertTrue($pending->status->needsCreditRetry());
        $this->assertTrue($failed->status->needsCreditRetry());
    }

    public function test_provider_and_currency_filters_cannot_cross_contaminate_results(): void
    {
        [, $inrWallet] = $this->studentWithWallet('INR');
        [, $usdWallet] = $this->studentWithWallet('USD');
        $this->recharge($inrWallet, ['provider' => 'razorpay', 'reference' => 'WRCH-XCTN1']);
        $this->recharge($usdWallet, ['provider' => 'stripe', 'currency_code' => 'USD', 'reference' => 'WRCH-XCTN2']);

        $rows = app(FinancialReportsServiceInterface::class)->paginatedRechargeMonitoring(
            $this->manager(),
            ['provider' => 'razorpay', 'currencyCode' => 'USD'],
            null,
            25,
        );

        // No row can satisfy provider=razorpay AND currency=USD given the fixtures above.
        $this->assertCount(0, $rows->items());
    }

    public function test_recharge_monitoring_is_registered_available_with_a_real_route(): void
    {
        $registry = app(ReportRegistryInterface::class);
        $definition = $registry->find('recharge_monitoring');

        $this->assertNotNull($definition);
        $this->assertTrue($definition->available);
        $this->assertTrue($definition->financial);
        $this->assertSame(RechargeMonitoring::class, $definition->routeName);
    }
}
