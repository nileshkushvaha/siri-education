<?php

declare(strict_types=1);

namespace App\Lessons\Enums;

/** Where a piece of attendance evidence came from. */
enum AttendanceSource: string
{
    case ProviderWebhook = 'provider_webhook';
    case ProviderSync = 'provider_sync';
    case InstructorConfirmation = 'instructor_confirmation';
    case AdminOverride = 'admin_override';
    case SystemFallback = 'system_fallback';

    public function label(): string
    {
        return match ($this) {
            self::ProviderWebhook => 'Provider Webhook',
            self::ProviderSync => 'Provider Sync',
            self::InstructorConfirmation => 'Instructor Confirmation',
            self::AdminOverride => 'Administrator Override',
            self::SystemFallback => 'System Fallback',
        };
    }

    /** Evidence recorded by a human actor (requires authorization). */
    public function isManual(): bool
    {
        return match ($this) {
            self::InstructorConfirmation, self::AdminOverride => true,
            default => false,
        };
    }
}
