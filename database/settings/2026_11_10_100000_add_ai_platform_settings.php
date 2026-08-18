<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * AI platform foundation (P0).
 *
 * Ships INERT: `features.ai_enabled` is false, every capability flag is
 * false, and no API key exists. Nothing in the application calls a
 * provider until an administrator supplies a key and turns the module
 * on — and even then, only the diagnostics connectivity check has a
 * registered prompt, because P1-P4 are not implemented.
 *
 * `features.ai_enabled` lives in FeatureSettings rather than AiSettings
 * so there is exactly one master switch per feature module, matching
 * every other module in that class.
 *
 * Default models are the current general-purpose OpenAI line-up. They
 * are settings, not constants, precisely because model names change
 * faster than deploys do.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('features.ai_enabled', false);

        // 'fake' rather than 'openai': the shipped default must not be a
        // provider that would attempt a network call the moment someone
        // flips the module on before configuring a key.
        $this->migrator->add('ai.provider', 'fake');
        $this->migrator->add('ai.openai_api_key', null);
        $this->migrator->add('ai.openai_organization', null);

        $this->migrator->add('ai.generation_model', 'gpt-4.1');
        $this->migrator->add('ai.fast_model', 'gpt-4.1-mini');
        $this->migrator->add('ai.embedding_model', 'text-embedding-3-small');
        $this->migrator->add('ai.moderation_model', 'omni-moderation-latest');

        $this->migrator->add('ai.request_timeout_seconds', 30);

        $this->migrator->add('ai.quality_insights_enabled', false);
        $this->migrator->add('ai.homework_assistant_enabled', false);
        $this->migrator->add('ai.lesson_summary_enabled', false);
        $this->migrator->add('ai.communication_moderation_enabled', false);

        // A conservative ceiling rather than null: an unbudgeted AI
        // integration is the failure mode that gets noticed on an
        // invoice. An operator raises it deliberately.
        $this->migrator->add('ai.daily_cost_limit', 5.0);
        $this->migrator->add('ai.monthly_cost_limit', 100.0);
        $this->migrator->add('ai.cost_currency', 'USD');

        // "input/output" per one million tokens, in cost_currency.
        // Estimation input only — never a billing source of truth, and
        // an operator is expected to refresh these from the provider's
        // price list.
        $this->migrator->add('ai.model_pricing', [
            'gpt-4.1' => '2.0/8.0',
            'gpt-4.1-mini' => '0.4/1.6',
            'text-embedding-3-small' => '0.02/0',
            'omni-moderation-latest' => '0/0',
        ]);

        $this->migrator->add('ai.last_health_status', 'unknown');
        $this->migrator->add('ai.last_health_check_at', null);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('features.ai_enabled');

        foreach ([
            'provider', 'openai_api_key', 'openai_organization',
            'generation_model', 'fast_model', 'embedding_model', 'moderation_model',
            'request_timeout_seconds',
            'quality_insights_enabled', 'homework_assistant_enabled',
            'lesson_summary_enabled', 'communication_moderation_enabled',
            'daily_cost_limit', 'monthly_cost_limit', 'cost_currency', 'model_pricing',
            'last_health_status', 'last_health_check_at',
        ] as $key) {
            $this->migrator->deleteIfExists("ai.{$key}");
        }
    }
};
