<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging\Safety;

use App\Ai\Contracts\AiTaskInputResolverInterface;
use App\Ai\Contracts\AiTaskResultHandlerInterface;
use App\Messaging\Safety\Resolvers\CommunicationRiskResultHandler;
use App\Messaging\Safety\Resolvers\CommunicationSafetyInputResolver;
use App\Messaging\Safety\Resolvers\MessageModerationResultHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * P4 must reuse the P0 foundation and the existing deterministic and
 * compliance machinery, and must remain structurally incapable of
 * enforcing anything.
 */
class MessageSafetyArchitectureTest extends TestCase
{
    use RefreshDatabase;

    private const string DOMAIN = 'Messaging/Safety';

    // ── Reuses the foundation ─────────────────────────────────────────

    public function test_the_domain_never_names_a_provider_or_calls_http(): void
    {
        foreach ($this->domainFiles() as $path => $code) {
            $this->assertStringNotContainsString('App\\Ai\\Providers', $code, "Must not name an AI provider: {$path}");
            $this->assertStringNotContainsString('api.openai.com', $code, $path);
            $this->assertDoesNotMatchRegularExpression('/\bHttp::/', $code, "AI calls belong behind the AI layer: {$path}");
        }
    }

    public function test_the_domain_uses_the_shared_ai_job_and_no_job_of_its_own(): void
    {
        $service = (string) file_get_contents(app_path('Messaging/Safety/Services/MessageSafetyService.php'));

        $this->assertStringContainsString('ExecuteAiTaskJob::dispatch', $service);
        $this->assertFalse(is_dir(app_path('Messaging/Safety/Jobs')), 'P4 must not add its own AI job class.');
    }

    public function test_the_bridge_classes_implement_the_foundation_contracts(): void
    {
        $this->assertInstanceOf(AiTaskInputResolverInterface::class, app(CommunicationSafetyInputResolver::class));
        $this->assertInstanceOf(AiTaskResultHandlerInterface::class, app(CommunicationRiskResultHandler::class));
        $this->assertInstanceOf(AiTaskResultHandlerInterface::class, app(MessageModerationResultHandler::class));
    }

    // ── Reuses the deterministic layer rather than reimplementing it ──

    /**
     * The single most expensive mistake this phase could make would be
     * a second copy of the contact-detection patterns. The safety
     * domain writes none of its own — it calls the detector that
     * already runs on every message.
     */
    public function test_the_safety_domain_contains_no_contact_detection_patterns_of_its_own(): void
    {
        foreach ($this->domainFiles() as $path => $code) {
            $stripped = $this->withoutComments($code);

            // Any re-implementation would need a regex; the domain has
            // none at all.
            $this->assertDoesNotMatchRegularExpression(
                '/preg_match|preg_replace|preg_split/',
                $stripped,
                "Detection belongs to LeakageDetector, not here: {$path}",
            );
        }
    }

    public function test_the_triage_gate_delegates_to_the_existing_detector(): void
    {
        $detector = (string) file_get_contents(app_path('Messaging/Safety/Support/AmbiguousIntentDetector.php'));

        $this->assertStringContainsString('LeakageDetector', $detector);
        // No regexes of its own: it matches literal phrases only.
        $this->assertDoesNotMatchRegularExpression('/preg_match|preg_replace/', $detector);
    }

    // ── Cannot enforce ────────────────────────────────────────────────

    /**
     * The core safety property of P4. If this fails, an AI path has
     * gained the ability to act against a user.
     */
    public function test_no_safety_code_path_can_restrict_hide_or_suspend_anything(): void
    {
        $surfaces = [
            ...$this->domainFiles(),
            app_path('Models/MessageSafetyFinding.php') => (string) file_get_contents(app_path('Models/MessageSafetyFinding.php')),
            app_path('Policies/MessageSafetyFindingPolicy.php') => (string) file_get_contents(app_path('Policies/MessageSafetyFindingPolicy.php')),
            app_path('Listeners/Messaging/AnalyseSentMessageForSafety.php') => (string) file_get_contents(app_path('Listeners/Messaging/AnalyseSentMessageForSafety.php')),
            app_path('Listeners/Messaging/ModerateReportedMessage.php') => (string) file_get_contents(app_path('Listeners/Messaging/ModerateReportedMessage.php')),
        ];

        foreach ($surfaces as $path => $code) {
            $stripped = $this->withoutComments($code);

            foreach ([
                'applyRestriction', 'restrictConversation', 'suspendUser',
                'StudentLifecycleService', 'InstructorStatus', 'ConversationStatus::Restricted',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $stripped, "Safety findings must never enforce: {$path}");
            }

            // Word-boundary matched: the triage phrase list legitimately
            // contains "bank transfer", which a naive substring check
            // for "ban" would flag.
            foreach (['ban', 'suspend', 'block'] as $verb) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\\b'.$verb.'(User|Account|Message|Conversation)\\b/i',
                    $stripped,
                    "Safety findings must never enforce: {$path}",
                );
            }
        }
    }

    /** The message body is never altered — the pre-existing platform rule. */
    public function test_the_domain_never_writes_to_a_message(): void
    {
        foreach ($this->domainFiles() as $path => $code) {
            foreach (['$message->save(', '$message->update(', '$message->forceFill(', "'body' =>"] as $write) {
                $this->assertStringNotContainsString($write, $code, "Message text is never altered: {$path}");
            }
        }
    }

    public function test_the_findings_table_carries_no_enforcement_column(): void
    {
        $columns = Schema::getColumnListing('message_safety_findings');

        foreach (['blocked', 'banned', 'restricted', 'hidden', 'removed', 'action', 'suspended'] as $forbidden) {
            foreach ($columns as $column) {
                $this->assertStringNotContainsString($forbidden, $column, "A finding is evidence, not an action: {$column}");
            }
        }

        // And the column that keeps probabilistic apart from factual.
        $this->assertContains('source_type', $columns);
    }

    // ── Reuses the compliance queue rather than duplicating it ────────

    public function test_no_second_compliance_resolution_surface_was_added(): void
    {
        $this->assertFalse(
            is_dir(app_path('Filament/Resources/MessageSafetyFindings')),
            'Per-message findings extend the existing Conversations screen; account-level review stays in Compliance Flags.',
        );
    }

    /** Only human-confirmed findings may reach the compliance pipeline. */
    public function test_the_escalation_rule_counts_confirmed_findings_only(): void
    {
        $rule = (string) file_get_contents(app_path('Compliance/Rules/RepeatedConfirmedMessageRisksRule.php'));

        $this->assertStringContainsString('countConfirmedForSenderSince', $rule);
        // No direct AI concepts inside the compliance pipeline.
        $this->assertStringNotContainsString('AiRun', $rule);
        $this->assertStringNotContainsString('confidence', $rule);
    }

    public function test_the_safety_service_never_writes_a_compliance_flag_directly(): void
    {
        foreach ($this->domainFiles() as $path => $code) {
            $this->assertStringNotContainsString('ComplianceMonitoringService', $code, "Escalation goes through an event and a deterministic rule: {$path}");
            $this->assertStringNotContainsString('SuspiciousActivityFlag', $code, $path);
        }
    }

    private function withoutComments(string $code): string
    {
        $out = '';

        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /** @return array<string, string> */
    private function domainFiles(): array
    {
        return $this->phpFilesIn([app_path(self::DOMAIN)]);
    }

    /**
     * @param  list<string>  $directories
     * @return array<string, string>
     */
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
