<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Support\Timezone\IanaTimezone;
use App\Support\UserTimezoneResolver;
use Illuminate\Support\Facades\Validator;
use ReflectionClass;
use Tests\TestCase;

/**
 * TZ-1 permanent guard: user-timezone resolution has exactly ONE owner.
 *
 * The anti-pattern this exists to stop is the inline fallback ladder
 * that TZ-1 removed from ten services:
 *
 *     $user->profile?->timezone ?: config('app.timezone')
 *     $user->profile?->timezone ?? 'UTC'
 *
 * Each copy carried a DIFFERENT fallback from the canonical chain and
 * skipped validation entirely, so a legacy stored value reached
 * `->timezone()` and threw.
 *
 * Deliberately scoped to that pattern, NOT to `config('app.timezone')`
 * as a string. That call is legitimate and still used on purpose — for
 * converting a local boundary to the storage timezone
 * (InstructorDashboardService), for platform-owned periodic
 * compensation arithmetic (InstructorPeriodicCompensationService), and
 * as a provider label default (ZoomMeetingProvider). A guard that
 * banned the string outright would be wrong and would be switched off
 * within a week.
 *
 * Every scan below runs over EXECUTABLE code only — comments and
 * docblocks are stripped first (see strippedSource()). Without that,
 * this file, the resolver's own docblock and every explanatory comment
 * describing the banned pattern would flag themselves, which is the
 * classic way an architecture guard ends up with a hand-maintained
 * allowlist that slowly stops meaning anything.
 */
class UserTimezoneResolutionGuardTest extends TestCase
{
    // ── The resolver is the single owner ────────────────────────────────

    public function test_canonical_resolver_exists(): void
    {
        $this->assertTrue(class_exists(UserTimezoneResolver::class));
        $this->assertTrue(class_exists(IanaTimezone::class));
    }

    public function test_the_superseded_recipient_resolver_is_gone(): void
    {
        // Promoted into UserTimezoneResolver. If this file comes back,
        // two competing user resolvers exist again — the exact state
        // TZ-1 closed. Checked on disk rather than with class_exists()
        // so a stale composer classmap cannot turn the guard into a
        // fatal include error instead of a clean assertion.
        $this->assertFileDoesNotExist(app_path('Support/RecipientTimezoneResolver.php'));
    }

    public function test_no_production_code_reintroduces_the_inline_user_fallback(): void
    {
        $offenders = [];

        foreach ($this->productionFiles() as $file) {
            // `profile?->timezone` (or `profile->timezone`) followed by
            // a `?:`/`??` fallback to config() or to a string literal —
            // the ladder itself, not any mention of a profile timezone.
            //
            // Reading the raw stored value stays allowed: form prefill,
            // API exposure and "has this user actually chosen one?"
            // checks all legitimately need it. Falling back to the
            // resolver's own helpers is allowed too — that is the fix,
            // not the offence.
            if (preg_match('/profile\??->timezone\s*(?:\?\?|\?:)\s*(?:config\s*\(|[\'"])/', $this->strippedSource($file)) === 1) {
                $offenders[] = $this->relative($file);
            }
        }

        $this->assertSame([], $offenders, implode(' ', [
            'Inline user-timezone fallback found. Use UserTimezoneResolver::resolve($user) instead —',
            'it validates every tier and falls through profile -> Country -> platform default -> UTC.',
            'Offending files:', implode(', ', $offenders),
        ]));
    }

