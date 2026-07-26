<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\Templates\NotificationTemplateChannel;
use App\Notifications\Templates\NotificationTemplateKey;
use App\Notifications\Templates\NotificationTemplateRenderer;
use App\Services\Notifications\NotificationTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * GAP-039 requirement #3 — the one authoritative renderer. Covers
 * default fallback, valid override rendering, variable allowlisting,
 * escaping/injection prevention, channel isolation, cache invalidation,
 * and safe fallback when an override is inactive/invalid or the
 * database is unavailable.
 */
final class NotificationTemplateRendererTest extends TestCase
{
    use RefreshDatabase;

    private NotificationTemplateRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->renderer = app(NotificationTemplateRenderer::class);
    }

    private function template(NotificationTemplateKey $key, NotificationTemplateChannel $channel): NotificationTemplate
    {
        return NotificationTemplate::query()
            ->where('template_key', $key->value)
            ->where('channel', $channel->value)
            ->firstOrFail();
    }

    // ── Default fallback ──────────────────────────────────────────────

    public function test_renders_the_code_owned_default_when_no_override_exists(): void
    {
        $rendered = $this->renderer->render(
            NotificationTemplateKey::WalletRechargeSucceeded,
            NotificationTemplateChannel::Mail,
            ['amount' => '$50.00', 'reference' => 'RCHG-1'],
        );

        $this->assertSame('Wallet recharge successful', $rendered->subject);
        $this->assertSame(
            ['Your wallet was recharged with $50.00.', 'Reference: RCHG-1'],
            $rendered->lines,
        );
    }

    // ── Valid override rendering ──────────────────────────────────────

    public function test_renders_an_active_override_instead_of_the_default(): void
    {
        $template = $this->template(NotificationTemplateKey::WalletRechargeSucceeded, NotificationTemplateChannel::Mail);
        $template->update(['subject' => 'Top-up complete!', 'body' => 'We added {{amount}} to your balance.']);

        $rendered = $this->renderer->render(
            NotificationTemplateKey::WalletRechargeSucceeded,
            NotificationTemplateChannel::Mail,
            ['amount' => '$50.00', 'reference' => 'RCHG-1'],
        );

        $this->assertSame('Top-up complete!', $rendered->subject);
        $this->assertSame(['We added $50.00 to your balance.'], $rendered->lines);
    }

    public function test_a_partial_override_falls_back_to_the_default_for_the_missing_half(): void
    {
        $template = $this->template(NotificationTemplateKey::WalletRechargeSucceeded, NotificationTemplateChannel::Mail);
        $template->update(['subject' => 'Top-up complete!', 'body' => null]);

        $rendered = $this->renderer->render(
            NotificationTemplateKey::WalletRechargeSucceeded,
            NotificationTemplateChannel::Mail,
            ['amount' => '$50.00', 'reference' => 'RCHG-1'],
        );

        $this->assertSame('Top-up complete!', $rendered->subject);
        $this->assertSame(['Your wallet was recharged with $50.00.', 'Reference: RCHG-1'], $rendered->lines);
    }

    // ── Allowed / unknown / missing variables ─────────────────────────

    public function test_rejects_a_variable_not_in_the_allowlist_passed_by_the_caller(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->renderer->render(
            NotificationTemplateKey::WalletRechargeSucceeded,
            NotificationTemplateChannel::Mail,
            ['amount' => '$50.00', 'reference' => 'RCHG-1', 'secret_internal_id' => 'nope'],
        );
    }

    public function test_a_missing_variable_value_renders_as_empty_rather_than_erroring(): void
    {
        $rendered = $this->renderer->render(
            NotificationTemplateKey::HomeworkDueReminder,
            NotificationTemplateChannel::Mail,
            [
                'homework_title' => 'Algebra',
                'due_wording' => 'in 1 hour',
                'due_date' => 'Mon 6pm',
                // 'context_label' intentionally omitted
            ],
        );

        $this->assertSame(
            ['"Algebra" is due in 1 hour.', 'Due: Mon 6pm'],
            $rendered->lines,
        );
    }

    public function test_an_override_referencing_an_unknown_variable_falls_back_to_the_default_instead_of_breaking_delivery(): void
    {
        $template = $this->template(NotificationTemplateKey::WalletRechargeSucceeded, NotificationTemplateChannel::Mail);
        $template->update(['subject' => 'Top-up', 'body' => 'Credited {{amount}}, ref {{does_not_exist}}.']);

        $rendered = $this->renderer->render(
            NotificationTemplateKey::WalletRechargeSucceeded,
            NotificationTemplateChannel::Mail,
            ['amount' => '$50.00', 'reference' => 'RCHG-1'],
        );

        // Falls back to the safe code-owned default, not a broken/partial render.
        $this->assertSame('Wallet recharge successful', $rendered->subject);
        $this->assertSame(['Your wallet was recharged with $50.00.', 'Reference: RCHG-1'], $rendered->lines);
    }

    // ── Escaping / injection prevention ───────────────────────────────

    public function test_variable_values_are_html_escaped(): void
    {
        $rendered = $this->renderer->render(
            NotificationTemplateKey::MessageReceived,
            NotificationTemplateChannel::Mail,
            ['sender_name' => '<script>alert(1)</script>'],
        );

        $this->assertStringNotContainsString('<script>', $rendered->subject);
        $this->assertStringContainsString('&lt;script&gt;', $rendered->subject);
        $this->assertStringNotContainsString('<script>', $rendered->lines[0]);
    }

    public function test_an_override_can_never_execute_blade_php_or_expressions(): void
    {
        $template = $this->template(NotificationTemplateKey::MessageReceived, NotificationTemplateChannel::Mail);
        $template->update([
            'subject' => 'New message',
            'body' => '{{ 7 * 7 }} {!! $sender_name !!} @php(die()) <?php echo 1; ?>',
        ]);

        $rendered = $this->renderer->render(
            NotificationTemplateKey::MessageReceived,
            NotificationTemplateChannel::Mail,
            ['sender_name' => 'Jane'],
        );

        // "sender_name" is the only recognized {{ }} token; everything
        // else (the arithmetic, Blade escape/PHP/directive syntax) is
        // treated as inert literal text — nothing is evaluated.
        $this->assertStringContainsString('@php(die())', $rendered->lines[0]);
        $this->assertStringContainsString('<?php echo 1; ?>', $rendered->lines[0]);
        $this->assertStringNotContainsString('49', $rendered->lines[0]);
    }

    // ── Channel isolation ──────────────────────────────────────────────

    public function test_mail_and_database_overrides_for_the_same_key_are_independent(): void
    {
        $mail = $this->template(NotificationTemplateKey::WalletRechargeSucceeded, NotificationTemplateChannel::Mail);
        $mail->update(['subject' => 'Mail override', 'body' => 'Mail body {{amount}}']);

        $database = $this->template(NotificationTemplateKey::WalletRechargeSucceeded, NotificationTemplateChannel::Database);

        $mailRendered = $this->renderer->render(NotificationTemplateKey::WalletRechargeSucceeded, NotificationTemplateChannel::Mail, ['amount' => '$5', 'reference' => 'R']);
        $dbRendered = $this->renderer->render(NotificationTemplateKey::WalletRechargeSucceeded, NotificationTemplateChannel::Database, ['amount' => '$5']);

        $this->assertSame('Mail override', $mailRendered->subject);
        $this->assertSame('Wallet Recharge Successful', $dbRendered->subject);
        $this->assertFalse($database->fresh()->hasOverride());
    }

    public function test_rendering_an_unsupported_channel_for_a_template_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // ReferralRewardCredited only registers a 'mail' channel.
        $this->renderer->render(
            NotificationTemplateKey::ReferralRewardCredited,
            NotificationTemplateChannel::Database,
            [],
        );
    }

    // ── Cache invalidation ──────────────────────────────────────────────

    public function test_cache_is_invalidated_after_a_direct_model_update_via_the_service(): void
    {
        $before = $this->renderer->render(NotificationTemplateKey::WalletRechargeSucceeded, NotificationTemplateChannel::Mail, ['amount' => '$1', 'reference' => 'R']);
        $this->assertSame('Wallet recharge successful', $before->subject);

        $template = $this->template(NotificationTemplateKey::WalletRechargeSucceeded, NotificationTemplateChannel::Mail);
        app(NotificationTemplateService::class)->updateOverride(
            $template,
            User::factory()->create(),
            'Changed subject',
            null,
        );

        $after = $this->renderer->render(NotificationTemplateKey::WalletRechargeSucceeded, NotificationTemplateChannel::Mail, ['amount' => '$1', 'reference' => 'R']);
        $this->assertSame('Changed subject', $after->subject);
    }

    public function test_a_cached_no_override_result_does_not_requery_the_database(): void
    {
        // Warm the cache for "no override" (the common case for most templates).
        $this->renderer->render(NotificationTemplateKey::WalletRechargeSucceeded, NotificationTemplateChannel::Mail, ['amount' => '$1', 'reference' => 'R']);

        DB::enableQueryLog();
        $this->renderer->render(NotificationTemplateKey::WalletRechargeSucceeded, NotificationTemplateChannel::Mail, ['amount' => '$1', 'reference' => 'R']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(0, count($queries), 'a cached "no override" result must not requery the database');
    }

    // ── Inactive override falls back to default ───────────────────────

    public function test_a_deactivated_override_falls_back_to_the_default(): void
    {
        $template = $this->template(NotificationTemplateKey::WalletRechargeSucceeded, NotificationTemplateChannel::Mail);
        $template->update(['subject' => 'Should not appear', 'body' => 'Should not appear', 'is_active' => false]);
        Cache::forget(NotificationTemplateRenderer::cacheKey(NotificationTemplateKey::WalletRechargeSucceeded, NotificationTemplateChannel::Mail));

        $rendered = $this->renderer->render(
            NotificationTemplateKey::WalletRechargeSucceeded,
            NotificationTemplateChannel::Mail,
            ['amount' => '$50.00', 'reference' => 'RCHG-1'],
        );

        $this->assertSame('Wallet recharge successful', $rendered->subject);
    }

    // ── Database unavailable falls back to the default ────────────────

    public function test_falls_back_to_the_default_when_the_templates_table_is_unreachable(): void
    {
        Schema::drop('notification_templates');

        $rendered = $this->renderer->render(
            NotificationTemplateKey::WalletRechargeSucceeded,
            NotificationTemplateChannel::Mail,
            ['amount' => '$50.00', 'reference' => 'RCHG-1'],
        );

        $this->assertSame('Wallet recharge successful', $rendered->subject);
    }
}
