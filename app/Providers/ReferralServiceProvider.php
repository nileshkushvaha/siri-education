<?php

declare(strict_types=1);

namespace App\Providers;

use App\Referral\Contracts\ReferralAttributionServiceInterface;
use App\Referral\Contracts\ReferralCampaignServiceInterface;
use App\Referral\Contracts\ReferralCodeServiceInterface;
use App\Referral\Contracts\ReferralEligibilityServiceInterface;
use App\Referral\Contracts\ReferralRewardServiceInterface;
use App\Referral\Services\ReferralAttributionService;
use App\Referral\Services\ReferralCampaignService;
use App\Referral\Services\ReferralCodeService;
use App\Referral\Services\ReferralEligibilityService;
use App\Referral\Services\ReferralRewardService;
use Illuminate\Support\ServiceProvider;

class ReferralServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReferralCodeServiceInterface::class, ReferralCodeService::class);
        $this->app->singleton(ReferralAttributionServiceInterface::class, ReferralAttributionService::class);
        $this->app->singleton(ReferralCampaignServiceInterface::class, ReferralCampaignService::class);
        $this->app->singleton(ReferralEligibilityServiceInterface::class, ReferralEligibilityService::class);
        $this->app->singleton(ReferralRewardServiceInterface::class, ReferralRewardService::class);
    }
}
