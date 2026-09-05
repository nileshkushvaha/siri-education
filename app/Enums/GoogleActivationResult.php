<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Outcome of a "Continue with Google" attempt (Google account activation).
 *
 * Messages tell the visitor what went wrong with THEIR OWN Google account
 * and what to do next. Google has already verified they control that
 * email, so naming it back to them reveals nothing they don't know; the
 * one thing never exposed is the internal state of someone else's account
 * (IdentityConflict stays deliberately vague about the other user).
 */
enum GoogleActivationResult: string
{
    case Success = 'success';
    case Disabled = 'disabled';
    case NotRegistered = 'not_registered';
    case AlreadyActivated = 'already_activated';
    case IdentityConflict = 'identity_conflict';
    case AdminAccount = 'admin_account';
    case UnverifiedGoogleEmail = 'unverified_google_email';
    case AccountUnavailable = 'account_unavailable';
    case OAuthFailed = 'oauth_failed';
    case PendingApproval = 'pending_approval';

    public function isSuccessful(): bool
    {
        return $this === self::Success;
    }

    /**
     * @param  string|null  $googleEmail  the verified Google address, when known
     * @param  LoginResult|null  $loginResult  why LoginService refused (AccountUnavailable only)
     */
    public function message(?string $googleEmail = null, ?LoginResult $loginResult = null): string
    {
        $who = $googleEmail !== null && $googleEmail !== '' ? $googleEmail : 'this Google account';

        return match ($this) {
            self::Success => 'Welcome!',
            self::Disabled => 'Google sign-in is currently turned off. Please sign in with your email and password.',
            self::NotRegistered => "No student or instructor account is registered for {$who}, and new registrations are currently closed. Use the Google account that matches the email your administrator registered, or contact your administrator.",
            self::PendingApproval => "Your student account for {$who} has been created and is awaiting administrator approval. We'll email you as soon as it is ready.",
            self::AlreadyActivated => 'Your account has already been activated. Please sign in with your email and password. Forgot it? Use "Forgot password" below.',
            self::IdentityConflict => "The account for {$who} is already linked to a different Google account. Sign in with your email and password, or contact your administrator to re-link it.",
            self::AdminAccount => 'Administrator and manager accounts do not use Google sign-in. Please sign in with your email and password at /admin/login.',
            self::UnverifiedGoogleEmail => "Google could not confirm the email address on {$who}. Verify your email address in your Google account settings, then try again.",
            self::AccountUnavailable => $loginResult?->message() ?? 'Your account is not available for sign-in. Please contact support.',
            self::OAuthFailed => 'Sign-in with Google was interrupted before it completed. Please try again from this page.',
        };
    }
}
