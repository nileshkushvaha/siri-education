<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\Contracts\AiExecutionServiceInterface;
use App\Ai\Contracts\AiProviderInterface;
use App\Ai\Enums\AiFeature;
use App\Ai\Providers\Fake\FakeAiProvider;
use App\Ai\Providers\OpenAi\OpenAiProvider;
use App\Ai\Services\AiExecutionService;
use App\Settings\AiSettings;
use App\Settings\FeatureSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The structural guarantees that make P1-P4 addable without a redesign,
 * and that keep the security rules true by construction rather than by
 * discipline.
 */
class AiArchitectureTest extends TestCase
{
    use RefreshDatabase;

    private const string OPENAI_NAMESPACE = 'App\\Ai\\Providers\\OpenAi';

    // ── Provider replaceability ───────────────────────────────────────

    /** The whole point of the layer: nothing outside the adapter folder knows OpenAI exists. */
    public function test_no_code_outside_the_openai_adapter_references_openai(): void
    {
        $allowed = [
            app_path('Ai/Providers/OpenAi'),
            app_path('Providers/AiServiceProvider.php'),
        ];

        foreach ($this->phpFilesIn([app_path()]) as $path => $code) {
            foreach ($allowed as $allowedPath) {
                if (str_starts_with($path, $allowedPath)) {
                    continue 2;
                }
            }

            $this->assertStringNotContainsString(self::OPENAI_NAMESPACE, $code, "Direct OpenAI dependency in {$path}");
            $this->assertStringNotContainsString('api.openai.com', $code, "Hardcoded OpenAI URL in {$path}");
        }
    }

    public function test_the_openai_base_url_lives_only_in_the_http_client(): void
    {
        $client = app_path('Ai/Providers/OpenAi/OpenAiHttpClient.php');

        foreach ($this->phpFilesIn([app_path('Ai')]) as $path => $code) {
            if ($path === $client) {
                continue;
            }

            $this->assertStringNotContainsString('api.openai.com', $code, "Unexpected OpenAI base URL in {$path}");
        }
    }

    public function test_every_registered_provider_is_reached_through_the_contract(): void
    {
        foreach ([OpenAiProvider::class, FakeAiProvider::class] as $provider) {
            $this->assertTrue(is_subclass_of($provider, AiProviderInterface::class), "{$provider} must implement the provider contract.");
        }
    }

    /** Business modules may depend on the execution contract, never on an implementation. */
    public function test_no_business_module_references_a_provider_or_the_execution_implementation(): void
    {
        $businessModules = array_filter([
            app_path('Booking'), app_path('Payments'), app_path('Wallet'), app_path('Earnings'),
            app_path('Messaging'), app_path('Homework'), app_path('Lessons'), app_path('Reviews'),
            app_path('Quality'), app_path('Compliance'), app_path('Filament'), app_path('Http'),
            app_path('Livewire'), app_path('Models'),
        ], 'is_dir');

        foreach ($this->phpFilesIn($businessModules) as $path => $code) {
            $this->assertStringNotContainsString('App\\Ai\\Providers', $code, "Business code must not name an AI provider: {$path}");
            $this->assertStringNotContainsString(AiExecutionService::class, $code, "Business code must depend on the AI execution CONTRACT, not the class: {$path}");
        }
    }

    public function test_the_execution_service_is_bound_to_its_contract(): void
    {
        $this->assertInstanceOf(AiExecutionService::class, app(AiExecutionServiceInterface::class));
    }

    /** Only the resolver may decide which provider is active. */
    public function test_only_the_provider_resolver_reads_the_provider_setting(): void
    {
        $readers = [];

        foreach ($this->phpFilesIn([app_path('Ai')]) as $path => $code) {
            if (str_contains($code, '->provider;') || str_contains($code, 'settings->provider')) {
                $readers[] = basename($path);
            }
        }

        sort($readers);

        // AiFeatureGate reads it only to know whether the network-free
        // fake provider needs a credential at all.
        $this->assertSame(['AiFeatureGate.php', 'AiProviderResolver.php'], $readers);
    }

    // ── Model naming ──────────────────────────────────────────────────

