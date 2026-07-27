<?php

declare(strict_types=1);

namespace App\Services\Instructor;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Single source of truth for the instructor headline/biography text
 * shown on the public profile.
 *
 * Field audit findings:
 *  - headline vs. the former `designation` column: `designation` was
 *    write-only — captured by the admin form and the generic /profile
 *    self-edit form, but never read by any public view, card, search
 *    result, or SEO output. `headline` was always the sole
 *    actively-displayed field. Since this is a dev-mode cleanup with no
 *    legacy data to preserve, the orphaned column was dropped outright
 *    (see the drop_designation_from_user_profiles_table migration)
 *    rather than kept around as a fallback nothing would ever populate.
 *
 *  - bio vs. short_bio: audited and found NOT to be a duplicate pair —
 *    short_bio is an intentionally short, hand-written marketplace-card
 *    excerpt, distinct in purpose from the full bio shown on the detail
 *    page. Two call sites (InstructorService::card() and
 *    instructors/show.blade.php's meta description) already
 *    independently implemented the same `short_bio ?: Str::limit(bio)`
 *    fallback ad hoc — summary() centralizes that one pattern. bio and
 *    short_bio both remain live, editable, canonical fields.
 */
final class InstructorProfileTextResolver
{
    public function headline(User $instructor): ?string
    {
        return $instructor->profile?->headline;
    }

    public function biography(User $instructor): ?string
    {
        return $instructor->profile?->bio;
    }

    public function summary(User $instructor, int $limit = 130): ?string
    {
        $profile = $instructor->profile;

        if (filled($profile?->short_bio)) {
            return $profile->short_bio;
        }

        return filled($profile?->bio) ? Str::limit($profile->bio, $limit) : null;
    }
}
