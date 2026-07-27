<?php

declare(strict_types=1);

namespace App\Exceptions\Student;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * Thrown by StudentLifecycleService::
 * assertEligibleForStudentAction() when an interactive student action is
 * attempted by an account that is not an Active student. Deliberately
 * carries ONE fixed, generic message — never the actual status
 * (Registered/Suspended/Archived/null) or any administrative reason —
 * and extends AuthorizationException so Laravel/Livewire automatically
 * render it as a safe 403 instead of a 500, matching how the
 * surrounding services already surface ownership failures.
 */
final class StudentActionNotAvailableException extends AuthorizationException
{
    public static function make(): self
    {
        return new self('Your account is not available for this action. Please contact support.');
    }
}
