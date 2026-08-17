<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\Enums\PaymentCollectionRolloutScope;
use App\Booking\Exceptions\GatewayRequestException;
use App\Booking\Payments\RazorpayPaymentProvider;
use App\Enums\StudentStatus;
use App\Exceptions\Student\StudentActionNotAvailableException;
use App\Livewire\Frontend\Student\WalletOverview;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Models\WalletRecharge;
use App\Payments\Enums\PaymentReconciliationIssueType;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Exceptions\PaymentException;
use App\Payments\Services\PaymentCallbackVerifier;
use App\Payments\Services\PaymentService;
use App\Reporting\Repositories\WalletFinancialReportRepository;
use App\Settings\BookingSettings;
use App\Settings\FeatureSettings;
use App\Settings\PaymentGatewaySettings;
use App\Wallet\Contracts\WalletRechargeServiceInterface;
use App\Wallet\Enums\WalletRechargeStatus;
use App\Wallet\Enums\WalletStatus;
use App\Wallet\Exceptions\WalletException;
use App\Wallet\Services\WalletLedgerService;
use App\Wallet\Services\WalletRechargeSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\Support\EstablishesRechargeMarket;
use Tests\Support\InitiatesWalletRecharges;
use Tests\TestCase;

class WalletRechargeTest extends TestCase
{
    use EstablishesRechargeMarket;
    use InitiatesWalletRecharges;
    use RefreshDatabase;

    private Country $india;

    protected function setUp(): void
    {
        parent::setUp();

        $inr = Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);

        // Wallet recharge is external money collection and now passes the
        // same PaymentCollectionEligibilityService gate as booking
        // collection, so these fixtures must describe a real ACTIVE
        // market — an active Country with a Razorpay route, and payment
        // collection enabled under a rollout scope. Previously recharge
        // consulted only the provider's own currency list, so a student
        // whose country was inactive (or absent entirely) could still
        // fund a wallet; these tests passed without a country because
        // nothing asked for one.
        // Country::payment_routing is the FIRST step of the resolver's
        // routing order, so it — not BookingSettings — decides which
        // provider these fixtures exercise. Most cases here only need
        // "some working provider" and use `fake`; the Razorpay-specific
        // cases re-route via configureRazorpay().
        $this->india = Country::query()->updateOrCreate(['iso2' => 'IN'], [
            'name' => 'India',
            'status' => 'active',
            'default_currency_id' => $inr->id,
            'payment_routing' => ['provider' => 'fake', 'enabled' => true],
        ]);

        $gateways = app(PaymentGatewaySettings::class);
        $gateways->payments_enabled = true;
        $gateways->payment_collection_rollout_scope = PaymentCollectionRolloutScope::ActiveCountryRouting->value;
        $gateways->save();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        app(FeatureSettings::class)->wallet_enabled = true;
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function student(): User
    {
        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);

        UserProfile::updateOrCreate(['user_id' => $student->id], ['country_id' => $this->india->id]);

