<?php

declare(strict_types=1);

namespace App\Package\Exceptions;

use App\Package\Enums\InstructorPackageProposalStatus;

final class InvalidPackageProposalTransitionException extends PackageException
{
    public static function between(InstructorPackageProposalStatus $from, InstructorPackageProposalStatus $to): self
    {
        return new self(sprintf(
            'A package proposal cannot move from "%s" to "%s".',
            $from->value,
            $to->value,
        ));
    }
}
