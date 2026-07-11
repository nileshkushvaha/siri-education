<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings;

use App\Console\Commands\AccruePeriodicCompensation;
use App\Console\Commands\ReconcileInstructorPayouts;
use App\Console\Commands\ReleaseInstructorEarnings;
use App\Console\Commands\RetryBlockedLessons;
use App\Earnings\Contracts\InstructorCompensationAgreementServiceInterface;
use App\Earnings\Contracts\InstructorCompensationResolverInterface;
use App\Earnings\Contracts\InstructorEarningRepositoryInterface;
use App\Earnings\Contracts\InstructorEarningServiceInterface;
use App\Earnings\Contracts\InstructorPayoutExecutionServiceInterface;
use App\Earnings\Contracts\InstructorPayoutMethodServiceInterface;
use App\Earnings\Contracts\InstructorPayoutProviderRegistryInterface;
use App\Earnings\Contracts\InstructorPayoutProviderResolverInterface;
use App\Earnings\Contracts\InstructorPayoutReconciliationServiceInterface;
use App\Earnings\Contracts\InstructorPeriodicCompensationServiceInterface;
use App\Earnings\Contracts\InstructorWithdrawalAllocationServiceInterface;
use App\Earnings\Contracts\InstructorWithdrawalBalanceServiceInterface;
use App\Earnings\Contracts\InstructorWithdrawalServiceInterface;
use App\Earnings\Contracts\PayoutMethodFingerprintServiceInterface;
use App\Earnings\Contracts\PayoutMethodSnapshotServiceInterface;
use App\Earnings\Contracts\PayoutRequestFingerprintServiceInterface;
use App\Earnings\DTOs\CompensationResolution;
use App\Earnings\DTOs\PayoutInitiationRequest;
use App\Earnings\Providers\Fake\FakeInstructorPayoutProvider;
use App\Earnings\Registry\InstructorPayoutProviderRegistry;
use App\Earnings\Repositories\InstructorEarningRepository;
use App\Earnings\Services\InstructorCompensationAgreementService;
use App\Earnings\Services\InstructorCompensationResolver;
use App\Earnings\Services\InstructorEarningService;
use App\Earnings\Services\InstructorPayoutExecutionService;
use App\Earnings\Services\InstructorPayoutMethodService;
use App\Earnings\Services\InstructorPayoutProviderResolver;
use App\Earnings\Services\InstructorPayoutReconciliationService;
use App\Earnings\Services\InstructorPeriodicCompensationService;
use App\Earnings\Services\InstructorWithdrawalAllocationService;
use App\Earnings\Services\InstructorWithdrawalBalanceService;
use App\Earnings\Services\InstructorWithdrawalService;
use App\Earnings\Services\PayoutMethodFingerprintService;
use App\Earnings\Services\PayoutMethodSnapshotService;
use App\Earnings\Services\PayoutRequestFingerprintService;
use App\Models\Lesson;
use App\Settings\InstructorEarningSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 14.4 — architecture-level regression tests that prevent the
 * commission-era design (and other retired shapes) from returning.
 * These inspect executable application code and the container, never
 * documentation.
 */
class FinancialArchitectureTest extends TestCase
{
    use RefreshDatabase;

    /** Every executable financial source file, path => contents. */
    private static ?array $sources = null;

    // ── No commission dependency ─────────────────────────────────────

    public function test_no_compensation_code_references_student_pricing(): void
    {
        foreach ($this->financialSources() as $path => $code) {
            $this->assertStringNotContainsString('student_amount_minor', $code, $path);
            $this->assertStringNotContainsString('platform_margin_minor', $code, $path);
            $this->assertStringNotContainsString('instructor_percentage', $code, $path);
            $this->assertStringNotContainsString('percentage_of_student_price', $code, $path);
        }
    }

    public function test_compensation_resolver_signature_cannot_accept_student_price(): void
    {
        $method = new \ReflectionMethod(InstructorCompensationResolverInterface::class, 'resolveForLesson');

        // One parameter: the lesson. No amount, price, or payment can be passed.
        $this->assertSame(1, $method->getNumberOfParameters());
        $this->assertSame(Lesson::class, (string) $method->getParameters()[0]->getType());
    }

    public function test_compensation_resolution_dto_has_no_student_price_field(): void
    {
        $properties = array_map(
            fn (\ReflectionProperty $p): string => $p->getName(),
            (new \ReflectionClass(CompensationResolution::class))->getProperties(),
        );

        foreach ($properties as $name) {
            $this->assertStringNotContainsStringIgnoringCase('student', $name);
            $this->assertStringNotContainsStringIgnoringCase('margin', $name);
            $this->assertStringNotContainsStringIgnoringCase('price', $name);
            $this->assertStringNotContainsStringIgnoringCase('percentage', $name);
        }
    }

