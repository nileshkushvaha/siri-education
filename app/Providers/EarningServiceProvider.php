<?php

declare(strict_types=1);

namespace App\Providers;

use App\Earnings\Contracts\InstructorCompensationAgreementServiceInterface;
use App\Earnings\Contracts\InstructorCompensationResolverInterface;
use App\Earnings\Contracts\InstructorEarningRepositoryInterface;
use App\Earnings\Contracts\InstructorEarningServiceInterface;
use App\Earnings\Contracts\InstructorPayoutMethodServiceInterface;
use App\Earnings\Contracts\InstructorPeriodicCompensationServiceInterface;
use App\Earnings\Contracts\InstructorWithdrawalAllocationServiceInterface;
use App\Earnings\Contracts\InstructorWithdrawalBalanceServiceInterface;
use App\Earnings\Contracts\InstructorWithdrawalServiceInterface;
use App\Earnings\Contracts\PayoutMethodFingerprintServiceInterface;
use App\Earnings\Contracts\PayoutMethodSnapshotServiceInterface;
use App\Earnings\Repositories\InstructorEarningRepository;
use App\Earnings\Services\InstructorCompensationAgreementService;
use App\Earnings\Services\InstructorCompensationResolver;
use App\Earnings\Services\InstructorEarningService;
use App\Earnings\Services\InstructorPayoutMethodService;
use App\Earnings\Services\InstructorPeriodicCompensationService;
use App\Earnings\Services\InstructorWithdrawalAllocationService;
use App\Earnings\Services\InstructorWithdrawalBalanceService;
use App\Earnings\Services\InstructorWithdrawalService;
use App\Earnings\Services\PayoutMethodFingerprintService;
use App\Earnings\Services\PayoutMethodSnapshotService;
use Illuminate\Support\ServiceProvider;

class EarningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InstructorEarningRepositoryInterface::class, InstructorEarningRepository::class);
        $this->app->singleton(InstructorEarningServiceInterface::class, InstructorEarningService::class);

        // Phase 14.2 — agreement-based compensation (never student price).
        $this->app->singleton(InstructorCompensationAgreementServiceInterface::class, InstructorCompensationAgreementService::class);
        $this->app->singleton(InstructorCompensationResolverInterface::class, InstructorCompensationResolver::class);
        $this->app->singleton(InstructorPeriodicCompensationServiceInterface::class, InstructorPeriodicCompensationService::class);

        // Phase 15 — payout methods & withdrawals (no money movement).
        $this->app->singleton(PayoutMethodFingerprintServiceInterface::class, PayoutMethodFingerprintService::class);
        $this->app->singleton(PayoutMethodSnapshotServiceInterface::class, PayoutMethodSnapshotService::class);
        $this->app->singleton(InstructorPayoutMethodServiceInterface::class, InstructorPayoutMethodService::class);
        $this->app->singleton(InstructorWithdrawalBalanceServiceInterface::class, InstructorWithdrawalBalanceService::class);
        $this->app->singleton(InstructorWithdrawalAllocationServiceInterface::class, InstructorWithdrawalAllocationService::class);
        $this->app->singleton(InstructorWithdrawalServiceInterface::class, InstructorWithdrawalService::class);
    }
}
