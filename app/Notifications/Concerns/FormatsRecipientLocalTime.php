<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Models\User;
use App\Support\UserTimezoneResolver;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * TZ-3 (TZ-AUD-014): the one way a notification turns an absolute
 * instant into text a recipient can act on.
 *
 * Extracted verbatim from RoutesBookingChannels, where the booking
 * family had been doing this correctly all along — the logic was never
 * booking-specific, only its location was. Generalizing it is what stops
 * a fourth domain from inventing a QualityTimezoneResolver.
 *
 * The rule it enforces has one subtlety worth stating: the timezone
 * comes from the ACTUAL `$notifiable`, resolved at render time — never
 * from the record being described. A booking belongs to a student, but
 * the instructor's copy of that email must still read in the
 * instructor's clock, and neither of them is the admin who gets the
 * quality alert. Anything pre-formatted before Laravel picks the
 * recipient has already leaked somebody else's timezone into the
 * message.
 *
 * Non-User notifiables (AnonymousNotifiable, used for guest email
 * routing) have no profile, so they get the platform fallback.
 */
trait FormatsRecipientLocalTime
{
    /**
     * SRS-21-6, SRS §21.13/§21.16: the ACTUAL notifiable's own timezone
     * — never the record's captured timezone, never another
     * participant's, never the server's.
     */
    protected function recipientTimezone(object $notifiable): string
    {
        return $notifiable instanceof User
            ? UserTimezoneResolver::resolve($notifiable)
            : UserTimezoneResolver::PLATFORM_FALLBACK;
    }

    /** The instant, moved into the recipient's clock. Nothing is mutated or re-saved. */
    protected function recipientLocal(DateTimeInterface $instant, object $notifiable): CarbonImmutable
    {
        return CarbonImmutable::instance($instant)->setTimezone($this->recipientTimezone($notifiable));
    }

    /**
     * A date and time in the recipient's clock, labelled with the IANA
     * identifier — the convention the booking notifications already
     * established (`… on Sat, Aug 15 2026 at 17:30 (Asia/Kolkata)`).
     *
     * The label is not decoration. Without it a bare "5:30 PM" is
     * unverifiable by the reader, and support cannot tell whether a
     * confused student is looking at their own clock or someone else's.
     * The full identifier is used rather than an abbreviation because
     * `CST` and `IST` each denote several different offsets.
     */
    protected function recipientDateTime(DateTimeInterface $instant, object $notifiable, string $format = 'M j, Y g:i A'): string
    {
        return sprintf(
            '%s (%s)',
            $this->recipientLocal($instant, $notifiable)->format($format),
            $this->recipientTimezone($notifiable),
        );
    }

    /**
     * A calendar DATE in the recipient's clock, labelled.
     *
     * Deliberately separate from recipientDateTime() because a deadline
     * date is the case where getting this wrong is most visible: an
     * expiry at 23:30 UTC is "Aug 15" in Los Angeles and "Aug 16" in
     * Kolkata, so rendering the server's date tells half the world the
     * wrong day. The label stays for the same reason — the date itself
     * is timezone-dependent, so it needs to say whose date it is.
     */
    protected function recipientDate(DateTimeInterface $instant, object $notifiable, string $format = 'M j, Y'): string
    {
        return sprintf(
            '%s (%s)',
            $this->recipientLocal($instant, $notifiable)->format($format),
            $this->recipientTimezone($notifiable),
        );
    }
}
