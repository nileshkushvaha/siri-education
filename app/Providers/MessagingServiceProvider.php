<?php

declare(strict_types=1);

namespace App\Providers;

use App\Ai\Contracts\AiPromptRegistryInterface;
use App\Ai\Contracts\AiSchemaRegistryInterface;
use App\Messaging\Safety\Contracts\MessageSafetyFindingRepositoryInterface;
use App\Messaging\Safety\Contracts\MessageSafetyServiceInterface;
use App\Messaging\Safety\Prompts\CommunicationRiskPrompt;
use App\Messaging\Safety\Prompts\MessageModerationPrompt;
use App\Messaging\Safety\Repositories\MessageSafetyFindingRepository;
use App\Messaging\Safety\Schemas\CommunicationRiskSchema;
use App\Messaging\Safety\Services\MessageSafetyService;
use App\Models\MessageSafetyFinding;
use App\Policies\MessageSafetyFindingPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * The Messaging domain's first service provider — added for P4's
 * communication-safety layer. The pre-existing messaging services are
 * plain container-resolved classes and stay that way; only the new
 * safety bindings, policy and AI registrations live here.
 */
class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MessageSafetyFindingRepositoryInterface::class, MessageSafetyFindingRepository::class);
        $this->app->singleton(MessageSafetyServiceInterface::class, MessageSafetyService::class);
        $this->app->singleton(MessageSafetyService::class);
    }

    public function boot(): void
    {
        Gate::policy(MessageSafetyFinding::class, MessageSafetyFindingPolicy::class);

        // The domain registers its own prompts and schema into the P0
        // registries — app/Ai never learns this feature exists. Two
        // prompts because the phase has two genuinely different AI
        // paths: structured intent analysis, and the provider's own
        // safety classifier.
        $this->app->make(AiSchemaRegistryInterface::class)->register(new CommunicationRiskSchema);
        $this->app->make(AiPromptRegistryInterface::class)->register(CommunicationRiskPrompt::definition());
        $this->app->make(AiPromptRegistryInterface::class)->register(MessageModerationPrompt::definition());
    }
}
