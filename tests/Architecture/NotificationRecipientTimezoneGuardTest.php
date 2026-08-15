<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Notifications\Concerns\FormatsRecipientLocalTime;
use Tests\TestCase;

/**
 * TZ-3 permanent guard: a notification must not format a domain instant
 * without saying whose clock it is in.
 *
 * The three defects this closes all looked the same:
 *
 *     $this->alert->triggered_at->format('M j, Y g:i A')
 *
 * — an absolute UTC instant formatted directly, so every recipient read
 * the server's clock. The correct forms all convert first:
 *
 *     $this->booking->starts_at->timezone($timezone)->format(...)
 *     $this->recipientDateTime($this->alert->triggered_at, $notifiable)
 *
 * The check is deliberately anchored on `*_at` attributes rather than on
 * `format()` in general: `format()` is used all over these classes for
 * money and labels, and a guard that flagged all of it would be turned
 * off within a week.
 */
class NotificationRecipientTimezoneGuardTest extends TestCase
{
    /**
     * Platform-generated "this email was sent at" timestamps, which are
     * NOT user-domain events and are correct as server time.
     *
     * Both templates render `now()->format('d M Y, h:i A T')` — the `T`
     * prints the zone, so the reader is told plainly what clock they are
     * looking at. Converting them would be worse, not better: they
     * describe when the platform acted, not when anything happened to
     * the recipient.
     *
     * Kept short and explained on purpose. A long allowlist means the
     * rule is wrong.
     */
    private const array PLATFORM_TIMESTAMP_TEMPLATES = [
        'resources/views/emails/auth/admin-account-locked.blade.php',
        'resources/views/emails/auth/admin-new-registration.blade.php',
    ];

    public function test_the_shared_recipient_formatting_trait_exists(): void
    {
        $this->assertTrue(trait_exists(FormatsRecipientLocalTime::class));
    }

    public function test_no_notification_formats_a_domain_instant_without_converting_it(): void
    {
        $offenders = [];

        foreach ($this->notificationFiles() as $file) {
            $source = $this->strippedSource($file);

            // `…->something_at->format(` with no conversion in between.
            // The correct spellings both break this pattern: an explicit
            // `->timezone($tz)->format(` inserts a call, and the trait
            // helpers take the instant as an argument instead.
            if (preg_match('/->\w*_at(?:\??)->format\s*\(/', $source) === 1) {
                $offenders[] = str_replace(base_path().'/', '', $file);
            }
        }

        $this->assertSame([], $offenders, implode(' ', [
            'A notification formatted an absolute instant without converting it to the recipient\'s',
            'timezone. Use $this->recipientDateTime($instant, $notifiable) (or ->timezone($tz) as the',
            'booking family does) so each recipient reads their own clock (TZ-AUD-014). Offending files:',
            implode(', ', $offenders),
        ]));
    }

    public function test_notifications_never_resolve_a_timezone_from_the_authenticated_user(): void
    {
        // A queued notification has no session, and the recipient is
        // frequently not the actor anyway. The notifiable is the only
        // correct source.
        $offenders = [];

        foreach ($this->notificationFiles() as $file) {
            $source = $this->strippedSource($file);

            if (preg_match('/\bauth\s*\(\s*\)|\bAuth::(user|id)\s*\(/', $source) === 1) {
                $offenders[] = str_replace(base_path().'/', '', $file);
            }
        }

        $this->assertSame([], $offenders, 'Notifications must resolve timezone from $notifiable, not auth(): '.implode(', ', $offenders));
    }

    public function test_notifications_do_not_reimplement_the_resolver_fallback_chain(): void
    {
        // One resolver owns profile -> Country -> platform -> UTC.
        // A local `?: config('app.timezone')` inside a notification is
        // a second, wrong copy of it.
        $offenders = [];

        foreach ($this->notificationFiles() as $file) {
            $source = $this->strippedSource($file);

            if (str_contains($source, "config('app.timezone')") || str_contains($source, 'config("app.timezone")')) {
                $offenders[] = str_replace(base_path().'/', '', $file);
            }
        }

        $this->assertSame([], $offenders, 'Notifications must go through UserTimezoneResolver: '.implode(', ', $offenders));
    }

    public function test_platform_timestamp_templates_still_label_their_timezone(): void
    {
        // These stay server-time by design (see the constant's docblock),
        // which is only acceptable while they keep printing the zone.
        foreach (self::PLATFORM_TIMESTAMP_TEMPLATES as $relative) {
            $contents = (string) file_get_contents(base_path($relative));

            $this->assertStringContainsString('now()->format(', $contents, "{$relative} is expected to be a platform timestamp");
            $this->assertMatchesRegularExpression(
                '/now\(\)->format\([\'"][^\'"]*T[\'"]\)/',
                $contents,
                "{$relative} renders a server-time timestamp, so its format must keep the T zone label.",
            );
        }
    }

    /** Executable source only, so comments describing the banned pattern never trip a scan. */
    private function strippedSource(string $file): string
    {
        $kept = '';

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $kept .= $token[1];

                continue;
            }

            $kept .= $token;
        }

        return $kept;
    }

    /** @return list<string> */
    private function notificationFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Notifications'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
