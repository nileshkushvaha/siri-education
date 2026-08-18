<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\DTOs\AiModerationRequest;
use App\Ai\DTOs\AiStructuredRequest;
use App\Ai\DTOs\AiTextRequest;
use App\Ai\Enums\AiFailureCode;
use App\Ai\Providers\OpenAi\OpenAiProvider;
use App\Ai\Providers\OpenAi\OpenAiRequestException;
use App\Settings\AiSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The OpenAI adapter against a faked HTTP layer: authentication,
 * response normalization, and — most importantly — that every transport
 * outcome becomes a classified AiFailureCode with a message that
 * carries neither the credential nor the prompt.
 */
class OpenAiProviderTest extends TestCase
{
    use RefreshDatabase;

    private const string API_KEY = 'sk-test-secret-key-value-1234567890';

    protected function setUp(): void
    {
        parent::setUp();

        $settings = app(AiSettings::class);
        $settings->provider = 'openai';
        $settings->openai_api_key = Crypt::encryptString(self::API_KEY);
        $settings->save();
    }

    private function provider(): OpenAiProvider
    {
        return app(OpenAiProvider::class);
    }

    private function textRequest(): AiTextRequest
    {
        return new AiTextRequest('gpt-4.1', 'system', 'student homework text', 100, 0.2, 10);
    }

    private function completion(array $overrides = []): array
    {
        return array_replace_recursive([
            'choices' => [['message' => ['content' => 'Generated text.'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30],
        ], $overrides);
    }

    // ── Success ───────────────────────────────────────────────────────

    public function test_a_successful_completion_is_normalized_into_neutral_dtos(): void
    {
        Http::fake(['api.openai.com/v1/chat/completions' => Http::response($this->completion(), 200, ['x-request-id' => 'req_123'])]);

        $response = $this->provider()->generateText($this->textRequest());

        $this->assertSame('Generated text.', $response->text);
        $this->assertSame(120, $response->usage->inputTokens);
        $this->assertSame(30, $response->usage->outputTokens);
        $this->assertSame('req_123', $response->usage->providerRequestId);
    }

    public function test_the_decrypted_key_is_sent_as_a_bearer_token_and_never_in_the_url(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->completion())]);

        $this->provider()->generateText($this->textRequest());

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('Bearer '.self::API_KEY, $request->header('Authorization')[0]);
            $this->assertStringNotContainsString(self::API_KEY, $request->url());

