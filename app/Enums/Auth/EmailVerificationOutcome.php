<?php

declare(strict_types=1);

namespace App\Enums\Auth;

/**
 * The result of following a signed email-verification link.
 *
 * Verifying an email address and activating an account are two separate
 * decisions, and this enum keeps them separate. A person who controls
 * the mailbox has proven that fact regardless of what an administrator
 * has since done to the account, so the email can be verified while the
 * account stays restricted — and the success page must then not claim
 * the account is usable.
 */
enum EmailVerificationOutcome: string
{
    /** The link was followed again after a successful verification. Not an error. */
    case AlreadyVerified = 'already_verified';

    /** The normal path: a pending registration is now verified and active. */
    case VerifiedAndActivated = 'verified_and_activated';

    /**
     * Email verified, but the account still requires administrator
     * approval (`User::STATUS_INACTIVE`, created when
     * `RegistrationSettings::$require_admin_approval` is on). Verification
     * must never short-circuit that approval.
     */
    case VerifiedPendingApproval = 'verified_pending_approval';

    /**
     * Email verified, but the account is blocked or suspended. An old
     * link must never undo an administrative restriction.
     */
    case VerifiedAccountRestricted = 'verified_account_restricted';

    /**
     * The account is in a status this workflow does not recognise. Fails
     * closed: the email is left alone and nothing is activated.
     */
    case UnsupportedAccountState = 'unsupported_account_state';

    /** Whether the account can be logged into as a result of this outcome. */
    public function accountIsUsable(): bool
    {
        return match ($this) {
            self::VerifiedAndActivated, self::AlreadyVerified => true,
            default => false,
        };
    }

    /** Whether the email address itself ended up verified. */
    public function emailIsVerified(): bool
    {
        return $this !== self::UnsupportedAccountState;
    }

    /**
     * Public-facing copy. Deliberately free of internal status names and
     * of any detail that would confirm what an administrator has done to
     * a particular account.
     */
    public function message(): string
    {
        return match ($this) {
            self::AlreadyVerified => 'Your email address was already verified. You can sign in now.',
            self::VerifiedAndActivated => 'Your email address is verified and your account is active. You can sign in now.',
            self::VerifiedPendingApproval => 'Your email address is verified. Your account is awaiting review before you can sign in — we will email you once it is ready.',
            self::VerifiedAccountRestricted => 'Your email address is verified, but this account cannot be accessed at the moment. Please contact support if you need help.',
            self::UnsupportedAccountState => 'We could not complete verification for this account. Please contact support.',
        };
    }
}