    public function test_no_commission_settings_are_exposed(): void
    {
        $properties = array_map(
            fn (\ReflectionProperty $p): string => $p->getName(),
            (new \ReflectionClass(InstructorEarningSettings::class))->getProperties(),
        );

        foreach (['default_calculation_type', 'default_percentage', 'default_fixed_amount_minor', 'default_currency_code'] as $legacy) {
            $this->assertNotContains($legacy, $properties);
        }
    }

    // ── Container integrity ──────────────────────────────────────────

    public function test_every_financial_contract_resolves_to_exactly_one_bound_implementation(): void
    {
        $bindings = [
            InstructorEarningRepositoryInterface::class => InstructorEarningRepository::class,
            InstructorEarningServiceInterface::class => InstructorEarningService::class,
            InstructorCompensationAgreementServiceInterface::class => InstructorCompensationAgreementService::class,
            InstructorCompensationResolverInterface::class => InstructorCompensationResolver::class,
            InstructorPeriodicCompensationServiceInterface::class => InstructorPeriodicCompensationService::class,
            InstructorPayoutMethodServiceInterface::class => InstructorPayoutMethodService::class,
            InstructorWithdrawalServiceInterface::class => InstructorWithdrawalService::class,
            InstructorWithdrawalBalanceServiceInterface::class => InstructorWithdrawalBalanceService::class,
            InstructorWithdrawalAllocationServiceInterface::class => InstructorWithdrawalAllocationService::class,
            PayoutMethodFingerprintServiceInterface::class => PayoutMethodFingerprintService::class,
            PayoutMethodSnapshotServiceInterface::class => PayoutMethodSnapshotService::class,
            PayoutRequestFingerprintServiceInterface::class => PayoutRequestFingerprintService::class,
            InstructorPayoutProviderRegistryInterface::class => InstructorPayoutProviderRegistry::class,
            InstructorPayoutProviderResolverInterface::class => InstructorPayoutProviderResolver::class,
            InstructorPayoutExecutionServiceInterface::class => InstructorPayoutExecutionService::class,
            InstructorPayoutReconciliationServiceInterface::class => InstructorPayoutReconciliationService::class,
        ];

        foreach ($bindings as $contract => $implementation) {
            $this->assertInstanceOf($implementation, app($contract), $contract);
            // Singleton: repeated resolution yields the same instance.
            $this->assertSame(app($contract), app($contract), $contract);
        }
    }

    public function test_every_financial_scheduled_command_resolves(): void
    {
        foreach ([
            ReleaseInstructorEarnings::class,
            AccruePeriodicCompensation::class,
            RetryBlockedLessons::class,
            ReconcileInstructorPayouts::class,
        ] as $command) {
            $this->assertInstanceOf($command, app($command));
        }
    }

    // ── Phase 16A: provider-neutral payout execution ─────────────────

    public function test_only_the_fake_payout_provider_is_registered(): void
    {
        $registry = app(InstructorPayoutProviderRegistryInterface::class);

        $this->assertTrue($registry->has('fake'));
        $this->assertInstanceOf(FakeInstructorPayoutProvider::class, $registry->get('fake'));

        foreach (['razorpayx', 'stripe', 'wise', 'paypal', 'stripe_connect'] as $key) {
            $this->assertFalse($registry->has($key), "No adapter for \"{$key}\" may be registered in Phase 16A.");
        }
    }

