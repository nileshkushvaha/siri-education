<?php

declare(strict_types=1);

namespace App\Providers;

use App\Ai\Contracts\AiExecutionServiceInterface;
use App\Ai\Contracts\AiFeatureRegistryInterface;
use App\Ai\Contracts\AiPromptRegistryInterface;
use App\Ai\Contracts\AiProviderRegistryInterface;
use App\Ai\Contracts\AiProviderResolverInterface;
use App\Ai\Contracts\AiRunRepositoryInterface;
use App\Ai\Contracts\AiSchemaRegistryInterface;
use App\Ai\Enums\AiFeature;
use App\Ai\Evaluation\AiFeedbackRecorder;
use App\Ai\Evaluation\Contracts\AiFeedbackRecorderInterface;
use App\Ai\Prompts\AiPromptCatalog;
use App\Ai\Prompts\AiPromptRegistry;
use App\Ai\Providers\Fake\FakeAiProvider;
use App\Ai\Providers\OpenAi\OpenAiClientInterface;
use App\Ai\Providers\OpenAi\OpenAiHttpClient;
use App\Ai\Providers\OpenAi\OpenAiProvider;
use App\Ai\Registry\AiFeatureDefinition;
use App\Ai\Registry\AiFeatureRegistry;
use App\Ai\Registry\AiProviderRegistry;
use App\Ai\Repositories\AiRunRepository;
use App\Ai\Schemas\AiSchemaCatalog;
use App\Ai\Schemas\AiSchemaRegistry;
use App\Ai\Services\AiExecutionService;
use App\Ai\Services\AiProviderResolver;
use App\Ai\Services\NullTaskInputResolver;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the AI platform layer (P0).
 *
 * Everything binds to an interface, so replacing OpenAI later is a
 * change to the registry closure below and nothing else — no business
 * module names a provider, a client, or a model.
 *
 * Registering an adapter here does NOT enable it: FeatureSettings::
 * $ai_enabled is off, `ai.provider` defaults to the network-free fake,
 * and no API key ships. Registration only makes the provider
 * SELECTABLE.
 */
class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiRunRepositoryInterface::class, AiRunRepository::class);

        // The OpenAI transport seam — the only class that authenticates
        // against OpenAI, bound behind an interface so the adapter can
        // be exercised without a network.
        $this->app->singleton(OpenAiClientInterface::class, OpenAiHttpClient::class);

        $this->app->singleton(AiProviderRegistryInterface::class, function (): AiProviderRegistry {
            $registry = new AiProviderRegistry;
            $registry->register(new FakeAiProvider);
            $registry->register($this->app->make(OpenAiProvider::class));

            return $registry;
        });

        // The feature allowlist. Empty by default and populated by each
        // owning domain — a feature nobody registered cannot run, which
        // is what stops a new AI capability becoming available merely by
        // existing.
        $this->app->singleton(AiFeatureRegistryInterface::class, function (): AiFeatureRegistry {
            $registry = new AiFeatureRegistry;

            // The one feature the AI module owns: the admin connectivity
            // check. Carries no variables, reads no platform data.
            $registry->register(new AiFeatureDefinition(
                feature: AiFeature::PlatformDiagnostics,
                ownerDomain: 'app/Ai',
                purpose: 'Verify provider credentials from the admin settings page. Sends no platform data.',
                inputResolver: NullTaskInputResolver::class,
                resultHandlers: [],
                allowedPromptKeys: ['platform_connectivity_check'],
                // Only ever triggered by an administrator pressing a
                // button, so an acting user is always available.
                requiresAuthenticatedActor: false,
            ));

            return $registry;
        });

        $this->app->singleton(AiProviderResolverInterface::class, AiProviderResolver::class);
        $this->app->singleton(AiProviderResolver::class);

        $this->app->singleton(AiPromptRegistryInterface::class, function (): AiPromptRegistry {
            $registry = new AiPromptRegistry;

            foreach (AiPromptCatalog::definitions() as $definition) {
                $registry->register($definition);
            }

            return $registry;
        });

        $this->app->singleton(AiSchemaRegistryInterface::class, function (): AiSchemaRegistry {
            $registry = new AiSchemaRegistry;

            foreach (AiSchemaCatalog::schemas() as $schema) {
                $registry->register($schema);
            }

            return $registry;
        });

        // The single entry point business modules may depend on.
        $this->app->singleton(AiExecutionServiceInterface::class, AiExecutionService::class);

        // AI-E0: the reusable evaluation hook every feature records
        // reviewer verdicts through.
        $this->app->singleton(AiFeedbackRecorderInterface::class, AiFeedbackRecorder::class);
    }
}
