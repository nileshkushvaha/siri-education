<?php

declare(strict_types=1);

namespace App\Enums;

enum PortalAudience: string
{
    case Student = 'student';
    case Instructor = 'instructor';
    case AdminOrUnsupported = 'admin_or_unsupported';
}
