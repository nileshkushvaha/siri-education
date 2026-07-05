<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class InstructorSettings extends Settings
{
    public bool $approval_required;

    public bool $profile_publish_requires_approval;

    public int $featured_instructor_limit;

    public bool $availability_required_for_public_profile;

    public static function group(): string
    {
        return 'instructor';
    }
}
