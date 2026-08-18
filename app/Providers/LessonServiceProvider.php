<?php

declare(strict_types=1);

namespace App\Providers;

use App\Ai\Contracts\AiFeatureRegistryInterface;
use App\Ai\Contracts\AiPromptRegistryInterface;
use App\Ai\Contracts\AiSchemaRegistryInterface;
use App\Ai\Enums\AiFeature;
use App\Ai\Registry\AiFeatureDefinition;
use App\Lessons\Contracts\LessonAttendanceRepositoryInterface;
use App\Lessons\Contracts\LessonAttendanceServiceInterface;
use App\Lessons\Contracts\LessonConfirmationServiceInterface;
use App\Lessons\Contracts\LessonFinalizationServiceInterface;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Contracts\LessonRepositoryInterface;
use App\Lessons\Contracts\LessonReviewServiceInterface;
use App\Lessons\Repositories\LessonAttendanceRepository;
use App\Lessons\Repositories\LessonRepository;
use App\Lessons\Services\LessonAttendanceService;
use App\Lessons\Services\LessonConfirmationService;
use App\Lessons\Services\LessonFinalizationService;
use App\Lessons\Services\LessonLifecycleService;
use App\Lessons\Services\LessonOutcomeService;
use App\Lessons\Services\LessonReviewService;
use App\Lessons\Summaries\Contracts\LessonSummaryRepositoryInterface;
use App\Lessons\Summaries\Contracts\LessonSummaryServiceInterface;
use App\Lessons\Summaries\Prompts\LessonSummaryPrompt;
use App\Lessons\Summaries\Repositories\LessonSummaryRepository;
use App\Lessons\Summaries\Resolvers\LessonSummaryInputResolver;
use App\Lessons\Summaries\Resolvers\LessonSummaryResultHandler;
use App\Lessons\Summaries\Schemas\LessonSummarySchema;
use App\Lessons\Summaries\Services\LessonSummaryService;
use App\Models\LessonAiSummary;
use App\Policies\LessonAiSummaryPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class LessonServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LessonRepositoryInterface::class, LessonRepository::class);
        $this->app->singleton(LessonLifecycleServiceInterface::class, LessonLifecycleService::class);
        $this->app->singleton(LessonAttendanceRepositoryInterface::class, LessonAttendanceRepository::class);
        $this->app->singleton(LessonAttendanceServiceInterface::class, LessonAttendanceService::class);
        $this->app->singleton(LessonOutcomeServiceInterface::class, LessonOutcomeService::class);
        $this->app->singleton(LessonFinalizationServiceInterface::class, LessonFinalizationService::class);
        $this->app->singleton(LessonConfirmationServiceInterface::class, LessonConfirmationService::class);
        $this->app->singleton(LessonReviewServiceInterface::class, LessonReviewService::class);

        // AI lesson summaries (P3). Reaches AI only through
        // AiExecutionServiceInterface, via the shared ExecuteAiTaskJob.
        $this->app->singleton(LessonSummaryRepositoryInterface::class, LessonSummaryRepository::class);
        $this->app->singleton(LessonSummaryServiceInterface::class, LessonSummaryService::class);
        $this->app->singleton(LessonSummaryService::class);
    }

    public function boot(): void
    {

        // P3's declared shape. Instructor-initiated by design — this is
        // the switch that would have to be flipped to wire summaries to
        // the LessonCompleted event, and it should not be flipped
        // quietly.
        $this->app->make(AiFeatureRegistryInterface::class)->register(new AiFeatureDefinition(
            feature: AiFeature::LessonSummary,
            ownerDomain: 'app/Lessons/Summaries',
            purpose: 'Draft a lesson write-up from the instructor\'s own notes for them to approve. Never touches progress.',
            inputResolver: LessonSummaryInputResolver::class,
            resultHandlers: [LessonSummaryResultHandler::class],
            allowedPromptKeys: ['lesson_summary'],
            requiresAuthenticatedActor: true,
        ));
        Gate::policy(LessonAiSummary::class, LessonAiSummaryPolicy::class);

        // The domain registers its own prompt and schema into the P0
        // registries — app/Ai never learns this feature exists.
        $this->app->make(AiSchemaRegistryInterface::class)->register(new LessonSummarySchema);
        $this->app->make(AiPromptRegistryInterface::class)->register(LessonSummaryPrompt::definition());
    }
}
