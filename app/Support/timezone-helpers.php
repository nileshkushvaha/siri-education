<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Timezone\ViewerDateTime;

/**
 * TZ-4: Blade-facing shorthands for viewer-local presentation.
 *
 * These exist because the alternative in a Blade file is either a
 * fully-qualified static on every line or an `@php use` block per
 * template — and the thing being replaced,
 * `{{ $booking->starts_at->format('M j, Y') }}`, is short enough that a
 * verbose replacement would simply not get adopted.
 *
 * They add no behaviour of their own: every one is a thin pass-through
 * to ViewerDateTime, which owns the resolution and the null handling.
 * Use `<x-ui.local-datetime>` where a `<time>` element is wanted (it
 * also emits the canonical UTC value as a machine-readable attribute);
 * use these inside attributes, string concatenation and ternaries where
 * a component cannot go.
 *
 * NOT for notifications or queued code — there is no viewer there. That
 * side uses FormatsRecipientLocalTime, which takes the recipient
 * explicitly.
 */
if (! function_exists('viewer_datetime')) {
    /** An instant as date + time in the logged-in viewer's timezone. */
    function viewer_datetime(mixed $instant, ?string $format = null, ?User $viewer = null): ?string
    {
        return ViewerDateTime::dateTime($instant, $viewer, $format ?? ViewerDateTime::DATE_TIME);
    }
}

if (! function_exists('viewer_date')) {
    /**
     * An instant as a calendar date in the logged-in viewer's timezone.
     *
     * For an ABSOLUTE INSTANT only. A date-only column (a birthday, a
     * holiday, a settlement period boundary) has no timezone to convert
     * from — pass those straight to `->format()` as before, or they will
     * shift a day for viewers west of UTC.
     */
    function viewer_date(mixed $instant, ?string $format = null, ?User $viewer = null): ?string
    {
        return ViewerDateTime::date($instant, $viewer, $format ?? ViewerDateTime::DATE);
    }
}

if (! function_exists('viewer_time')) {
    /** An instant as a time of day in the logged-in viewer's timezone. */
    function viewer_time(mixed $instant, ?string $format = null, ?User $viewer = null): ?string
    {
        return ViewerDateTime::time($instant, $viewer, $format ?? ViewerDateTime::TIME);
    }
}

if (! function_exists('viewer_datetime_labelled')) {
    /** As viewer_datetime(), with the IANA identifier appended — for deadlines and confirmations. */
    function viewer_datetime_labelled(mixed $instant, ?string $format = null, ?User $viewer = null): ?string
    {
        return ViewerDateTime::labelled($instant, $viewer, $format ?? ViewerDateTime::DATE_TIME);
    }
}
