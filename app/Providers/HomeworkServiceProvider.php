<?php

declare(strict_types=1);

namespace App\Providers;

use App\Ai\Contracts\AiPromptRegistryInterface;
use App\Ai\Contracts\AiSchemaRegistryInterface;
use App\Homework\Contracts\HomeworkRepositoryInterface;
use App\Homework\Contracts\HomeworkServiceInterface;
use App\Homework\Copilot\Contracts\HomeworkFeedbackDraftRepositoryInterface;
use App\Homework\Copilot\Contracts\HomeworkFeedbackDraftServiceInterface;
use App\Homework\Copilot\Prompts\HomeworkFeedbackPrompt;
use App\Homework\Copilot\Repositories\HomeworkFeedbackDraftRepository;
use App\Homework\Copilot\Schemas\HomeworkFeedbackSchema;
use App\Homework\Copilot\Services\HomeworkFeedbackDraftService;
use App\Homework\Repositories\HomeworkRepository;
use App\Homework\Services\HomeworkService;
use App\Models\HomeworkAiFeedbackDraft;
use App\Models\HomeworkAssignment;
use App\Models\HomeworkResource;
use App\Models\HomeworkResourceVersion;
use App\Policies\HomeworkAiFeedbackDraftPolicy;
use App\Policies\HomeworkAssignmentPolicy;
use App\Policies\HomeworkResourcePolicy;
use App\Policies\HomeworkResourceVersionPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class HomeworkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(HomeworkRepositoryInterface::class, HomeworkRepository::class);
        $this->app->bind(HomeworkServiceInterface::class, HomeworkService::class);

        // Homework Copilot (P2). Reaches AI only through
        // AiExecutionServiceInterface, via the shared ExecuteAiTaskJob.
        $this->app->singleton(HomeworkFeedbackDraftRepositoryInterface::class, HomeworkFeedbackDraftRepository::class);
        $this->app->singleton(HomeworkFeedbackDraftServiceInterface::class, HomeworkFeedbackDraftService::class);
        $this->app->singleton(HomeworkFeedbackDraftService::class);
    }

    public function boot(): void
    {
        Gate::policy(HomeworkAssignment::class, HomeworkAssignmentPolicy::class);
        Gate::policy(HomeworkResource::class, HomeworkResourcePolicy::class);
        Gate::policy(HomeworkResourceVersion::class, HomeworkResourceVersionPolicy::class);
        Gate::policy(HomeworkAiFeedbackDraft::class, HomeworkAiFeedbackDraftPolicy::class);

        // The domain registers its own prompt and schema into the P0
        // registries — app/Ai never learns this feature exists.
        $this->app->make(AiSchemaRegistryInterface::class)->register(new HomeworkFeedbackSchema);
        $this->app->make(AiPromptRegistryInterface::class)->register(HomeworkFeedbackPrompt::definition());
    }
}
