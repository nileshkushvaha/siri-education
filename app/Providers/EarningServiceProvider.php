<?php

declare(strict_types=1);

namespace App\Providers;

use App\Earnings\Contracts\FinancialFeatureConfigurationServiceInterface;
use App\Earnings\Contracts\InstructorCompensationAgreementServiceInterface;
use App\Earnings\Contracts\InstructorCompensationResolverInterface;
use App\Earnings\Contracts\InstructorEarningRepositoryInterface;
use App\Earnings\Contracts\InstructorEarningServiceInterface;
use App\Earnings\Contracts\InstructorPayoutEligibilityServiceInterface;
use App\Earnings\Contracts\InstructorPayoutExecutionServiceInterface;
use App\Earnings\Contracts\InstructorPayoutMethodServiceInterface;
use App\Earnings\Contracts\InstructorPayoutProviderRegistryInterface;
use App\Earnings\Contracts\InstructorPayoutProviderResolverInterface;
use App\Earnings\Contracts\InstructorPayoutReconciliationServiceInterface;
use App\Earnings\Contracts\InstructorPeriodicCompensationServiceInterface;
use App\Earnings\Contracts\InstructorWithdrawalAllocationServiceInterface;
use App\Earnings\Contracts\InstructorWithdrawalBalanceServiceInterface;
use App\Earnings\Contracts\InstructorWithdrawalServiceInterface;
use App\Earnings\Contracts\LessonEarningReconciliationServiceInterface;
use App\Earnings\Contracts\LessonFinancialDispositionServiceInterface;
use App\Earnings\Contracts\PayoutMethodFingerprintServiceInterface;
use App\Earnings\Contracts\PayoutMethodSnapshotServiceInterface;
use App\Earnings\Contracts\PayoutRequestFingerprintServiceInterface;
use App\Earnings\Contracts\RazorpayXDestinationProvisioningServiceInterface;
use App\Earnings\Providers\Fake\FakeInstructorPayoutProvider;
use App\Earnings\Providers\RazorpayX\RazorpayXDestinationProvisioningService;
use App\Earnings\Providers\RazorpayX\RazorpayXHttpPayoutClient;
use App\Earnings\Providers\RazorpayX\RazorpayXInstructorPayoutProvider;
use App\Earnings\Providers\RazorpayX\RazorpayXPayoutClientInterface;
use App\Earnings\Registry\InstructorPayoutProviderRegistry;
use App\Earnings\Repositories\InstructorEarningRepository;
use App\Earnings\Services\FinancialFeatureConfigurationService;
use App\Earnings\Services\InstructorCompensationAgreementService;
use App\Earnings\Services\InstructorCompensationResolver;
use App\Earnings\Services\InstructorEarningService;
use App\Earnings\Services\InstructorPayoutEligibilityService;
use App\Earnings\Services\InstructorPayoutExecutionService;
use App\Earnings\Services\InstructorPayoutMethodService;
use App\Earnings\Services\InstructorPayoutProviderResolver;
use App\Earnings\Services\InstructorPayoutReconciliationService;
use App\Earnings\Services\InstructorPeriodicCompensationService;
use App\Earnings\Services\InstructorWithdrawalAllocationService;
use App\Earnings\Services\InstructorWithdrawalBalanceService;
use App\Earnings\Services\InstructorWithdrawalService;
use App\Earnings\Services\LessonEarningReconciliationService;
use App\Earnings\Services\LessonFinancialDispositionService;
use App\Earnings\Services\PayoutMethodFingerprintService;
use App\Earnings\Services\PayoutMethodSnapshotService;
use App\Earnings\Services\PayoutRequestFingerprintService;
use App\Wallet\Contracts\LessonWalletRefundServiceInterface;
use App\Wallet\Services\LessonWalletRefundService;
use Illuminate\Support\ServiceProvider;

class EarningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InstructorEarningRepositoryInterface::class, InstructorEarningRepository::class);
        $this->app->singleton(InstructorEarningServiceInterface::class, InstructorEarningService::class);

        // Phase 17E — lesson financial-disposition bridge (classification
        // and holds only; execution belongs to a later phase).
        $this->app->singleton(LessonFinancialDispositionServiceInterface::class, LessonFinancialDispositionService::class);

        // Phase 17F — wallet-only refund execution for approved dispositions.
        $this->app->singleton(LessonWalletRefundServiceInterface::class, LessonWalletRefundService::class);

        // Phase 17G — instructor-side earning reconciliation execution.
        $this->app->singleton(LessonEarningReconciliationServiceInterface::class, LessonEarningReconciliationService::class);

        // Phase 14.2 — agreement-based compensation (never student price).
        $this->app->singleton(InstructorCompensationAgreementServiceInterface::class, InstructorCompensationAgreementService::class);
        $this->app->singleton(InstructorCompensationResolverInterface::class, InstructorCompensationResolver::class);
        $this->app->singleton(InstructorPeriodicCompensationServiceInterface::class, InstructorPeriodicCompensationService::class);
        $this->app->singleton(FinancialFeatureConfigurationServiceInterface::class, FinancialFeatureConfigurationService::class);

        // Phase 15 — payout methods & withdrawals (no money movement).
        $this->app->singleton(PayoutMethodFingerprintServiceInterface::class, PayoutMethodFingerprintService::class);
        $this->app->singleton(PayoutMethodSnapshotServiceInterface::class, PayoutMethodSnapshotService::class);
        $this->app->singleton(InstructorPayoutMethodServiceInterface::class, InstructorPayoutMethodService::class);
        $this->app->singleton(InstructorWithdrawalBalanceServiceInterface::class, InstructorWithdrawalBalanceService::class);
        $this->app->singleton(InstructorWithdrawalAllocationServiceInterface::class, InstructorWithdrawalAllocationService::class);
        $this->app->singleton(InstructorWithdrawalServiceInterface::class, InstructorWithdrawalService::class);

        // Phase 16A — provider-neutral payout execution (fake provider only).
        $this->app->singleton(PayoutRequestFingerprintServiceInterface::class, PayoutRequestFingerprintService::class);
        $this->app->singleton(InstructorPayoutProviderRegistryInterface::class, function (): InstructorPayoutProviderRegistry {
            $registry = new InstructorPayoutProviderRegistry;
            $registry->register(new FakeInstructorPayoutProvider);
            $registry->register($this->app->make(RazorpayXInstructorPayoutProvider::class));

            return $registry;
        });
        $this->app->singleton(InstructorPayoutProviderResolverInterface::class, InstructorPayoutProviderResolver::class);
        $this->app->singleton(InstructorPayoutExecutionServiceInterface::class, InstructorPayoutExecutionService::class);
        $this->app->singleton(InstructorPayoutReconciliationServiceInterface::class, InstructorPayoutReconciliationService::class);

        // Phase 16A.1 — provider-neutral route eligibility (distinct from
        // account-level InstructorPayoutEligibility, Phase 15).
        $this->app->singleton(InstructorPayoutEligibilityServiceInterface::class, InstructorPayoutEligibilityService::class);

        // Phase 16B — RazorpayX India/INR payout adapter. The client
        // interface is the only thing that ever touches Http:: for
        // RazorpayX; everything above it is transport-agnostic.
        $this->app->singleton(RazorpayXPayoutClientInterface::class, RazorpayXHttpPayoutClient::class);
        $this->app->singleton(RazorpayXDestinationProvisioningServiceInterface::class, RazorpayXDestinationProvisioningService::class);
    }
}
