<?php

declare(strict_types=1);

namespace App\Providers;

use App\Ai\Contracts\AiPromptRegistryInterface;
use App\Ai\Contracts\AiSchemaRegistryInterface;
use App\Models\AiQualityInsight;
use App\Models\InstructorQualityAlert;
use App\Policies\AiQualityInsightPolicy;
use App\Policies\InstructorQualityAlertPolicy;
use App\Quality\Contracts\AdminQualityDashboardRepositoryInterface;
use App\Quality\Contracts\AdminQualityDashboardServiceInterface;
use App\Quality\Contracts\InstructorQualityAlertRepositoryInterface;
use App\Quality\Contracts\InstructorQualityAlertServiceInterface;
use App\Quality\Contracts\QualitySignalRepositoryInterface;
use App\Quality\Intelligence\Contracts\QualityInsightRepositoryInterface;
use App\Quality\Intelligence\Contracts\QualityInsightServiceInterface;
use App\Quality\Intelligence\Prompts\QualityInsightPrompt;
use App\Quality\Intelligence\Repositories\QualityInsightRepository;
use App\Quality\Intelligence\Schemas\QualityInsightSchema;
use App\Quality\Intelligence\Services\QualityInsightService;
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

        // AI Quality Intelligence (P1). Nothing here calls a provider —
        // the domain reaches AI only through AiExecutionServiceInterface,
        // via ExecuteAiTaskJob.
        $this->app->singleton(QualityInsightRepositoryInterface::class, QualityInsightRepository::class);
        $this->app->singleton(QualityInsightServiceInterface::class, QualityInsightService::class);
        $this->app->singleton(QualityInsightService::class);
    }

    public function boot(): void
    {
        Gate::policy(InstructorQualityAlert::class, InstructorQualityAlertPolicy::class);
        Gate::policy(AiQualityInsight::class, AiQualityInsightPolicy::class);

        // The domain registers its own prompt and schema into the P0
        // registries. Kept here rather than in AiPromptCatalog so the
        // AI module never has to know a feature exists — which is what
        // lets P2-P4 land without touching app/Ai at all.
        $this->app->make(AiSchemaRegistryInterface::class)->register(new QualityInsightSchema);
        $this->app->make(AiPromptRegistryInterface::class)->register(QualityInsightPrompt::definition());
    }
}
