<?php

declare(strict_types=1);

namespace App\Ai\Prompts;

use App\Ai\Enums\AiCapability;
use App\Ai\Enums\AiFeature;
use App\Ai\Enums\AiModelRole;
use App\Ai\Exceptions\AiConfigurationException;

/**
 * One immutable, versioned prompt.
 *
 * Versions are ADDITIVE AND FROZEN: changing wording means registering
 * a new version, never editing an existing one. An ai_runs row records
 * prompt_key + prompt_version, and that pairing is only meaningful if
 * "lesson_summary:v1" refers to the same text forever — otherwise a
 * future quality comparison is measuring two different prompts under
 * one name.
 *
 * Templates use {{ variable }} placeholders. Rendering is a plain
 * substitution with no expression evaluation: template text is
 * developer-authored, but variable VALUES are student/instructor
 * content, and a templating engine that could execute them would turn
 * user content into code.
 */
final readonly class PromptDefinition
{
    public function __construct(
        public string $key,
        public string $version,
        public AiFeature $feature,
        public AiCapability $capability,
        public string $systemTemplate,
        public string $userTemplate,
        public ?string $schemaKey = null,
        public ?AiModelRole $modelRole = null,
        public int $maxOutputTokens = 800,
        /** Low by default: these are analysis tasks, not creative writing. */
        public float $temperature = 0.2,
    ) {}

    public function identifier(): string
    {
        return "{$this->key}:{$this->version}";
    }

    public function resolvedModelRole(): AiModelRole
    {
        return $this->modelRole ?? $this->capability->defaultModelRole();
    }

    /**
     * @param  array<string, string>  $variables
     *
     * @throws AiConfigurationException when the template needs a variable the caller did not supply
     */
    public function render(array $variables): RenderedPrompt
    {
        return new RenderedPrompt(
            system: $this->substitute($this->systemTemplate, $variables),
            user: $this->substitute($this->userTemplate, $variables),
            definition: $this,
        );
    }

    /** @param array<string, string> $variables */
    private function substitute(string $template, array $variables): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            function (array $matches) use ($variables): string {
                $name = $matches[1];

                if (! array_key_exists($name, $variables)) {
                    // Named, never valued — the missing variable's name is
                    // developer-authored; its value would have been content.
                    throw new AiConfigurationException(
                        sprintf('Prompt "%s" requires the variable "%s".', $this->identifier(), $name),
                    );
                }

                return $variables[$name];
            },
            $template,
        ) ?? $template;
    }
}
