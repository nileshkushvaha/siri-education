<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\InstructorQualityAlert;
use App\Policies\InstructorQualityAlertPolicy;
use App\Quality\Contracts\AdminQualityDashboardRepositoryInterface;
use App\Quality\Contracts\AdminQualityDashboardServiceInterface;
use App\Quality\Contracts\InstructorQualityAlertRepositoryInterface;
use App\Quality\Contracts\InstructorQualityAlertServiceInterface;
use App\Quality\Contracts\QualitySignalRepositoryInterface;
use App\Quality\Repositories\AdminQualityDashboardRepository;
use App\Quality\Repositories\InstructorQualityAlertRepository;
use App\Quality\Repositories\QualitySignalRepository;
use App\Quality\Services\AdminQualityDashboardService;
use App\Quality\Services\InstructorQualityAlertService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class QualityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InstructorQualityAlertRepositoryInterface::class, InstructorQualityAlertRepository::class);
        $this->app->singleton(QualitySignalRepositoryInterface::class, QualitySignalRepository::class);
        $this->app->singleton(InstructorQualityAlertServiceInterface::class, InstructorQualityAlertService::class);
        $this->app->singleton(AdminQualityDashboardRepositoryInterface::class, AdminQualityDashboardRepository::class);
        $this->app->singleton(AdminQualityDashboardServiceInterface::class, AdminQualityDashboardService::class);
    }

    public function boot(): void
    {
        Gate::policy(InstructorQualityAlert::class, InstructorQualityAlertPolicy::class);
    }
}
