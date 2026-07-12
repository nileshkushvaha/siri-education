<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Booking\Contracts\PaymentCollectionEligibilityServiceInterface;
use App\Booking\Contracts\PaymentProviderInterface;
use App\Booking\DTOs\PaymentProviderCapabilities;
use App\Booking\Enums\PaymentCollectionRolloutScope;
use App\Booking\Registry\PaymentProviderRegistry;
use App\Booking\Services\PaymentProviderResolver;
use App\Earnings\Contracts\InstructorPayoutEligibilityServiceInterface;
use App\Earnings\Contracts\InstructorPayoutProviderInterface;
use App\Earnings\Contracts\InstructorPayoutProviderRegistryInterface;
use App\Earnings\Contracts\InstructorPayoutProviderResolverInterface;
use App\Earnings\DTOs\PayoutProviderCapabilities;
use App\Earnings\Enums\PayoutMethodType;
use App\Earnings\Enums\PayoutRolloutScope;
use App\Earnings\Providers\Fake\FakeInstructorPayoutProvider;
use App\Settings\InstructorEarningSettings;
use App\Settings\PaymentGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 16A.1 — proves the "same pattern, separate contracts" principle
 * structurally: collection and payout both use a registry/resolver/
 * capabilities/eligibility shape, but the two are never interchangeable
 * and neither domain's provider selection can influence the other's.
 * Inspects executable code and the container, never documentation.
 */
class CrossDomainFinancialIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** Every executable source file under app/, path => contents. */
    private static ?array $appSources = null;

    // ── Pattern consistency ───────────────────────────────────────────

    public function test_collection_and_payout_each_expose_a_registry_resolver_capabilities_eligibility_shape(): void
    {
        $this->assertTrue(class_exists(PaymentProviderRegistry::class));
        $this->assertTrue(class_exists(PaymentProviderResolver::class));
        $this->assertTrue(class_exists(PaymentProviderCapabilities::class));
        $this->assertTrue(interface_exists(PaymentCollectionEligibilityServiceInterface::class));

        $this->assertTrue(interface_exists(InstructorPayoutProviderRegistryInterface::class));
        $this->assertTrue(interface_exists(InstructorPayoutProviderResolverInterface::class));
        $this->assertTrue(class_exists(PayoutProviderCapabilities::class));
        $this->assertTrue(interface_exists(InstructorPayoutEligibilityServiceInterface::class));
    }

    public function test_collection_and_payout_provider_interfaces_are_never_the_same_or_related_type(): void
    {
        $this->assertFalse(is_a(PaymentProviderInterface::class, InstructorPayoutProviderInterface::class, true));
        $this->assertFalse(is_a(InstructorPayoutProviderInterface::class, PaymentProviderInterface::class, true));
        $this->assertNotSame(PaymentProviderInterface::class, InstructorPayoutProviderInterface::class);
    }

    public function test_no_generic_financial_provider_interface_attempts_to_span_both_directions(): void
    {
        foreach ($this->appSources() as $path => $code) {
            $this->assertDoesNotMatchRegularExpression(
                '/interface\s+FinancialProviderInterface/',
                $code,
                "A generic bidirectional financial provider interface must never exist: {$path}",
            );
        }
    }

    public function test_collection_provider_cannot_resolve_from_the_payout_registry_or_vice_versa(): void
    {
        $payoutRegistry = app(InstructorPayoutProviderRegistryInterface::class);
        $this->assertFalse($payoutRegistry->has('razorpay'), 'The student collection provider key must never resolve from the payout registry.');
        $this->assertFalse($payoutRegistry->has('stripe'));

        $collectionRegistry = app(PaymentProviderRegistry::class);
        $this->assertFalse($collectionRegistry->has('razorpayx'), 'A payout-only provider key must never resolve from the collection registry.');
    }

    // ── Routing integrity ─────────────────────────────────────────────

    public function test_only_the_fake_and_razorpayx_providers_are_registered_for_payout(): void
    {
        $registry = app(InstructorPayoutProviderRegistryInterface::class);
        $this->assertTrue($registry->has('fake'));
        $this->assertInstanceOf(FakeInstructorPayoutProvider::class, $registry->get('fake'));

        // Phase 16B — a RazorpayX adapter now exists (registered), but
        // it is disabled/uncredentialed by default; every other
        // provider key remains genuinely unregistered.
        $this->assertTrue($registry->has('razorpayx'));

        foreach (['stripe', 'wise', 'paypal'] as $unregistered) {
            $this->assertFalse($registry->has($unregistered));
        }
    }

    public function test_india_payout_route_does_not_assume_razorpayx_exists(): void
    {
        $result = app(InstructorPayoutEligibilityServiceInterface::class)->resolve('IN', 'IN', 'INR', PayoutMethodType::BankTransfer);

        // A RazorpayX adapter is registered (Phase 16B), but
        // InstructorEarningSettings::payout_provider still defaults to
        // 'fake' — an India/INR route must resolve against whatever IS
        // actually configured, never silently switch to RazorpayX just
        // because its adapter now exists.
        $this->assertNotSame('razorpayx', $result->provider);
    }

    public function test_no_currency_conversion_helper_exists_in_either_provider_domain(): void
    {
        foreach ($this->appSources() as $path => $code) {
            if (! str_contains($path, '/Earnings/') && ! str_contains($path, '/Booking/')) {
                continue;
            }

            foreach (['convertCurrency', 'exchangeRate', 'fx_rate', 'currency_conversion'] as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $code, "Currency conversion must not exist: {$path}");
            }
        }
    }

    // ── Cross-domain write isolation ──────────────────────────────────

    public function test_booking_collection_services_cannot_create_instructor_earnings_or_allocations(): void
    {
        foreach ($this->phpFilesIn([app_path('Booking')]) as $path => $code) {
            $this->assertStringNotContainsString('InstructorEarning', $code, "Booking domain must never write instructor earnings: {$path}");
            $this->assertStringNotContainsString('InstructorWithdrawalAllocation', $code, "Booking domain must never write withdrawal allocations: {$path}");
            $this->assertStringNotContainsString('InstructorPayoutAttempt', $code, "Booking domain must never write payout attempts: {$path}");
        }
    }

    public function test_payout_services_cannot_read_student_price_or_payment_records(): void
    {
        // Targets the actual BookingPayment/StudentLessonPrice/Booking
        // MODEL classes specifically (import or static/instantiation use)
        // — not the legitimate, pre-existing, already-tested
        // BookingPaymentStatus/BookingStatus ELIGIBILITY enums the
        // earnings domain reads to gate earning creation (Phase 14),
        // which are a world away from reading a price or payment record.
        foreach ($this->phpFilesIn([app_path('Earnings')]) as $path => $code) {
            $this->assertDoesNotMatchRegularExpression(
                '/use App\\\\Models\\\\BookingPayment;|BookingPayment::|new BookingPayment/',
                $code,
                "Payout domain must never read/write student payment records: {$path}",
            );
            $this->assertDoesNotMatchRegularExpression(
                '/use App\\\\Models\\\\StudentLessonPrice|StudentLessonPrice(Repository|Resolver)?::/',
                $code,
                "Payout domain must never read student pricing: {$path}",
            );
            $this->assertDoesNotMatchRegularExpression(
                '/use App\\\\Models\\\\Booking;|\bBooking \$booking\b/',
                $code,
                "Payout domain must never directly reference the Booking model: {$path}",
            );
        }
    }

    public function test_instructor_compensation_agreement_service_never_references_wallet_or_booking_payment(): void
    {
        $code = file_get_contents(app_path('Earnings/Services/InstructorCompensationAgreementService.php'));
        $this->assertStringNotContainsString('Wallet', $code);
        $this->assertStringNotContainsString('BookingPayment', $code);
    }

    public function test_booking_price_calculator_never_references_instructor_compensation(): void
    {
        $path = app_path('Booking/Services/BookingPriceCalculator.php');

        if (! is_file($path)) {
            $this->markTestSkipped('BookingPriceCalculator.php not found at the audited path.');
        }

        $code = file_get_contents($path);
        $this->assertStringNotContainsString('InstructorCompensation', $code);
        $this->assertStringNotContainsString('InstructorEarning', $code);
    }

    public function test_wallet_refund_credit_never_mutates_instructor_earnings(): void
    {
        $code = file_get_contents(app_path('Booking/Services/BookingPaymentService.php'));
        $this->assertStringNotContainsString('InstructorEarning', $code);
    }

    public function test_instructor_payout_reversal_never_touches_student_wallet(): void
    {
        $code = file_get_contents(app_path('Earnings/Services/InstructorPayoutExecutionService.php'));
        $this->assertStringNotContainsString('Wallet', $code);
    }

    public function test_collection_webhook_processor_cannot_process_payout_events(): void
    {
        $code = file_get_contents(app_path('Http/Controllers/Api/BookingPaymentWebhookController.php'));
        $this->assertStringNotContainsString('InstructorPayout', $code);
    }

    public function test_payout_execution_service_handles_no_http_route(): void
    {
        // Phase 16B's one deliberate exception: the RazorpayX payout
        // webhook — public, unauthenticated, signature-verified — never
        // touches a Filament/Livewire session and never calls
        // InstructorPayoutExecutionService directly (it normalizes the
        // event first; see RazorpayXPayoutWebhookController).
        $razorpayXWebhookUri = 'api/webhooks/payouts/razorpayx';

        foreach (app('router')->getRoutes() as $route) {
            $uri = $route->uri();

            if ($uri === $razorpayXWebhookUri) {
                $this->assertSame(['POST'], $route->methods(), $uri);

                continue;
            }

            if (! preg_match('/payout/i', $uri)) {
                continue;
            }

            // Every other payout-related route must be an admin Filament
            // page (GET/HEAD only) — no execution endpoint.
            $this->assertSame(['GET', 'HEAD'], $route->methods(), $uri);
        }
    }

    // ── Phase 16C additions ─────────────────────────────────────────────

    public function test_no_guest_payment_route_exists(): void
    {
        // GuestBookingPaymentController exists (Phase 10.2C-Fix, kept for
        // reference) but must never be bound to any route — no
        // unauthenticated user may initiate payment.
        foreach (app('router')->getRoutes() as $route) {
            $this->assertStringNotContainsStringIgnoringCase(
                'GuestBookingPaymentController',
                $route->getActionName(),
                "No route may resolve to GuestBookingPaymentController: {$route->uri()}",
            );
        }
    }

    public function test_no_manual_mark_paid_action_exists_in_the_admin_panel(): void
    {
        // Neither the read-only BookingPaymentResource nor the
        // reconciliation-issue resource may expose a generic status edit
        // or a manual "mark paid" action — only markPaid()/applyProviderStatus(),
        // reached exclusively via a signed webhook or an authenticated
        // provider status fetch, may ever do that.
        foreach ([
            app_path('Filament/Resources/BookingPayments/Tables/BookingPaymentsTable.php'),
            app_path('Filament/Resources/BookingPaymentReconciliationIssues/Tables/BookingPaymentReconciliationIssuesTable.php'),
        ] as $path) {
            $code = file_get_contents($path);

            $this->assertDoesNotMatchRegularExpression(
                '/status\s*=>\s*BookingPaymentRecordStatus::Captured|payment_status\s*=>\s*BookingPaymentStatus::Paid|->markPaid\(/',
                $code,
                "Admin table must never directly settle a payment: {$path}",
            );
        }
    }

    public function test_browser_code_cannot_set_payment_status(): void
    {
        // Every Livewire component under Booking must never assign
        // payment_status/BookingPaymentRecordStatus directly — settlement
        // is exclusively BookingPaymentService's job (markPaid()/
        // markFailed()/applyProviderStatus()), reached only via a signed
        // webhook or (client-side) an authenticated re-check that itself
        // makes no write (checkPaymentStatus()).
        foreach ($this->phpFilesIn([app_path('Livewire/Frontend/Booking'), app_path('Livewire/Frontend/Student')]) as $path => $code) {
            $this->assertDoesNotMatchRegularExpression(
                '/->payment_status\s*=|payment_status\'\s*=>\s*[\'"]paid/i',
                $code,
                "Frontend Livewire code must never directly write payment_status: {$path}",
            );
        }
    }

    public function test_checkout_and_webhook_paths_never_trust_client_supplied_amount_or_currency(): void
    {
        // Amount/currency always come from the persisted BookingPayment
        // row (set once at createPayment()-time from the price snapshot),
        // never re-read from request input at checkout or webhook time.
        foreach ($this->phpFilesIn([app_path('Booking/Payments'), app_path('Http/Controllers/Api')]) as $path => $code) {
            if (! str_contains($path, 'Payment')) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression(
                '/request\(\)->(input|get)\([\'"](amount|currency)/i',
                $code,
                "Payment code must never trust a client-supplied amount/currency: {$path}",
            );
        }
    }

    public function test_all_real_stripe_calls_pass_through_stripe_gateway_client(): void
    {
        foreach ($this->appSources() as $path => $code) {
            if (str_ends_with($path, 'StripeSdkClient.php')) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression(
                '/new\s+\\\\?Stripe\\\\StripeClient|Stripe\\\\StripeClient::/',
                $code,
                "Only StripeSdkClient may instantiate the Stripe SDK client: {$path}",
            );
        }
    }

    public function test_stripe_collection_provider_cannot_resolve_from_the_payout_registry(): void
    {
        // Reinforces test_only_the_fake_and_razorpayx_providers_are_registered_for_payout()
        // with the exact Phase 16C wording the spec asks for.
        $payoutRegistry = app(InstructorPayoutProviderRegistryInterface::class);
        $this->assertFalse($payoutRegistry->has('stripe'), 'Stripe collection provider must never resolve as a payout provider.');
    }

    public function test_razorpayx_payout_provider_cannot_resolve_from_the_collection_registry(): void
    {
        // Reinforces test_collection_provider_cannot_resolve_from_the_payout_registry_or_vice_versa()
        // with the exact Phase 16C wording the spec asks for.
        $collectionRegistry = app(PaymentProviderRegistry::class);
        $this->assertFalse($collectionRegistry->has('razorpayx'), 'RazorpayX payout provider must never resolve as a collection provider.');
    }

    public function test_no_currency_conversion_exists_anywhere_in_the_collection_reconciliation_subsystem(): void
    {
        foreach ($this->phpFilesIn([app_path('Booking')]) as $path => $code) {
            if (! str_contains($path, 'Reconciliation')) {
                continue;
            }

            foreach (['convertCurrency', 'exchangeRate', 'fx_rate', 'currency_conversion'] as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $code, "Currency conversion must not exist: {$path}");
            }
        }
    }

    public function test_payment_webhooks_and_payout_webhooks_remain_separate_including_the_renamed_generic_route(): void
    {
        $bookingSettlementUri = 'api/webhooks/bookings/payments/{provider}';
        $genericCollectionUri = 'api/webhooks/payments/generic/{gateway}';
        $razorpayXWebhookUri = 'api/webhooks/payouts/razorpayx';

        $routes = collect(app('router')->getRoutes())->map(fn ($route) => $route->uri())->all();

        $this->assertContains($bookingSettlementUri, $routes);
        $this->assertContains($genericCollectionUri, $routes);
        $this->assertContains($razorpayXWebhookUri, $routes);

        // The old, unisolated generic path must no longer exist at all —
        // nothing may be configured to point at it by accident.
        $this->assertNotContains('api/webhooks/payments/{gateway}', $routes);
    }

    // ── Credentials/settings separation ───────────────────────────────

    public function test_collection_and_payout_use_distinct_settings_classes(): void
    {
        $this->assertNotSame(
            PaymentGatewaySettings::class,
            InstructorEarningSettings::class,
        );
    }

    public function test_collection_and_payout_rollout_scope_enums_are_distinct(): void
    {
        $this->assertNotSame(
            PaymentCollectionRolloutScope::class,
            PayoutRolloutScope::class,
        );
    }

    public function test_country_payment_routing_and_payout_routing_are_separate_columns(): void
    {
        $columns = Schema::getColumnListing('countries');
        $this->assertContains('payment_routing', $columns);
        $this->assertContains('payout_routing', $columns);
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /** @return array<string, string> */
    private function appSources(): array
    {
        if (self::$appSources === null) {
            self::$appSources = $this->phpFilesIn([app_path()]);
        }

        return self::$appSources;
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, string>
     */
    private function phpFilesIn(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[$file->getPathname()] = file_get_contents($file->getPathname());
                }
            }
        }

        return $files;
    }
}
