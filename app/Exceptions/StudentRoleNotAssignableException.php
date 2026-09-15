<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** The student role is granted at registration only — never added to an existing account. */
final class StudentRoleNotAssignableException extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'The student role is only granted at registration. This is an instructor account; to learn on the platform, register a separate student account with a different email.',
        );
    }
}
