<?php

declare(strict_types=1);

namespace App\Support\Timezone;

use App\Models\User;
use App\Support\UserTimezoneResolver;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Auth;

/**
 * TZ-4: how an absolute instant is presented to the person LOOKING at
 * the screen.
 *
 * It answers exactly one question — "how should this instant read for
 * this viewer?" — and deliberately answers no others. It does not decide
 * whether a booking is today, whether a package has expired, which
 * bookings belong to a reporting day, or when a cancellation window
 * closes. Those are calculations, they live in their domains, and they
 * work on UTC instants. Mixing them in here is how a formatter quietly
 * becomes a second source of business truth.
 *
 * The viewer is the LOGGED-IN user, never the subject of the record. An
 * admin reading a student's booking sees it in the admin's clock; an
 * instructor reading a student's homework sees it in the instructor's.
 * The booking's own `timezone` column is a booking-origin snapshot
 * (TZ-1) and is never consulted here.
 *
 * Nothing is mutated: Carbon instances are immutable throughout, and no
 * model is touched or saved. The stored instant is unchanged by
 * rendering it.
 *
 * The counterpart on the notification side is
 * FormatsRecipientLocalTime, which resolves the RECIPIENT instead. The
 * two are kept separate on purpose — "who is reading this page" and "who
 * is this email addressed to" are different questions that happen to
 * share a resolver.
 */
final class ViewerDateTime
{
    /** Compact portal default: `Aug 15, 2026 9:00 AM`. */
    public const string DATE_TIME = 'M j, Y g:i A';

    public const string DATE = 'M j, Y';

    public const string TIME = 'g:i A';

    /** Session key holding an anonymous visitor's validated browser timezone (display only). */
    public const string BROWSER_TIMEZONE_KEY = 'viewer_browser_timezone';

    /**
     * The viewer's resolved timezone.
     *
     * Reading Auth here is correct and is the point of the class: this
     * is presentation, always inside a request, always for whoever is
     * looking. An explicit $viewer still wins, which is what keeps it
     * testable and lets a caller render for a specific person. Queued
     * and notification code must not use this class at all — it has
     * FormatsRecipientLocalTime, which takes the recipient explicitly.
     */
    public static function timezoneFor(?User $viewer = null): string
    {
        $viewer ??= Auth::user();

        if ($viewer instanceof User) {
            // TZ-6: an authenticated user's own resolved timezone always
            // wins. A browser hint never overrides a choice they made.
            return UserTimezoneResolver::resolve($viewer);
        }

        return self::anonymousTimezone();
    }

    /**
     * TZ-6 (Product Decision 3 / TZ-AUD-020): display timezone for a
     * visitor with no account.
     *
     *     validated browser IANA identifier
     *         -> GeneralSettings.default_timezone
     *             -> UTC
     *
     * The browser hint is DISPLAY CONTEXT ONLY. It is validated against
     * the canonical IANA list before it is trusted at all, and it is
     * never written to a profile, never used to infer a country, never
     * allowed to influence price, payment or availability validation —
     * a visitor cannot change what a lesson costs or when it is bookable
     * by editing their device clock. There is no IP lookup, no
     * geolocation prompt and no phone-prefix guessing anywhere in this
     * path, by design.
     */
    public static function anonymousTimezone(): string
    {
        return IanaTimezone::sanitize(session(self::BROWSER_TIMEZONE_KEY))
            ?? UserTimezoneResolver::platformDefault();
    }

    /**
     * Record a browser-reported timezone for the current visitor.
     *
     * Rejects anything that is not a canonical IANA identifier, so a
     * crafted value cannot reach Carbon. Deliberately session-scoped and
     * deliberately NOT persisted: it describes the device in front of us
     * right now, not a decision the person has made.
     */
    public static function rememberBrowserTimezone(?string $timezone): bool
    {
        $valid = IanaTimezone::sanitize($timezone);

        if ($valid === null) {
            return false;
        }

        session([self::BROWSER_TIMEZONE_KEY => $valid]);

        return true;
    }

    /** The instant moved into the viewer's clock. Null-safe; never mutates. */
    public static function local(DateTimeInterface|string|null $instant, ?User $viewer = null): ?CarbonImmutable
    {
        if ($instant === null || $instant === '') {
            return null;
        }

        $carbon = $instant instanceof DateTimeInterface
            ? CarbonImmutable::instance($instant)
            : CarbonImmutable::parse($instant, 'UTC');

        return $carbon->setTimezone(self::timezoneFor($viewer));
    }

    public static function dateTime(DateTimeInterface|string|null $instant, ?User $viewer = null, string $format = self::DATE_TIME): ?string
    {
        return self::local($instant, $viewer)?->format($format);
    }

    public static function date(DateTimeInterface|string|null $instant, ?User $viewer = null, string $format = self::DATE): ?string
    {
        return self::local($instant, $viewer)?->format($format);
    }

    public static function time(DateTimeInterface|string|null $instant, ?User $viewer = null, string $format = self::TIME): ?string
    {
        return self::local($instant, $viewer)?->format($format);
    }

    /**
     * Same as dateTime(), with the IANA identifier appended.
     *
     * Reserved for the places where being wrong costs the reader
     * something — a deadline, a booking confirmation — rather than every
     * table row. A page-level "Times shown in Asia/Kolkata" note carries
     * the context for lists; repeating it on every cell is noise.
     */
    public static function labelled(DateTimeInterface|string|null $instant, ?User $viewer = null, string $format = self::DATE_TIME): ?string
    {
        $local = self::local($instant, $viewer);

        return $local === null
            ? null
            : sprintf('%s (%s)', $local->format($format), $local->timezoneName);
    }
}