    public function test_no_external_payout_provider_adapter_or_http_call_exists(): void
    {
        foreach ($this->phpFilesIn([app_path('Earnings')]) as $path => $code) {
            foreach (['Http::', 'GuzzleHttp\\', 'curl_init', 'curl_exec', "file_get_contents('http", 'file_get_contents("http'] as $needle) {
                $this->assertStringNotContainsString($needle, $code, "Unexpected network call in {$path}");
            }

            foreach (['razorpayx', 'razorpay x', 'stripe connect', 'api.razorpay'] as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $code, "Unexpected external-provider reference in {$path}");
            }
        }
    }

    public function test_payout_initiation_request_carries_no_forbidden_field(): void
    {
        $properties = array_map(
            fn (\ReflectionProperty $p): string => $p->getName(),
            (new \ReflectionClass(PayoutInitiationRequest::class))->getProperties(),
        );

        foreach ($properties as $name) {
            $this->assertStringNotContainsStringIgnoringCase('student', $name);
            $this->assertStringNotContainsStringIgnoringCase('margin', $name);
            $this->assertStringNotContainsStringIgnoringCase('secret', $name);
            $this->assertStringNotContainsStringIgnoringCase('credential', $name);
        }
    }

    public function test_no_manual_mark_paid_action_exists_anywhere(): void
    {
        // Targets an actual Filament action/method declaration named
        // "mark(_)paid" — not prose (several docblocks here correctly
        // state "no mark-paid action exists" as a design guarantee).
        foreach ($this->phpFilesIn([
            app_path('Filament/Resources/InstructorWithdrawalRequests'),
            app_path('Filament/Resources/InstructorPayoutAttempts'),
            app_path('Filament/Resources/InstructorPayoutReconciliationIssues'),
        ]) as $path => $code) {
            $this->assertDoesNotMatchRegularExpression(
                "/(Action::make\\(\\s*['\"]mark[_ -]?paid|function\\s+markA?s?Paid)/i",
                $code,
                "Manual mark-paid action found in {$path}",
            );
        }
    }

    public function test_only_execution_service_transitions_payout_attempt_status(): void
    {
        foreach ($this->phpFilesIn([
            app_path('Filament'),
            app_path('Livewire'),
            app_path('Http/Controllers'),
        ]) as $path => $code) {
            $this->assertDoesNotMatchRegularExpression(
                '/InstructorPayoutAttempt(Status)?::[a-zA-Z]+.{0,80}(forceFill|update)\\(/s',
                $code,
                "UI layer mutates payout attempt status directly: {$path}",
            );
        }
    }

    // ── Service boundaries ───────────────────────────────────────────

    public function test_ui_layers_never_mutate_financial_status_directly(): void
    {
        $uiPaths = [
            app_path('Filament/Resources/InstructorEarnings'),
            app_path('Filament/Resources/InstructorSettlementBatches'),
            app_path('Filament/Resources/InstructorPayoutMethods'),
            app_path('Filament/Resources/InstructorWithdrawalRequests'),
            app_path('Filament/Resources/InstructorCompensationAgreements'),
            app_path('Filament/Resources/InstructorCompensationExceptions'),
            app_path('Filament/Resources/InstructorPayoutAttempts'),
            app_path('Filament/Resources/InstructorPayoutReconciliationIssues'),
            app_path('Livewire/Frontend/Instructor'),
        ];

        foreach ($this->phpFilesIn($uiPaths) as $path => $code) {
            $this->assertDoesNotMatchRegularExpression(
                "/(update|forceFill|fill)\\(\\s*\\[\\s*'status'/",
                $code,
                "UI layer mutates a financial status directly: {$path}",
            );
            $this->assertStringNotContainsString('DeleteAction', $code, "UI layer exposes delete on financial records: {$path}");
        }
    }

    public function test_settlement_and_withdrawal_services_stay_out_of_each_others_writes(): void
    {
        $earningService = file_get_contents(app_path('Earnings/Services/InstructorEarningService.php'));
        $withdrawalService = file_get_contents(app_path('Earnings/Services/InstructorWithdrawalService.php'));

        // The withdrawal domain never assigns settlement batches…
        $this->assertDoesNotMatchRegularExpression("/settlement_batch_id'\\s*=>/", $withdrawalService);
        // …and the settlement domain never writes withdrawal allocations.
        $this->assertStringNotContainsString('InstructorWithdrawalAllocation', $earningService);
    }

    public function test_booking_domain_does_not_write_instructor_earnings(): void
    {
        foreach ($this->phpFilesIn([app_path('Booking')]) as $path => $code) {
            $this->assertStringNotContainsString('InstructorEarning', $code, $path);
        }
    }

    // ── Route integrity ──────────────────────────────────────────────

    public function test_no_public_financial_mutation_or_payout_execution_route_exists(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            $uri = $route->uri();

            if (! preg_match('/payout|withdraw|settlement|earning|compensation/i', $uri)) {
                continue;
            }

            // Financial pages are GET-only shells (mutations flow through
            // Livewire's internal endpoint into policy-guarded services —
            // access control itself is proven by the 403 tests). No
            // mutation verbs, no webhooks, no mark-paid, no execution
            // endpoints may exist as routes.
            $this->assertSame(['GET', 'HEAD'], $route->methods(), $uri);
            $this->assertDoesNotMatchRegularExpression('/paid|execute|transfer|webhook/i', $uri);
        }
    }

    // ── Audit integrity ──────────────────────────────────────────────

    public function test_financial_services_use_audit_trail_service_never_raw_activity(): void
    {
        foreach ($this->phpFilesIn([app_path('Earnings/Services')]) as $path => $code) {
            $this->assertDoesNotMatchRegularExpression('/[^a-zA-Z>]activity\\(/', $code, $path);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /** @return array<string, string> */
    private function financialSources(): array
    {
        if (self::$sources === null) {
            self::$sources = $this->phpFilesIn([
                app_path('Earnings'),
                app_path('Models'),
                app_path('Filament'),
                app_path('Livewire'),
                app_path('Settings'),
            ]);
        }

        return self::$sources;
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