            return true;
        });
    }

    public function test_a_structured_request_sends_the_schema_and_decodes_the_payload(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->completion([
            'choices' => [['message' => ['content' => '{"ok":true}']]],
        ]))]);

        $response = $this->provider()->generateStructured(new AiStructuredRequest(
            model: 'gpt-4.1',
            systemPrompt: 'system',
            userPrompt: 'user',
            schemaName: 'connectivity_check',
            jsonSchema: ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]],
            maxOutputTokens: 64,
            temperature: 0.0,
            timeoutSeconds: 10,
        ));

        $this->assertSame(['ok' => true], $response->payload);

        Http::assertSent(fn (Request $request): bool => $request['response_format']['json_schema']['name'] === 'connectivity_check');
    }

    public function test_moderation_results_are_normalized(): void
    {
        Http::fake(['api.openai.com/v1/moderations' => Http::response([
            'results' => [[
                'flagged' => true,
                'categories' => ['harassment' => true, 'violence' => false],
                'category_scores' => ['harassment' => 0.91, 'violence' => 0.01],
            ]],
        ])]);

        $result = $this->provider()->moderate(new AiModerationRequest('omni-moderation-latest', 'message body', 10));

        $this->assertTrue($result->flagged);
        $this->assertSame(['harassment'], $result->categories);
        $this->assertSame(0.91, $result->highestScore);
    }

    // ── Failure classification ────────────────────────────────────────

    public function test_rejected_credentials_are_classified_as_authentication_failed_and_not_retried(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Incorrect API key provided', 'code' => 'invalid_api_key']], 401)]);

        try {
            $this->provider()->generateText($this->textRequest());
            $this->fail('Expected an OpenAiRequestException.');
        } catch (OpenAiRequestException $e) {
            $this->assertSame(AiFailureCode::AuthenticationFailed, $e->failureCode);
            $this->assertFalse($e->failureCode->isRetryable());
        }
    }

    public function test_rate_limiting_is_retryable(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Rate limit reached']], 429)]);

        try {
            $this->provider()->generateText($this->textRequest());
            $this->fail('Expected an OpenAiRequestException.');
        } catch (OpenAiRequestException $e) {
            $this->assertSame(AiFailureCode::RateLimited, $e->failureCode);
            $this->assertTrue($e->failureCode->isRetryable());
        }
    }

    public function test_a_provider_server_error_is_retryable(): void
    {
        Http::fake(['api.openai.com/*' => Http::response('', 503)]);

        try {
            $this->provider()->generateText($this->textRequest());
            $this->fail('Expected an OpenAiRequestException.');
        } catch (OpenAiRequestException $e) {
            $this->assertSame(AiFailureCode::ProviderServerError, $e->failureCode);
            $this->assertTrue($e->failureCode->isRetryable());
        }
    }

    public function test_a_connection_failure_is_classified_as_provider_unavailable(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host'));

        try {
            $this->provider()->generateText($this->textRequest());
            $this->fail('Expected an OpenAiRequestException.');
        } catch (OpenAiRequestException $e) {
            $this->assertSame(AiFailureCode::ProviderUnavailable, $e->failureCode);
        }
    }

    public function test_a_read_timeout_is_classified_as_timeout(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30000 ms'));

        try {
            $this->provider()->generateText($this->textRequest());
            $this->fail('Expected an OpenAiRequestException.');
        } catch (OpenAiRequestException $e) {
            $this->assertSame(AiFailureCode::Timeout, $e->failureCode);
        }
    }

    public function test_malformed_structured_json_is_an_invalid_response(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->completion([
            'choices' => [['message' => ['content' => 'not json at all']]],
        ]))]);

        try {
            $this->provider()->generateStructured(new AiStructuredRequest('gpt-4.1', 's', 'u', 'schema', [], 64, 0.0, 10));
            $this->fail('Expected an OpenAiRequestException.');
        } catch (OpenAiRequestException $e) {
            $this->assertSame(AiFailureCode::InvalidResponse, $e->failureCode);
        }
    }

    public function test_a_safety_filtered_completion_is_not_retried(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => null], 'finish_reason' => 'content_filter']],
            'usage' => [],
        ])]);

        try {
            $this->provider()->generateText($this->textRequest());
            $this->fail('Expected an OpenAiRequestException.');
        } catch (OpenAiRequestException $e) {
            $this->assertSame(AiFailureCode::ContentFiltered, $e->failureCode);
            $this->assertFalse($e->failureCode->isRetryable());
        }
    }

    // ── Message safety ────────────────────────────────────────────────

    public function test_a_provider_error_message_never_leaks_the_prompt_or_the_key(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'error' => [
                // OpenAI routinely quotes the offending input back.
                'message' => 'Invalid input: "student homework text" exceeded the limit for key '.self::API_KEY,
                'code' => 'invalid_request_error',
            ],
        ], 400)]);

        try {
            $this->provider()->generateText($this->textRequest());
            $this->fail('Expected an OpenAiRequestException.');
        } catch (OpenAiRequestException $e) {
            $this->assertStringNotContainsString('student homework text', $e->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $e->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $e->getTraceAsString());
        }
    }

    // ── Health check ──────────────────────────────────────────────────

    public function test_the_health_check_sends_no_prompt(): void
    {
        Http::fake(['api.openai.com/v1/models*' => Http::response(['data' => []])]);

        $this->assertTrue($this->provider()->healthCheck()->healthy);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && str_contains($request->url(), '/models'));
    }

    public function test_the_health_check_reports_a_rejected_key_without_quoting_it(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'bad key '.self::API_KEY]], 401)]);

        $health = $this->provider()->healthCheck();

        $this->assertFalse($health->healthy);
        $this->assertStringNotContainsString(self::API_KEY, (string) $health->safeMessage);
    }

    public function test_a_missing_key_fails_as_not_configured_without_a_network_call(): void
    {
        Http::fake();

        $settings = app(AiSettings::class);
        $settings->openai_api_key = null;
        $settings->save();

        try {
            $this->provider()->generateText($this->textRequest());
            $this->fail('Expected an OpenAiRequestException.');
        } catch (OpenAiRequestException $e) {
            $this->assertSame(AiFailureCode::NotConfigured, $e->failureCode);
        }

        Http::assertNothingSent();
    }
}