        return $student->refresh();
    }

    /**
     * The per-currency recharge limits (SRS §13.12) this test wants
     * enforced. They live on `currencies`, in that currency's own minor
     * units — there is no platform-wide recharge limit to set, because
     * one number cannot mean ₹500 and $10 at the same time.
     */
    private function setRechargeLimits(string $code, ?int $minMinor, ?int $maxMinor): void
    {
        Currency::query()->where('code', $code)->update([
            'minimum_recharge_minor' => $minMinor,
            'maximum_recharge_minor' => $maxMinor,
        ]);
    }

    private function service(): WalletRechargeServiceInterface
    {
        return app(WalletRechargeServiceInterface::class);
    }

    private function configureRazorpay(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'rzp_test_key_id';
        $gateways->razorpay_key_secret = Crypt::encryptString('test_key_secret');
        $gateways->razorpay_webhook_secret = Crypt::encryptString('test_webhook_secret');
        $gateways->save();

        app(BookingSettings::class)->payment_provider = 'razorpay';
        app(BookingSettings::class)->save();

        // Country routing outranks BookingSettings, so a Razorpay test
        // must re-route the market too — otherwise it silently keeps
        // collecting through `fake`.
        Country::query()->whereKey($this->india->id)->update([
            'payment_routing' => json_encode(['provider' => 'razorpay', 'enabled' => true]),
        ]);
    }

    /**
     * Razorpay with International Payments attested — the only way a
     * non-INR recharge is collectable, exactly as for a non-INR booking.
     * Recharge reads this through the provider's approved-currency list,
     * so there is no wallet-specific international switch to set.
     */
    private function configureRazorpayInternational(): void
    {
        $this->configureRazorpay();

        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_international_enabled = true;
        $gateways->razorpay_international_currencies = RazorpayPaymentProvider::DEFAULT_INTERNATIONAL_CURRENCIES;
        $gateways->save();

        $this->fakeRazorpayOrderApi();
    }

    private function fakeRazorpayOrderApi(string $orderId = 'order_TEST123'): void
    {
        $gateway = Mockery::mock(RazorpayGatewayClient::class);
        $gateway->shouldReceive('createOrder')->andReturn(['id' => $orderId]);
        $this->app->instance(RazorpayGatewayClient::class, $gateway);
    }

    // ── Initiation ───────────────────────────────────────────────────────

    public function test_eligible_student_initiates_a_recharge(): void
    {
        $student = $this->student();

        $checkout = $this->service()->initiate($student, 50000);

        $this->assertSame('fake', $checkout->provider);
        $this->assertSame(50000, $checkout->amountMinor);
        $this->assertSame('INR', $checkout->currencyCode);

        $payment = Payment::query()->whereKey($checkout->paymentId)->sole();
        $recharge = WalletRecharge::query()->whereKey($payment->payable_id)->sole();
        $this->assertSame(WalletRechargeStatus::Requested, $recharge->status);
        $this->assertSame($student->id, $recharge->user_id);

        // Provider identity lives on the Payment attempt and NOWHERE
        // else — the whole point of the ledger cutover.
        $this->assertSame(WalletRecharge::PAYABLE_TYPE, $payment->payable_type);
        $this->assertSame($recharge->id, $payment->payable_id);
        $this->assertSame($student->id, (int) $payment->user_id);
        $this->assertSame(50000, (int) $payment->amount_minor);
        $this->assertSame('INR', $payment->currency_code);
        $this->assertNotNull($payment->provider_order_id);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
    }

    public function test_wallet_is_created_before_the_non_null_recharge_row_when_no_wallet_exists(): void
    {
        $student = $this->student();
        $this->assertNull(Wallet::query()->forUser($student->id)->first());

        [$recharge] = $this->initiateRecharge($student, 50000);

        $wallet = Wallet::query()->forUser($student->id)->sole();

        $this->assertNotNull($recharge->wallet_id);
        $this->assertSame($wallet->id, $recharge->wallet_id);
        $this->assertSame($student->id, $recharge->user_id);
    }

    public function test_a_restricted_student_lifecycle_rejects_initiation(): void
    {
        $student = $this->student();
        UserProfile::query()->where('user_id', $student->id)->update(['student_status' => StudentStatus::Suspended]);

        $this->expectException(StudentActionNotAvailableException::class);

        $this->service()->initiate($student, 50000);
    }

    public function test_wallet_ui_renders_restricted_student_error_instead_of_throwing_a_403(): void
    {
        $student = $this->student();
        UserProfile::query()->where('user_id', $student->id)->update(['student_status' => StudentStatus::Suspended]);

        Livewire::actingAs($student)
            ->test(WalletOverview::class)
            ->set('rechargeAmount', '500')
            ->call('initiateRecharge')
            ->assertSet('rechargeBanner', StudentActionNotAvailableException::make()->getMessage())
            ->assertSee('Your account is not available for this action. Please contact support.');

        $this->assertDatabaseCount('wallet_recharges', 0);
    }

    /**
     * The checkout partials reference `$wire`, which only exists inside
     * Livewire's own script scope. Included as bare <script> tags they
     * executed at page load with `$wire` undefined — the listeners were
     * never registered, so Razorpay Checkout could not open at all
     * ("Uncaught ReferenceError: $wire is not defined").
     *
     * `@script` captures its body via output buffering and pushes it
     * into the component's script store instead of emitting it inline,
     * so the absence of the handler from the component's own markup is
     * exactly the proof it is now correctly scoped.
     */
    public function test_checkout_scripts_are_registered_in_livewire_script_scope_not_inline(): void
    {
        $component = Livewire::actingAs($this->student())->test(WalletOverview::class);

        // stripInitialData removes the wire:snapshot/wire:effects
        // payloads, leaving the markup the browser actually renders at
        // page load. @script content belongs in the effects payload (from
        // where Livewire evaluates it with $wire bound), so finding a
        // handler in what remains means it is being emitted inline again.
        $renderedMarkup = $component->html(stripInitialData: true);

        $this->assertStringNotContainsString(
            '$wire.on(',
            $renderedMarkup,
            'Checkout handlers must be inside @script, not emitted inline where $wire is undefined.',
        );
        $this->assertStringNotContainsString('$wire.verifyWalletRecharge(', $renderedMarkup);

        // And the positive half: the handlers must genuinely still be
        // registered — a partial that stopped being included at all would
        // otherwise satisfy the assertions above.
        $this->assertStringContainsString('wallet-recharge-checkout-ready', $component->html());
        $this->assertStringContainsString('wallet-recharge-stripe-checkout-ready', $component->html());
    }

    public function test_wallet_feature_disabled_rejects_initiation(): void
    {
        app(FeatureSettings::class)->wallet_enabled = false;
        $student = $this->student();

        $this->expectException(WalletException::class);

        $this->service()->initiate($student, 50000);
    }

    public function test_frozen_wallet_rejects_new_initiation(): void
    {
        $student = $this->student();
        $wallet = Wallet::query()->create([
            'user_id' => $student->id,
            'currency_id' => Currency::query()->where('code', 'INR')->value('id'),
            'currency_code' => 'INR',
            'status' => WalletStatus::Frozen,
        ]);

        $this->expectException(WalletException::class);
        $this->expectExceptionMessage('frozen');

        $this->service()->initiate($student, 50000);
    }

    public function test_closed_wallet_rejects_initiation(): void
    {
        $student = $this->student();
        Wallet::query()->create([
            'user_id' => $student->id,
            'currency_id' => Currency::query()->where('code', 'INR')->value('id'),
            'currency_code' => 'INR',
            'status' => WalletStatus::Closed,
        ]);

        $this->expectException(WalletException::class);
        $this->expectExceptionMessage('closed');

        $this->service()->initiate($student, 50000);
    }

    public function test_zero_amount_is_rejected(): void
    {
        $this->expectException(WalletException::class);

        $this->service()->initiate($this->student(), 0);
    }

    public function test_negative_amount_is_rejected(): void
    {
        $this->expectException(WalletException::class);

        $this->service()->initiate($this->student(), -100);
    }

    public function test_below_minimum_amount_is_rejected(): void
    {
        $this->setRechargeLimits('INR', minMinor: 10_000, maxMinor: null); // ₹100.00

        $this->expectException(WalletException::class);
        $this->expectExceptionMessage('minimum');

        $this->service()->initiate($this->student(), 5000); // ₹50.00 < ₹100.00 minimum
    }

    public function test_above_maximum_amount_is_rejected(): void
    {
        $this->setRechargeLimits('INR', minMinor: null, maxMinor: 5_000_000); // ₹50,000.00

        $this->expectException(WalletException::class);
        $this->expectExceptionMessage('maximum');

        $this->service()->initiate($this->student(), 6_000_000); // ₹60,000.00 > ₹50,000.00 maximum
    }

    /**
     * The defect the per-currency move exists to fix. The retired
     * platform-wide `wallet.minimum_recharge_amount` was re-expressed in
     * each wallet's own minor units, so a single configured "500" meant
     * ₹500 in India AND $500 in the United States. A limit configured
     * for one currency must not constrain another.
     */
    public function test_a_limit_configured_for_one_currency_does_not_constrain_another(): void
    {
        $usd = Currency::query()->firstOrCreate(['code' => 'USD'], [
            'name' => 'US Dollar', 'symbol' => '$', 'numeric_code' => '840',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 2,
        ]);

        $usa = Country::query()->updateOrCreate(['iso2' => 'US'], [
            'name' => 'United States',
            'status' => 'active',
            'default_currency_id' => $usd->id,
            'payment_routing' => ['provider' => 'razorpay', 'enabled' => true],
        ]);

        // A high INR floor, and no USD floor at all.
        $this->setRechargeLimits('INR', minMinor: 50_000, maxMinor: null); // ₹500.00
        $this->setRechargeLimits('USD', minMinor: null, maxMinor: null);

        $this->configureRazorpayInternational();

        $student = $this->student();
        UserProfile::updateOrCreate(['user_id' => $student->id], ['country_id' => $usa->id]);

        // $10.00 — far below the INR floor's numeral, and accepted,
        // because the INR floor is a quantity of rupees and says nothing
        // about dollars.
        $checkout = $this->service()->initiate($student->refresh(), 1000);

        $this->assertSame('USD', $checkout->currencyCode);
        $this->assertSame(1000, $checkout->amountMinor);
    }

    public function test_inactive_currency_rejects_initiation(): void
    {
        $student = $this->student();
        Currency::query()->where('code', 'INR')->update(['status' => 'inactive']);

        $this->expectException(WalletException::class);

        $this->service()->initiate($student, 50000);
    }

    public function test_provider_capability_false_rejects_initiation(): void
    {
        $this->configureRazorpay();

        // A fully valid, ACTIVE, Razorpay-routed market whose only
        // problem is its currency: without the international attestation
        // Razorpay's approved set is INR alone, so GBP is unsupported.
        // Everything else about this market is deliberately correct, so
        // the rejection can only come from the currency capability gate
        // — an explicit ISO2 rather than Country::factory()'s random one,
        // which could collide with the India market seeded in setUp().
        $britain = $this->establishRechargeMarket('GB', 'GBP', provider: 'razorpay', numericCode: '826');

        $student = $this->attachStudentToMarket(
            User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]),
            $britain,
        );

        $this->expectException(WalletException::class);

        try {
            $this->service()->initiate($student, 5000);
        } finally {
            $this->assertDatabaseCount('wallet_recharges', 0);
        }
    }

    public function test_provider_creation_failure_creates_no_ledger_entry_and_marks_the_attempt_failed(): void
    {
        $this->configureRazorpay();
        $gateway = Mockery::mock(RazorpayGatewayClient::class);
        $gateway->shouldReceive('createOrder')->andThrow(new GatewayRequestException('network error'));
        $this->app->instance(RazorpayGatewayClient::class, $gateway);

        $student = $this->student();

        try {
            $this->service()->initiate($student, 50000);
            $this->fail('Expected a WalletException.');
        } catch (WalletException) {
            // expected
        }

        $recharge = WalletRecharge::query()->where('user_id', $student->id)->sole();
        $this->assertSame(WalletRechargeStatus::Failed, $recharge->status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $student->id)->count());

        // The generic ledger still holds durable evidence that we tried.
        $this->assertSame(PaymentStatus::Failed, Payment::query()
            ->where('payable_type', WalletRecharge::PAYABLE_TYPE)
            ->where('payable_id', $recharge->id)
            ->sole()
            ->status);
    }

    public function test_fake_provider_recharge_is_available_in_testing(): void
    {
        $checkout = $this->service()->initiate($this->student(), 50000);

        $this->assertSame('fake', $checkout->provider);
    }

    // ── Settlement ───────────────────────────────────────────────────────

    public function test_valid_authoritative_capture_credits_exact_minor_units(): void
    {
        Notification::fake();
        $student = $this->student();
        [$recharge, $payment] = $this->initiateRecharge($student, 50000);

        $result = $this->settle($payment, $this->capturedEvent($payment));

        $this->assertTrue($result->settled);
        $this->assertSame(WalletRechargeStatus::Succeeded, $result->recharge->status);
        $this->assertSame(PaymentStatus::Paid, $payment->refresh()->status);
        $this->assertSame(50000, Wallet::query()->forUser($student->id)->sole()->balance_minor);
    }

    public function test_exactly_one_ledger_entry_with_correct_source_linkage(): void
    {
        $student = $this->student();
        [$recharge, $payment] = $this->initiateRecharge($student, 50000);

        $this->settle($payment, $this->capturedEvent($payment));

        $entries = WalletLedgerEntry::query()->where('user_id', $student->id)->get();
        $this->assertCount(1, $entries);
        $this->assertSame(WalletRecharge::class, $entries->first()->source_type);
        $this->assertSame($recharge->id, $entries->first()->source_id);
        $this->assertSame(50000, $entries->first()->amount_minor);
        $this->assertSame('INR', $entries->first()->currency_code);
    }

    /**
     * A signature proves the message came from the provider; it proves
     * nothing about WHAT was collected. A mismatch must raise a durable
     * operator-visible issue on the canonical queue rather than a
     * wallet-only error record.
     */
    public function test_provider_amount_mismatch_never_credits_and_raises_an_issue(): void
    {
        $student = $this->student();
        [, $payment] = $this->initiateRecharge($student, 50000);

        $result = $this->settle($payment, $this->capturedEvent($payment, amountMinor: 1));

        $this->assertTrue($result->ignored);
        $this->assertNotSame(PaymentStatus::Paid, $payment->refresh()->status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
        $this->assertDatabaseHas('payment_reconciliation_issues', [
            'payment_id' => $payment->id,
            'issue_type' => PaymentReconciliationIssueType::AmountMismatch->value,
        ]);
    }

    /** No conversion, ever — a currency difference is a discrepancy, never arithmetic to reconcile. */
    public function test_provider_currency_mismatch_never_credits_and_raises_an_issue(): void
    {
        $student = $this->student();
        [, $payment] = $this->initiateRecharge($student, 50000);

        $result = $this->settle($payment, $this->capturedEvent($payment, currencyCode: 'USD'));

        $this->assertTrue($result->ignored);
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
        $this->assertDatabaseHas('payment_reconciliation_issues', [
            'payment_id' => $payment->id,
            'issue_type' => PaymentReconciliationIssueType::CurrencyMismatch->value,
        ]);
    }

    public function test_provider_mismatch_never_credits(): void
    {
        $student = $this->student();
        [, $payment] = $this->initiateRecharge($student, 50000);

        $result = $this->settle($payment, $this->capturedEvent($payment, provider: 'razorpay'));

        $this->assertTrue($result->ignored);
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
    }

    public function test_a_processing_event_never_credits(): void
    {
        $student = $this->student();
        [$recharge, $payment] = $this->initiateRecharge($student, 50000);

        $result = $this->settle($payment, $this->processingEvent($payment));

        $this->assertFalse($result->settled);
        $this->assertSame(PaymentStatus::Processing, $payment->refresh()->status);
        $this->assertSame(WalletRechargeStatus::Requested, $recharge->refresh()->status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
    }

    public function test_terminal_provider_failure_never_credits(): void
    {
        $student = $this->student();
        [$recharge, $payment] = $this->initiateRecharge($student, 50000);

        $result = $this->settle($payment, $this->failedEvent($payment));

        $this->assertFalse($result->settled);
        $this->assertSame(PaymentStatus::Failed, $payment->refresh()->status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
        $this->assertSame(0, Wallet::query()->forUser($student->id)->sole()->balance_minor);

        // The RECHARGE stays open: a failed attempt is not a failed
        // intent, and the student may retry with a NEW attempt.
        $this->assertSame(WalletRechargeStatus::Requested, $recharge->refresh()->status);
    }

    /** A failed attempt must not block a legitimate retry, and the retry must credit exactly once. */
    public function test_a_new_attempt_after_a_failed_one_settles_normally(): void
    {
        $student = $this->student();
        [$recharge, $first] = $this->initiateRecharge($student, 50000);
        $this->settle($first, $this->failedEvent($first));

        // The generic ledger permits a fresh attempt precisely because
        // the previous one is terminal — the one-open-attempt invariant.
        $second = app(PaymentService::class)->startAttempt($recharge->refresh(), 'fake', 'WRCH-RETRY-1');
        app(PaymentService::class)->recordProviderOrder($second, 'fake_order_retry_1');

        $this->settle($second->refresh(), $this->capturedEvent($second->refresh()));

        $this->assertSame(WalletRechargeStatus::Succeeded, $recharge->refresh()->status);
        $this->assertSame(50000, Wallet::query()->forUser($student->id)->sole()->balance_minor);
        $this->assertSame(1, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
    }

    public function test_duplicate_event_delivery_is_a_replay_and_does_not_double_credit(): void
    {
        $student = $this->student();
        [, $payment] = $this->initiateRecharge($student, 50000);
        $event = $this->capturedEvent($payment);

        $first = $this->settle($payment, $event);
        $second = $this->settle($payment->refresh(), $event);

        $this->assertTrue($first->settled);
        $this->assertTrue($second->replayed);
        $this->assertSame(50000, Wallet::query()->forUser($student->id)->sole()->balance_minor);
        $this->assertSame(1, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
    }

    // ── Two-phase settlement recovery ────────────────────────────────────

    /**
     * The case packages deliberately do not have. A frozen/closed wallet
     * is a REAL, persistent refusal, so the Payment must stay Paid — the
     * money genuinely was collected — while the recharge becomes durably
     * retryable. Pretending the payment failed would be a lie about
     * money SIRI holds.
     */
    public function test_a_credit_failure_keeps_the_payment_paid_and_leaves_recoverable_state(): void
    {
        $student = $this->student();
        [$recharge, $payment] = $this->initiateRecharge($student, 50000);

        Wallet::query()->whereKey($recharge->wallet_id)->update(['status' => WalletStatus::Closed]);

        $result = $this->settle($payment, $this->capturedEvent($payment));

        $this->assertTrue($result->creditFailed);
        $this->assertSame(PaymentStatus::Paid, $payment->refresh()->status);
        $this->assertSame(WalletRechargeStatus::CreditFailed, $recharge->refresh()->status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
        $this->assertSame(0, Wallet::query()->forUser($student->id)->sole()->balance_minor);

        // Recoverable AND visible — money collected that cannot reach a
        // wallet needs an operator, not a silent retry loop.
        $this->assertDatabaseHas('payment_reconciliation_issues', [
            'payment_id' => $payment->id,
            'issue_type' => PaymentReconciliationIssueType::SettlementFailed->value,
        ]);
    }

    public function test_retry_credit_needs_no_provider_call_and_credits_exactly_once(): void
    {
        $student = $this->student();
        [$recharge, $payment] = $this->initiateRecharge($student, 50000);
        Wallet::query()->whereKey($recharge->wallet_id)->update(['status' => WalletStatus::Closed]);
        $this->settle($payment, $this->capturedEvent($payment));

        Wallet::query()->whereKey($recharge->wallet_id)->update(['status' => WalletStatus::Active]);

        $paymentCountBefore = Payment::query()->count();
        $retried = app(WalletRechargeSettlementService::class)->retryCredit($recharge->refresh());

        $this->assertTrue($retried->settled);
        $this->assertSame(WalletRechargeStatus::Succeeded, $recharge->refresh()->status);
        $this->assertSame(50000, Wallet::query()->forUser($student->id)->sole()->balance_minor);
        $this->assertSame(1, WalletLedgerEntry::query()->where('user_id', $student->id)->where('entry_type', 'recharge_confirmed')->count());

        // No second Payment, and therefore no second charge.
        $this->assertSame($paymentCountBefore, Payment::query()->count());

        // A further retry is refused outright rather than crediting again.
        $this->expectException(WalletException::class);
        app(WalletRechargeSettlementService::class)->retryCredit($recharge->refresh());
    }

    /** A frozen wallet blocks debits, never credits — money owed still lands. */
    public function test_frozen_after_initiation_wallet_still_accepts_confirmed_credit(): void
    {
        $student = $this->student();
        [$recharge, $payment] = $this->initiateRecharge($student, 50000);
        Wallet::query()->whereKey($recharge->wallet_id)->update(['status' => WalletStatus::Frozen]);

        $result = $this->settle($payment, $this->capturedEvent($payment));

        $this->assertTrue($result->settled);
        $wallet = Wallet::query()->forUser($student->id)->sole();
        $this->assertSame(50000, $wallet->balance_minor);
        $this->assertSame(WalletStatus::Frozen, $wallet->status);
    }

    // ── Razorpay-specific ────────────────────────────────────────────────

    public function test_razorpay_recharge_capability_is_enabled(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi();
        $student = $this->student();

        $checkout = $this->service()->initiate($student, 50000);

        $this->assertSame('razorpay', $checkout->provider);
        $this->assertArrayHasKey('key_id', $checkout->checkoutPayload);
    }

    /**
     * The callback is browser-supplied and replayable, and Checkout.js
     * fires on AUTHORIZATION rather than capture. It may record WHICH
     * payment the browser reported and nothing else — the evidence, not
     * the verdict.
     */
    public function test_razorpay_callback_records_the_payment_id_but_never_credits_the_wallet(): void
    {
        Notification::fake();
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_SIG1');
        $student = $this->student();

        [$recharge, $payment] = $this->initiateRecharge($student, 50000);

        $paymentId = 'pay_SIG1';
        $signature = hash_hmac('sha256', 'order_SIG1|'.$paymentId, 'test_key_secret');

        $verified = app(PaymentCallbackVerifier::class)
            ->verifyRazorpayCheckout($recharge, 'order_SIG1', $paymentId, $signature);

        // Evidence recorded on the PAYMENT — never on the recharge,
        // which owns no provider identity at all.
        $this->assertSame($paymentId, $verified->provider_payment_id);
        $this->assertSame('order_SIG1', $verified->provider_order_id);
        $this->assertSame($payment->id, $verified->id);

        // Nothing settled: not the payment, not the recharge, not the
        // ledger, not the balance, and no notification or invoice.
        $this->assertSame(PaymentStatus::Pending, $verified->status);
        $this->assertSame(WalletRechargeStatus::Requested, $recharge->refresh()->status);
        $this->assertNull($recharge->succeeded_at);
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
        $this->assertSame(0, Wallet::query()->forUser($student->id)->sole()->balance_minor);
        $this->assertSame(0, Invoice::query()->where('user_id', $student->id)->count());
        Notification::assertNothingSent();
    }

    /** The webhook is what actually settles — and it still does, after a callback already ran. */
    public function test_the_webhook_credits_the_wallet_after_a_non_authoritative_callback(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_SIG2');
        $student = $this->student();

        [$recharge, $payment] = $this->initiateRecharge($student, 50000);
        $paymentId = 'pay_SIG2';
        app(PaymentCallbackVerifier::class)->verifyRazorpayCheckout(
            $recharge,
            'order_SIG2',
            $paymentId,
            hash_hmac('sha256', 'order_SIG2|'.$paymentId, 'test_key_secret'),
        );

        $this->settle($payment->refresh(), $this->capturedEvent($payment->refresh()));

        $this->assertSame(WalletRechargeStatus::Succeeded, $recharge->refresh()->status);
        $this->assertSame(PaymentStatus::Paid, $payment->refresh()->status);
        $this->assertSame(50000, Wallet::query()->forUser($student->id)->sole()->balance_minor);
        $this->assertSame(1, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
    }

    /** A callback replayed after settlement must not credit again, nor reopen a settled attempt. */
    public function test_a_replayed_callback_after_settlement_changes_nothing(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_SIG3');
        $student = $this->student();

        [$recharge, $payment] = $this->initiateRecharge($student, 50000);
        $this->settle($payment, $this->capturedEvent($payment));
        $this->assertSame(50000, Wallet::query()->forUser($student->id)->sole()->balance_minor);

        $paymentId = 'pay_SIG3';
        app(PaymentCallbackVerifier::class)->verifyRazorpayCheckout(
            $recharge,
            'order_SIG3',
            $paymentId,
            hash_hmac('sha256', 'order_SIG3|'.$paymentId, 'test_key_secret'),
        );

        $this->assertSame(WalletRechargeStatus::Succeeded, $recharge->refresh()->status);
        $this->assertSame(50000, Wallet::query()->forUser($student->id)->sole()->balance_minor);
        $this->assertSame(1, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
    }

    public function test_razorpay_invalid_signature_never_credits(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_BADSIG');
        $student = $this->student();
        [$recharge] = $this->initiateRecharge($student, 50000);

        $this->expectException(PaymentException::class);

        try {
            app(PaymentCallbackVerifier::class)
                ->verifyRazorpayCheckout($recharge, 'order_BADSIG', 'pay_BADSIG', 'not-a-real-signature');
        } finally {
            $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
        }
    }

    /**
     * IDOR: the attempt is resolved from the PAYABLE plus the order id,
     * so a signature that is genuinely valid for someone else's order
     * cannot attach itself to this student's recharge.
     */
    public function test_a_students_own_wallet_recharge_cannot_be_verified_against_another_students(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_OWNER1');
        $owner = $this->student();
        $intruder = $this->student();

        [$ownerRecharge] = $this->initiateRecharge($owner, 50000);

        $this->fakeRazorpayOrderApi('order_INTRUDER1');
        [$intruderRecharge] = $this->initiateRecharge($intruder, 50000);

        $paymentId = 'pay_OWNER1';
        $signature = hash_hmac('sha256', 'order_OWNER1|'.$paymentId, 'test_key_secret');

        // A perfectly valid signature for the OWNER's order, presented
        // against the intruder's own recharge.
        $this->expectException(PaymentException::class);

        try {
            app(PaymentCallbackVerifier::class)
                ->verifyRazorpayCheckout($intruderRecharge, 'order_OWNER1', $paymentId, $signature);
        } finally {
            $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $owner->id)->count());
            $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $intruder->id)->count());
        }
    }

    /** A signed event for one recharge must never settle another. */
    public function test_a_verified_event_cannot_settle_a_different_students_recharge(): void
    {
        $victim = $this->student();
        $attacker = $this->student();

        [, $victimPayment] = $this->initiateRecharge($victim, 50000);
        [$attackerRecharge, $attackerPayment] = $this->initiateRecharge($attacker, 50000);

        // The attacker's event, applied to the victim's payment: the
        // settlement service resolves the recharge from the PAYMENT's
        // own payable_id, never from the event, so the victim's recharge
        // is what settles — and the attacker gains nothing.
        $this->settle($victimPayment, $this->capturedEvent($victimPayment));

        $this->assertSame(50000, Wallet::query()->forUser($victim->id)->sole()->balance_minor);
        $this->assertSame(WalletRechargeStatus::Requested, $attackerRecharge->refresh()->status);
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $attacker->id)->count());
        $this->assertNotSame(PaymentStatus::Paid, $attackerPayment->refresh()->status);
    }

    // ── Reporting ────────────────────────────────────────────────────────

    public function test_successful_recharge_appears_in_the_wallet_statement(): void
    {
        $student = $this->student();
        [, $payment] = $this->initiateRecharge($student, 50000);

        $this->settle($payment, $this->capturedEvent($payment));

        $wallet = Wallet::query()->forUser($student->id)->sole();
        $statement = app(WalletLedgerService::class)->statement($wallet, 10);

        $this->assertTrue($statement->getCollection()->contains(fn ($entry) => $entry->entry_type->value === 'recharge_confirmed'));
    }

    public function test_open_and_failed_recharges_are_visible_operationally_but_not_in_the_ledger(): void
    {
        $student = $this->student();
        [, $openPayment] = $this->initiateRecharge($student, 50000);

        // A second recharge whose payment the provider terminally
        // failed. The RECHARGE stays open — a failed attempt is not a
        // failed intent — so operationally there are two `requested`
        // rows and no ledger movement at all.
        [, $failedPayment] = $this->initiateRecharge($student, 20000);
        $this->settle($failedPayment, $this->failedEvent($failedPayment));

        $breakdown = app(WalletFinancialReportRepository::class)->rechargeAttemptStatusBreakdown();

        $this->assertSame(2, $breakdown[WalletRechargeStatus::Requested->value] ?? 0);
        $this->assertSame(0, WalletLedgerEntry::query()->where('user_id', $student->id)->count());
        $this->assertSame(PaymentStatus::Pending, $openPayment->refresh()->status);
        $this->assertSame(PaymentStatus::Failed, $failedPayment->refresh()->status);
    }
}