    /**
     * Scoped to DEFAULT and FALLBACK positions — `->default('…')`,
     * `?? '…'`, `?: '…'` and plain assignment — because those are the
     * positions in which a country literal actually decides somebody's
     * clock.
     *
     * Two other appearances are deliberately outside scope and must
     * stay legal, or the guard becomes a nuisance that gets suppressed:
     * an input's `placeholder('e.g. Asia/Kolkata')` is help text, and
     * NotificationTemplateSampleData's `'timezone' => 'Asia/Kolkata'` is
     * a preview payload for the admin template editor. Neither can be
     * read by any resolution path.
     */
    public function test_no_india_specific_timezone_is_used_as_a_default_or_fallback(): void
    {
        $offenders = [];

        foreach ($this->productionFiles() as $file) {
            if (preg_match('/(?:->default\s*\(|\?\?|\?:|(?<![=!<>])=(?!>))\s*[\'"]Asia\/Kolkata[\'"]/', $this->strippedSource($file)) === 1) {
                $offenders[] = $this->relative($file);
            }
        }

        $this->assertSame([], $offenders, implode(' ', [
            'Hardcoded Asia/Kolkata found in runtime code. A single country\'s timezone must never be',
            'a platform-wide fallback — use UserTimezoneResolver::platformDefault(). Offending files:',
            implode(', ', $offenders),
        ]));
    }

    // ── The resolver stays context-free ─────────────────────────────────

    public function test_resolver_never_reads_the_authenticated_user_itself(): void
    {
        // A resolver reaching for auth() would be wrong in the three
        // places it matters most: a notification recipient is not the
        // logged-in user, a queued job has no session, and an admin
        // surface chooses whose timezone it wants. The CALLER passes the
        // User in — which is also why no currentUserTimezone() exists.
        $source = $this->strippedSource((new ReflectionClass(UserTimezoneResolver::class))->getFileName());

        $this->assertDoesNotMatchRegularExpression('/\bauth\s*\(\s*\)/', $source);
        $this->assertDoesNotMatchRegularExpression('/\bAuth::(user|id)\s*\(/', $source);
        $this->assertStringNotContainsString('currentUserTimezone', $source);
    }

    // ── One definition of a valid identifier ────────────────────────────

    public function test_shared_validator_matches_the_framework_validation_rule(): void
    {
        // IanaTimezone::identifiers() backs the resolver, the booking
        // wizard's browser detection and every timezone Select;
        // `timezone:all` backs profile request validation. If the two
        // ever diverge, a value could pass validation and then be
        // rejected at resolution time, or vice versa.
        $identifiers = IanaTimezone::identifiers();

        $this->assertNotEmpty($identifiers);

        foreach (['Asia/Kolkata', 'Europe/London', 'America/New_York', 'Australia/Sydney', 'UTC'] as $canonical) {
            $this->assertContains($canonical, $identifiers);
            $this->assertTrue(IanaTimezone::isValid($canonical));
            $this->assertTrue($this->passesFrameworkRule($canonical), "{$canonical} should pass timezone:all");
        }

        // Each of these is accepted by `new DateTimeZone(...)`, which is
        // exactly why constructor success is not the validity test.
        foreach (['EST', 'GMT', 'CST6CDT', 'US/Eastern', 'Asia/Calcutta', '+05:30', 'GMT+5', 'not-a-timezone', ''] as $rejected) {
            $this->assertFalse(IanaTimezone::isValid($rejected), "{$rejected} must not be accepted");
            $this->assertFalse($this->passesFrameworkRule($rejected), "{$rejected} should fail timezone:all");
        }
    }

    public function test_validator_rejects_non_string_input_without_throwing(): void
    {
        foreach ([null, 0, 5.5, [], true] as $notAString) {
            $this->assertFalse(IanaTimezone::isValid($notAString));
            $this->assertNull(IanaTimezone::sanitize($notAString));
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function passesFrameworkRule(string $value): bool
    {
        return ! Validator::make(['tz' => $value], ['tz' => ['required', 'timezone:all']])->fails();
    }

    /** Executable source only: comments and docblocks removed, so documentation never trips a scan. */
    private function strippedSource(string $file): string
    {
        $source = (string) file_get_contents($file);
        $kept = '';

        foreach (token_get_all($source) as $token) {
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

    private function relative(string $file): string
    {
        return str_replace(base_path().'/', '', $file);
    }

    /** @return list<string> */
    private function productionFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
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
