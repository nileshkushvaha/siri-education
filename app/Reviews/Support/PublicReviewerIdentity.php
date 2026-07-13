<?php

declare(strict_types=1);

namespace App\Reviews\Support;

use App\Enums\StudentStatus;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Masks a reviewing student's identity for public display, per
 * `ReviewSettings::$public_review_identity_mode`. Computed fresh at
 * read time from the student's *current* name/status — never stored,
 * so a later setting change reshapes every past review's label
 * without touching a single review row, and a student who becomes
 * unavailable after review submission never leaks a stale label.
 */
final class PublicReviewerIdentity
{
    private const string FALLBACK_LABEL = 'Verified Student';

    public static function label(?User $student, string $mode): string
    {
        if ($student === null || ! self::isAvailable($student)) {
            return self::FALLBACK_LABEL;
        }

        return match ($mode) {
            'anonymous' => self::FALLBACK_LABEL,
            'first_name_only' => self::firstName($student) ?? self::FALLBACK_LABEL,
            default => self::firstNameInitial($student) ?? self::FALLBACK_LABEL, // 'first_name_initial'
        };
    }

    private static function isAvailable(User $student): bool
    {
        return $student->isActive() && $student->profile?->student_status !== StudentStatus::Archived;
    }

    private static function firstName(User $student): ?string
    {
        $firstName = trim((string) ($student->first_name ?? ''));

        return $firstName !== '' ? $firstName : null;
    }

    private static function firstNameInitial(User $student): ?string
    {
        $firstName = self::firstName($student);

        return $firstName !== null ? Str::upper(Str::substr($firstName, 0, 1)).'***' : null;
    }
}
