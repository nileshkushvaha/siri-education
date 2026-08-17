<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\Auth\AccountUnlockedNotification;
use App\Notifications\Auth\WelcomeNotification;
use App\Notifications\Concerns\ConfiguresTransactionalEmail;
use App\Notifications\Contracts\TransactionalEmail;
use App\Notifications\Instructor\InstructorProfileStatusNotification;
use App\Notifications\Instructor\InstructorWithdrawalStatusNotification;
use App\Notifications\Newsletter\NewsletterWelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use ReflectionClass;
use Tests\TestCase;

/**
 * The shared SIRI email shell: every MailMessage-based transactional
 * notification must render inside one branded layout rather than Laravel's
 * unbranded default markdown theme, and every transactional notification
 * must route through the category sender.
 */
class TransactionalEmailThemeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => 'database/settings']);
    }

    // ── The shell is applied centrally ────────────────────────────────────

    public function test_configure_mail_message_applies_the_branded_view(): void
    {
        $message = $this->buildStockMessage();

        $this->assertSame(
            ['html' => 'emails.notification', 'text' => 'emails.notification-text'],
            $message->view,
            'Stock MailMessage notifications must not fall through to Laravel\'s default theme.',
        );
    }

    public function test_a_bespoke_template_still_overrides_the_default_shell(): void
    {
        $user = User::factory()->create();

        // WelcomeNotification calls configureMailMessage() and *then* ->view().
        $message = (new WelcomeNotification)->toMail($user);

        $this->assertSame('emails.auth.welcome', $message->view);
    }

    // ── The shell actually renders ────────────────────────────────────────

    public function test_branded_shell_renders_branding_content_and_a_working_cta(): void
    {
        $message = $this->buildStockMessage();
        $html = view('emails.notification', $message->data())->render();

        $this->assertStringContainsString(config('app.name'), $html);
        $this->assertStringContainsString('Your booking is confirmed', $html);      // greeting
        $this->assertStringContainsString('Reference: BKG-1024', $html);            // intro line
        $this->assertStringContainsString('https://example.test/bookings/1024', $html); // CTA href
        $this->assertStringContainsString('View booking', $html);                   // CTA label
        $this->assertStringContainsString('All rights reserved', $html);            // shared footer

        // Fallback link for clients that strip buttons.
        $this->assertStringContainsString('copy and paste this link', $html);
    }

    public function test_branded_shell_renders_a_plain_text_alternative(): void
    {
        $message = $this->buildStockMessage();
        $text = view('emails.notification-text', $message->data())->render();

        $this->assertStringContainsString('Reference: BKG-1024', $text);
        $this->assertStringContainsString('https://example.test/bookings/1024', $text);
        $this->assertStringNotContainsString('<div', $text);
    }

    public function test_error_level_messages_render_without_leaking_markup(): void
    {
        $message = $this->buildStockMessage();
        $message->error()->line('Amount <b>not</b> captured');

        $html = view('emails.notification', $message->data())->render();

        // Escaped, not injected.
        $this->assertStringContainsString('Amount &lt;b&gt;not&lt;/b&gt; captured', $html);
    }

    // ── Every transactional notification routes through the sender ────────

    /**
     * These four previously extended Notification directly, bypassing the
     * category sender, the notifications queue, and the branded shell.
     */
    public function test_previously_unrouted_notifications_now_use_the_shared_pipeline(): void
    {
        $classes = [
            AccountUnlockedNotification::class,
            InstructorProfileStatusNotification::class,
            NewsletterWelcomeNotification::class,
            InstructorWithdrawalStatusNotification::class,
        ];

        foreach ($classes as $class) {
            $this->assertTrue(
                is_a($class, TransactionalEmail::class, true),
                "{$class} must declare an email category/sender.",
            );
            $this->assertContains(
                ConfiguresTransactionalEmail::class,
                $this->traitsOf($class),
                "{$class} must use the shared transactional email concern.",
            );
        }
    }

    public function test_no_notification_builds_a_bare_mail_message(): void
    {
        $offenders = [];

        foreach ($this->notificationFiles() as $file) {
            $source = (string) file_get_contents($file);

            if (! str_contains($source, 'function toMail')) {
                continue;
            }

            if (str_contains($source, '(new MailMessage)') && ! str_contains($source, 'configureMailMessage(new MailMessage)')) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders,
            'Every toMail() must build through configureMailMessage() so it gets the category sender and the branded shell.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** A representative stock notification message, built through the real concern. */
    private function buildStockMessage(): MailMessage
    {
        $notification = new class
        {
            use ConfiguresTransactionalEmail;

            public function senderKey(): string
            {
                return 'booking';
            }

            public function build(): MailMessage
            {
                return $this->configureMailMessage(new MailMessage)
                    ->subject('Booking confirmed')
                    ->greeting('Your booking is confirmed')
                    ->line('Reference: BKG-1024')
                    ->action('View booking', 'https://example.test/bookings/1024')
                    ->line('See you then.');
            }
        };

        return $notification->build();
    }

    /** @return array<int, string> */
    private function traitsOf(string $class): array
    {
        $traits = [];

        for ($ref = new ReflectionClass($class); $ref !== false; $ref = $ref->getParentClass()) {
            $traits = array_merge($traits, $ref->getTraitNames());
        }

        return $traits;
    }

    /** @return array<int, string> */
    private function notificationFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Notifications')),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
