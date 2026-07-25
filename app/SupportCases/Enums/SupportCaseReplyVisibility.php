<?php

declare(strict_types=1);

namespace App\SupportCases\Enums;

/**
 * SRS §25.19-25.20/§25.37/§25.41: internal notes are never visible to
 * students or instructors. This is the single flag every query and
 * view must filter on before showing a reply to a requester.
 */
enum SupportCaseReplyVisibility: string
{
    case RequesterVisible = 'requester_visible';
    case InternalNote = 'internal_note';

    public function label(): string
    {
        return match ($this) {
            self::RequesterVisible => 'Public Reply',
            self::InternalNote => 'Internal Note',
        };
    }
}
