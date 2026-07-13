<?php

declare(strict_types=1);

namespace App\Lessons\Enums;

/** What went wrong with the meeting, as reported by a participant. */
enum TechnicalIssueCategory: string
{
    case UnableToJoin = 'unable_to_join';
    case InvalidMeetingLink = 'invalid_meeting_link';
    case AudioIssue = 'audio_issue';
    case VideoIssue = 'video_issue';
    case InternetDisconnection = 'internet_disconnection';
    case ProviderOutage = 'provider_outage';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::UnableToJoin => 'Unable to Join',
            self::InvalidMeetingLink => 'Invalid Meeting Link',
            self::AudioIssue => 'Audio Issue',
            self::VideoIssue => 'Video Issue',
            self::InternetDisconnection => 'Internet Disconnection',
            self::ProviderOutage => 'Provider Outage',
            self::Other => 'Other',
        };
    }
}