    public function test_no_model_name_is_hardcoded_outside_the_ai_module_and_its_settings(): void
    {
        $needles = ['gpt-4', 'gpt-5', 'text-embedding-', 'omni-moderation'];

        foreach ($this->phpFilesIn([app_path()]) as $path => $code) {
            foreach ($needles as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $code,
                    "Model names belong in AiSettings, never in code: {$path}",
                );
            }
        }
    }

    // ── Content and secret discipline ─────────────────────────────────

    public function test_the_ai_runs_table_has_no_column_that_could_hold_content(): void
    {
        // Columns that legitimately contain one of the needles as a
        // metadata identifier or a counter — never content itself.
        $identifierColumns = ['prompt_key', 'prompt_version', 'input_tokens', 'output_tokens'];

        $columns = array_diff(Schema::getColumnListing('ai_runs'), $identifierColumns);

        foreach (['prompt', 'response', 'input', 'output', 'content', 'payload', 'body', 'text', 'message'] as $forbidden) {
            foreach ($columns as $column) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $column,
                    "ai_runs must never store AI content: found {$column}",
                );
            }
        }
    }

    public function test_the_ai_module_never_logs_outside_the_allowlisting_logger(): void
    {
        $logger = app_path('Ai/Services/AiLogger.php');

        foreach ($this->phpFilesIn([app_path('Ai')]) as $path => $code) {
            if ($path === $logger) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression('/\bLog::/', $code, "Use AiLogger, never the Log facade: {$path}");
            $this->assertDoesNotMatchRegularExpression('/\blogger\(/', $code, "Use AiLogger, never the logger() helper: {$path}");
        }
    }

    public function test_the_ai_module_never_writes_the_audit_trail_directly(): void
    {
        foreach ($this->phpFilesIn([app_path('Ai')]) as $path => $code) {
            $this->assertDoesNotMatchRegularExpression('/\bactivity\(/', $code, "Never call activity() directly: {$path}");
        }
    }

    // ── Safety boundary ───────────────────────────────────────────────

    /**
     * The AI module may not touch money, identity or lifecycle state. It
     * summarizes, classifies and suggests; every consequential action
     * stays with the domain service and its human review path.
     */
    public function test_the_ai_module_cannot_reach_financial_or_lifecycle_services(): void
    {
        $forbidden = [
            'Wallet', 'Payment', 'Payout', 'Refund', 'Invoice', 'Withdrawal',
            'Kyc', 'Suspend', 'Compensation', 'Earning',
        ];

        foreach ($this->phpFilesIn([app_path('Ai')]) as $path => $code) {
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    "App\\{$needle}",
                    $code,
                    "The AI module must never depend on {$needle}: {$path}",
                );
            }
        }
    }

    public function test_the_ai_module_imports_no_domain_model_other_than_its_own_records(): void
    {
        // AiRun and AiFeedbackEvent are the AI module's OWN telemetry
        // records — the same kind of thing, one about a run and one
        // about a reviewer's verdict on it. Every other model belongs to
        // a business domain, and depending on one would make the AI
        // layer un-reusable and un-swappable, which is the rule's point.
        $ownRecords = ['AiRun', 'AiFeedbackEvent'];

        foreach ($this->phpFilesIn([app_path('Ai')]) as $path => $code) {
            preg_match_all('/^use App\\\\Models\\\\([A-Za-z]+);/m', $code, $matches);

            foreach ($matches[1] as $model) {
                $this->assertContains($model, $ownRecords, "The AI module must not depend on {$model}: {$path}");
            }
        }
    }

    // ── Feature rollout ───────────────────────────────────────────────

    public function test_every_feature_flag_ships_off(): void
    {
        $settings = app(AiSettings::class);

        $this->assertFalse(app(FeatureSettings::class)->ai_enabled);

        foreach (AiFeature::cases() as $feature) {
            $flag = $feature->settingsFlag();

            if ($flag === null) {
                continue;
            }

            $this->assertFalse((bool) $settings->{$flag}, "{$feature->value} must ship disabled.");
        }
    }

    public function test_the_shipped_default_provider_makes_no_network_call(): void
    {
        $this->assertSame('fake', app(AiSettings::class)->provider);
    }

    /** @return array<string, string> */
    private function phpFilesIn(array $directories): array
    {
        $files = [];

        foreach ($directories as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        return $files;
    }
}
