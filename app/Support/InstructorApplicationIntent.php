<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Carries a guest's "I want to become an instructor" intent from the
 * public /become-instructor CTA (?intent=instructor on the registration
 * page) through registration and email verification, so the very next
 * screen after verifying is the instructor onboarding wizard instead of
 * the plain student dashboard.
 *
 * Session-only, never persisted to the database, and self-clearing —
 * consume() reads it exactly once. If the browser session is lost
 * (different device opens the verification email link, session
 * expires), the intent is silently lost and the user simply lands on
 * their normal dashboard, where the existing "Become an Instructor"
 * entry points remain reachable manually.
 */
final class InstructorApplicationIntent
{
    private const SESSION_KEY = 'registration_intent';

    private const INSTRUCTOR = 'instructor';

    /** Call on any page that accepts ?intent=instructor (currently: the registration page). */
    public static function captureFromRequest(): void
    {
        if (request()->query('intent') === self::INSTRUCTOR) {
            session([self::SESSION_KEY => self::INSTRUCTOR]);
        }
    }

    public static function pending(): bool
    {
        return session(self::SESSION_KEY) === self::INSTRUCTOR;
    }

    /** Reads and clears the pending flag in one step — a given intent is redeemed at most once. */
    public static function consume(): bool
    {
        $pending = self::pending();

        session()->forget(self::SESSION_KEY);

        return $pending;
    }
}
